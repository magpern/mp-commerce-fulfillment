#!/usr/bin/env bash
#
# Minimal release-blocking gate: version parity, zip content, docs presence.
# Grows alongside the sibling plugins' release-audit scripts as this plugin
# matures; Milestone 0 checks only what actually exists yet.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

FAILURES=0

section() {
	echo ""
	echo "== $1 =="
}

pass() {
	echo "  OK: $1"
}

fail() {
	echo "  FAIL: $1" >&2
	FAILURES=$((FAILURES + 1))
}

# `set -o pipefail` + `grep -q` is unsafe: grep exits early on match, unzip
# gets SIGPIPE (exit 141), and the pipeline is treated as failure — a false
# "missing file" for entries that appear early in the zip listing.
zip_has() {
	local zip_path="$1"
	local needle="$2"
	local matches

	matches="$(unzip -l "$zip_path" | grep -F -- "$needle" || true)"
	[ -n "$matches" ]
}

zip_has_regex() {
	local zip_path="$1"
	local pattern="$2"
	local matches

	matches="$(unzip -l "$zip_path" | grep -E -- "$pattern" || true)"
	[ -n "$matches" ]
}

zip_has_iregex() {
	local zip_path="$1"
	local pattern="$2"
	local matches

	matches="$(unzip -l "$zip_path" | grep -Ei -- "$pattern" || true)"
	[ -n "$matches" ]
}

section "Version parity"

HEADER_VERSION="$(sed -n 's/^ \* Version: //p' mp-commerce-fulfillment.php | tr -d '[:space:]')"
CONSTANT_VERSION="$(sed -n "s/.*define( 'MPCF_VERSION', '\([^']*\)' ).*/\1/p" mp-commerce-fulfillment.php)"
README_STABLE="$(sed -n 's/^Stable tag: //p' readme.txt | tr -d '[:space:]')"

if [ -n "$HEADER_VERSION" ] && [ "$HEADER_VERSION" = "$CONSTANT_VERSION" ] && [ "$HEADER_VERSION" = "$README_STABLE" ]; then
	pass "header ($HEADER_VERSION) == MPCF_VERSION ($CONSTANT_VERSION) == readme.txt Stable tag ($README_STABLE)"
else
	fail "version mismatch: header=$HEADER_VERSION constant=$CONSTANT_VERSION readme=$README_STABLE"
fi

section "Required documentation present"

for doc in docs/ARCHITECTURE_PLAN.md docs/ROADMAP.md docs/COMPATIBILITY.md docs/PERSISTED_DATA.md docs/HOOKS.md docs/TEST_STRATEGY.md docs/API.md; do
	if [ -f "$doc" ]; then
		pass "$doc exists"
	else
		fail "$doc is missing"
	fi
done

section "Zero-dependency property (ADR-0006)"

# composer.json's own require section is the source of truth for the
# zero-runtime-dependency property — after `composer install --no-dev`,
# vendor/composer/installed.json's package list is exactly this section's
# contents, so checking the static file is equivalent and does not
# require this script's caller to have already run that install.
REQUIRE_KEYS="$(php -r '
	$data = json_decode( file_get_contents( "composer.json" ), true );
	echo implode( ",", array_keys( $data["require"] ?? array() ) );
')"

if [ "$REQUIRE_KEYS" = "php" ]; then
	pass "composer.json require section names only php — no runtime package dependency"
else
	fail "composer.json require section names a runtime package ($REQUIRE_KEYS) — the zero-dependency property no longer holds"
fi

section "Zip build and content"

if [ -d vendor/phpunit ]; then
	fail "vendor/ contains dev dependencies — run 'composer install --no-dev' before auditing a release"
else
	ZIP_PATH="$(bash bin/build-zip.sh "$HEADER_VERSION")"

	if [ -f "$ZIP_PATH" ]; then
		pass "built $ZIP_PATH"

		for required in \
			"mp-commerce-fulfillment/mp-commerce-fulfillment.php" \
			"mp-commerce-fulfillment/uninstall.php" \
			"mp-commerce-fulfillment/vendor/autoload.php" \
			"mp-commerce-fulfillment/templates/documents/packing-slip.php" \
			"mp-commerce-fulfillment/templates/documents/picking-list.php"; do
			if zip_has "$ZIP_PATH" "$required"; then
				pass "zip contains $required"
			else
				fail "zip is missing $required"
			fi
		done

		if zip_has_regex "$ZIP_PATH" "mp-commerce-fulfillment/(vendor/(phpunit|dealerdirect)|tests/)"; then
			fail "zip contains dev-only files (phpunit/tests) — build with --no-dev"
		else
			pass "zip contains no dev-only files"
		fi

		# ADR-0006: Playwright/npm are dev-and-CI-only and must never reach
		# the release artifact, on top of bin/build-zip.sh's own belt-and-
		# suspenders check — a third, independent defense on the same
		# property. Deliberately unanchored to any particular path depth
		# (unlike the dev-only-files check above, whose two paths only
		# ever appear at the plugin root) — a Node artifact could in
		# principle end up nested inside assets/ or anywhere else.
		if zip_has_iregex "$ZIP_PATH" "(^|/)(package(-lock)?\.json|node_modules/|playwright\.config\.|tests/browser/|\.playwright/|playwright-report/|test-results/)"; then
			fail "zip contains a Node/Playwright artifact — see ADR-0006"
		else
			pass "zip contains no Node/Playwright artifact"
		fi
	else
		fail "bin/build-zip.sh did not produce $ZIP_PATH"
	fi
fi

section "Result"

if [ "$FAILURES" -eq 0 ]; then
	echo "Release audit passed."
	exit 0
fi

echo "Release audit failed with $FAILURES issue(s)." >&2
exit 1
