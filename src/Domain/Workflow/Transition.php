<?php
/**
 * A single allowed edge between two workflow states.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Workflow;

/**
 * Immutable value object describing one allowed `(from, to)` edge.
 *
 * Guards are ordered predicate identifiers (resolved to real
 * `TransitionGuard` implementations by the Engine layer, which is also
 * where they run), not callables — this keeps the value object itself pure
 * data, so it round-trips through `from_array()`/`to_array()` cleanly like
 * every other workflow value object.
 */
final class Transition {

	/**
	 * Origin state key.
	 *
	 * @var string
	 */
	private string $from;

	/**
	 * Destination state key.
	 *
	 * @var string
	 */
	private string $to;

	/**
	 * Capability an actor must hold to take this edge.
	 *
	 * @var string
	 */
	private string $required_capability;

	/**
	 * Whether taking this edge requires an audited free-text reason.
	 *
	 * @var bool
	 */
	private bool $requires_reason;

	/**
	 * Ordered guard identifiers, resolved by the Engine layer.
	 *
	 * @var list<string>
	 */
	private array $guards;

	/**
	 * Domain event types this edge dispatches when taken.
	 *
	 * @var list<string>
	 */
	private array $events;

	/**
	 * Assembles a transition. Use {@see from_array()} to build one from data.
	 *
	 * @param string             $from                Origin state key.
	 * @param string             $to                  Destination state key.
	 * @param string             $required_capability Capability required to take this edge.
	 * @param bool               $requires_reason     Whether an audited reason is required.
	 * @param array<int, string> $guards              Ordered guard identifiers.
	 * @param array<int, string> $events              Domain event types dispatched when taken.
	 */
	private function __construct(
		string $from,
		string $to,
		string $required_capability,
		bool $requires_reason,
		array $guards,
		array $events
	) {
		$this->from                = $from;
		$this->to                  = $to;
		$this->required_capability = $required_capability;
		$this->requires_reason     = $requires_reason;
		$this->guards              = $guards;
		$this->events              = $events;
	}

	/**
	 * Builds a transition from its array shape.
	 *
	 * @param array{from:string,to:string,required_capability:string,requires_reason?:bool,guards?:list<string>,events?:list<string>} $data Array shape produced by {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) $data['from'],
			(string) $data['to'],
			(string) $data['required_capability'],
			(bool) ( $data['requires_reason'] ?? false ),
			array_values( $data['guards'] ?? array() ),
			array_values( $data['events'] ?? array() )
		);
	}

	/**
	 * The array shape {@see from_array()} rebuilds from.
	 *
	 * @return array{from:string,to:string,required_capability:string,requires_reason:bool,guards:list<string>,events:list<string>}
	 */
	public function to_array(): array {
		return array(
			'from'                => $this->from,
			'to'                  => $this->to,
			'required_capability' => $this->required_capability,
			'requires_reason'     => $this->requires_reason,
			'guards'              => $this->guards,
			'events'              => $this->events,
		);
	}

	/**
	 * Origin state key.
	 */
	public function from(): string {
		return $this->from;
	}

	/**
	 * Destination state key.
	 */
	public function to(): string {
		return $this->to;
	}

	/**
	 * Capability an actor must hold to take this edge.
	 */
	public function required_capability(): string {
		return $this->required_capability;
	}

	/**
	 * Whether taking this edge requires an audited free-text reason.
	 */
	public function requires_reason(): bool {
		return $this->requires_reason;
	}

	/**
	 * Ordered guard identifiers.
	 *
	 * @return list<string>
	 */
	public function guards(): array {
		return $this->guards;
	}

	/**
	 * Domain event types this edge dispatches when taken.
	 *
	 * @return list<string>
	 */
	public function events(): array {
		return $this->events;
	}
}
