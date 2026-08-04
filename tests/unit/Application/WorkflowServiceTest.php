<?php
/**
 * Tests for the sole fulfillment-state writer.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application;

use DateTimeImmutable;
use MPCF\Application\AvailableTransition;
use MPCF\Application\EventDispatcher;
use MPCF\Application\TransitionContextFactory;
use MPCF\Application\WorkflowService;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\PackageSpec;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Settings;
use MPCF\Engine\WorkflowEngine;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use MPCF\Tests\Unit\Application\Doubles\RecordingSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sole fulfillment-state writer.
 */
final class WorkflowServiceTest extends TestCase {

	/**
	 * @var InMemoryFulfillmentRepository
	 */
	private InMemoryFulfillmentRepository $fulfillments;

	/**
	 * @var InMemoryFulfillmentItemRepository
	 */
	private InMemoryFulfillmentItemRepository $items;

	/**
	 * @var InMemoryShipmentRepository
	 */
	private InMemoryShipmentRepository $shipments;

	/**
	 * @var InMemoryPackageRepository
	 */
	private InMemoryPackageRepository $packages;

	/**
	 * @var InMemoryEventRepository
	 */
	private InMemoryEventRepository $events;

	/**
	 * @var EventDispatcher
	 */
	private EventDispatcher $dispatcher;

	/**
	 * @var FixedClock
	 */
	private FixedClock $clock;

	/**
	 * @var WorkflowService
	 */
	private WorkflowService $service;

	protected function setUp(): void {
		$this->fulfillments = new InMemoryFulfillmentRepository();
		$this->items        = new InMemoryFulfillmentItemRepository();
		$this->shipments    = new InMemoryShipmentRepository();
		$this->packages     = new InMemoryPackageRepository();
		$this->events       = new InMemoryEventRepository();
		$this->dispatcher   = new EventDispatcher();
		$this->clock        = new FixedClock( new DateTimeImmutable( '2026-08-02 10:00:00' ) );

		$this->service = new WorkflowService(
			$this->fulfillments,
			$this->events,
			new WorkflowEngine( GuardRegistry::standard() ),
			$this->dispatcher,
			$this->clock,
			array( StandardWorkflow::NAME => StandardWorkflow::definition() ),
			new TransitionContextFactory( $this->items, $this->shipments, $this->packages, new Settings( array() ) )
		);
	}

	private function seed_fulfillment( string $state = 'queued' ): int {
		$fulfillment = Fulfillment::intake( 1001, 'woocommerce', 1, StandardWorkflow::NAME, 'queued', '#1001', 'Jane Doe', 1, new DateTimeImmutable( '2026-08-01 09:00:00' ) );
		$id          = $this->fulfillments->insert( $fulfillment );

		if ( 'queued' !== $state ) {
			$stored = $this->fulfillments->find( $id );
			$stored->apply_transition( $state, null, new DateTimeImmutable() );
			$this->fulfillments->save( $stored );
		}

		return $id;
	}

	public function test_a_guardless_transition_succeeds_and_persists_the_new_state(): void {
		$id = $this->seed_fulfillment();

		$outcome = $this->service->transition( $id, 'picking', Actor::user( 7, 'Jane (Operator)' ) );

		self::assertTrue( $outcome->is_success() );
		self::assertSame( 'picking', $outcome->fulfillment()->state() );
		self::assertSame( 'picking', $this->fulfillments->find( $id )->state() );
	}

	public function test_a_successful_transition_increments_the_stored_version(): void {
		$id = $this->seed_fulfillment();

		$this->service->transition( $id, 'picking', Actor::system() );

		self::assertSame( 2, $this->fulfillments->find( $id )->version() );
	}

	public function test_a_successful_transition_appends_a_hash_chained_audit_event(): void {
		$id = $this->seed_fulfillment();

		$this->service->transition( $id, 'picking', Actor::user( 7, 'Jane' ) );

		$timeline = $this->events->timeline_for_fulfillment( $id );

		self::assertCount( 1, $timeline );
		self::assertSame( 'fulfillment.state_changed', $timeline[0]['event_type'] );
		self::assertSame( 'queued', $timeline[0]['payload']['from'] );
		self::assertSame( 'picking', $timeline[0]['payload']['to'] );
		self::assertNull( $timeline[0]['prev_hash'] );
		self::assertSame( 'user', $timeline[0]['actor_type'] );
		self::assertSame( 7, $timeline[0]['actor_id'] );
	}

	public function test_a_rejected_transition_after_a_successful_one_appends_nothing_further(): void {
		$id = $this->seed_fulfillment();
		$this->items->insert_all( array( FulfillmentItem::intake( $id, 1, 1, 0, 'SKU', 'Widget', 2 ) ) );

		$this->service->transition( $id, 'picking', Actor::system() );
		$this->service->transition( $id, 'picked', Actor::system() ); // Blocked by all_items_picked — nothing picked yet.

		self::assertCount( 1, $this->events->timeline_for_fulfillment( $id ) );
	}

	public function test_events_across_multiple_successful_transitions_chain_by_hash(): void {
		$id = $this->seed_fulfillment();

		$this->service->transition( $id, 'picking', Actor::system() );

		$item = FulfillmentItem::intake( $id, 1, 1, 0, 'SKU', 'Widget', 1 );
		$item->record_picked( 1 );
		$this->items->insert_all( array( $item ) );

		$this->service->transition( $id, 'picked', Actor::system() );

		$timeline = $this->events->timeline_for_fulfillment( $id );

		self::assertCount( 2, $timeline );
		self::assertSame( $timeline[0]['hash'], $timeline[1]['prev_hash'] );
	}

	public function test_dispatches_every_declared_event_to_the_dispatcher(): void {
		$subscriber = new RecordingSubscriber();
		$this->dispatcher->subscribe( 'fulfillment.state_changed', $subscriber );

		$id = $this->seed_fulfillment();
		$this->service->transition( $id, 'picking', Actor::system() );

		self::assertCount( 1, $subscriber->received() );
	}

	public function test_fails_with_fulfillment_not_found_for_an_unknown_id(): void {
		$outcome = $this->service->transition( 999999, 'picking', Actor::system() );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'fulfillment_not_found', $outcome->failure_code() );
		self::assertNull( $outcome->fulfillment() );
	}

	public function test_fails_with_unknown_workflow_when_the_fulfillments_workflow_is_not_registered(): void {
		$service = new WorkflowService(
			$this->fulfillments,
			$this->events,
			new WorkflowEngine( GuardRegistry::standard() ),
			$this->dispatcher,
			$this->clock,
			array(), // No workflows registered.
			new TransitionContextFactory( $this->items, $this->shipments, $this->packages, new Settings( array() ) )
		);

		$id      = $this->seed_fulfillment();
		$outcome = $service->transition( $id, 'picking', Actor::system() );

		self::assertSame( 'unknown_workflow', $outcome->failure_code() );
	}

	public function test_fails_with_the_guard_id_when_a_guard_blocks_the_transition_and_persists_nothing(): void {
		$id = $this->seed_fulfillment( 'picking' );
		$this->items->insert_all( array( FulfillmentItem::intake( $id, 1, 1, 0, 'SKU', 'Widget', 2 ) ) );

		$outcome = $this->service->transition( $id, 'picked', Actor::system() ); // Nothing picked yet.

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'all_items_picked', $outcome->failure_code() );
		self::assertSame( 'picking', $this->fulfillments->find( $id )->state(), 'A rejected transition must not change the stored state.' );
		self::assertSame( array(), $this->events->timeline_for_fulfillment( $id ), 'A rejected transition must not append any audit event.' );
	}

	public function test_an_unsafe_reason_rejects_the_whole_transition_and_persists_nothing(): void {
		$id             = $this->seed_fulfillment( 'picking' );
		$version_before = $this->fulfillments->find( $id )->version();

		$outcome = $this->service->transition( $id, 'problem', Actor::system(), 'Contact the customer at jane@example.com about this.' );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'unsafe_event_payload', $outcome->failure_code() );
		self::assertSame( 'picking', $this->fulfillments->find( $id )->state(), 'An unsafe payload must not change the stored state.' );
		self::assertSame( $version_before, $this->fulfillments->find( $id )->version(), 'An unsafe payload must not advance the stored version.' );
		self::assertSame( array(), $this->events->timeline_for_fulfillment( $id ), 'An unsafe payload must not append any audit event.' );
	}

	public function test_a_reason_is_persisted_alongside_an_exception_transition(): void {
		$id = $this->seed_fulfillment( 'picking' );

		$outcome = $this->service->transition( $id, 'problem', Actor::system(), 'Customer address dispute.' );

		self::assertTrue( $outcome->is_success() );
		self::assertSame( 'Customer address dispute.', $this->fulfillments->find( $id )->exception_reason() );

		$timeline = $this->events->timeline_for_fulfillment( $id );
		self::assertSame( 'Customer address dispute.', $timeline[0]['payload']['reason'] );
	}

	public function test_the_double_rejects_a_save_from_a_stale_copy_loaded_before_a_concurrent_write(): void {
		$id = $this->seed_fulfillment();

		// Two independent copies of the same row, simulating two concurrent
		// requests both starting from version 1 — find() hydrates a fresh
		// instance per call, exactly like the real repository.
		$first_copy  = $this->fulfillments->find( $id );
		$second_copy = $this->fulfillments->find( $id );

		$first_copy->apply_transition( 'picking', null, new DateTimeImmutable() );
		self::assertTrue( $this->fulfillments->save( $first_copy ) );

		$second_copy->apply_transition( 'packing', null, new DateTimeImmutable() );
		self::assertFalse( $this->fulfillments->save( $second_copy ), 'The second, now-stale copy must be rejected.' );
	}

	public function test_transition_reports_version_conflict_when_save_reports_a_lock_failure(): void {
		// A minimal repository double whose save() always reports a lock
		// conflict, proving WorkflowService surfaces exactly that failure
		// code rather than treating it as success or throwing.
		$fulfillments = new class() implements \MPCF\Domain\Repository\FulfillmentRepository {
			/**
			 * @var Fulfillment|null
			 */
			private ?Fulfillment $stored = null;

			public function find( int $id ): ?Fulfillment {
				return $this->stored;
			}

			public function find_by_order_id( int $order_id ): ?Fulfillment {
				return $this->stored;
			}

			public function find_all_by_order_id( int $order_id ): array {
				return null === $this->stored ? array() : array( $this->stored );
			}

			public function find_map_by_order_ids( array $order_ids ): array {
				if ( null === $this->stored || ! in_array( $this->stored->order_id(), array_map( 'intval', $order_ids ), true ) ) {
					return array();
				}

				return array( $this->stored->order_id() => $this->stored );
			}

			public function query( \MPCF\Domain\FulfillmentQuery $query ): \MPCF\Domain\FulfillmentQueryResult {
				return new \MPCF\Domain\FulfillmentQueryResult( array(), 0, 1, 20 );
			}

			public function count_in_states( array $states ): int {
				return 0;
			}

			public function insert( Fulfillment $fulfillment ): int {
				$this->stored = Fulfillment::from_array( array( 'id' => 1 ) + $fulfillment->to_array() );

				return 1;
			}

			public function save( Fulfillment $fulfillment ): bool {
				return false;
			}

			public function touch( int $id, int $expected_version ): bool {
				return false;
			}
		};

		$id = $fulfillments->insert( Fulfillment::intake( 1001, 'woocommerce', 1, StandardWorkflow::NAME, 'queued', '#1001', 'Jane Doe', 1, new DateTimeImmutable() ) );

		$service = new WorkflowService(
			$fulfillments,
			$this->events,
			new WorkflowEngine( GuardRegistry::standard() ),
			$this->dispatcher,
			$this->clock,
			array( StandardWorkflow::NAME => StandardWorkflow::definition() ),
			new TransitionContextFactory( $this->items, $this->shipments, $this->packages, new Settings( array() ) )
		);

		$outcome = $service->transition( $id, 'picking', Actor::system() );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'version_conflict', $outcome->failure_code() );
		self::assertSame( array(), $this->events->timeline_for_fulfillment( $id ), 'A version conflict must not append any audit event.' );
	}

	public function test_resolving_an_exception_state_succeeds_via_the_engines_dynamic_edge(): void {
		$id          = $this->seed_fulfillment( 'picking' );
		$fulfillment = $this->fulfillments->find( $id );
		$fulfillment->apply_transition( 'problem', 'picking', new DateTimeImmutable() );
		$this->fulfillments->save( $fulfillment );

		$outcome = $this->service->transition( $id, 'picking', Actor::system() );

		self::assertTrue( $outcome->is_success() );
		self::assertSame( 'picking', $outcome->fulfillment()->state() );
		self::assertNull( $outcome->fulfillment()->return_to_state() );
	}

	/**
	 * Architecture Plan §IV.3.B, finding B: `package_spec_present` used to
	 * be a caller-asserted boolean. It is derived from a real package spec
	 * now — this is the upgrade-relevant case: a `0.1.x` fulfillment
	 * reaching `packing -> packed` has no shipment/package rows at all,
	 * and must stay blocked until one is created with a spec.
	 */
	public function test_packing_to_packed_is_blocked_without_a_real_package_spec_and_permitted_once_one_exists(): void {
		$id = $this->seed_fulfillment( 'packing' );
		$this->items->insert_all( array( self::fully_packed_item( $id ) ) );

		$blocked = $this->service->transition( $id, 'packed', Actor::system() );

		self::assertFalse( $blocked->is_success() );
		self::assertSame( 'package_spec_present', $blocked->failure_code() );

		$shipment_id = $this->shipments->insert( Shipment::create( $id, new DateTimeImmutable() ) );
		$package     = Package::create( $shipment_id, 1, new DateTimeImmutable() );
		$package->set_spec( PackageSpec::create( 500, null, null, null ) );
		$this->packages->insert( $package );

		$permitted = $this->service->transition( $id, 'packed', Actor::system() );

		self::assertTrue( $permitted->is_success() );
	}

	/**
	 * Architecture Plan §IV.3.B, finding D / the upgrade consequence
	 * flagged in §IV.15/M2-R8: a fulfillment sitting in `packed` with no
	 * shipment row (whether from a `0.1.x` upgrade or simply because none
	 * was ever created) cannot ship until one exists.
	 */
	public function test_packed_to_shipped_is_blocked_without_a_real_shipment_and_permitted_once_one_exists(): void {
		$id = $this->seed_fulfillment( 'packed' );

		$blocked = $this->service->transition( $id, 'shipped', Actor::system() );

		self::assertFalse( $blocked->is_success() );
		self::assertSame( 'has_shipment', $blocked->failure_code() );
		self::assertSame( 'packed', $this->fulfillments->find( $id )->state() );

		$this->shipments->insert( Shipment::create( $id, new DateTimeImmutable() ) );

		$permitted = $this->service->transition( $id, 'shipped', Actor::system() );

		self::assertTrue( $permitted->is_success() );
		self::assertSame( 'shipped', $permitted->fulfillment()->state() );
	}

	public function test_available_transitions_omits_a_candidate_the_capability_predicate_rejects(): void {
		$id = $this->seed_fulfillment( 'queued' );

		$candidates = $this->service->available_transitions( $id, static fn( string $capability ): bool => false ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Always-false stub; the point of this test is that it is never even called for the omitted candidate's capability.

		self::assertSame( array(), $candidates );
	}

	public function test_available_transitions_reports_the_real_guard_rejection_for_a_blocked_candidate(): void {
		$id = $this->seed_fulfillment( 'packing' );
		$this->items->insert_all( array( FulfillmentItem::intake( $id, 1, 1, 0, 'SKU', 'Widget', 2 ) ) );

		$candidates = $this->service->available_transitions( $id, static fn( string $capability ): bool => true ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Always-true stub; this test exercises the engine's rejection, not the predicate.
		$packed     = self::find_candidate( $candidates, 'packed' );

		self::assertNotNull( $packed );
		self::assertFalse( $packed->is_approved() );
		self::assertSame( 'all_items_packed', $packed->rejection_code() );
	}

	public function test_available_transitions_includes_the_dynamic_exception_resolution_edge(): void {
		$id          = $this->seed_fulfillment( 'picking' );
		$fulfillment = $this->fulfillments->find( $id );
		$fulfillment->apply_transition( 'problem', 'picking', new DateTimeImmutable() );
		$this->fulfillments->save( $fulfillment );

		$candidates = $this->service->available_transitions( $id, static fn( string $capability ): bool => true ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Always-true stub; this test exercises the dynamic resolve edge, not the predicate.
		$resolution = self::find_candidate( $candidates, 'picking' );

		self::assertNotNull( $resolution );
		self::assertTrue( $resolution->is_approved() );
	}

	/**
	 * @param array<int, AvailableTransition> $candidates Candidates to search.
	 */
	private static function find_candidate( array $candidates, string $target ): ?AvailableTransition {
		foreach ( $candidates as $candidate ) {
			if ( $candidate->target() === $target ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * A single-line item, fully picked and packed — the minimum needed to
	 * clear `all_items_packed` so a test can isolate the guard it actually
	 * wants to exercise.
	 */
	private static function fully_packed_item( int $fulfillment_id ): FulfillmentItem {
		$item = FulfillmentItem::intake( $fulfillment_id, 1, 1, 0, 'SKU', 'Widget', 1 );
		$item->record_picked( 1 );
		$item->record_packed( 1 );

		return $item;
	}
}
