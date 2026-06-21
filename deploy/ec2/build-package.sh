#!/usr/bin/env bash
# Build EC2 deployment ZIP (Apache + PHP + MariaDB — same stack as XAMPP).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="${ROOT}/yourlms-ec2.zip"
STAGING="$(mktemp -d)"

cleanup() { rm -rf "${STAGING}"; }
trap cleanup EXIT

cd "${ROOT}"

rsync -a \
  --exclude '.git/' \
  --exclude 'uploads/*' \
  --exclude 'import-temp/' \
  --exclude 'config.local.php' \
  --exclude 'includes/branding.local.php' \
  --exclude '.install-lock' \
  --exclude 'data/*.sqlite' \
  --exclude 'data/*.sqlite-*' \
  --exclude 'deploy/debian-home/data/' \
  --exclude 'yourlms-ec2.zip' \
  --exclude 'yourlms-shared-hosting.zip' \
  --exclude 'cybersecurity-nice-project-export.zip' \
  . "${STAGING}/"



cp "${ROOT}/deploy/ec2/install.sh" "${STAGING}/install.sh"
chmod +x "${STAGING}/install.sh" "${STAGING}/deploy/ec2/setup-cli.php"

cd "${STAGING}"
zip -rq "${OUT}" .

echo "Created ${OUT}"
echo ""
echo "Deploy on EC2 (SSH):"
echo "  scp yourlms-ec2.zip ec2-user@YOUR-IP:~/"
echo "  ssh ec2-user@YOUR-IP"
echo "  unzip yourlms-ec2.zip -d yourlms && cd yourlms"
echo "  sudo bash install.sh"
echo ""
echo "Then open: http://YOUR-EC2-IP/nice-lms/"