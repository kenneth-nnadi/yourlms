#!/bin/bash
set -euo pipefail

chown -R www-data:www-data /var/www/html/uploads 2>/dev/null || true
mkdir -p /var/www/html/uploads
chown www-data:www-data /var/www/html/uploads

exec "$@"