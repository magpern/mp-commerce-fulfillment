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

for doc in docs/ARCHITECTURE_PLAN.md docs/ROADMAP.md docs/COMPATIBILITY.md docs/PERSISTED_DATA.md docs/HOOKS.md docs/TEST_STRATEGY.md; do
	if [ -f "$doc" ]; then
		pass "$doc exists"
	else
		fail "$doc is missing"
	fi
done

section "Zip build and content"

if [ -d vendor/phpunit ]; then
	fail "vendor/ contains dev dependencies — run 'composer install --no-dev' before auditing a release"
else
	ZIP_PATH="$(bash bin/build-zip.sh "$HEADER_VERSION")"

	if [ -f "$ZIP_PATH" ]; then
		pass "built $ZIP_PATH"

		for required in "mp-commerce-fulfillment/mp-commerce-fulfillment.php" "mp-commerce-fulfillment/uninstall.php" "mp-commerce-fulfillment/vendor/autoload.php"; do
			if unzip -l "$ZIP_PATH" | grep -q "$required"; then
				pass "zip contains $required"
			else
				fail "zip is missing $required"
			fi
		done

		if unzip -l "$ZIP_PATH" | grep -qE "mp-commerce-fulfillment/(vendor/(phpunit|dealerdirect)|tests/)"; then
			fail "zip contains dev-only files (phpunit/tests) — build with --no-dev"
		else
			pass "zip contains no dev-only files"
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
