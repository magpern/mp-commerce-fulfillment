<?php
/**
 * The outcome of one {@see IntakeService::intake()} call.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\Fulfillment;

/**
 * Distinguishes "created a new fulfillment" from "one already existed" —
 * both are success from the caller's point of view (idempotency is the
 * point), but the distinction matters to a caller counting real intakes,
 * such as the CLI backfill command.
 */
final class IntakeOutcome {

	/**
	 * Whether intake succeeded (created or already existed).
	 *
	 * @var bool
	 */
	private bool $succeeded;

	/**
	 * Whether this call is the one that created the fulfillment, as opposed
	 * to finding one that already existed.
	 *
	 * @var bool
	 */
	private bool $created;

	/**
	 * The fulfillment, if intake succeeded.
	 *
	 * @var Fulfillment|null
	 */
	private ?Fulfillment $fulfillment;

	/**
	 * Machine-readable failure code, if it did not succeed.
	 *
	 * @var string|null
	 */
	private ?string $failure_code;

	/**
	 * Human-readable failure message, if it did not succeed.
	 *
	 * @var string|null
	 */
	private ?string $failure_message;

	/**
	 * Assembles an outcome. Use {@see created()}, {@see already_existed()} or
	 * {@see failed()} instead of calling this directly.
	 *
	 * @param bool             $succeeded       Whether intake succeeded.
	 * @param bool             $created         Whether this call created the fulfillment.
	 * @param Fulfillment|null $fulfillment     The fulfillment, if it succeeded.
	 * @param string|null      $failure_code    Machine-readable failure code, if not.
	 * @param string|null      $failure_message Human-readable failure message, if not.
	 */
	private function __construct( bool $succeeded, bool $created, ?Fulfillment $fulfillment, ?string $failure_code, ?string $failure_message ) {
		$this->succeeded       = $succeeded;
		$this->created         = $created;
		$this->fulfillment     = $fulfillment;
		$this->failure_code    = $failure_code;
		$this->failure_message = $failure_message;
	}

	/**
	 * Builds an outcome for a brand-new fulfillment.
	 *
	 * @param Fulfillment $fulfillment The newly created fulfillment.
	 */
	public static function created( Fulfillment $fulfillment ): self {
		return new self( true, true, $fulfillment, null, null );
	}

	/**
	 * Builds an outcome for an order that already had a fulfillment — the
	 * idempotent no-op path, whether detected up front or via the insert
	 * race fallback.
	 *
	 * @param Fulfillment $fulfillment The pre-existing fulfillment.
	 */
	public static function already_existed( Fulfillment $fulfillment ): self {
		return new self( true, false, $fulfillment, null, null );
	}

	/**
	 * Builds a failure outcome.
	 *
	 * @param string $code    Machine-readable failure code.
	 * @param string $message Human-readable failure message.
	 */
	public static function failed( string $code, string $message ): self {
		return new self( false, false, null, $code, $message );
	}

	/**
	 * Whether intake succeeded (created or already existed).
	 */
	public function is_success(): bool {
		return $this->succeeded;
	}

	/**
	 * Whether this call is the one that created the fulfillment.
	 */
	public function was_created(): bool {
		return $this->created;
	}

	/**
	 * The fulfillment, if intake succeeded.
	 */
	public function fulfillment(): ?Fulfillment {
		return $this->fulfillment;
	}

	/**
	 * Machine-readable failure code, if it did not succeed.
	 */
	public function failure_code(): ?string {
		return $this->failure_code;
	}

	/**
	 * Human-readable failure message, if it did not succeed.
	 */
	public function failure_message(): ?string {
		return $this->failure_message;
	}
}
