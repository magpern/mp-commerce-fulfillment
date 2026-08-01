<?php
/**
 * An immutable, named, versioned workflow: states, transitions, and the
 * state a new fulfillment starts in.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Workflow;

use InvalidArgumentException;

/**
 * Data, not behavior — the Engine layer interprets a definition; this class
 * only assembles and structurally validates its own shape:
 *
 * - exactly one state of type `initial`, matching the declared
 *   `initial_state`;
 * - at least one state of type `terminal`;
 * - every transition endpoint refers to a declared state;
 * - no orphan state (every state is either the initial state or some
 *   transition's `to`).
 *
 * Resolving an exception state back to whatever state it interrupted is
 * deliberately **not** a static edge here — the target is a specific
 * fulfillment's `return_to_state`, not workflow-wide data, so the Engine
 * layer handles that resolution dynamically rather than this class
 * enumerating every exception-state-to-every-possible-origin combination.
 */
final class WorkflowDefinition {

	/**
	 * Stable identifier, e.g. "standard".
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Definition version — bump when transitions/guards change shape.
	 *
	 * @var int
	 */
	private int $version;

	/**
	 * The state key a new fulfillment starts in.
	 *
	 * @var string
	 */
	private string $initial_state;

	/**
	 * Declared states, keyed by key.
	 *
	 * @var array<string, State>
	 */
	private array $states;

	/**
	 * Allowed edges.
	 *
	 * @var list<Transition>
	 */
	private array $transitions;

	/**
	 * Assembles a definition. Use {@see from_array()}, which also validates
	 * the result — this constructor does not.
	 *
	 * @param string                 $name          Stable identifier.
	 * @param int                    $version       Definition version.
	 * @param string                 $initial_state State key a new fulfillment starts in.
	 * @param array<string, State>   $states        States keyed by key.
	 * @param array<int, Transition> $transitions   Allowed edges.
	 */
	private function __construct( string $name, int $version, string $initial_state, array $states, array $transitions ) {
		$this->name          = $name;
		$this->version       = $version;
		$this->initial_state = $initial_state;
		$this->states        = $states;
		$this->transitions   = $transitions;
	}

	/**
	 * Builds and structurally validates a definition from its array shape.
	 *
	 * @param array{name:string,version:int,initial_state:string,states:list<array<string,mixed>>,transitions:list<array<string,mixed>>} $data Array shape produced by {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		$states = array();

		foreach ( $data['states'] as $state_data ) {
			$state                   = State::from_array( $state_data );
			$states[ $state->key() ] = $state;
		}

		$transitions = array_map(
			static fn( array $transition_data ): Transition => Transition::from_array( $transition_data ),
			$data['transitions']
		);

		$definition = new self(
			(string) $data['name'],
			(int) $data['version'],
			(string) $data['initial_state'],
			$states,
			$transitions
		);

		$definition->validate();

		return $definition;
	}

	/**
	 * The array shape {@see from_array()} rebuilds from.
	 *
	 * @return array{name:string,version:int,initial_state:string,states:list<array<string,mixed>>,transitions:list<array<string,mixed>>}
	 */
	public function to_array(): array {
		return array(
			'name'          => $this->name,
			'version'       => $this->version,
			'initial_state' => $this->initial_state,
			'states'        => array_map( static fn( State $state ): array => $state->to_array(), array_values( $this->states ) ),
			'transitions'   => array_map( static fn( Transition $transition ): array => $transition->to_array(), $this->transitions ),
		);
	}

	/**
	 * Stable identifier, e.g. "standard".
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Definition version.
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * The state key a new fulfillment starts in.
	 */
	public function initial_state(): string {
		return $this->initial_state;
	}

	/**
	 * Whether a state key is declared in this definition.
	 *
	 * @param string $key State key.
	 */
	public function has_state( string $key ): bool {
		return isset( $this->states[ $key ] );
	}

	/**
	 * A declared state by key.
	 *
	 * @param string $key State key.
	 * @throws InvalidArgumentException When `$key` is not declared.
	 */
	public function state( string $key ): State {
		if ( ! isset( $this->states[ $key ] ) ) {
			throw new InvalidArgumentException( "Unknown state \"{$key}\" in workflow \"{$this->name}\"." );
		}

		return $this->states[ $key ];
	}

	/**
	 * Every declared state.
	 *
	 * @return list<State>
	 */
	public function states(): array {
		return array_values( $this->states );
	}

	/**
	 * The transition from `$from` to `$to`, or null if that edge does not
	 * exist in this definition.
	 *
	 * @param string $from Origin state key.
	 * @param string $to   Destination state key.
	 */
	public function transition( string $from, string $to ): ?Transition {
		foreach ( $this->transitions as $transition ) {
			if ( $transition->from() === $from && $transition->to() === $to ) {
				return $transition;
			}
		}

		return null;
	}

	/**
	 * Every transition whose `from` matches, in declaration order — the
	 * available next steps for a fulfillment currently in `$from`.
	 *
	 * @param string $from Origin state key.
	 * @return list<Transition>
	 */
	public function transitions_from( string $from ): array {
		return array_values(
			array_filter(
				$this->transitions,
				static fn( Transition $transition ): bool => $transition->from() === $from
			)
		);
	}

	/**
	 * Structural validation described in this class's docblock.
	 *
	 * @throws InvalidArgumentException When the definition is structurally invalid.
	 */
	private function validate(): void {
		$initial_states = array_values( array_filter( $this->states, static fn( State $state ): bool => $state->is_initial() ) );

		if ( 1 !== count( $initial_states ) ) {
			throw new InvalidArgumentException(
				"Workflow \"{$this->name}\" must declare exactly one initial state; found " . count( $initial_states ) . '.'
			);
		}

		if ( $initial_states[0]->key() !== $this->initial_state ) {
			throw new InvalidArgumentException(
				"Workflow \"{$this->name}\"'s declared initial_state must match its single initial-type state."
			);
		}

		if ( array() === array_filter( $this->states, static fn( State $state ): bool => $state->is_terminal() ) ) {
			throw new InvalidArgumentException( "Workflow \"{$this->name}\" must declare at least one terminal state." );
		}

		$reachable = array( $this->initial_state => true );

		foreach ( $this->transitions as $transition ) {
			foreach ( array( $transition->from(), $transition->to() ) as $endpoint ) {
				if ( ! $this->has_state( $endpoint ) ) {
					throw new InvalidArgumentException(
						"Workflow \"{$this->name}\" has a transition referencing undeclared state \"{$endpoint}\"."
					);
				}
			}

			$reachable[ $transition->to() ] = true;
		}

		foreach ( array_keys( $this->states ) as $key ) {
			if ( ! isset( $reachable[ $key ] ) ) {
				throw new InvalidArgumentException(
					"Workflow \"{$this->name}\" declares orphan state \"{$key}\" reachable by no transition."
				);
			}
		}
	}
}
