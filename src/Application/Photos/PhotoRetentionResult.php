<?php
/**
 * Structured outcome of one retention purge batch.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Photos;

/**
 * Counts only — never filesystem paths.
 */
final class PhotoRetentionResult {

	/**
	 * Candidates considered in this batch.
	 *
	 * @var int
	 */
	private int $examined;

	/**
	 * Photos whose bytes were purged and metadata marked.
	 *
	 * @var int
	 */
	private int $purged;

	/**
	 * Candidates skipped (already purged, retention off, etc.).
	 *
	 * @var int
	 */
	private int $skipped;

	/**
	 * Candidates that failed filesystem or metadata steps.
	 *
	 * @var int
	 */
	private int $failed;

	/**
	 * Bounded failure summaries (no paths).
	 *
	 * @var list<string>
	 */
	private array $failures;

	/**
	 * Builds a batch result.
	 *
	 * @param int               $examined Candidates considered.
	 * @param int               $purged   Successfully purged.
	 * @param int               $skipped  Skipped.
	 * @param int               $failed   Failed.
	 * @param array<int,string> $failures Bounded failure messages.
	 */
	public function __construct( int $examined, int $purged, int $skipped, int $failed, array $failures = array() ) {
		$this->examined = $examined;
		$this->purged   = $purged;
		$this->skipped  = $skipped;
		$this->failed   = $failed;
		$this->failures = $failures;
	}

	/**
	 * Empty successful result (e.g. retention disabled).
	 */
	public static function empty(): self {
		return new self( 0, 0, 0, 0, array() );
	}

	/**
	 * Candidates considered.
	 */
	public function examined(): int {
		return $this->examined;
	}

	/**
	 * Successfully purged.
	 */
	public function purged(): int {
		return $this->purged;
	}

	/**
	 * Skipped.
	 */
	public function skipped(): int {
		return $this->skipped;
	}

	/**
	 * Failed.
	 */
	public function failed(): int {
		return $this->failed;
	}

	/**
	 * Bounded failure messages (no paths).
	 *
	 * @return list<string>
	 */
	public function failures(): array {
		return $this->failures;
	}
}
