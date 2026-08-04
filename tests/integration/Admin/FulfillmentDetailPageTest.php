<?php
/**
 * Integration tests for the Fulfillment Detail admin screen, against a
 * real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use DateTimeImmutable;
use MPCF\Admin\FulfillmentDetailPage;
use MPCF\Application\EventDispatcher;
use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\NoteService;
use MPCF\Application\TransitionContextFactory;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\WorkflowEngine;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbNoteRepository;
use MPCF\Infrastructure\Database\WpdbPackageRepository;
use MPCF\Infrastructure\Database\WpdbShipmentRepository;
use MPCF\Infrastructure\SystemClock;
use MPCF\Settings;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\SectionNavigation;
use WP_UnitTestCase;

/**
 * `submit_transition()`/`apply_note()` are exercised directly (no `$_POST`/
 * redirect simulation needed — see their own docblocks) against a real
 * database and real capability checks.
 */
final class FulfillmentDetailPageTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	/**
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $fulfillments;

	/**
	 * @var WpdbFulfillmentItemRepository
	 */
	private WpdbFulfillmentItemRepository $items;

	/**
	 * @var WpdbNoteRepository
	 */
	private WpdbNoteRepository $notes;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
		\MPCF\Plugin::activate();

		$this->fulfillments = new WpdbFulfillmentRepository();
		$this->items        = new WpdbFulfillmentItemRepository();
		$this->notes        = new WpdbNoteRepository();
	}

	private function build_page( ?FulfillmentRepository $fulfillments = null ): FulfillmentDetailPage {
		$fulfillments = $fulfillments ?? $this->fulfillments;
		$events       = new WpdbEventRepository();
		$definition   = StandardWorkflow::definition();

		$workflow = new WorkflowService(
			$fulfillments,
			$events,
			new WorkflowEngine( GuardRegistry::standard() ),
			new EventDispatcher(),
			new SystemClock(),
			array( StandardWorkflow::NAME => $definition ),
			new TransitionContextFactory( $this->items, new WpdbShipmentRepository(), new WpdbPackageRepository(), new Settings( array() ) )
		);

		return new FulfillmentDetailPage(
			new AdminPageShell( new SectionNavigation() ),
			new ComponentRenderer(),
			new FulfillmentDetailService( $fulfillments, $this->items, $events, $this->notes ),
			new NoteService( $this->notes, new SystemClock() ),
			$workflow,
			$definition
		);
	}

	private function seed( int $order_id ): int {
		$fulfillment = Fulfillment::intake( $order_id, 'woocommerce', 1, 'standard', 'queued', '#' . $order_id, 'Jane Doe', 1, new DateTimeImmutable() );
		$id          = $this->fulfillments->insert( $fulfillment );

		$this->items->insert_all( array( FulfillmentItem::intake( $id, 501, 900, 0, 'SKU-1', 'Widget', 1 ) ) );

		return $id;
	}

	public function test_a_guardless_transition_succeeds(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id    = $this->seed( 1001 );
		$error = $this->build_page()->submit_transition( $id, 'picking', null );

		self::assertNull( $error );
		self::assertSame( 'picking', $this->fulfillments->find( $id )->state() );
	}

	public function test_a_guard_blocked_transition_reports_the_real_rejection_reason(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id   = $this->seed( 2001 );
		$page = $this->build_page();
		$page->submit_transition( $id, 'picking', null );

		// The seeded item is never marked picked, so picking -> picked must
		// be rejected by AllItemsPickedGuard specifically.
		$error = $page->submit_transition( $id, 'picked', null );

		self::assertNotNull( $error );
		self::assertStringContainsString( 'picked', strtolower( $error ) );
		self::assertSame( 'picking', $this->fulfillments->find( $id )->state(), 'A rejected transition must not change the stored state.' );
	}

	public function test_a_capability_forbidden_transition_is_rejected_for_an_operator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id    = $this->seed( 3001 );
		$error = $this->build_page()->submit_transition( $id, 'cancelled', 'no longer needed' );

		self::assertNotNull( $error );
		self::assertSame( 'queued', $this->fulfillments->find( $id )->state() );
	}

	public function test_the_same_transition_succeeds_for_a_lead(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id    = $this->seed( 3002 );
		$error = $this->build_page()->submit_transition( $id, 'cancelled', 'no longer needed' );

		self::assertNull( $error );
		self::assertSame( 'cancelled', $this->fulfillments->find( $id )->state() );
	}

	public function test_a_version_conflict_is_surfaced_as_a_failure_message(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id = $this->seed( 4001 );

		// A repository whose save() always reports a lost optimistic-lock
		// race, regardless of what actually happened — proves
		// submit_transition() surfaces exactly that failure, the same
		// message the admin-notice rendering path displays verbatim.
		$conflicted = new class( $this->fulfillments ) implements FulfillmentRepository {
			/**
			 * @var FulfillmentRepository
			 */
			private FulfillmentRepository $real;

			public function __construct( FulfillmentRepository $real ) {
				$this->real = $real;
			}

			public function find( int $id ): ?Fulfillment {
				return $this->real->find( $id );
			}

			public function find_by_order_id( int $order_id ): ?Fulfillment {
				return $this->real->find_by_order_id( $order_id );
			}

			public function find_all_by_order_id( int $order_id ): array {
				return $this->real->find_all_by_order_id( $order_id );
			}

			public function find_map_by_order_ids( array $order_ids ): array {
				return $this->real->find_map_by_order_ids( $order_ids );
			}

			public function query( \MPCF\Domain\FulfillmentQuery $query ): \MPCF\Domain\FulfillmentQueryResult {
				return $this->real->query( $query );
			}

			public function count_in_states( array $states ): int {
				return $this->real->count_in_states( $states );
			}

			public function insert( Fulfillment $fulfillment ): ?int {
				return $this->real->insert( $fulfillment );
			}

			public function save( Fulfillment $fulfillment ): bool {
				return false;
			}

			public function touch( int $id, int $expected_version ): bool {
				return $this->real->touch( $id, $expected_version );
			}
		};

		$error = $this->build_page( $conflicted )->submit_transition( $id, 'picking', null );

		self::assertNotNull( $error );
		self::assertStringContainsString( 'else', strtolower( $error ), 'The version-conflict message must be the same one WorkflowService reports.' );
	}

	public function test_notes_render_pinned_first(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id = $this->seed( 5001 );

		$this->notes->insert( \MPCF\Domain\Note::create( $id, 1, 'Unpinned note.', new DateTimeImmutable( '2026-08-01 10:00:00' ) ) );
		$this->notes->insert( \MPCF\Domain\Note::create( $id, 1, 'Pinned note.', new DateTimeImmutable( '2026-07-30 10:00:00' ), true ) );

		$notes = ( new NoteService( $this->notes, new SystemClock() ) )->list_for( $id );

		self::assertSame( 'Pinned note.', $notes[0]->body(), 'Pinned notes must render first regardless of age.' );
	}

	public function test_apply_note_is_rejected_without_the_add_notes_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$id    = $this->seed( 6001 );
		$error = $this->build_page()->apply_note( $id, 'Trying to add a note.' );

		self::assertNotNull( $error );
		self::assertSame( array(), $this->notes->find_for_fulfillment( $id ) );
	}

	public function test_apply_note_succeeds_for_an_operator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id    = $this->seed( 6002 );
		$error = $this->build_page()->apply_note( $id, 'Customer called about delivery.' );

		self::assertNull( $error );
		self::assertCount( 1, $this->notes->find_for_fulfillment( $id ) );
	}

	/**
	 * `render()` echoes directly, so this captures output via
	 * `ob_start()`/`ob_get_clean()`, the same technique the Queue's and
	 * Workspace's own admin-screen integration tests use.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @param int $paged          `paged` query-string value to simulate.
	 */
	private function render_for( int $fulfillment_id, int $paged = 1 ): string {
		$_GET['fulfillment_id'] = (string) $fulfillment_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating a query param, not real request input.
		$_GET['paged']          = (string) $paged; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating a query param, not real request input.

		ob_start();
		$this->build_page()->render();

		return (string) ob_get_clean();
	}

	public function test_the_audit_trail_paginates_at_20_rows_per_page(): void {
		// F23 (Architecture Plan §IV.10, risk M2-R11): this screen used to
		// render its fulfillment's entire, unbounded event chain in one go.
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id        = $this->seed( 7001 );
		$events    = new \MPCF\Infrastructure\Database\WpdbEventRepository();
		$prev_hash = $events->last_hash_for_fulfillment( $id );

		// 1 (intake) + 24 markers = 25 total: page 1 holds 20, page 2 holds 5.
		for ( $i = 0; $i < 24; $i++ ) {
			$prev_hash = $events->append(
				\MPCF\Domain\Event\DomainEvent::for_fulfillment( $id, "test.marker_{$i}", \MPCF\Domain\Event\Actor::system(), new DateTimeImmutable() ),
				$prev_hash
			);
		}

		$page_one = $this->render_for( $id, 1 );
		$page_two = $this->render_for( $id, 2 );

		self::assertStringContainsString( 'test.marker_0<', $page_one, 'Page 1 must hold the oldest events.' );
		self::assertStringNotContainsString( 'test.marker_23<', $page_one, 'Page 1 must not overflow into the newest events.' );

		self::assertStringNotContainsString( 'test.marker_0<', $page_two, 'Page 2 must not repeat page 1\'s rows.' );
		self::assertStringContainsString( 'test.marker_23<', $page_two, 'Page 2 must hold the newest events.' );

		self::assertStringContainsString( 'aria-current="page">2<', $page_two );
	}
}
