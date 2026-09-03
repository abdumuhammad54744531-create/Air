#!/usr/bin/env bash
set -euo pipefail
APP_DIR="/srv/apps/air"
BACKUP_DIR="/srv/backups/air"
exec 9>/run/lock/deploy-air.lock
flock -n 9 || { echo "Deploy Air lain masih berjalan." >&2; exit 1; }
test -d "$APP_DIR/.git" || { echo "Repository Air belum terpasang." >&2; exit 1; }
test -f "$APP_DIR/.env" || { echo "Kredensial .env Air tidak ditemukan." >&2; exit 1; }
sudo -u abdu git -C "$APP_DIR" config core.fileMode false
install -d -m 0750 -o root -g root "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
mariadb-dump --single-transaction --routines --triggers monitoring_air | gzip -9 > "$BACKUP_DIR/monitoring_air-$STAMP.sql.gz"
cp -a "$APP_DIR/.env" "$BACKUP_DIR/env-$STAMP"
sudo -u abdu git -C "$APP_DIR" fetch origin main
sudo -u abdu git -C "$APP_DIR" merge --ff-only origin/main
install -m 0750 "$APP_DIR/deploy/server/deploy-air.sh" /usr/local/sbin/deploy-air
install -m 0644 "$APP_DIR/deploy/server/nginx-air.conf" /etc/nginx/sites-available/air-buton.oisara.my.id
for migration in "$APP_DIR"/database/migrations/*.php; do php "$migration"; done
install -d -m 2770 -o abdu -g www-data "$APP_DIR/storage" "$APP_DIR/storage/sessions" "$APP_DIR/storage/hydraulic" "$APP_DIR/storage/uploads" "$APP_DIR/storage/backups" "$APP_DIR/public/uploads"
chown -R abdu:www-data "$APP_DIR"
find "$APP_DIR" -type d -not -path '*/.git/*' -exec chmod 0750 {} +
find "$APP_DIR" -type f -not -path '*/.git/*' -exec chmod 0640 {} +
chmod 0755 "$APP_DIR/public" "$APP_DIR/public/index.php"
for writable_dir in "$APP_DIR/storage" "$APP_DIR/public/uploads"; do
    find "$writable_dir" -type d -exec chmod 2770 {} +
    find "$writable_dir" -type f -exec chmod 0660 {} +
done
# PHP-FPM menolak membaca file sesi yang dibuat oleh UID lain. Deploy berjalan
# sebagai root/abdu, jadi direktori dan seluruh file sesi harus dikembalikan ke
# pengguna proses PHP setelah chown umum di atas.
install -d -m 2770 -o www-data -g www-data "$APP_DIR/storage/sessions"
chown -R www-data:www-data "$APP_DIR/storage/sessions"
find "$APP_DIR/storage/sessions" -type d -exec chmod 2770 {} +
find "$APP_DIR/storage/sessions" -type f -exec chmod 0600 {} +
chmod 0640 "$APP_DIR/.env"
nginx -t
systemctl reload nginx
curl -fsS -H 'Host: air-buton.oisara.my.id' http://127.0.0.1/login | grep -q 'Masuk'
echo "DEPLOY_OK"
echo "APP=air"
echo "BACKUP=$BACKUP_DIR/monitoring_air-$STAMP.sql.gz"
echo "ENV_PRESERVED=yes"
