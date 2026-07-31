#!/usr/bin/env bash
#
# Builds an installable plugin zip into dist/.
# Run `composer install --no-dev` first so vendor/ contains only the autoloader.
#
# Usage: bin/build-zip.sh [version]   (defaults to the plugin header version)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(sed -n 's/^ \* Version: //p' "$ROOT/mp-commerce-fulfillment.php" | tr -d '[:space:]')}"

if [ -z "$VERSION" ]; then
    echo "Could not determine plugin version." >&2
    exit 1
fi

if [ -d "$ROOT/vendor/phpunit" ]; then
    echo "vendor/ contains dev dependencies — run 'composer install --no-dev' before building." >&2
    exit 1
fi

DIST="$ROOT/dist"
BUILD="$DIST/mp-commerce-fulfillment"

rm -rf "$DIST"
mkdir -p "$BUILD"

cp "$ROOT/mp-commerce-fulfillment.php" "$ROOT/uninstall.php" "$BUILD/"
[ -f "$ROOT/readme.txt" ] && cp "$ROOT/readme.txt" "$BUILD/"
[ -f "$ROOT/LICENSE" ] && cp "$ROOT/LICENSE" "$BUILD/"
cp -R "$ROOT/src" "$BUILD/src"
cp -R "$ROOT/vendor" "$BUILD/vendor"
[ -d "$ROOT/languages" ] && cp -R "$ROOT/languages" "$BUILD/languages"
[ -d "$ROOT/assets" ] && cp -R "$ROOT/assets" "$BUILD/assets"

( cd "$DIST" && zip -rq "mp-commerce-fulfillment-${VERSION}.zip" mp-commerce-fulfillment )

echo "dist/mp-commerce-fulfillment-${VERSION}.zip"
