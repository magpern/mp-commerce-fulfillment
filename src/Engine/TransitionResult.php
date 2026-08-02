<?php
/**
 * The outcome of one {@see WorkflowEngine::transition()} attempt.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine;

/**
 * Immutable value object. Carries no more than the Engine layer itself
 * decided: the new state and which domain event types the edge declares
 * when approved; a machine-readable code and a human-readable message when
 * rejected. Persisting the outcome, dispatching the declared events, and
 * appending audit entries are all the Application layer's job — this
 * object is purely a report of what the Engine decided.
 */
final class TransitionResult {

	/**
	 * Whether the transition was approved.
	 *
	 * @var bool
	 */
	private bool $approved;

	/**
	 * State entered, if approved.
	 *
	 * @var string|null
	 */
	private ?string $new_state;

	/**
	 * State being interrupted, if this approved transition entered an
	 * exception state.
	 *
	 * @var string|null
	 */
	private ?string $entering_exception_from;

	/**
	 * Domain event types the edge declares, if approved.
	 *
	 * @var list<string>
	 */
	private array $events;

	/**
	 * Machine-readable rejection code, if rejected.
	 *
	 * @var string|null
	 */
	private ?string $rejection_code;

	/**
	 * Human-readable rejection message, if rejected.
	 *
	 * @var string|null
	 */
	private ?string $rejection_message;

	/**
	 * Assembles a result. Use {@see approved()} or {@see rejected()} instead
	 * of calling this directly.
	 *
	 * @param bool               $approved                 Whether the transition was approved.
	 * @param string|null        $new_state                State entered, if approved.
	 * @param string|null        $entering_exception_from  State being interrupted, if any.
	 * @param array<int, string> $events                   Domain event types declared, if approved.
	 * @param string|null        $rejection_code           Machine-readable rejection code, if rejected.
	 * @param string|null        $rejection_message        Human-readable rejection message, if rejected.
	 */
	private function __construct(
		bool $approved,
		?string $new_state,
		?string $entering_exception_from,
		array $events,
		?string $rejection_code,
		?string $rejection_message
	) {
		$this->approved                = $approved;
		$this->new_state               = $new_state;
		$this->entering_exception_from = $entering_exception_from;
		$this->events                  = $events;
		$this->rejection_code          = $rejection_code;
		$this->rejection_message       = $rejection_message;
	}

	/**
	 * Builds an approved result.
	 *
	 * @param string             $new_state                State entered.
	 * @param string|null        $entering_exception_from  State being interrupted, if any.
	 * @param array<int, string> $events                   Domain event types the edge declares.
	 */
	public static function approved( string $new_state, ?string $entering_exception_from, array $events ): self {
		return new self( true, $new_state, $entering_exception_from, $events, null, null );
	}

	/**
	 * Builds a rejected result.
	 *
	 * @param string $code    Machine-readable rejection code (a guard id, or a structural code like "no_such_edge").
	 * @param string $message Human-readable rejection message.
	 */
	public static function rejected( string $code, string $message ): self {
		return new self( false, null, null, array(), $code, $message );
	}

	/**
	 * Whether the transition was approved.
	 */
	public function is_approved(): bool {
		return $this->approved;
	}

	/**
	 * State entered, if approved.
	 */
	public function new_state(): ?string {
		return $this->new_state;
	}

	/**
	 * State being interrupted, if this approved transition entered an
	 * exception state.
	 */
	public function entering_exception_from(): ?string {
		return $this->entering_exception_from;
	}

	/**
	 * Domain event types the edge declares, if approved.
	 *
	 * @return list<string>
	 */
	public function events(): array {
		return $this->events;
	}

	/**
	 * Machine-readable rejection code, if rejected.
	 */
	public function rejection_code(): ?string {
		return $this->rejection_code;
	}

	/**
	 * Human-readable rejection message, if rejected.
	 */
	public function rejection_message(): ?string {
		return $this->rejection_message;
	}
}
