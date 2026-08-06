<?php
/**
 * Pure retention eligibility for package photography evidence.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Media;

use DateTimeImmutable;
use DateTimeZone;

/**
 * WordPress-free policy helpers. Retention months of 0 means retain
 * indefinitely (never eligible). Cutoffs and comparisons use UTC only.
 */
final class PhotoRetentionEligibility {

	/**
	 * Computes the inclusive age cutoff for a retention window.
	 *
	 * Photos with {@see PhotoRecord::created_at()} at or before this instant
	 * are age-eligible. Returns null when retention is indefinite (0).
	 *
	 * @param int               $retention_months Configured months (0 = forever).
	 * @param DateTimeImmutable $now              Current UTC time.
	 */
	public static function cutoff( int $retention_months, DateTimeImmutable $now ): ?DateTimeImmutable {
		if ( $retention_months <= 0 ) {
			return null;
		}

		$utc = $now->setTimezone( new DateTimeZone( 'UTC' ) );

		return $utc->modify( '-' . $retention_months . ' months' );
	}

	/**
	 * Whether a photo is eligible for byte purge under the given policy.
	 *
	 * Already-purged rows are never eligible. When {@see cutoff()} is null
	 * (retention 0), nothing is eligible. Age uses created_at vs cutoff in UTC.
	 * Callers still verify file presence during purge execution.
	 *
	 * @param PhotoRecord            $photo  Candidate row.
	 * @param DateTimeImmutable|null $cutoff Age cutoff from {@see cutoff()}, or null.
	 */
	public static function is_eligible( PhotoRecord $photo, ?DateTimeImmutable $cutoff ): bool {
		if ( $photo->is_purged() ) {
			return false;
		}

		if ( null === $cutoff ) {
			return false;
		}

		$created = $photo->created_at()->setTimezone( new DateTimeZone( 'UTC' ) );
		$bound   = $cutoff->setTimezone( new DateTimeZone( 'UTC' ) );

		return $created <= $bound;
	}
}
