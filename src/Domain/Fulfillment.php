<?php
/**
 * The fulfillment aggregate root.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

use DateTimeImmutable;

/**
 * A paid order, ingested into its own record with a workflow state, an
 * optimistic-lock version, and a handful of display-only snapshot fields.
 * The order itself is never mutated here or by anything that touches this
 * object — this aggregate is authoritative for the *warehouse* lifecycle
 * only; the order platform stays authoritative for the money lifecycle.
 * The order is referenced by id and read live through a port owned by the
 * platform-integration layer whenever anything beyond a display hint is
 * needed.
 *
 * State is never written by any method here beyond {@see apply_transition()}
 * and {@see assign()}/{@see unassign()}/{@see set_priority()} — approving
 * *whether* a transition is allowed is the Engine layer's job entirely;
 * this class only records the outcome once approved. `version` is bumped
 * by {@see increment_version()}, called by the persistence layer after a
 * successful optimistic-lock write, so the in-memory copy never disagrees
 * with what was actually saved.
 */
final class Fulfillment {

	/**
	 * Own id, or null before the repository assigns one.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * The order this fulfillment was ingested from.
	 *
	 * @var int
	 */
	private int $order_id;

	/**
	 * Identifies which order platform `order_id` belongs to. Fixed to a
	 * single value in Milestone 1; the field exists so the `OrderSource`
	 * port is architecturally ready for more than one, without a schema
	 * change later.
	 *
	 * @var string
	 */
	private string $order_source;

	/**
	 * Warehouse this fulfillment belongs to. Defaults to `1` until a
	 * multi-warehouse milestone introduces a real location hierarchy.
	 *
	 * @var int
	 */
	private int $warehouse_id;

	/**
	 * Which {@see \MPCF\Domain\Workflow\WorkflowDefinition} governs this
	 * fulfillment's `state`.
	 *
	 * @var string
	 */
	private string $workflow;

	/**
	 * Current state key.
	 *
	 * @var string
	 */
	private string $state;

	/**
	 * The state this fulfillment was in immediately before `state`, or null
	 * for a fulfillment that has never transitioned.
	 *
	 * @var string|null
	 */
	private ?string $previous_state;

	/**
	 * If `state` is an exception state, the working state it interrupted —
	 * restored verbatim when the exception resolves. Null otherwise.
	 *
	 * @var string|null
	 */
	private ?string $return_to_state;

	/**
	 * Free-text reason recorded for the current exception or cancellation,
	 * if any.
	 *
	 * @var string|null
	 */
	private ?string $exception_reason;

	/**
	 * Manual priority ranking; higher sorts first in the Queue.
	 *
	 * @var int
	 */
	private int $priority;

	/**
	 * Assignee kind — `"user"` in Milestone 1; future values are registry
	 * data, not a schema change.
	 *
	 * @var string|null
	 */
	private ?string $assignee_type;

	/**
	 * Assignee id within `assignee_type`.
	 *
	 * @var int|null
	 */
	private ?int $assignee_id;

	/**
	 * Optimistic-lock version. Every write conditions on this value.
	 *
	 * @var int
	 */
	private int $version;

	/**
	 * Order number, snapshotted at intake — a display hint only.
	 *
	 * @var string
	 */
	private string $order_number_snapshot;

	/**
	 * Customer name, snapshotted at intake — a display hint only.
	 *
	 * @var string
	 */
	private string $customer_name_snapshot;

	/**
	 * Line item count, snapshotted at intake — a display hint only.
	 *
	 * @var int
	 */
	private int $item_count;

	/**
	 * When this fulfillment was created.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $created_at;

	/**
	 * When `state` was last set.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $state_entered_at;

	/**
	 * When this fulfillment first reached a `completed` state, if it has.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $completed_at;

	/**
	 * Assembles a fulfillment. Use {@see intake()} or {@see from_array()}
	 * instead of calling this directly.
	 *
	 * @param int|null               $id                      Own id, or null before insert.
	 * @param int                    $order_id                Origin order id.
	 * @param string                 $order_source            Origin order platform identifier.
	 * @param int                    $warehouse_id            Owning warehouse id.
	 * @param string                 $workflow                Governing workflow name.
	 * @param string                 $state                   Current state key.
	 * @param string|null            $previous_state          State immediately before `state`.
	 * @param string|null            $return_to_state         Interrupted state, if `state` is an exception.
	 * @param string|null            $exception_reason        Reason for the current exception/cancellation.
	 * @param int                    $priority                Manual priority ranking.
	 * @param string|null            $assignee_type           Assignee kind.
	 * @param int|null               $assignee_id             Assignee id within `assignee_type`.
	 * @param int                    $version                 Optimistic-lock version.
	 * @param string                 $order_number_snapshot   Order number snapshot.
	 * @param string                 $customer_name_snapshot  Customer name snapshot.
	 * @param int                    $item_count              Line item count snapshot.
	 * @param DateTimeImmutable      $created_at              Creation time.
	 * @param DateTimeImmutable      $state_entered_at        When `state` was last set.
	 * @param DateTimeImmutable|null $completed_at            First-completed time, if any.
	 */
	private function __construct(
		?int $id,
		int $order_id,
		string $order_source,
		int $warehouse_id,
		string $workflow,
		string $state,
		?string $previous_state,
		?string $return_to_state,
		?string $exception_reason,
		int $priority,
		?string $assignee_type,
		?int $assignee_id,
		int $version,
		string $order_number_snapshot,
		string $customer_name_snapshot,
		int $item_count,
		DateTimeImmutable $created_at,
		DateTimeImmutable $state_entered_at,
		?DateTimeImmutable $completed_at
	) {
		$this->id                     = $id;
		$this->order_id               = $order_id;
		$this->order_source           = $order_source;
		$this->warehouse_id           = $warehouse_id;
		$this->workflow               = $workflow;
		$this->state                  = $state;
		$this->previous_state         = $previous_state;
		$this->return_to_state        = $return_to_state;
		$this->exception_reason       = $exception_reason;
		$this->priority               = $priority;
		$this->assignee_type          = $assignee_type;
		$this->assignee_id            = $assignee_id;
		$this->version                = $version;
		$this->order_number_snapshot  = $order_number_snapshot;
		$this->customer_name_snapshot = $customer_name_snapshot;
		$this->item_count             = $item_count;
		$this->created_at             = $created_at;
		$this->state_entered_at       = $state_entered_at;
		$this->completed_at           = $completed_at;
	}

	/**
	 * Builds a brand-new fulfillment at intake time — no id yet (the
	 * repository assigns one on insert), version 1, in the workflow's
	 * initial state.
	 *
	 * @param int               $order_id               Origin order id.
	 * @param string            $order_source           Origin order platform identifier.
	 * @param int               $warehouse_id           Owning warehouse id.
	 * @param string            $workflow               Governing workflow name.
	 * @param string            $initial_state          The workflow's initial state key.
	 * @param string            $order_number_snapshot  Order number snapshot.
	 * @param string            $customer_name_snapshot Customer name snapshot.
	 * @param int               $item_count             Line item count snapshot.
	 * @param DateTimeImmutable $now                    Current time.
	 */
	public static function intake(
		int $order_id,
		string $order_source,
		int $warehouse_id,
		string $workflow,
		string $initial_state,
		string $order_number_snapshot,
		string $customer_name_snapshot,
		int $item_count,
		DateTimeImmutable $now
	): self {
		return new self(
			null,
			$order_id,
			$order_source,
			$warehouse_id,
			$workflow,
			$initial_state,
			null,
			null,
			null,
			0,
			null,
			null,
			1,
			$order_number_snapshot,
			$customer_name_snapshot,
			$item_count,
			$now,
			$now,
			null
		);
	}

	/**
	 * Reconstructs a fulfillment from already-typed data (the repository's
	 * job is converting a raw database row into these types; this factory
	 * assembles them, it does not parse them).
	 *
	 * @param array<string, mixed> $data Array shape produced by {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['id'] ) ? (int) $data['id'] : null,
			(int) $data['order_id'],
			(string) $data['order_source'],
			(int) $data['warehouse_id'],
			(string) $data['workflow'],
			(string) $data['state'],
			isset( $data['previous_state'] ) ? (string) $data['previous_state'] : null,
			isset( $data['return_to_state'] ) ? (string) $data['return_to_state'] : null,
			isset( $data['exception_reason'] ) ? (string) $data['exception_reason'] : null,
			(int) $data['priority'],
			isset( $data['assignee_type'] ) ? (string) $data['assignee_type'] : null,
			isset( $data['assignee_id'] ) ? (int) $data['assignee_id'] : null,
			(int) $data['version'],
			(string) $data['order_number_snapshot'],
			(string) $data['customer_name_snapshot'],
			(int) $data['item_count'],
			$data['created_at'],
			$data['state_entered_at'],
			$data['completed_at'] ?? null
		);
	}

	/**
	 * The array shape {@see from_array()} rebuilds from.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                     => $this->id,
			'order_id'               => $this->order_id,
			'order_source'           => $this->order_source,
			'warehouse_id'           => $this->warehouse_id,
			'workflow'               => $this->workflow,
			'state'                  => $this->state,
			'previous_state'         => $this->previous_state,
			'return_to_state'        => $this->return_to_state,
			'exception_reason'       => $this->exception_reason,
			'priority'               => $this->priority,
			'assignee_type'          => $this->assignee_type,
			'assignee_id'            => $this->assignee_id,
			'version'                => $this->version,
			'order_number_snapshot'  => $this->order_number_snapshot,
			'customer_name_snapshot' => $this->customer_name_snapshot,
			'item_count'             => $this->item_count,
			'created_at'             => $this->created_at,
			'state_entered_at'       => $this->state_entered_at,
			'completed_at'           => $this->completed_at,
		);
	}

	/**
	 * Own id, or null before the repository assigns one.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * The order this fulfillment was ingested from.
	 */
	public function order_id(): int {
		return $this->order_id;
	}

	/**
	 * Identifies which order platform `order_id` belongs to.
	 */
	public function order_source(): string {
		return $this->order_source;
	}

	/**
	 * Warehouse this fulfillment belongs to.
	 */
	public function warehouse_id(): int {
		return $this->warehouse_id;
	}

	/**
	 * Which workflow governs this fulfillment's state.
	 */
	public function workflow(): string {
		return $this->workflow;
	}

	/**
	 * Current state key.
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * The state this fulfillment was in immediately before `state`.
	 */
	public function previous_state(): ?string {
		return $this->previous_state;
	}

	/**
	 * If `state` is an exception state, the working state it interrupted.
	 */
	public function return_to_state(): ?string {
		return $this->return_to_state;
	}

	/**
	 * Free-text reason recorded for the current exception or cancellation.
	 */
	public function exception_reason(): ?string {
		return $this->exception_reason;
	}

	/**
	 * Manual priority ranking.
	 */
	public function priority(): int {
		return $this->priority;
	}

	/**
	 * Assignee kind.
	 */
	public function assignee_type(): ?string {
		return $this->assignee_type;
	}

	/**
	 * Assignee id within `assignee_type`.
	 */
	public function assignee_id(): ?int {
		return $this->assignee_id;
	}

	/**
	 * Optimistic-lock version.
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Order number, snapshotted at intake.
	 */
	public function order_number_snapshot(): string {
		return $this->order_number_snapshot;
	}

	/**
	 * Customer name, snapshotted at intake.
	 */
	public function customer_name_snapshot(): string {
		return $this->customer_name_snapshot;
	}

	/**
	 * Line item count, snapshotted at intake.
	 */
	public function item_count(): int {
		return $this->item_count;
	}

	/**
	 * When this fulfillment was created.
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * When `state` was last set.
	 */
	public function state_entered_at(): DateTimeImmutable {
		return $this->state_entered_at;
	}

	/**
	 * When this fulfillment first reached a `completed` state, if it has.
	 */
	public function completed_at(): ?DateTimeImmutable {
		return $this->completed_at;
	}

	/**
	 * Records an already-approved transition (the Engine layer decides
	 * whether `$new_state` is reachable; this method only records that
	 * decision). `$entering_exception_from` carries the state being
	 * interrupted so it can be restored verbatim once the exception
	 * resolves; a transition that is itself a resolution passes null,
	 * clearing `return_to_state`.
	 *
	 * @param string            $new_state                State being entered.
	 * @param string|null       $entering_exception_from  State being interrupted, if any.
	 * @param DateTimeImmutable $now                       Current time.
	 */
	public function apply_transition( string $new_state, ?string $entering_exception_from, DateTimeImmutable $now ): void {
		$this->previous_state   = $this->state;
		$this->state            = $new_state;
		$this->return_to_state  = $entering_exception_from;
		$this->exception_reason = null;
		$this->state_entered_at = $now;

		if ( null === $entering_exception_from && null === $this->completed_at && 'completed' === $new_state ) {
			$this->completed_at = $now;
		}
	}

	/**
	 * Records the reason for the current exception or cancellation.
	 *
	 * @param string $reason Free-text reason.
	 */
	public function set_exception_reason( string $reason ): void {
		$this->exception_reason = $reason;
	}

	/**
	 * Assigns this fulfillment to an operator.
	 *
	 * @param string $type Assignee kind.
	 * @param int    $id   Assignee id within `$type`.
	 */
	public function assign( string $type, int $id ): void {
		$this->assignee_type = $type;
		$this->assignee_id   = $id;
	}

	/**
	 * Clears any assignment.
	 */
	public function unassign(): void {
		$this->assignee_type = null;
		$this->assignee_id   = null;
	}

	/**
	 * Sets the manual priority ranking.
	 *
	 * @param int $priority New priority.
	 */
	public function set_priority( int $priority ): void {
		$this->priority = $priority;
	}

	/**
	 * Called by the persistence layer after a successful optimistic-lock
	 * write, so the in-memory copy tracks what was actually saved.
	 */
	public function increment_version(): void {
		++$this->version;
	}
}
