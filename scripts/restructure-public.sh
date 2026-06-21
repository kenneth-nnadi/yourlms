#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

mkdir -p public

# Move web-facing directories
for dir in admin api assets; do
  if [[ -d "$dir" && ! -d "public/$dir" ]]; then
    mv "$dir" "public/"
  fi
done

# Move web-facing PHP entry points (keep config.php at project root)
for f in *.php; do
  [[ "$f" == "config.php" ]] && continue
  [[ -f "public/$f" ]] && continue
  mv "$f" "public/"
done

echo "Restructure move complete."