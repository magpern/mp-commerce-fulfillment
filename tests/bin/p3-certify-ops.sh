#!/usr/bin/env bash
#
# P3 operational certification helpers against disposable compose_mpcf_rc.
# Evidence only — does not deploy production.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LOG_DIR="${MPCF_P3_LOG_DIR:-/tmp/mpcf-p3-cert}"
mkdir -p "$LOG_DIR"

WP() {
  docker run --rm --network compose_mpcf_rc --volumes-from compose-wordpress-1 \
    -e WORDPRESS_DB_HOST=db -e WORDPRESS_DB_USER=wordpress \
    -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
    wordpress:cli-2.12.0-php8.4 wp "$@" --allow-root
}

cmd="${1:-help}"

case "$cmd" in
  doctor)
    WP mpcf doctor --format=json | tee "$LOG_DIR/doctor.json"
    ;;
  validate)
    WP mpcf validate schema | tee "$LOG_DIR/validate-schema.txt"
    WP mpcf validate consistency | tee "$LOG_DIR/validate-consistency.txt"
    WP mpcf validate schedules | tee "$LOG_DIR/validate-schedules.txt"
    WP mpcf validate storage | tee "$LOG_DIR/validate-storage.txt"
    ;;
  backup)
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    WP db export - --add-drop-table > "$LOG_DIR/db-${stamp}.sql"
    docker exec compose-wordpress-1 bash -lc 'cd /var/www/html/wp-content/uploads && tar -czf - mpcf 2>/dev/null || true' > "$LOG_DIR/uploads-mpcf-${stamp}.tgz"
    WP option get mpcf_settings --format=json > "$LOG_DIR/settings-${stamp}.json" || echo '{}' > "$LOG_DIR/settings-${stamp}.json"
    WP option get mpcf_db_version > "$LOG_DIR/db-version-${stamp}.txt"
    ls -la "$LOG_DIR"/*"${stamp}"* | tee "$LOG_DIR/backup-manifest-${stamp}.txt"
    echo "BACKUP_STAMP=$stamp"
    ;;
  soak)
    # Bounded soak: repeated doctor + validate + AS schedule probe + capacity.
    rounds="${2:-20}"
    {
      echo "soak_start=$(date -u -Iseconds) rounds=$rounds"
      for i in $(seq 1 "$rounds"); do
        echo "=== round $i ==="
        WP mpcf doctor 2>&1 | tail -5
        WP mpcf validate schedules 2>&1 | tail -10
        WP eval 'global $wpdb; echo "fulfillments=".(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mpcf_fulfillments")." events=".(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mpcf_events")."\n";' 2>&1
        sleep 2
      done
      echo "soak_end=$(date -u -Iseconds)"
    } | tee "$LOG_DIR/soak.log"
    ;;
  dr-missing-schedules)
    WP eval 'if ( function_exists("as_unschedule_all_actions") ) { as_unschedule_all_actions("mpcf_purge_photo_retention"); as_unschedule_all_actions("mpcf_analytics_daily_rollup"); echo "unscheduled\n"; }' 2>&1 | tee "$LOG_DIR/dr-unschedule.txt"
    WP mpcf doctor 2>&1 | tee "$LOG_DIR/dr-doctor-after-unschedule.txt" || true
    WP mpcf repair schedules 2>&1 | tee "$LOG_DIR/dr-repair-schedules-dry.txt" || true
    WP mpcf repair schedules --yes 2>&1 | tee "$LOG_DIR/dr-repair-schedules-yes.txt" || true
    WP mpcf doctor 2>&1 | tee "$LOG_DIR/dr-doctor-after-repair.txt" || true
    ;;
  dr-missing-storage)
    docker exec compose-wordpress-1 bash -lc 'rm -f /var/www/html/wp-content/uploads/mpcf/.htaccess; rmdir /var/www/html/wp-content/uploads/mpcf/documents 2>/dev/null || rm -rf /var/www/html/wp-content/uploads/mpcf/documents; echo removed'
    WP mpcf doctor 2>&1 | tee "$LOG_DIR/dr-doctor-missing-storage.txt" || true
    WP mpcf repair storage-dirs --yes 2>&1 | tee "$LOG_DIR/dr-repair-storage.txt" || true
    WP mpcf doctor 2>&1 | tee "$LOG_DIR/dr-doctor-storage-fixed.txt" || true
    ;;
  privacy-smoke)
    WP eval '
$ex = apply_filters("wp_privacy_personal_data_exporters", array());
$er = apply_filters("wp_privacy_personal_data_erasers", array());
echo "exporter=".(isset($ex["mpcf-fulfillment-data"])?"yes":"no")."\n";
echo "eraser=".(isset($er["mpcf-fulfillment-data"])?"yes":"no")."\n";
' 2>&1 | tee "$LOG_DIR/privacy-hooks.txt"
    ;;
  help|*)
    echo "Usage: $0 {doctor|validate|backup|soak [rounds]|dr-missing-schedules|dr-missing-storage|privacy-smoke}"
    ;;
esac
