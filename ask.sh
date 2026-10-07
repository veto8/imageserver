#!/bin/bash
# imageserver - dev stack menu: nginx proxy + WordPress + MariaDB + wp-cli

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CFG="$DIR/dockers/docker-compose.yml"

PREFIX="imageserver"
SVC_PROXY="nginx-proxy"
SVC_APP="wordpress"
SVC_DB="db"
SVC_WPCLI="wpcli"
SVC_PMA="phpmyadmin"
APP="${PREFIX}_${SVC_APP}"
DB="${PREFIX}_${SVC_DB}"
C_PROXY="${PREFIX}_nginx_proxy"
C_APP="${PREFIX}_wordpress"
C_DB="${PREFIX}_db"
C_PMA="${PREFIX}_phpmyadmin"

HTTP_PORT="${IMAGESERVER_HTTP_PORT:-80}"
HTTPS_PORT="${IMAGESERVER_HTTPS_PORT:-443}"
PMA_PORT="${IMAGESERVER_PMA_PORT:-8081}"
HOST="www.app.local"
PMA_HOSTNAME="phpmyadmin.app.local"
URL="https://${HOST}"
PMA_URL="https://${PMA_HOSTNAME}"
PMA_PLAIN_URL="http://127.0.0.1:${PMA_PORT}"
CERT_DIR="$DIR/dockers/certs/_.app.local"
PLUGINS_DIR="$DIR/plugins"
PLUGIN_DIR="$PLUGINS_DIR/imageserver"
PLUGIN_REL="plugins/imageserver"
MOUNT_REL="$(grep -o '\.\./[^:]*:/var/www/html/wp-content/plugins/imageserver' "$CFG" | head -1 | cut -d: -f1 | sed 's|^\.\./||')"
MOUNT_REL="${MOUNT_REL:-imageserver}"
MOUNT_SRC="$DIR/$MOUNT_REL"
DB_NAME="${WORDPRESS_DB_NAME:-imageserver}"
DB_USER="${WORDPRESS_DB_USER:-imageserver}"
DB_PASS="${WORDPRESS_DB_PASSWORD:-imageserver}"
DB_ROOT_PASS="${MARIADB_ROOT_PASSWORD:-imageserver-root}"

INIT_DIR="$DIR/dockers/init"
DUMP_DIR="$DIR/dumps"
WP_TITLE="Image Server Test"
WP_USER="admin"
WP_PASS="admin"
WP_EMAIL="admin@example.com"

[ -f "$CFG" ] || { echo "Missing compose file: $CFG"; exit 1; }

dc() {
  docker compose -f "$CFG" "$@"
}

confirm() {
  local reply
  read -rp "$1 [y/N] " reply
  case "$reply" in
    [Yy]*) return 0 ;;
    *) echo "Cancelled."; return 1 ;;
  esac
}

stack_up() {
  docker ps --filter "name=${PREFIX}_" --filter "status=running" -q 2>/dev/null | grep -q .
}

install_trusted_cert() {
  local src
  for src in "${IMAGESERVER_CERT_SRC:-}" \
    /home/veto/webs/gitlab/tibellus/dockers/certs/_.app.local \
    /home/veto/webs/gitlab/exobank/dockers/certs/_.app.local; do
    [ -n "$src" ] || continue
    [ -f "$src/${HOST}.crt" ] || continue
    mkdir -p "$CERT_DIR" || return 1
    cp "$src/${HOST}.crt" "$CERT_DIR/${HOST}.crt" || return 1
    cp "$src/${HOST}.key" "$CERT_DIR/${HOST}.key" || return 1
    cp "$src/${HOST}.crt" "$CERT_DIR/${PMA_HOSTNAME}.crt" || return 1
    cp "$src/${HOST}.key" "$CERT_DIR/${PMA_HOSTNAME}.key" || return 1
    chmod 644 "$CERT_DIR"/*.crt
    chmod 600 "$CERT_DIR"/*.key
    echo "  installed the shared *.app.local cert from $src"
    echo "  it is issued by 'minica root ca', which your browser already trusts"
    return 0
  done
  return 1
}

setup_tls() {
  local names="127.0.0.1 ${HOST} ${PMA_HOSTNAME}"
  if grep -q "${HOST}" /etc/hosts 2>/dev/null && grep -q "${PMA_HOSTNAME}" /etc/hosts 2>/dev/null; then
    echo "  /etc/hosts already resolves ${HOST} and ${PMA_HOSTNAME}"
  elif printf '%s\n' "$names" | sudo tee -a /etc/hosts >/dev/null 2>&1; then
    echo "  appended '${names}' to /etc/hosts"
  elif printf '%s\n' "$names" >>/etc/hosts 2>/dev/null; then
    echo "  appended '${names}' to /etc/hosts"
  else
    echo "  WARNING: cannot edit /etc/hosts - add this line yourself:"
    echo "           ${names}"
  fi

  if [ -f "$CERT_DIR/${HOST}.crt" ] && [ -f "$CERT_DIR/${PMA_HOSTNAME}.crt" ]; then
    echo "  cert already present in dockers/certs/_.app.local"
    echo "  issuer: $(openssl x509 -in "$CERT_DIR/${HOST}.crt" -noout -issuer 2>/dev/null | cut -d= -f2-)"
    return 0
  fi
  install_trusted_cert && return 0
  mkdir -p "$CERT_DIR" || return 1
  echo "  no trusted cert source found - generating a self-signed one"
  echo "  (browsers and curl will reject it until you trust it or set IMAGESERVER_CERT_SRC)"
  echo "  generating self-signed wildcard cert for *.app.local ..."
  if ! openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
    -subj "/CN=*.app.local" \
    -addext "subjectAltName=DNS:*.app.local" \
    -keyout "$CERT_DIR/app.local.key" -out "$CERT_DIR/app.local.crt" 2>/dev/null; then
    echo "  WARNING: openssl failed (needs 1.1.1+ for -addext) - generate the cert yourself"
    return 1
  fi
  local host
  for host in "$HOST" "$PMA_HOSTNAME"; do
    cp "$CERT_DIR/app.local.crt" "$CERT_DIR/${host}.crt"
    cp "$CERT_DIR/app.local.key" "$CERT_DIR/${host}.key"
  done
  chmod 644 "$CERT_DIR"/*.crt
  chmod 600 "$CERT_DIR"/*.key
  echo "  cert written to dockers/certs/_.app.local (gitignored)"
  echo "  your browser will warn once - accept the certificate for both names"
}

start_foreground() {
  echo "Configuring TLS hosts and cert ..."
  setup_tls
  echo ""
  echo "Starting in the foreground - logs stream here, Ctrl+C stops the stack ..."
  echo ""
  dc up
  echo ""
  echo "Stack stopped. Restart it with task 2 when you are ready."
}

start_background() {
  echo "Configuring TLS hosts and cert ..."
  setup_tls
  echo ""
  echo "Starting nginx-proxy, WordPress, MariaDB and phpMyAdmin in the background ..."
  dc up -d "$SVC_PROXY" "$SVC_APP" "$SVC_DB" "$SVC_PMA"
  echo ""
  echo "WordPress:  $URL"
  echo "phpMyAdmin: $PMA_URL (root / $DB_ROOT_PASS)"
  echo "            plain http fallback: $PMA_PLAIN_URL"
  echo "If WordPress is not installed yet, run task 10."
}

status_stack() {
  local table line cname state status svc
  local running=0 unhealthy=0 notcreated=0 stopped=0 details=""
  local site_state site_line code rc hint

  table="$(docker ps -a --filter "name=${PREFIX}_" --format '{{.Names}}|{{.State}}|{{.Status}}' 2>/dev/null)"

  for svc in "$SVC_PROXY" "$SVC_APP" "$SVC_DB" "$SVC_PMA"; do
    case "$svc" in
      "$SVC_PROXY") cname="$C_PROXY" ;;
      "$SVC_APP") cname="$C_APP" ;;
      "$SVC_DB") cname="$C_DB" ;;
      "$SVC_PMA") cname="$C_PMA" ;;
    esac
    line="$(printf '%s\n' "$table" | grep "^${cname}|" | head -1)"
    if [ -z "$line" ]; then
      notcreated=$((notcreated + 1))
      details="${details}  FAIL ${cname} not created - run task 2"$'\n'
      continue
    fi
    state="${line#*|}"
    state="${state%%|*}"
    status="${line#*|}"
    status="${status#*|}"
    if [ "$state" = running ]; then
      running=$((running + 1))
      case "$status" in
        *unhealthy*|*starting*)
          unhealthy=$((unhealthy + 1))
          details="${details}  warn ${cname} ${status}"$'\n'
          ;;
        *)
          details="${details}  ok   ${cname} ${status}"$'\n'
          ;;
      esac
    else
      stopped=$((stopped + 1))
      details="${details}  FAIL ${cname} ${status}"$'\n'
    fi
  done

  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$URL" 2>/dev/null)"
  rc=$?
  hint=""
  case "$rc" in
    0) ;;
    6) hint=" - ${HOST} is not in /etc/hosts, run task 1" ;;
    7) hint=" - nothing is listening on ${HTTPS_PORT}" ;;
    28) hint=" - no answer within 5s" ;;
    60) hint=" - the certificate is not trusted, see the TLS section" ;;
    127) hint=" - curl is not installed" ;;
  esac
  if [ "$rc" -ne 0 ]; then
    site_state=warn
    site_line="warn ${URL} not checked${hint}"
  else
    case "$code" in
      2*|3*) site_state=ok; site_line="ok   ${URL} returned ${code}" ;;
      *) site_state=fail; site_line="FAIL ${URL} returned ${code}" ;;
    esac
  fi

  echo "== Stack =="
  if [ "$notcreated" -gt 0 ] || [ "$stopped" -gt 0 ]; then
    echo "  FAIL not up - $running/4 running, $stopped stopped, $notcreated never created"
    echo "       start it with task 2 (background) or task 1 (foreground)"
  elif [ "$unhealthy" -gt 0 ]; then
    echo "  warn up - $running/4 running, $unhealthy not ready yet or failing its healthcheck"
  elif [ "$site_state" = fail ]; then
    echo "  warn containers are up but the site does not answer"
  elif [ "$site_state" = warn ]; then
    echo "  warn containers are up but ${URL} could not be checked"
  else
    echo "  ok   up - $running/4 running and the site answers"
  fi

  echo ""
  echo "== Services =="
  printf '%s' "$details"
  printf '  %s\n' "$site_line"

  echo ""
  echo "== Host ports =="
  local port holders
  for port in "$HTTP_PORT" "$HTTPS_PORT" "$PMA_PORT"; do
    holders="$(docker ps --filter "publish=$port" --format '{{.Names}}' 2>/dev/null | tr '\n' ' ')"
    if [ -z "$holders" ]; then
      printf '  %-4s :%-5s free - no container publishes it\n' "warn" "$port"
    elif printf '%s' "$holders" | grep -q "$C_PROXY"; then
      printf '  %-4s :%-5s %s\n' "ok" "$port" "$holders"
    else
      printf '  %-4s :%-5s %s - held by another stack\n' "warn" "$port" "$holders"
    fi
  done
  echo "  pma:  $PMA_URL (root / $DB_ROOT_PASS)"
  echo "        plain http: $PMA_PLAIN_URL"

  echo ""
  echo "== TLS =="
  if [ -f "$CERT_DIR/${HOST}.crt" ] && [ -f "$CERT_DIR/${PMA_HOSTNAME}.crt" ]; then
    echo "  ok   expires $(openssl x509 -in "$CERT_DIR/${HOST}.crt" -noout -enddate 2>/dev/null | cut -d= -f2-)"
  else
    echo "  FAIL no cert yet - run task 1 to generate one"
  fi
  if grep -q "${PMA_HOSTNAME}" /etc/hosts 2>/dev/null; then
    echo "  ok   hosts ok - $(grep "${PMA_HOSTNAME}" /etc/hosts | head -1)"
  else
    echo "  FAIL ${PMA_HOSTNAME} missing from /etc/hosts - run task 1"
  fi

  echo ""
  echo "== Plugin =="
  if [ -f "$PLUGIN_DIR/imageserver.php" ]; then
    echo "  ok   source ${PLUGIN_REL}/imageserver.php"
  else
    echo "  FAIL no imageserver.php in ${PLUGIN_REL} - the plugin source is missing"
  fi
  if [ "$MOUNT_SRC" = "$PLUGIN_DIR" ]; then
    echo "  ok   compose mounts ${PLUGIN_REL} into wp-content/plugins/imageserver"
  else
    echo "  FAIL compose mounts ${MOUNT_REL} into wp-content/plugins/imageserver,"
    echo "       which is not ${PLUGIN_REL} - WordPress finds no plugin there"
  fi
}

stop_stack() {
  echo "Stopping (containers and volumes are kept) ..."
  dc stop
  echo "Stopped."
}

restart_stack() {
  dc stop
  dc up -d "$SVC_PROXY" "$SVC_APP" "$SVC_DB" "$SVC_PMA"
  echo "Restarted. WordPress: $URL  phpMyAdmin: $PMA_URL"
}

enter_app() {
  stack_up || { echo "Stack is not running - start it with task 1 (foreground) or 2 (background) first."; return 1; }
  echo "Entering $APP ('exit' leaves) ..."
  docker exec -it "$APP" bash
}

enter_db() {
  stack_up || { echo "Stack is not running - start it with task 1 (foreground) or 2 (background) first."; return 1; }
  echo "Entering $DB as root ('exit' leaves) ..."
  docker exec -it "$DB" mariadb -u root -p"$DB_ROOT_PASS"
}

db_root() {
  docker exec -i "$DB" mariadb -u root -p"$DB_ROOT_PASS" "$@"
}

export_db() {
  stack_up || { echo "Stack is not running - start it with task 1 (foreground) or 2 (background) first."; return 1; }
  mkdir -p "$INIT_DIR" || return 1
  local out
  out="$INIT_DIR/${DB_NAME}.sql.gz"
  echo "Dumping $DB_NAME ..."
  if docker exec "$DB" sh -c 'command -v mariadb-dump >/dev/null && echo yes' | grep -q yes; then
    docker exec "$DB" mariadb-dump -u"$DB_USER" -p"$DB_PASS" --single-transaction --databases "$DB_NAME" | gzip > "$out"
  else
    docker exec "$DB" mysqldump -u"$DB_USER" -p"$DB_PASS" --single-transaction --databases "$DB_NAME" | gzip > "$out"
  fi
  [ -s "$out" ] || { echo "Dump failed or empty: $out"; return 1; }
  echo "Wrote $out ($(du -h "$out" | cut -f1))"
  echo "  A fresh stack loads this on its own: compose mounts dockers/init as"
  echo "  /docker-entrypoint-initdb.d, so 'down -v' and then task 2 restore it."
  echo "  That only happens on an empty data volume - an existing db_data is left alone."
  echo "  Task 10 imports a dump from the same directory."
}

export_db_local() {
  stack_up || { echo "Stack is not running - start it with task 1 (foreground) or 2 (background) first."; return 1; }
  mkdir -p "$DUMP_DIR" || return 1
  local out
  out="$DUMP_DIR/${DB_NAME}-$(date +%Y%m%d-%H%M%S).sql.gz"
  echo "Dumping $DB_NAME ..."
  if docker exec "$DB" sh -c 'command -v mariadb-dump >/dev/null && echo yes' | grep -q yes; then
    docker exec "$DB" mariadb-dump -u"$DB_USER" -p"$DB_PASS" --single-transaction --databases "$DB_NAME" | gzip > "$out"
  else
    docker exec "$DB" mysqldump -u"$DB_USER" -p"$DB_PASS" --single-transaction --databases "$DB_NAME" | gzip > "$out"
  fi
  [ -s "$out" ] || { echo "Dump failed or empty: $out"; rm -f "$out"; return 1; }
  echo "Wrote $out ($(du -h "$out" | cut -f1))"
  echo "  dumps/ is gitignored - a local backup, not the seed."
}

list_dumps() {
  find "$INIT_DIR" -maxdepth 1 -type f -name '*.sql.gz' -printf '%T@ %p\n' 2>/dev/null | sort -rn | cut -d' ' -f2-
}

import_db() {
  local file dumps
  dumps="$(list_dumps)"
  if [ -z "$dumps" ]; then
    echo "No dumps in $INIT_DIR - export one with task 8 first."
    return 1
  fi
  echo "Available dumps (newest first):"
  while read -r file; do
    printf '  %s  %s\n' "$(du -h "$file" | cut -f1)" "$(basename "$file")"
  done <<< "$dumps"
  read -rp "Dump to import (name): " file
  file="$INIT_DIR/$(basename "$file")"
  [ -f "$file" ] || { echo "No such dump: $file"; return 1; }
  echo "This DROPS and recreates '$DB_NAME' on $DB, wiping all current data."
  confirm "Continue?" || return 1
  echo "Stopping $SVC_APP ..."
  dc stop "$SVC_APP"
  echo "Recreating $DB_NAME ..."
  db_root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || return 1
  echo "Restoring $(basename "$file") ..."
  if gunzip -c "$file" | docker exec -i "$DB" mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null; then
    :
  elif gunzip -c "$file" | docker exec -i "$DB" mariadb -u"$DB_USER" -p"$DB_PASS" 2>/dev/null; then
    echo "  (the dump selected its own database)"
  else
    echo "Restore failed - the database is currently empty."
    dc up -d "$SVC_APP"
    return 1
  fi
  dc up -d "$SVC_APP"
  echo "Imported. WordPress: $URL"
}

wpcli() {
  stack_up || { echo "Stack is not running - start it with task 1 (foreground) or 2 (background) first."; return 1; }
  dc run --rm "$SVC_WPCLI" wp "$@"
}

ensure_plugin() {
  local slug="$1" label="$2"
  if [ -d "$PLUGINS_DIR/$slug" ]; then
    echo "  $label is vendored in plugins/$slug (bind-mounted) - activating"
  elif dc run --rm "$SVC_WPCLI" plugin is-installed "$slug" >/dev/null 2>&1; then
    echo "  $label already installed in the volume - activating"
  else
    echo "  $label not vendored - installing from wordpress.org"
    dc run --rm "$SVC_WPCLI" plugin install "$slug" || return 1
  fi
  dc run --rm "$SVC_WPCLI" plugin activate "$slug"
}

setup_site() {
  stack_up || { echo "Stack is not running - start it with task 1 (foreground) or 2 (background) first."; return 1; }
  echo "== WordPress core =="
  if dc run --rm "$SVC_WPCLI" core is-installed >/dev/null 2>&1; then
    echo "  already installed - skipping"
  else
    dc run --rm "$SVC_WPCLI" core install \
      --url="$URL" \
      --title="$WP_TITLE" \
      --admin_user="$WP_USER" \
      --admin_password="$WP_PASS" \
      --admin_email="$WP_EMAIL" \
      --skip-email || return 1
  fi
  echo "== WooCommerce =="
  ensure_plugin woocommerce "WooCommerce" || return 1
  echo "== Plugin Check =="
  ensure_plugin plugin-check "Plugin Check" || return 1
  echo "== Image Server plugin =="
  dc run --rm "$SVC_WPCLI" plugin activate imageserver || return 1
  echo ""
  echo "Done. $URL  (login $WP_USER / $WP_PASS)"
  echo "Settings live under Settings -> Image Server."
}

activate_plugin() {
  stack_up || { echo "Stack is not running - start it with task 1 (foreground) or 2 (background) first."; return 1; }
  if ! wpcli plugin is-installed imageserver >/dev/null 2>&1; then
    echo "Plugin files are not visible to WordPress."
    echo "Check that dockers/docker-compose.yml mounts ../imageserver into"
    echo "wp-content/plugins/imageserver, then recreate the stack (task 5)."
    return 1
  fi
  wpcli plugin activate imageserver
}

deactivate_plugin() {
  stack_up || { echo "Stack is not running - start it with task 1 (foreground) or 2 (background) first."; return 1; }
  wpcli plugin deactivate imageserver
}

list_plugins() {
  stack_up || { echo "Stack is not running - start it with task 1 (foreground) or 2 (background) first."; return 1; }
  wpcli plugin list
}

remove_containers() {
  echo "Removing containers and networks, keeping volumes ..."
  dc down
  echo "Done. Data volumes (${PREFIX}_wordpress_data, ${PREFIX}_db_data) are intact."
}

remove_all() {
  echo "This removes the containers AND the database volumes - all local data is lost."
  confirm "Really remove containers and volumes?" || return 1
  dc down -v
  echo "Done. WordPress must be reinstalled with task 10."
}

while true; do
  echo ""
  echo "imageserver - $URL   (phpMyAdmin $PMA_URL)"
  echo "  1  Run (foreground) - start the stack and stream logs, Ctrl+C stops it"
  echo "  2  Run (background) - start the stack detached"
  echo "  3  Status - up/ok verdict, services, site, ports, TLS, plugin"
  echo "  4  Stop - stop the stack (containers stay)"
  echo "  5  Restart - restart the stack"
  echo "  6  Enter WordPress container"
  echo "  7  Enter DB (mariadb, root)"
  echo "  8  Export DB (seed) - dump to dockers/init (a fresh stack loads it)"
  echo "  9  Export DB (local) - timestamped dump to dumps/"
  echo " 10  Import DB - drop + reload DB from a dump in dockers/init"
  echo " 11  Setup site - install WordPress, WooCommerce, activate plugin"
  echo " 12  Activate plugin - imageserver"
  echo " 13  Deactivate plugin - imageserver"
  echo " 14  List plugins - name, status, version"
  echo " 15  wp-cli - run a wp command, e.g. 15 option get imageserver_settings"
  echo " 16  Remove containers (keeps volumes)"
  echo " 17  Remove containers AND volumes (destructive - wipes data)"
  echo "  0  Exit"
  if ! read -rp "Task: " task; then
    break
  fi
  case "$task" in
    1) start_foreground ;;
    2) start_background ;;
    3) status_stack ;;
    4) stop_stack ;;
    5) restart_stack ;;
    6) enter_app ;;
    7) enter_db ;;
    8) export_db ;;
    9) export_db_local ;;
    10) import_db ;;
    11) setup_site ;;
    12) activate_plugin ;;
    13) deactivate_plugin ;;
    14) list_plugins ;;
    15) read -rp "wp arguments: " -a wp_args; wpcli "${wp_args[@]}" ;;
    16) remove_containers ;;
    17) remove_all ;;
    0) break ;;
    *) echo "Unknown task" ;;
  esac
done
exit 0
