<?php
/**
 * The outcome of one PackingService batch mutation.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

/**
 * Same shape as {@see ShippingOutcome} and for the same reason: one batch
 * quantity update needs exactly the same three facts (did it succeed,
 * what changed if so, why not if not).
 */
final class PackingOutcome {

	/**
	 * Whether the batch succeeded.
	 *
	 * @var bool
	 */
	private bool $succeeded;

	/**
	 * The items the batch actually changed, in the same order the caller
	 * submitted them. Empty for a failed outcome.
	 *
	 * @var array<int, \MPCF\Domain\FulfillmentItem>
	 */
	private array $updated_items;

	/**
	 * The fulfillment's new version after the batch, or null if it failed.
	 *
	 * @var int|null
	 */
	private ?int $version;

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
	 * Assembles an outcome. Use {@see succeeded()} or {@see failed()}
	 * instead of calling this directly.
	 *
	 * @param bool                                     $succeeded       Whether the batch succeeded.
	 * @param array<int, \MPCF\Domain\FulfillmentItem> $updated_items   Items the batch actually changed.
	 * @param int|null                                 $version         The fulfillment's new version, if it succeeded.
	 * @param string|null                              $failure_code    Machine-readable failure code, if not.
	 * @param string|null                              $failure_message Human-readable failure message, if not.
	 */
	private function __construct( bool $succeeded, array $updated_items, ?int $version, ?string $failure_code, ?string $failure_message ) {
		$this->succeeded       = $succeeded;
		$this->updated_items   = $updated_items;
		$this->version         = $version;
		$this->failure_code    = $failure_code;
		$this->failure_message = $failure_message;
	}

	/**
	 * Builds a success outcome.
	 *
	 * @param array<int, \MPCF\Domain\FulfillmentItem> $updated_items Items the batch actually changed.
	 * @param int                                      $version       The fulfillment's new version.
	 */
	public static function succeeded( array $updated_items, int $version ): self {
		return new self( true, $updated_items, $version, null, null );
	}

	/**
	 * Builds a failure outcome.
	 *
	 * @param string $code    Machine-readable failure code (`not_found`, `invalid_payload`, `version_conflict`).
	 * @param string $message Human-readable failure message.
	 */
	public static function failed( string $code, string $message ): self {
		return new self( false, array(), null, $code, $message );
	}

	/**
	 * Whether the batch succeeded.
	 */
	public function is_success(): bool {
		return $this->succeeded;
	}

	/**
	 * The items the batch actually changed.
	 *
	 * @return array<int, \MPCF\Domain\FulfillmentItem>
	 */
	public function updated_items(): array {
		return $this->updated_items;
	}

	/**
	 * The fulfillment's new version, if it succeeded.
	 */
	public function version(): ?int {
		return $this->version;
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
