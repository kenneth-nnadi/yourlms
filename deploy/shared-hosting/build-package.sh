#!/usr/bin/env bash
# Build a ZIP you can upload via FTP/SFTP to public_html (no root required).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="${ROOT}/yourlms-shared-hosting.zip"

cd "${ROOT}"
zip -r "${OUT}" . \
  -x '*.git*' \
  -x 'uploads/*' \
  -x 'uploads/**' \
  -x 'import-temp/*' \
  -x 'deploy/debian-home/data/*' \
  -x 'config.local.php' \
  -x 'includes/branding.local.php' \
  -x '.install-lock' \
  -x 'yourlms-shared-hosting.zip' \
  -x 'cybersecurity-nice-project-export.zip' \
  -x 'YourLMS.png'

echo "Created ${OUT}"
echo ""
echo "Deploy (no cPanel, no MySQL):"
echo "  1. Upload and extract into public_html/yourlms"
echo "  2. chmod 755 data/ uploads/  (writable by the web server)"
echo "  3. Open https://yourdomain.com/yourlms/install.php"
echo "  4. Delete install.php after setup"
echo ""
echo "Data is stored in data/yourlms.sqlite — back up that file with uploads/."