#!/usr/bin/env bash
#
# Generates or verifies languages/mp-commerce-fulfillment.pot from plugin source.
#
# Usage:
#   bin/make-pot.sh          Regenerate the committed POT file
#   bin/make-pot.sh --check  Exit 1 when the committed POT is stale
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
POT="$ROOT/languages/mp-commerce-fulfillment.pot"
DOMAIN="mp-commerce-fulfillment"
MODE="${1:-}"

mkdir -p "$ROOT/languages"

normalize_pot() {
	local file="$1"

	# Drop wall-clock-dependent headers so diffs are deterministic.
	sed -i \
		-e '/^"POT-Creation-Date:/d' \
		-e '/^"PO-Revision-Date:/d' \
		-e '/^"X-Generator:/d' \
		"$file"
}

ensure_wp_cli() {
	if command -v wp >/dev/null 2>&1; then
		return 0
	fi

	if ! command -v curl >/dev/null 2>&1; then
		return 1
	fi

	curl -sSLo /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	chmod +x /tmp/wp-cli.phar
	export PATH="/tmp:${PATH}"
	ln -sf /tmp/wp-cli.phar /tmp/wp
}

run_make_pot() {
	local dest="$1"

	ensure_wp_cli || true

	if command -v wp >/dev/null 2>&1; then
		if wp i18n make-pot "$ROOT" "$dest" \
			--domain="$DOMAIN" \
			--exclude=vendor,tests,docs,dist,assets/mpds,src/Vendor \
			--slug=mp-commerce-fulfillment \
			--allow-root \
			--headers='{"Report-Msgid-Bugs-To":"https://github.com/magpern/mp-commerce-fulfillment/issues"}'; then
			return
		fi
	fi

	if ! command -v docker >/dev/null 2>&1; then
		echo "wp i18n make-pot failed and docker is unavailable for fallback" >&2
		exit 1
	fi

	docker run --rm -u "$(id -u):$(id -g)" \
		-v "$ROOT:/app" -w /app \
		wordpress:cli-php8.1 \
		wp i18n make-pot /app "/app/${dest#$ROOT/}" \
			--domain="$DOMAIN" \
			--exclude=vendor,tests,docs,dist,assets/mpds,src/Vendor \
			--slug=mp-commerce-fulfillment \
			--allow-root \
			--headers='{"Report-Msgid-Bugs-To":"https://github.com/magpern/mp-commerce-fulfillment/issues"}'
}

if [[ "$MODE" == "--check" ]]; then
	if [[ ! -f "$POT" ]]; then
		echo "Missing $POT — run: composer make-pot" >&2
		exit 1
	fi

	TMP_GEN="$ROOT/languages/.pot-check.tmp"
	TMP_EXP="$(mktemp)"
	trap 'rm -f "$TMP_GEN" "$TMP_EXP"' EXIT

	cp "$POT" "$TMP_EXP"
	run_make_pot "$TMP_GEN"
	normalize_pot "$TMP_GEN"
	normalize_pot "$TMP_EXP"

	if ! diff -u "$TMP_EXP" "$TMP_GEN" >/dev/null; then
		echo "POT file is stale. Run: composer make-pot" >&2
		diff -u "$TMP_EXP" "$TMP_GEN" || true
		exit 1
	fi

	exit 0
fi

run_make_pot "$POT"
normalize_pot "$POT"
echo "$POT"
