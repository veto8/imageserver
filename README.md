# Image Server

WordPress plugin to use an image server instead of WooCommerce media-library images for product output.

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

The test stack contains one Nginx proxy, WordPress, MariaDB, and an optional WP-CLI helper. Only Nginx is exposed to the host.

```bash
docker compose -f test/docker-compose.yml up -d proxy wordpress db
```

After WordPress is available at `http://127.0.0.1:8080`, initialize it and install WooCommerce:

```bash
docker compose -f test/docker-compose.yml run --rm wpcli core install \
  --url=http://127.0.0.1:8080 \
  --title='Image Server Test' \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=admin@example.com \
  --skip-email
docker compose -f test/docker-compose.yml run --rm wpcli plugin install woocommerce --activate
docker compose -f test/docker-compose.yml run --rm wpcli plugin activate imageserver
```

Stop the stack with:

```bash
docker compose -f test/docker-compose.yml down
```
