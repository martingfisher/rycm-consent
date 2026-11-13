#!/usr/bin/env bash
#
# Build dist/rycm-consent.zip: the installable plugin, with a top-level
# rycm-consent/ folder so WordPress replaces the existing copy on update.
#
#   bin/build-zip.sh
#
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/rycm-consent" "$ROOT/dist"
cp "$ROOT/rycm-consent.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$ROOT/LICENSE" "$STAGE/rycm-consent/"
cp -R "$ROOT/includes" "$ROOT/assets" "$STAGE/rycm-consent/"

rm -f "$ROOT/dist/rycm-consent.zip"
(cd "$STAGE" && zip -qr -X "$ROOT/dist/rycm-consent.zip" rycm-consent)
echo "Built dist/rycm-consent.zip ($(du -h "$ROOT/dist/rycm-consent.zip" | cut -f1))"
