#!/usr/bin/env bash
#
# Turns the file-only WordPress install tests/bin/install-wp.sh provisions
# into a real, running, seeded site — everything tests/browser/ needs that
# the PHPUnit integration tier does not (a real database-backed install,
# not just wp-phpunit's in-process bootstrap).
#
# Env overrides:
#   WP_CORE_DIR      target directory (default: tests/tmp/wordpress)
#   WP_DB_HOST/NAME/USER/PASS  same convention as tests/bin/install-wp.sh's
#                              PHPUnit-tier callers (see .github/workflows/ci.yml)
#   MPCF_BASE_URL    site URL the built-in PHP server will answer on
#                    (default: http://127.0.0.1:8888)
#   MPCF_ADMIN_USER / MPCF_ADMIN_PASSWORD  admin credentials Playwright's
#                    global-setup.js logs in with (defaults: admin/password)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CORE_DIR="${WP_CORE_DIR:-$ROOT/tests/tmp/wordpress}"
DB_HOST="${WP_DB_HOST:-127.0.0.1}"
DB_NAME="${WP_DB_NAME:-wordpress_browser}"
DB_USER="${WP_DB_USER:-root}"
DB_PASS="${WP_DB_PASS:-root}"
SITE_URL="${MPCF_BASE_URL:-http://127.0.0.1:8888}"
ADMIN_USER="${MPCF_ADMIN_USER:-admin}"
ADMIN_PASSWORD="${MPCF_ADMIN_PASSWORD:-password}"

bash "$ROOT/tests/bin/install-wp.sh"

WP_CLI_PHAR="$ROOT/tests/bin/.wp-cli.phar"

if [ ! -f "$WP_CLI_PHAR" ]; then
    echo "Downloading WP-CLI..."
    curl -fsSL -o "$WP_CLI_PHAR" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi

WP=(php -d memory_limit=512M "$WP_CLI_PHAR" --path="$CORE_DIR" --allow-root)

if [ ! -f "$CORE_DIR/wp-config.php" ]; then
    "${WP[@]}" config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check
fi

if ! "${WP[@]}" core is-installed 2>/dev/null; then
    "${WP[@]}" core install --url="$SITE_URL" --title="MPCF Browser Tests" \
        --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASSWORD" \
        --admin_email="admin@example.test" --skip-email
fi

"${WP[@]}" plugin activate woocommerce mp-commerce-fulfillment

echo "Seeding one paid order..."
"${WP[@]}" eval-file "$ROOT/tests/browser/seed.php"
echo ""

echo "Site ready: $SITE_URL (serve with: php -S 127.0.0.1:8888 -t $CORE_DIR)"
