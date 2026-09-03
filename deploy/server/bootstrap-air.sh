#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/srv/apps/air"
REPO_URL="https://github.com/abdumuhammad54744531-create/Air.git"
DOMAIN="air-buton.oisara.my.id"
DB_NAME="monitoring_air"
DB_USER="air_app"
DONE_MARKER="/var/lib/oisara/air-bootstrap.done"
FIRST_INSTALL=0

if [[ ${EUID:-999} -ne 0 ]]; then
    echo "Bootstrap Air harus dijalankan sebagai root." >&2
    exit 1
fi
install -d -m 0755 -o abdu -g www-data /srv/apps
install -d -m 0750 -o root -g root /srv/backups/air /var/lib/oisara

if [[ ! -d "$APP_DIR/.git" ]]; then
    if [[ -e "$APP_DIR" ]]; then
        echo "Target $APP_DIR sudah ada tetapi bukan repository Git." >&2
        exit 1
    fi
    sudo -u abdu git clone --branch main --single-branch "$REPO_URL" "$APP_DIR"
fi
sudo -u abdu git -C "$APP_DIR" config core.fileMode false

if [[ ! -f "$APP_DIR/.env" ]]; then
    FIRST_INSTALL=1
    DB_PASS="$(openssl rand -hex 24)"
    ADMIN_PASS="$(openssl rand -hex 12)"
    cat > "$APP_DIR/.env" <<ENV
APP_NAME="Sistem Informasi Monitoring dan Manajemen Air"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://${DOMAIN}
APP_TIMEZONE=Asia/Makassar
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}
PUBLIC_PAGE_ENABLED=true
DEVICE_OFFLINE_MINUTES=15
DASHBOARD_REFRESH_SECONDS=30
SESSION_TIMEOUT_MINUTES=120
APP_ALLOW_REINSTALL=false
EPANET_BIN=/usr/local/bin/runepanet
EPANET_LIBRARY_PATH=/usr/local/lib
ENV
else
    DB_PASS="$(sed -n 's/^DB_PASSWORD=//p' "$APP_DIR/.env" | tail -n1 | tr -d '"')"
    ADMIN_PASS=""
fi

mariadb -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}'; ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}'; GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1'; FLUSH PRIVILEGES;"

if ! mariadb -NBe "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='users'" | grep -q 1; then
    mariadb "$DB_NAME" < "$APP_DIR/database/schema.sql"
    mariadb "$DB_NAME" < "$APP_DIR/database/seed.sql"
    mariadb "$DB_NAME" < "$APP_DIR/database/water_simulation.sql"
    for migration in "$APP_DIR"/database/migrations/*.php; do php "$migration"; done
fi

if [[ "$FIRST_INSTALL" -eq 1 ]]; then
    ADMIN_HASH="$(ADMIN_PASS="$ADMIN_PASS" php -r 'echo password_hash((string)getenv("ADMIN_PASS"), PASSWORD_DEFAULT);')"
    mariadb "$DB_NAME" -e "UPDATE users SET password='${ADMIN_HASH}', email='abdumuhammad5474453105@gmail.com', must_change_password=1 WHERE username='admin';"
fi

install -d -m 2770 -o abdu -g www-data "$APP_DIR/storage" "$APP_DIR/storage/sessions" "$APP_DIR/storage/hydraulic" "$APP_DIR/storage/uploads" "$APP_DIR/storage/backups" "$APP_DIR/public/uploads"
touch "$APP_DIR/storage/installed.lock"
chown -R abdu:www-data "$APP_DIR"
find "$APP_DIR" -type d -not -path '*/.git/*' -exec chmod 0750 {} +
find "$APP_DIR" -type f -not -path '*/.git/*' -exec chmod 0640 {} +
chmod 0755 "$APP_DIR/public" "$APP_DIR/public/index.php"
for writable_dir in "$APP_DIR/storage" "$APP_DIR/public/uploads"; do
    find "$writable_dir" -type d -exec chmod 2770 {} +
    find "$writable_dir" -type f -exec chmod 0660 {} +
done
# PHP-FPM menolak membaca file sesi yang dibuat oleh UID lain. Pastikan sesi
# selalu dimiliki www-data setelah bootstrap mengatur kepemilikan aplikasi.
install -d -m 2770 -o www-data -g www-data "$APP_DIR/storage/sessions"
chown -R www-data:www-data "$APP_DIR/storage/sessions"
find "$APP_DIR/storage/sessions" -type d -exec chmod 2770 {} +
find "$APP_DIR/storage/sessions" -type f -exec chmod 0600 {} +
chmod 0640 "$APP_DIR/.env"

if [[ ! -x /usr/local/bin/runepanet || ! -f /usr/local/lib/libepanet2.so ]]; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends build-essential cmake ca-certificates git
    BUILD_ROOT="$(mktemp -d)"
    git clone --depth 1 --branch v2.3.5 https://github.com/OpenWaterAnalytics/EPANET.git "$BUILD_ROOT/epanet"
    cmake -S "$BUILD_ROOT/epanet" -B "$BUILD_ROOT/epanet/build" -DCMAKE_BUILD_TYPE=Release -DBUILD_TESTS=OFF
    cmake --build "$BUILD_ROOT/epanet/build" --parallel 2
    install -m 0755 "$BUILD_ROOT/epanet/build/bin/runepanet" /usr/local/bin/runepanet
    install -m 0644 "$BUILD_ROOT/epanet/build/lib/libepanet2.so" /usr/local/lib/libepanet2.so
    ldconfig
fi

rm -f /etc/nginx/sites-enabled/air.oisara.my.id /etc/nginx/sites-available/air.oisara.my.id
install -m 0644 "$APP_DIR/deploy/server/nginx-air.conf" /etc/nginx/sites-available/air-buton.oisara.my.id
ln -sfn /etc/nginx/sites-available/air-buton.oisara.my.id /etc/nginx/sites-enabled/air-buton.oisara.my.id
install -m 0750 "$APP_DIR/deploy/server/deploy-air.sh" /usr/local/sbin/deploy-air
cat > /etc/sudoers.d/oisara-panel-air <<'SUDOERS'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/deploy-air
SUDOERS
chmod 0440 /etc/sudoers.d/oisara-panel-air
visudo -cf /etc/sudoers.d/oisara-panel-air

PANEL_FILE="/srv/oisara-panel/public/index.php"
if [[ -f "$PANEL_FILE" && ! -f "$DONE_MARKER" ]]; then
    cp -a "$PANEL_FILE" "$PANEL_FILE.backup-air-$(date +%Y%m%d-%H%M%S)"
    php "$APP_DIR/deploy/server/patch-panel.php" "$PANEL_FILE"
    php -l "$PANEL_FILE"
fi

if grep -q 'hostname: air\.oisara\.my\.id' /etc/cloudflared/config.yml; then
    sed -i '/  - hostname: air\.oisara\.my\.id/{N;d;}' /etc/cloudflared/config.yml
fi
if ! grep -q 'hostname: air-buton\.oisara\.my\.id' /etc/cloudflared/config.yml; then
    cp -a /etc/cloudflared/config.yml "/etc/cloudflared/config.yml.backup-air-$(date +%Y%m%d-%H%M%S)"
    sed -i '/  - service: http_status:404/i\  - hostname: air-buton.oisara.my.id\n    service: http://localhost:80' /etc/cloudflared/config.yml
fi
nginx -t
systemctl reload nginx
systemctl restart cloudflared
# DNS oisara.my.id memakai wildcard yang sudah mengarah ke tunnel ini.
# Tidak menjalankan `route dns` karena origin certificate server terikat ke zona oisara.web.id.
curl -fsS -H "Host: $DOMAIN" http://127.0.0.1/login | grep -q 'Masuk'
date -Iseconds > "$DONE_MARKER"
echo "AIR_BOOTSTRAP_OK"
echo "URL=https://${DOMAIN}"
echo "ADMIN_USERNAME=admin"
if [[ "$FIRST_INSTALL" -eq 1 ]]; then echo "ADMIN_PASSWORD=${ADMIN_PASS}"; fi
