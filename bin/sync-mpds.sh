#!/usr/bin/env bash
#
# Vendors a pinned tag of magpern/mp-admin-design-system into this repo,
# mechanically rewriting namespaces/prefixes per docs/CONSUMING.md in that
# repo. The result is committed — this plugin never depends on the design
# system repo at runtime or in CI (see ADR-MPDS-0001 there).
#
# Rewrite rules (deterministic string substitution only):
#   Mpds\          -> MPCF\Vendor\Mpds\
#   --mpds-        -> --mpcf-ds-
#   mpds-ui-       -> mpcf-ui-
#   data-mpds-     -> data-mpcf-
#
# Usage: bin/sync-mpds.sh <tag> [source-repo-url-or-path]
#   bin/sync-mpds.sh v0.1.0
#   bin/sync-mpds.sh v0.1.0 /opt/biopentra/dev/mp-admin-design-system   (local checkout, for this host)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TAG="${1:?Usage: bin/sync-mpds.sh <tag> [source-repo-url-or-path]}"
SOURCE="${2:-https://github.com/magpern/mp-admin-design-system.git}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "Fetching mp-admin-design-system @ ${TAG} from ${SOURCE}..."
git clone --quiet --depth 1 --branch "$TAG" "$SOURCE" "$WORK/mpds"

CSS_DEST="$ROOT/assets/mpds/css"
JS_DEST="$ROOT/assets/mpds/js"
PHP_DEST="$ROOT/src/Vendor/Mpds"

rm -rf "$CSS_DEST" "$JS_DEST" "$PHP_DEST"
mkdir -p "$CSS_DEST" "$JS_DEST" "$PHP_DEST"

cp -R "$WORK/mpds/css/." "$CSS_DEST/"
cp -R "$WORK/mpds/js/." "$JS_DEST/"
cp -R "$WORK/mpds/php/." "$PHP_DEST/"

rewrite_file() {
	local file="$1"
	# \bMpds\b (not -E's plain 'Mpds\\') so it catches both `Mpds\Sub` (a
	# sub-namespace) and the bare top-level `namespace Mpds;` declaration —
	# an anchored `Mpds\\` rule alone misses the latter.
	sed -i -E \
		-e 's/\bMpds\b/MPCF\\Vendor\\Mpds/g' \
		-e 's/--mpds-/--mpcf-ds-/g' \
		-e 's/mpds-ui-/mpcf-ui-/g' \
		-e 's/data-mpds-/data-mpcf-/g' \
		"$file"
}

while IFS= read -r -d '' file; do
	rewrite_file "$file"
done < <(find "$CSS_DEST" "$JS_DEST" "$PHP_DEST" -type f \( -name '*.css' -o -name '*.js' -o -name '*.php' \) -print0)

# assets/mpds/MANIFEST covers the CSS/JS half; src/Vendor/Mpds/MANIFEST
# covers the PHP half — kept separate so MpdsVendorGuardTest can point at
# each independently and so a future asset-only or PHP-only re-sync leaves
# an accurate manifest for the half that actually changed.
(
	cd "$ROOT/assets/mpds"
	find css js -type f | sort | while read -r f; do
		printf '%s  %s\n' "$(sha256sum "$f" | cut -d' ' -f1)" "$f"
	done
) > "$ROOT/assets/mpds/MANIFEST"

(
	cd "$ROOT/src/Vendor/Mpds"
	find . -type f -name '*.php' | sed 's|^\./||' | sort | while read -r f; do
		printf '%s  %s\n' "$(sha256sum "$f" | cut -d' ' -f1)" "$f"
	done
) > "$ROOT/src/Vendor/Mpds/MANIFEST"

echo "$TAG" > "$ROOT/assets/mpds/SOURCE_TAG"

echo "Vendored mp-admin-design-system @ ${TAG} into assets/mpds/ and src/Vendor/Mpds/."
