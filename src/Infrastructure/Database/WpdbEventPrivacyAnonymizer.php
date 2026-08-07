<?php
/**
 * GDPR actor scrubbing for the audit log (I5 exception — anonymize only).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

/**
 * The only class allowed to UPDATE `mpcf_events`, and only for actor
 * anonymization: never touches event body fields or chain columns.
 */
final class WpdbEventPrivacyAnonymizer {

	/**
	 * Nulls actor_id and anonymizes label for one user across all events.
	 *
	 * @param int    $user_id    WordPress user id.
	 * @param string $anon_label Replacement label snapshot.
	 */
	public function anonymize_actor_user( int $user_id, string $anon_label ): int {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return 0;
		}

		$table = Schema::table( Schema::EVENTS );
		$n     = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
				"UPDATE {$table} SET actor_id = NULL, actor_label_snapshot = %s WHERE actor_id = %d",
				$anon_label,
				$user_id
			)
		);

		return is_int( $n ) ? $n : 0;
	}

	/**
	 * Same scrub scoped to one fulfillment.
	 *
	 * @param int    $fulfillment_id Fulfillment id.
	 * @param int    $user_id        WordPress user id.
	 * @param string $anon_label     Replacement label snapshot.
	 */
	public function anonymize_actor_user_on_fulfillment( int $fulfillment_id, int $user_id, string $anon_label ): int {
		global $wpdb;

		if ( $fulfillment_id <= 0 || $user_id <= 0 ) {
			return 0;
		}

		$table = Schema::table( Schema::EVENTS );
		$n     = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
				"UPDATE {$table} SET actor_id = NULL, actor_label_snapshot = %s WHERE fulfillment_id = %d AND actor_id = %d",
				$anon_label,
				$fulfillment_id,
				$user_id
			)
		);

		return is_int( $n ) ? $n : 0;
	}
}
