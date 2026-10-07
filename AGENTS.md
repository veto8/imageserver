# AGENTS.md — imageserver

## What this is
WordPress plugin (PHP 8.2, WordPress 6.7+) that rewrites WooCommerce product image HTML to point at an external image server. It does not upload, proxy, or copy files — it only rewrites URLs in the markup WooCommerce already produced. Namespace `Salamander\Imageserver`, classes `IS_Admin` and `IS_Frontend`.

## Layout
```
ask.sh                                    dev stack menu (TLS setup, start/stop, DB, wp-cli)
imageserver/imageserver.php              plugin header, constants, autoloader, bootstrap
imageserver/src/Class/IS_Admin.php       settings page, option + sanitization, defaults
imageserver/src/Class/IS_Frontend.php    WooCommerce filters, meta reading, URL building
plugins/woocommerce/                     vendored WooCommerce 11.1.2, bind-mounted read-only
plugins/plugin-check/                    vendored Plugin Check 2.1.0, bind-mounted read-only
dockers/docker-compose.yml                nginx-proxy + WordPress + MariaDB + phpmyadmin + wpcli
dockers/nginx/wordpress.conf              per-vhost snippet for nginx-proxy (64m body cap)
dockers/certs/_.app.local/                shared *.app.local cert, copied from tibellus, gitignored
dumps/                                     local DB backups (gitignored), written by ask.sh task 9
```

`plugins/` holds third-party plugins that are tracked in git on purpose, so the versions are pinned and the code can be edited host-side. All three are bind-mounted **read-write** into **both** the `wordpress` and `wpcli` services — the wpcli mount must match or `wp plugin list` / `activate` would act on a different copy than the site serves. Host files are `1000:33` and group-writable so `www-data` (uid 33) can write; WordPress can therefore update the plugins in place, which will leave local modifications in `git status`. `ask.sh` task 11 activates the vendored copies rather than installing from wordpress.org, and only falls back to a download if `plugins/<slug>` is missing.

**Plugin mount is broken.** The plugin dir `imageserver/` is one level below the repo root, and the compose file mounts `../` (the repo root) as `wp-content/plugins/imageserver`. WordPress only detects a plugin whose main file sits at the root of its plugin dir, so it finds no plugin and `wp plugin activate imageserver` fails. Either point the mounts at `../imageserver` (one line each in the `wordpress` and `wpcli` services) or move the plugin to the repo root — the latter keeps the repo zip-installable as a plugin, the former keeps the files put. Ask before choosing.

## Autoloading — KNOWN BUG, plugin does not load
`imageserver.php` maps `Salamander\Imageserver\X` → `IMAGESERVER_PLUGIN_DIR . 'src/' . 'X' . '.php'`, but the classes live in `src/Class/`. There is no `composer.json`, so nothing else provides the mapping. `is_readable()` therefore fails, neither class is ever loaded, and `imageserver_init()` fatals with "Class not found" on every request — including activation, since `register_activation_hook` references `IS_Admin::activate`. The fix is to add the missing `Class/` segment in the autoloader's `$file` (or move the classes to `src/`). Verify the plugin loads at all before debugging anything else.

## Settings
Single option `imageserver_settings` (array), page at Settings → Image Server, capability `manage_options`, registered on `admin_init` with a `sanitize_callback`.
- `enabled` — checkbox, default `1`; when off, no filters are registered at all.
- `source` — base URL, default `https://img.salamander-jewelry.net`, run through `esc_url_raw` + `untrailingslashit`.
- `original_pattern` — default `/img/{path}`; used when no size is passed.
- `resize_pattern` — default `/canvas/{size}/{path}`.
- `sanitize_pattern()` appends `{path}` if missing and force-prefixes a leading `/`, so a pattern can never escape the source host.

## Data source
Meta written by the syncer, not by this plugin:
- product: `picture_paths` (plural)
- variation: `picture_path` (singular), falling back to the parent's `picture_paths` when empty.

`collect_paths()` accepts a JSON array, a real array, or a delimited string (`;`, `,`, `|`), trims, drops empties, and de-dupes. Paths may be absolute `http(s)://` URLs, which are passed through untouched; otherwise each segment is `rawurlencode`d and appended to `source + pattern`. `{size}` is `rawurlencode`d.

## Hooks (IS_Frontend::register)
Bails early when `is_admin() && !wp_doing_ajax()`, when WooCommerce is absent, or when disabled. Otherwise:
`woocommerce_single_product_image_thumbnail_html`, `woocommerce_gallery_thumbnail_html`, `woocommerce_catalog_product_thumbnail`, `woocommerce_cart_item_thumbnail`, `woocommerce_email_order_item_thumbnail`, `woocommerce_variation_image_html` — all at priority 10.

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

`ask.sh` is the entry point: task 1 runs the stack in the foreground (logs stream, Ctrl+C stops), 2 starts it detached, 3 is status, 4/5 stop and restart, 6/7 shell into WordPress or MariaDB, 8 exports the seed to `dockers/init`, 9 exports a timestamped local backup to `dumps/`, 10 imports a dump, 11 installs WordPress + WooCommerce + activates the plugin, 12/13 activate and deactivate the plugin, 14 lists all plugins, 15 is a wp-cli passthrough, 16/17 remove containers with or without volumes. WordPress sees https correctly through the proxy because core's `wp_fix_server_vars()` honours `X-Forwarded-Proto`.

DB credentials come from `WORDPRESS_DB_*` / `MARIADB_ROOT_PASSWORD` env vars, all defaulting to `imageserver` / `imageserver-root` — test-only values, never reuse them.

Certificates: `ask.sh` copies the shared `*.app.local` cert (issuer `CN=minica root ca 5f23cb`, SHA-256 `2C:7C:DC:EC:88:…:3F:10`, valid to 2125) into `dockers/certs/_.app.local/`, searching `IMAGESERVER_CERT_SRC` then the Tibellus and Exobank copies. Do **not** generate a self-signed cert here — the minica root is in the browser's trust store, a self-signed leaf is not, and `curl` fails with `(60)`. jwilder reads certs at startup, so restart the proxy after any cert change. Note the same private key is committed in `tibellus` and `exobank`; it is gitignored here, which is the safer default but inconsistent with them.

## Conventions
- Escape on output (`esc_url`, `esc_attr`, `esc_html`, `esc_url_raw`) and sanitize on input; never read `$_POST` directly.
- Resolve products defensively — `global $product`, `wc_get_product()`, and the filter's own `$product`/`$product_id` argument are all different shapes across these hooks, which is why `resolve_product()` exists.
- Keep the "no meta → return original HTML" behaviour.
- No comments in code unless asked.
- Do not run git-modifying commands or commit; the user commits.
- Do not commit credentials or real customer/image data.
- `.gitignore` is the WordPress site template plus `/dumps/` and `/dockers/certs/`. Never commit `wp-config.php`, `wp-content/uploads/`, or the TLS keys.

## Verify
- `php -l imageserver/imageserver.php imageserver/src/Class/*.php` — note the agent container has no PHP, so ask the user to run it if unavailable.
- `bash -n ask.sh && shellcheck ask.sh`.
- `docker compose -f dockers/docker-compose.yml config` to validate the stack (the agent container has no compose v2 plugin, so the user runs it).
- Manual: activate the plugin (this is where the autoloader bug surfaces), then check a product page, a category listing, cart, and a WooCommerce email for rewritten URLs.
