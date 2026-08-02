<?php
/**
 * Read-side facade for the operational Dashboard.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use DateTimeImmutable;
use MPCF\Domain\Clock;
use MPCF\Domain\FulfillmentQuery;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Workflow\State;
use MPCF\Domain\Workflow\WorkflowDefinition;

/**
 * Architecture Plan §9.3: an operational workspace, not an analytics page —
 * every method here answers "what needs attention now", never a historical
 * trend. Admin never calls {@see FulfillmentRepository}/{@see EventRepository}
 * directly (invariant I11, `AdminBoundaryGuardTest`).
 */
final class DashboardService {

	/**
	 * Fulfillment listing/counts.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * "Today" throughput counts.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Source of "now" for "today".
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Builds the service.
	 *
	 * @param FulfillmentRepository $fulfillments Fulfillment listing/counts.
	 * @param EventRepository       $events       "Today" throughput counts.
	 * @param Clock                 $clock        Source of "now" for "today".
	 */
	public function __construct( FulfillmentRepository $fulfillments, EventRepository $events, Clock $clock ) {
		$this->fulfillments = $fulfillments;
		$this->events       = $events;
		$this->clock        = $clock;
	}

	/**
	 * Problem/waiting fulfillments, oldest-in-state first.
	 *
	 * @param WorkflowDefinition $workflow Governing workflow.
	 * @param int                $limit    Maximum rows.
	 * @return list<\MPCF\Domain\Fulfillment>
	 */
	public function needs_attention( WorkflowDefinition $workflow, int $limit = 10 ): array {
		$states = $this->state_keys( $workflow, static fn( State $state ): bool => $state->is_exception() );

		if ( array() === $states ) {
			return array();
		}

		return $this->fulfillments->query( new FulfillmentQuery( $states, null, null, null, 'state_entered_at', 'ASC', 1, $limit ) )->items();
	}

	/**
	 * The oldest open fulfillments, created-oldest first.
	 *
	 * @param WorkflowDefinition $workflow Governing workflow.
	 * @param int                $limit    Maximum rows.
	 * @return list<\MPCF\Domain\Fulfillment>
	 */
	public function oldest_open( WorkflowDefinition $workflow, int $limit = 10 ): array {
		$states = $this->open_states( $workflow );

		if ( array() === $states ) {
			return array();
		}

		return $this->fulfillments->query( new FulfillmentQuery( $states, null, null, null, 'created_at', 'ASC', 1, $limit ) )->items();
	}

	/**
	 * Open fulfillments with no assignee, created-oldest first.
	 *
	 * @param WorkflowDefinition $workflow Governing workflow.
	 * @param int                $limit    Maximum rows.
	 * @return list<\MPCF\Domain\Fulfillment>
	 */
	public function unassigned( WorkflowDefinition $workflow, int $limit = 10 ): array {
		$states = $this->open_states( $workflow );

		if ( array() === $states ) {
			return array();
		}

		return $this->fulfillments->query( new FulfillmentQuery( $states, FulfillmentQuery::SENTINEL_UNASSIGNED, null, null, 'created_at', 'ASC', 1, $limit ) )->items();
	}

	/**
	 * Count of open fulfillments.
	 *
	 * @param WorkflowDefinition $workflow Governing workflow.
	 */
	public function open_count( WorkflowDefinition $workflow ): int {
		return $this->fulfillments->count_in_states( $this->open_states( $workflow ) );
	}

	/**
	 * Count of fulfillments currently in an exception state.
	 *
	 * @param WorkflowDefinition $workflow Governing workflow.
	 */
	public function exception_count( WorkflowDefinition $workflow ): int {
		return $this->fulfillments->count_in_states( $this->state_keys( $workflow, static fn( State $state ): bool => $state->is_exception() ) );
	}

	/**
	 * How many fulfillments were marked `packed` today.
	 */
	public function packed_today(): int {
		return $this->events->count_state_entries_since( 'packed', $this->today_start() );
	}

	/**
	 * How many fulfillments were marked `shipped` today.
	 */
	public function shipped_today(): int {
		return $this->events->count_state_entries_since( 'shipped', $this->today_start() );
	}

	/**
	 * Midnight, in the clock's own timezone.
	 */
	private function today_start(): DateTimeImmutable {
		return $this->clock->now()->setTime( 0, 0, 0 );
	}

	/**
	 * Every state key the Queue's default filter counts as open.
	 *
	 * @param WorkflowDefinition $workflow Governing workflow.
	 * @return list<string>
	 */
	private function open_states( WorkflowDefinition $workflow ): array {
		return $this->state_keys( $workflow, static fn( State $state ): bool => $state->counts_as_open() );
	}

	/**
	 * Every state key matching a predicate.
	 *
	 * @param WorkflowDefinition   $workflow Governing workflow.
	 * @param callable(State):bool $matches   Predicate a state must satisfy.
	 * @return list<string>
	 */
	private function state_keys( WorkflowDefinition $workflow, callable $matches ): array {
		return array_values(
			array_map(
				static fn( State $state ): string => $state->key(),
				array_filter( $workflow->states(), $matches )
			)
		);
	}
}
