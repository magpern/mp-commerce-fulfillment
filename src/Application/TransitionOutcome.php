<?php
/**
 * The outcome of one {@see WorkflowService::transition()} call.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\Fulfillment;

/**
 * A superset of {@see \MPCF\Engine\TransitionResult}: everything the Engine
 * layer can reject, plus the failure modes only the Application layer can
 * detect — the fulfillment does not exist, its workflow is not registered,
 * or the optimistic-lock write lost a race.
 */
final class TransitionOutcome {

	/**
	 * Whether the transition succeeded.
	 *
	 * @var bool
	 */
	private bool $succeeded;

	/**
	 * The fulfillment, in its post-transition state, if it succeeded.
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
	 * Assembles an outcome. Use {@see succeeded()} or {@see failed()}
	 * instead of calling this directly.
	 *
	 * @param bool             $succeeded       Whether the transition succeeded.
	 * @param Fulfillment|null $fulfillment     The fulfillment, if it succeeded.
	 * @param string|null      $failure_code    Machine-readable failure code, if not.
	 * @param string|null      $failure_message Human-readable failure message, if not.
	 */
	private function __construct( bool $succeeded, ?Fulfillment $fulfillment, ?string $failure_code, ?string $failure_message ) {
		$this->succeeded       = $succeeded;
		$this->fulfillment     = $fulfillment;
		$this->failure_code    = $failure_code;
		$this->failure_message = $failure_message;
	}

	/**
	 * Builds a success outcome.
	 *
	 * @param Fulfillment $fulfillment The fulfillment in its post-transition state.
	 */
	public static function succeeded( Fulfillment $fulfillment ): self {
		return new self( true, $fulfillment, null, null );
	}

	/**
	 * Builds a failure outcome.
	 *
	 * @param string $code    Machine-readable failure code.
	 * @param string $message Human-readable failure message.
	 */
	public static function failed( string $code, string $message ): self {
		return new self( false, null, $code, $message );
	}

	/**
	 * Whether the transition succeeded.
	 */
	public function is_success(): bool {
		return $this->succeeded;
	}

	/**
	 * The fulfillment, in its post-transition state, if it succeeded.
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
