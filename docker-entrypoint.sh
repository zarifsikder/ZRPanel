#!/bin/bash
set -e

echo "Waiting for MariaDB to be ready..."
until mariadb -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASS" -e "SELECT 1" &>/dev/null; do
    sleep 2
done
echo "MariaDB is ready."

echo "Starting cron service..."
service cron start 2>/dev/null || cron 2>/dev/null || true

echo "Running initial DNS sync..."
php /var/www/html/dns_sync.php 2>&1 || true

echo "Starting NSD nameserver..."
nsd-control start 2>&1 || true

exec "$@"
