# AGENTS.md — imageserver

## What this is
WordPress plugin (PHP 8.2, WordPress 6.7+) that rewrites WooCommerce product image HTML to point at an external image server. It does not upload, proxy, or copy files — it only rewrites URLs in the markup WooCommerce already produced. Namespace `Salamander\Imageserver`, classes `IS_Admin`, `IS_Frontend`, `IS_Product_Meta` and `IS_Server_Api`.

## Layout
```
ask.sh                                              dev stack menu (TLS setup, start/stop, DB, wp-cli)
plugins/imageserver/imageserver.php                plugin header, constants, autoloader, bootstrap
plugins/imageserver/src/Class/IS_Admin.php         settings page, option + sanitization, defaults, manifest fetch
plugins/imageserver/src/Class/IS_Frontend.php      WooCommerce filters, meta reading, URL building
plugins/imageserver/src/Class/IS_Product_Meta.php  product/variation meta box + registered REST meta keys
plugins/imageserver/src/Class/IS_Server_Api.php    fetches and validates GET {source}/api/patterns
plugins/woocommerce/                                vendored WooCommerce 11.1.2, bind-mounted read-only
plugins/plugin-check/                              vendored Plugin Check 2.1.0, bind-mounted read-only
mu-plugins/hide-admin-notices.php                  always-on helper, bind-mounted into both services
dockers/docker-compose.yml                          nginx-proxy + WordPress + MariaDB + phpmyadmin + wpcli
dockers/nginx/wordpress.conf                        per-vhost snippet for nginx-proxy (64m body cap)
dockers/certs/_.app.local/                          shared *.app.local cert, copied from tibellus, gitignored
```

`plugins/` holds third-party plugins that are tracked in git on purpose, so the versions are pinned and the code can be edited host-side. They are bind-mounted **read-write** into **both** the `wordpress` and `wpcli` services — the wpcli mount must match or `wp plugin list` / `activate` would act on a different copy than the site serves. Host files are `1000:33` and group-writable so `www-data` (uid 33) can write; WordPress can therefore update the plugins in place, which will leave local modifications in `git status`. `ask.sh` task 11 activates the vendored copies rather than installing from wordpress.org, and only falls back to a download if `plugins/<slug>` is missing.

The plugin is at `plugins/imageserver/` and the compose files mount exactly that directory (`../plugins/imageserver`) as `wp-content/plugins/imageserver`, so WordPress detects it and `wp plugin activate imageserver` works.

## Autoloading
`imageserver.php` maps `Salamander\Imageserver\X` → `IMAGESERVER_PLUGIN_DIR . 'src/Class/' . X . '.php'` and `require_once`s it. There is no `composer.json`; put new classes in `src/Class/` so the mapping keeps working.

## Settings
Single option `imageserver_settings` (array), page at Settings → Image Server, capability `manage_options`, registered on `admin_init` with a `sanitize_callback`.
- `enabled` — checkbox, default `1`; when off, no filters are registered at all.
- `source` — base URL, default `https://img.salamander-jewelry.net`, run through `esc_url_raw` + `untrailingslashit`.
- `resize_style` — `canvas` or `resize`, default `canvas`; chooses which server pattern fills `resize_pattern`.
- `original_pattern` — default `/img/{path}`; used when no size is passed. Fetched from the server, editable to override.
- `resize_pattern` — default `/canvas/{width}/{path}`. Fetched from the server, editable to override. Uses `{width}`/`{height}` — the server's canvas route expects an integer width, so the old `{size}` (a WooCommerce slug like `woocommerce_single`) is not used.
- `patterns_source`, `patterns_fetched_at`, `patterns_examples` — bookkeeping: which source the manifest was fetched from, when, and the server's example relative paths keyed by pattern. Preserved across `options.php` saves (they are not form fields).
- `sanitize_pattern()` appends `{path}` if missing and force-prefixes a leading `/`, so a pattern can never escape the source host.

### Manifest fetch
A **Fetch patterns from server** button posts to `admin-post.php?action=imageserver_fetch_patterns` (nonce `imageserver_fetch_patterns`, `manage_options`). `IS_Admin::handle_fetch_patterns()` calls `IS_Server_Api::fetch($source)`, and on success `apply_manifest()` fills `original_pattern` from `patterns[roles.original]` and `resize_pattern` from `patterns[resize_style]` (falling back to `defaults.resize`, then the first `resize_options` entry), then records `patterns_source`/`patterns_fetched_at`. Success or failure is reported through a one-minute transient read by `render_notices()`. `render_notices()` also warns on the settings screen when `source` differs from `patterns_source` (or the manifest has never been fetched), telling the user to fetch again. The runtime never makes HTTP calls — `IS_Frontend` uses the stored pattern strings only.

## Server API contract
`IS_Server_Api::fetch()` GETs `{source}/api/patterns`, expects HTTP 2xx + JSON, and requires a `patterns` object. Patterns are written in the client's placeholders `{path}`, `{width}`, `{height}`; keys seen today are `plain`, `resize`, `canvas`, `composite`. The server (rimgs) returns:
```json
{
  "service": "rimgs", "api": 1,
  "placeholders": { "path": "...", "width": "...", "height": "..." },
  "patterns": {
    "plain": "/img/{path}",
    "resize": "/img/x/{width}/{height}/{path}",
    "canvas": "/canvas/{width}/{path}",
    "composite": "/comp/{base}/{size}/{path}"
  },
  "examples": {
    "plain": "/img/CHM/14CM01-CR.png",
    "resize": "/img/x/150/150/CHM/14CM01-CR.png",
    "canvas": "/canvas/250/P8/TMCBC-AN.png",
    "composite": "/comp/BKBNJP2/120/STK/P-STK319-RO.png"
  },
  "roles": { "original": "plain" },
  "resize_options": ["canvas", "resize"],
  "defaults": { "resize": "canvas" }
}
```
`examples` is an optional map of pattern key → a real, source-relative file (e.g. `/img/CHM/14CM01-CR.png`). `IS_Server_Api` validates each as a relative path (no `://`), stores the map in `patterns_examples`, and the settings screen renders a **Server examples** table with a link and a live `<img>` preview per pattern — so the user can see the image server works.

Invalid patterns (non-string, missing `{path}`) are dropped; a manifest with no usable pattern is rejected. Anything that fails leaves the current settings untouched and shows the error.

## Data source
Meta written by the syncer, not by this plugin:
- product: `picture_paths` (plural)
- variation: `picture_path` (singular), falling back to the parent's `picture_paths` when empty.

`collect_paths()` accepts a JSON array, a real array, or a delimited string (`;`, `,`, `|`), trims, drops empties, and de-dupes. Paths may be absolute `http(s)://` URLs, which are passed through untouched; otherwise each segment is `rawurlencode`d and appended to `source + pattern`. `path_url()` substitutes `{path}`, `{size}`, `{width}` and `{height}`, where the dimensions come from `wc_get_image_size()` (falling back to `width == height`).

## Hooks (IS_Frontend::register)
Bails early when `is_admin() && !wp_doing_ajax()`, when WooCommerce is absent, or when disabled. Otherwise:
`woocommerce_single_product_image_thumbnail_html`, `woocommerce_product_get_image`, `woocommerce_cart_item_thumbnail`, `woocommerce_order_item_thumbnail` — all at priority 10.

`rewrite_html()` replaces `src`, `data-thumb`, `href` and rewrites `srcset` to a single `1x` entry, using a regex callback so the original quote style is preserved. If the meta is missing, the index does not exist, or the HTML has no `<img`, the original HTML is returned untouched — preserve that fallback in any change.

Gallery index comes from `array_search($attachment_id, get_gallery_image_ids())` and is 1-based (index 0 is the main image); an unknown id falls back to index 0.

## Test stack
`jwilder/nginx-proxy` terminates TLS and routes by `VIRTUAL_HOST`; WordPress and phpMyAdmin publish no container port of their own (phpMyAdmin also has a `127.0.0.1:8081` plain-http fallback for tunnels). Ports `80`/`443` are published by the proxy — override with `IMAGESERVER_HTTP_PORT` / `IMAGESERVER_HTTPS_PORT` if Tibellus already owns them, and note nginx-proxy's http→https redirect always targets `443`, so shift both together. The repo root is bind-mounted read-only into the plugin dir, so edits on the host are live in the container with no rebuild.

**Do not set `VIRTUAL_PROTO` here.** It tells nginx-proxy the *upstream container* speaks TLS, so it emits `proxy_pass https://…:80` and every request 502s with `SSL_do_handshake() failed … wrong version number`. The proxy serves 443 for any vhost that has a matching cert, so TLS termination needs nothing on the service — leave `VIRTUAL_PROTO` unset and the upstream plain http, exactly as Tibellus does for phoenix.

| Service | Container | Reach |
|---------|-----------|-------|
| nginx-proxy | imageserver_nginx_proxy | 80, 443 |
| wordpress | imageserver_wordpress | https://www.app.local |
| db | imageserver_db | internal only |
| phpmyadmin | imageserver_phpmyadmin | https://phpmyadmin.app.local, http://127.0.0.1:8081 |
| wpcli | imageserver_wpcli | `profiles: [setup]`, never starts with the stack |

`ask.sh` is the entry point: task 1 runs the stack in the foreground (logs stream, Ctrl+C stops), 2 starts it detached, 3 is status, 4/5 stop and restart, 6/7 shell into WordPress or MariaDB, 8 exports the seed to `dockers/init`, 9 exports a local dump to `dockers/init/imageserver.sql.gz` (overwrites the seed), 10 imports a dump, 11 installs WordPress + WooCommerce + activates the plugin, 12/13 activate and deactivate the plugin, 14 lists all plugins, 15 is a wp-cli passthrough, 16/17 remove containers with or without volumes. WordPress sees https correctly through the proxy because core's `wp_fix_server_vars()` honours `X-Forwarded-Proto`.

DB credentials come from `WORDPRESS_DB_*` / `MARIADB_ROOT_PASSWORD` env vars, all defaulting to `imageserver` / `imageserver-root` — test-only values, never reuse them.

Certificates: `ask.sh` copies the shared `*.app.local` cert (issuer `CN=minica root ca 5f23cb`, SHA-256 `2C:7C:DC:EC:88:…:3F:10`, valid to 2125) into `dockers/certs/_.app.local/`, searching `IMAGESERVER_CERT_SRC` then the Tibellus and Exobank copies. Do **not** generate a self-signed cert here — the minica root is in the browser's trust store, a self-signed leaf is not, and `curl` fails with `(60)`. jwilder reads certs at startup, so restart the proxy after any cert change. Note the same private key is committed in `tibellus` and `exobank`; it is gitignored here, which is the safer default but inconsistent with them.

## Conventions
- Escape on output (`esc_url`, `esc_attr`, `esc_html`, `esc_url_raw`) and sanitize on input; never read `$_POST` directly.
- Resolve products defensively — `global $product`, `wc_get_product()`, and the filter's own `$product`/`$product_id` argument are all different shapes across these hooks, which is why `current_product()` exists.
- Keep the "no meta → return original HTML" behaviour.
- No comments in code unless asked.
- Do not run git-modifying commands or commit; the user commits.
- Do not commit credentials or real customer/image data.
- `.gitignore` is the WordPress site template plus `/dumps/` and `/dockers/certs/`. Never commit `wp-config.php`, `wp-content/uploads/`, or the TLS keys.

## Verify
- `php -l plugins/imageserver/imageserver.php plugins/imageserver/src/Class/*.php` — note the agent container has no PHP, so ask the user to run it if unavailable.
- `bash -n ask.sh && shellcheck ask.sh`.
- `docker compose -f dockers/docker-compose.yml config` to validate the stack (the agent container has no compose v2 plugin, so the user runs it).
- Manual: activate the plugin, then on Settings → Image Server press **Fetch patterns from server** and confirm it reports success against `https://img.salamander-jewelry.net/api/patterns`; then check a product page, a category listing, cart, and a WooCommerce email for rewritten URLs.
