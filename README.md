<img src="imageserver.svg" alt="imageserver" width="120">
# Image Server

WordPress plugin to use an image server instead of WooCommerce media-library images for product output.


## Dashboard Login:
login: https://www.app.local
user: imageserver
pass: PassPassPass666

## Behavior

The plugin reads the syncer-provided product meta `picture_paths` and variation meta `picture_path`. It rewrites the front-end product, variation, gallery, catalog, cart, and email image HTML without uploading or proxying image files.

If the meta value is missing, the original WooCommerce image output is preserved.

## Settings

Open **Settings → Image Server** to configure:

- Image server source, such as `https://img.example.com`
- Original image pattern, such as `/img/{path}`
- Resized image pattern, such as `/canvas/{size}/{path}`

The `{path}` placeholder is replaced with the product image path. The `{size}` placeholder is replaced with the WooCommerce image size.

## Test stack

The test stack is nginx-proxy, WordPress, MariaDB, phpMyAdmin, and an optional WP-CLI helper. Browser traffic goes through the TLS-terminating reverse proxy; only the proxy is exposed on the host.

```bash
./ask.sh      # 1 foreground, 2 background, 3 status, 11 install WordPress + WooCommerce + plugin
```

`ask.sh` tasks 1 and 2 add `www.app.local` and `phpmyadmin.app.local` to `/etc/hosts` and install the shared `*.app.local` certificate into `dockers/certs/` (gitignored). That certificate is the one Tibellus and exobank use — issued by `minica root ca`, which your browser already trusts, so there is no certificate warning. If no trusted source is found, set `IMAGESERVER_CERT_SRC` to a directory holding it, otherwise a self-signed certificate is generated and the browser will warn.

| Service | URL |
|---------|-----|
| WordPress | https://www.app.local |
| phpMyAdmin | https://phpmyadmin.app.local (root / `imageserver-root`) |
| phpMyAdmin (plain http fallback) | http://127.0.0.1:8081 |

The proxy binds host ports `80`/`443` by default. Override with `IMAGESERVER_HTTP_PORT` / `IMAGESERVER_HTTPS_PORT` if another stack already owns them — but note nginx-proxy's http-to-https redirect always targets port `443`, so shift both together and browse via the port directly.

## Plugins

WooCommerce and Plugin Check are vendored in `plugins/` and bind-mounted read-only into both the `wordpress` and `wpcli` services, so they are version-controlled with this repo and editable on the host. `imageserver/` is mounted the same way.

| Plugin | Version | Source |
|--------|---------|--------|
| WooCommerce | 11.1.2 | `plugins/woocommerce` |
| Plugin Check | 2.1.0 | `plugins/plugin-check` |

Because the mounts are writable, WordPress can update these in place. A wp.org update writes straight into `plugins/` and shows up as local modifications in `git status` — commit or discard them deliberately. Task 11 activates the vendored copies instead of downloading them.

To drive the stack by hand instead:

```bash
docker compose -f dockers/docker-compose.yml up -d nginx-proxy wordpress db phpmyadmin
docker compose -f dockers/docker-compose.yml run --rm wpcli core install \
  --url=https://www.app.local \
  --title='Image Server Test' \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=admin@example.com \
  --skip-email
docker compose -f dockers/docker-compose.yml run --rm wpcli plugin install woocommerce --activate
docker compose -f dockers/docker-compose.yml run --rm wpcli plugin activate imageserver
docker compose -f dockers/docker-compose.yml down
```


