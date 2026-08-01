<?php
/**
 * A single workflow state and its display/behavior metadata.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Workflow;

/**
 * Immutable value object describing one state in a {@see WorkflowDefinition}.
 *
 * No native PHP enum (house convention) — state identifiers are plain
 * strings so a custom workflow can introduce a state the engine has never
 * heard of, without a code change. `type` classifies the state generically
 * (initial/working/exception/terminal) so engine and admin-screen behavior
 * never hardcodes a specific state name.
 */
final class State {

	/**
	 * The single state a fulfillment starts in.
	 */
	public const TYPE_INITIAL = 'initial';

	/**
	 * A state an operator actively works through.
	 */
	public const TYPE_WORKING = 'working';

	/**
	 * A state something interrupted normal progress into.
	 */
	public const TYPE_EXCEPTION = 'exception';

	/**
	 * A state nothing transitions out of.
	 */
	public const TYPE_TERMINAL = 'terminal';

	/**
	 * Stable identifier, e.g. "picking".
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * A design-system status-badge variant name.
	 *
	 * @var string
	 */
	private string $badge_variant;

	/**
	 * One of the `TYPE_*` constants.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Whether the Queue's default filter should include fulfillments in
	 * this state.
	 *
	 * @var bool
	 */
	private bool $counts_as_open;

	/**
	 * Whether this state implies an assigned operator.
	 *
	 * @var bool
	 */
	private bool $expects_operator;

	/**
	 * Assembles a state. Use {@see from_array()} to build one from data.
	 *
	 * @param string $key              Stable identifier.
	 * @param string $label            Human-readable label.
	 * @param string $badge_variant    Status-badge variant name.
	 * @param string $type             One of the `TYPE_*` constants.
	 * @param bool   $counts_as_open   Whether the Queue's default filter includes this state.
	 * @param bool   $expects_operator Whether this state implies an assigned operator.
	 */
	private function __construct(
		string $key,
		string $label,
		string $badge_variant,
		string $type,
		bool $counts_as_open,
		bool $expects_operator
	) {
		$this->key              = $key;
		$this->label            = $label;
		$this->badge_variant    = $badge_variant;
		$this->type             = $type;
		$this->counts_as_open   = $counts_as_open;
		$this->expects_operator = $expects_operator;
	}

	/**
	 * Builds a state from its array shape.
	 *
	 * @param array{key:string,label:string,badge_variant?:string,type:string,counts_as_open?:bool,expects_operator?:bool} $data Array shape produced by {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) $data['key'],
			(string) $data['label'],
			(string) ( $data['badge_variant'] ?? 'disabled' ),
			(string) $data['type'],
			(bool) ( $data['counts_as_open'] ?? false ),
			(bool) ( $data['expects_operator'] ?? false )
		);
	}

	/**
	 * The array shape {@see from_array()} rebuilds from.
	 *
	 * @return array{key:string,label:string,badge_variant:string,type:string,counts_as_open:bool,expects_operator:bool}
	 */
	public function to_array(): array {
		return array(
			'key'              => $this->key,
			'label'            => $this->label,
			'badge_variant'    => $this->badge_variant,
			'type'             => $this->type,
			'counts_as_open'   => $this->counts_as_open,
			'expects_operator' => $this->expects_operator,
		);
	}

	/**
	 * Stable identifier, e.g. "picking".
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Human-readable label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * A design-system status-badge variant name.
	 */
	public function badge_variant(): string {
		return $this->badge_variant;
	}

	/**
	 * One of the `TYPE_*` constants.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Whether this is the workflow's single initial state.
	 */
	public function is_initial(): bool {
		return self::TYPE_INITIAL === $this->type;
	}

	/**
	 * Whether nothing transitions out of this state.
	 */
	public function is_terminal(): bool {
		return self::TYPE_TERMINAL === $this->type;
	}

	/**
	 * Whether this state represents an interruption to normal progress.
	 */
	public function is_exception(): bool {
		return self::TYPE_EXCEPTION === $this->type;
	}

	/**
	 * Whether the Queue's default filter should include this state.
	 */
	public function counts_as_open(): bool {
		return $this->counts_as_open;
	}

	/**
	 * Whether this state implies an assigned operator.
	 */
	public function expects_operator(): bool {
		return $this->expects_operator;
	}
}
