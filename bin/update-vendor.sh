#!/usr/bin/env bash
#
# Replace the vendored CookieConsent with an exact released version from npm.
#
#   bin/update-vendor.sh 3.3.0
#
set -euo pipefail
VERSION="${1:?usage: bin/update-vendor.sh <exact version, e.g. 3.3.0>}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="$ROOT/assets/vendor/cookieconsent"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

(cd "$TMP" && npm pack "vanilla-cookieconsent@$VERSION" --silent >/dev/null && tar xzf ./*.tgz)
cp "$TMP/package/dist/cookieconsent.umd.js" "$TMP/package/dist/cookieconsent.css" "$TMP/package/LICENSE" "$DEST/"
sed -i.bak -E "s/^Version: .*/Version: $VERSION (npm vanilla-cookieconsent@$VERSION, dist\/cookieconsent.umd.js and dist\/cookieconsent.css, unmodified)/; s#releases/tag/v[0-9.]+#releases/tag/v$VERSION#" "$DEST/VERSION.txt" && rm "$DEST/VERSION.txt.bak"
sed -i.bak -E "s/define\( 'RYCM_CONSENT_CC_VERSION', '[^']+' \)/define( 'RYCM_CONSENT_CC_VERSION', '$VERSION' )/" "$ROOT/rycm-consent.php" && rm "$ROOT/rycm-consent.php.bak"
echo "CookieConsent $VERSION vendored. Now run: php tests/run.php, the integration suite, and the browser check in TESTING.md."
