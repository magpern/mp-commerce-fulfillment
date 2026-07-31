#!/usr/bin/env bash
#
# Provisions a WordPress core install with WooCommerce for the integration
# test suite, and symlinks this plugin into its plugins directory.
#
# Env overrides:
#   WP_CORE_DIR  target directory (default: tests/tmp/wordpress)
#   WP_VERSION   WordPress version (default: auto, pinned to wp-phpunit)
#   WC_VERSION   WooCommerce version (default: latest)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CORE_DIR="${WP_CORE_DIR:-$ROOT/tests/tmp/wordpress}"
WP_VERSION="${WP_VERSION:-auto}"
WC_VERSION="${WC_VERSION:-latest}"

# "auto" pins the core download to the installed wp-phpunit version so the
# test library and core never drift apart; falls back to latest.
if [ "$WP_VERSION" = "auto" ]; then
    WP_VERSION="latest"
    if command -v php >/dev/null 2>&1 && [ -f "$ROOT/vendor/composer/installed.json" ]; then
        detected="$(php -r '
            $data = json_decode( file_get_contents( $argv[1] ), true );
            foreach ( ( $data["packages"] ?? $data ) as $pkg ) {
                if ( "wp-phpunit/wp-phpunit" === ( $pkg["name"] ?? "" ) ) {
                    echo ltrim( $pkg["version"], "v" );
                }
            }
        ' "$ROOT/vendor/composer/installed.json")"
        if [ -n "$detected" ]; then
            WP_VERSION="$detected"
        fi
    fi
fi

mkdir -p "$CORE_DIR"

if [ ! -f "$CORE_DIR/wp-settings.php" ]; then
    if [ "$WP_VERSION" = "latest" ]; then
        wp_url="https://wordpress.org/latest.tar.gz"
    else
        wp_url="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
    fi
    echo "Downloading WordPress (${WP_VERSION})..."
    curl -fsSL "$wp_url" | tar -xz -C "$CORE_DIR" --strip-components=1
fi

PLUGINS_DIR="$CORE_DIR/wp-content/plugins"
mkdir -p "$PLUGINS_DIR"

if [ ! -d "$PLUGINS_DIR/woocommerce" ]; then
    if [ "$WC_VERSION" = "latest" ]; then
        wc_url="https://downloads.wordpress.org/plugin/woocommerce.zip"
    else
        wc_url="https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip"
    fi
    echo "Downloading WooCommerce (${WC_VERSION})..."
    tmpzip="$(mktemp)"
    curl -fsSL -o "$tmpzip" "$wc_url"
    if command -v unzip >/dev/null 2>&1; then
        unzip -q "$tmpzip" -d "$PLUGINS_DIR"
    else
        python3 -m zipfile -e "$tmpzip" "$PLUGINS_DIR"
    fi
    rm -f "$tmpzip"
fi

ln -sfn "$ROOT" "$PLUGINS_DIR/mp-commerce-fulfillment"

echo "WordPress core ready: $CORE_DIR"
