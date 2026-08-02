<?php
/**
 * The outcome of one ShippingService mutation.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

/**
 * Generic across `ShippingService`'s several mutations (create/update/
 * delete a shipment, add/update/remove a package, ship, mark delivered,
 * mark exception) — deliberately one outcome shape rather than one class
 * per operation, since every operation needs exactly the same three facts
 * (did it succeed, what came back if so, why not if not) and the result
 * type is unambiguous from which method a caller just called. `result()`
 * is `mixed` for that reason; callers know its concrete type from context,
 * exactly as `TransitionOutcome::fulfillment()` is concrete because
 * `WorkflowService::transition()` only ever produces a `Fulfillment`.
 */
final class ShippingOutcome {

	/**
	 * Whether the mutation succeeded.
	 *
	 * @var bool
	 */
	private bool $succeeded;

	/**
	 * The mutated (or created) domain object, if it succeeded. `null` for
	 * an operation with nothing to return (e.g. `ship()`).
	 *
	 * @var mixed
	 */
	private mixed $result;

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
	 * @param bool        $succeeded       Whether the mutation succeeded.
	 * @param mixed       $result          The mutated/created object, if it succeeded.
	 * @param string|null $failure_code    Machine-readable failure code, if not.
	 * @param string|null $failure_message Human-readable failure message, if not.
	 */
	private function __construct( bool $succeeded, mixed $result, ?string $failure_code, ?string $failure_message ) {
		$this->succeeded       = $succeeded;
		$this->result          = $result;
		$this->failure_code    = $failure_code;
		$this->failure_message = $failure_message;
	}

	/**
	 * Builds a success outcome.
	 *
	 * @param mixed $result The mutated/created object, or null if the
	 *                      operation has nothing to return.
	 */
	public static function succeeded( mixed $result = null ): self {
		return new self( true, $result, null, null );
	}

	/**
	 * Builds a failure outcome.
	 *
	 * @param string $code    Machine-readable failure code (`not_found`, `not_deletable`, `version_conflict`).
	 * @param string $message Human-readable failure message.
	 */
	public static function failed( string $code, string $message ): self {
		return new self( false, null, $code, $message );
	}

	/**
	 * Whether the mutation succeeded.
	 */
	public function is_success(): bool {
		return $this->succeeded;
	}

	/**
	 * The mutated/created object, if it succeeded.
	 */
	public function result(): mixed {
		return $this->result;
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
