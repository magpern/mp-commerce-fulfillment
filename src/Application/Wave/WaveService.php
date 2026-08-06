<?php
/**
 * Wave lifecycle application service.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Wave;

use InvalidArgumentException;
use MPCF\Application\AssignmentService;
use MPCF\Application\EventDispatcher;
use MPCF\Application\WorkflowService;
use MPCF\Domain\Clock;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Repository\WaveRepository;
use MPCF\Domain\Wave\Wave;
use MPCF\Domain\Wave\WaveState;
use MPCF\Domain\Wave\WaveWalkBuilder;
use MPCF\Domain\Repository\FulfillmentItemRepository;
use MPCF\Settings;

/**
 * Architecture Plan Part X.2 — create / members / activate / pause / resume /
 * complete / abandon. Mutations never touch inventory.
 */
final class WaveService {

	/**
	 * Wave persistence.
	 *
	 * @var WaveRepository
	 */
	private WaveRepository $waves;

	/**
	 * Fulfillment persistence.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Line items (walk / progress).
	 *
	 * @var FulfillmentItemRepository
	 */
	private FulfillmentItemRepository $items;

	/**
	 * Assignment helper.
	 *
	 * @var AssignmentService
	 */
	private AssignmentService $assignments;

	/**
	 * Workflow transitions (queued→picking on activate).
	 *
	 * @var WorkflowService
	 */
	private WorkflowService $workflow;

	/**
	 * Audit log.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * In-process dispatch.
	 *
	 * @var EventDispatcher
	 */
	private EventDispatcher $dispatcher;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Settings (wave_max_members).
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Walk builder.
	 *
	 * @var WaveWalkBuilder
	 */
	private WaveWalkBuilder $walk_builder;

	/**
	 * Builds the service.
	 *
	 * @param WaveRepository            $waves        Wave persistence.
	 * @param FulfillmentRepository     $fulfillments Fulfillment persistence.
	 * @param FulfillmentItemRepository $items        Line items.
	 * @param AssignmentService         $assignments  Assignment helper.
	 * @param WorkflowService           $workflow     Workflow transitions.
	 * @param EventRepository           $events       Audit log.
	 * @param EventDispatcher           $dispatcher   Dispatch.
	 * @param Clock                     $clock        Clock.
	 * @param Settings                  $settings     Settings.
	 * @param WaveWalkBuilder|null      $walk_builder Optional walk builder.
	 */
	public function __construct(
		WaveRepository $waves,
		FulfillmentRepository $fulfillments,
		FulfillmentItemRepository $items,
		AssignmentService $assignments,
		WorkflowService $workflow,
		EventRepository $events,
		EventDispatcher $dispatcher,
		Clock $clock,
		Settings $settings,
		?WaveWalkBuilder $walk_builder = null
	) {
		$this->waves        = $waves;
		$this->fulfillments = $fulfillments;
		$this->items        = $items;
		$this->assignments  = $assignments;
		$this->workflow     = $workflow;
		$this->events       = $events;
		$this->dispatcher   = $dispatcher;
		$this->clock        = $clock;
		$this->settings     = $settings;
		$this->walk_builder = $walk_builder ?? new WaveWalkBuilder();
	}

	/**
	 * Creates a draft wave, optionally with initial members.
	 *
	 * @param int        $warehouse_id     Warehouse id.
	 * @param Actor      $actor            Operator.
	 * @param array<int> $fulfillment_ids  Optional initial members.
	 * @param string     $title            Optional title.
	 */
	public function create( int $warehouse_id, Actor $actor, array $fulfillment_ids = array(), string $title = '' ): WaveOutcome {
		$user_id = $actor->id();
		$max     = $this->settings->wave_max_members();
		$now     = $this->clock->now();

		$wave = Wave::create(
			$warehouse_id,
			$now,
			$user_id,
			$title,
			array( 'wave_max_members' => $max )
		);

		$id = $this->waves->insert( $wave );

		if ( null === $id ) {
			return WaveOutcome::failed( 'persist_failed', 'Could not create the wave.' );
		}

		$wave->assign_id( $id );
		$this->record_global(
			'wave.created',
			$actor,
			array(
				'wave_id'      => $id,
				'warehouse_id' => $warehouse_id,
			)
		);

		if ( array() !== $fulfillment_ids ) {
			$added = $this->add_members( $id, $fulfillment_ids, $actor, $wave->version() );

			if ( ! $added->is_success() ) {
				return $added;
			}

			return $added;
		}

		return WaveOutcome::succeeded( $wave, 'created', 'Wave created.' );
	}

	/**
	 * Adds fulfillments to a draft/paused wave.
	 *
	 * @param int        $wave_id          Wave id.
	 * @param array<int> $fulfillment_ids  Fulfillment ids.
	 * @param Actor      $actor            Operator.
	 * @param int|null   $expected_version Optional optimistic version.
	 */
	public function add_members( int $wave_id, array $fulfillment_ids, Actor $actor, ?int $expected_version = null ): WaveOutcome {
		$wave = $this->waves->find( $wave_id );

		if ( null === $wave ) {
			return WaveOutcome::failed( 'not_found', "No wave exists with id {$wave_id}." );
		}

		$lock = $this->assert_owner_and_version( $wave, $actor, $expected_version );

		if ( null !== $lock ) {
			return $lock;
		}

		$max = $this->settings->wave_max_members();
		$now = $this->clock->now();
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $fulfillment_ids ) ) ) );

		foreach ( $ids as $fulfillment_id ) {
			if ( $wave->member_count() >= $max ) {
				return WaveOutcome::failed( 'wave_full', sprintf( 'Wave cannot exceed %d members.', $max ) );
			}

			$fulfillment = $this->fulfillments->find( $fulfillment_id );

			if ( null === $fulfillment ) {
				return WaveOutcome::failed( 'not_found', "No fulfillment exists with id {$fulfillment_id}." );
			}

			if ( ! in_array( $fulfillment->state(), array( 'queued', 'picking' ), true ) ) {
				return WaveOutcome::failed( 'invalid_member_state', 'Only queued or picking fulfillments can join a wave.' );
			}

			if ( (int) $fulfillment->warehouse_id() !== (int) $wave->warehouse_id() ) {
				return WaveOutcome::failed( 'warehouse_mismatch', 'Fulfillment belongs to a different warehouse.' );
			}

			$open = $this->waves->find_open_for_fulfillment( $fulfillment_id );

			if ( null !== $open && (int) $open->id() !== (int) $wave->id() ) {
				return WaveOutcome::failed( 'already_in_wave', 'Fulfillment is already in another open wave.' );
			}

			if ( null !== $wave->member( $fulfillment_id ) ) {
				continue;
			}

			try {
				$wave->add_member( $fulfillment_id, $now );
			} catch ( InvalidArgumentException $e ) {
				return WaveOutcome::failed( 'invalid_transition', $e->getMessage() );
			}

			$this->record_global(
				'wave.member_added',
				$actor,
				array(
					'wave_id'        => (int) $wave->id(),
					'fulfillment_id' => $fulfillment_id,
				)
			);
		}

		$this->waves->sync_members( $wave );

		if ( ! $this->waves->save( $wave ) ) {
			return WaveOutcome::failed( 'version_conflict', 'The wave was modified by someone else. Reload and try again.' );
		}

		$fresh = $this->waves->find( (int) $wave->id() );

		return WaveOutcome::succeeded( $fresh ?? $wave, 'members_added', 'Members added.' );
	}

	/**
	 * Removes a fulfillment from a draft/paused wave.
	 *
	 * @param int      $wave_id            Wave id.
	 * @param int      $fulfillment_id     Fulfillment id.
	 * @param Actor    $actor              Operator.
	 * @param int|null $expected_version   Optional version.
	 */
	public function remove_member( int $wave_id, int $fulfillment_id, Actor $actor, ?int $expected_version = null ): WaveOutcome {
		$wave = $this->waves->find( $wave_id );

		if ( null === $wave ) {
			return WaveOutcome::failed( 'not_found', "No wave exists with id {$wave_id}." );
		}

		$lock = $this->assert_owner_and_version( $wave, $actor, $expected_version );

		if ( null !== $lock ) {
			return $lock;
		}

		try {
			$wave->remove_member( $fulfillment_id, $this->clock->now() );
		} catch ( InvalidArgumentException $e ) {
			return WaveOutcome::failed( 'invalid_transition', $e->getMessage() );
		}

		$this->waves->sync_members( $wave );

		if ( ! $this->waves->save( $wave ) ) {
			return WaveOutcome::failed( 'version_conflict', 'The wave was modified by someone else. Reload and try again.' );
		}

		$this->record_global(
			'wave.member_removed',
			$actor,
			array(
				'wave_id'        => $wave_id,
				'fulfillment_id' => $fulfillment_id,
			)
		);

		$fresh = $this->waves->find( $wave_id );

		return WaveOutcome::succeeded( $fresh ?? $wave, 'member_removed', 'Member removed.' );
	}

	/**
	 * Activates a draft wave: claim ownership, ensure members are picking.
	 *
	 * @param int      $wave_id          Wave id.
	 * @param Actor    $actor            Operator.
	 * @param int|null $expected_version Optional version.
	 */
	public function activate( int $wave_id, Actor $actor, ?int $expected_version = null ): WaveOutcome {
		$wave = $this->waves->find( $wave_id );

		if ( null === $wave ) {
			return WaveOutcome::failed( 'not_found', "No wave exists with id {$wave_id}." );
		}

		$lock = $this->assert_owner_and_version( $wave, $actor, $expected_version, true );

		if ( null !== $lock ) {
			return $lock;
		}

		$user_id = (int) $actor->id();

		if ( $user_id <= 0 ) {
			return WaveOutcome::failed( 'invalid_payload', 'Activation requires an authenticated operator.' );
		}

		if ( 0 === $wave->member_count() ) {
			return WaveOutcome::failed( 'empty_wave', 'Activate requires at least one member.' );
		}

		try {
			$wave->activate( $user_id, $this->clock->now() );
		} catch ( InvalidArgumentException $e ) {
			return WaveOutcome::failed( 'invalid_transition', $e->getMessage() );
		}

		foreach ( $wave->members() as $member ) {
			$fulfillment = $this->fulfillments->find( $member->fulfillment_id() );

			if ( null === $fulfillment ) {
				continue;
			}

			if ( 'queued' === $fulfillment->state() ) {
				$transition = $this->workflow->transition( $member->fulfillment_id(), 'picking', $actor );

				if ( ! $transition->is_success() ) {
					return WaveOutcome::failed(
						(string) $transition->failure_code(),
						(string) $transition->failure_message()
					);
				}
			}

			$this->assignments->assign( $member->fulfillment_id(), $user_id, $actor );
		}

		if ( ! $this->waves->save( $wave ) ) {
			return WaveOutcome::failed( 'version_conflict', 'The wave was modified by someone else. Reload and try again.' );
		}

		$this->record_global(
			'wave.activated',
			$actor,
			array(
				'wave_id'       => $wave_id,
				'owner_user_id' => $user_id,
			)
		);

		$fresh = $this->waves->find( $wave_id );

		return WaveOutcome::succeeded( $fresh ?? $wave, 'activated', 'Wave activated.' );
	}

	/**
	 * Pauses an active wave.
	 *
	 * @param int      $wave_id          Wave id.
	 * @param Actor    $actor            Operator.
	 * @param int|null $expected_version Optional version.
	 */
	public function pause( int $wave_id, Actor $actor, ?int $expected_version = null ): WaveOutcome {
		return $this->lifecycle( $wave_id, $actor, $expected_version, 'pause', 'wave.paused', 'paused', 'Wave paused.' );
	}

	/**
	 * Resumes a paused wave.
	 *
	 * @param int      $wave_id          Wave id.
	 * @param Actor    $actor            Operator.
	 * @param int|null $expected_version Optional version.
	 */
	public function resume( int $wave_id, Actor $actor, ?int $expected_version = null ): WaveOutcome {
		return $this->lifecycle( $wave_id, $actor, $expected_version, 'resume', 'wave.resumed', 'resumed', 'Wave resumed.' );
	}

	/**
	 * Completes a wave when all members are picked (or force).
	 *
	 * @param int      $wave_id          Wave id.
	 * @param Actor    $actor            Operator.
	 * @param int|null $expected_version Optional version.
	 * @param bool     $force            Force-complete with unfinished members.
	 */
	public function complete( int $wave_id, Actor $actor, ?int $expected_version = null, bool $force = false ): WaveOutcome {
		$wave = $this->waves->find( $wave_id );

		if ( null === $wave ) {
			return WaveOutcome::failed( 'not_found', "No wave exists with id {$wave_id}." );
		}

		$lock = $this->assert_owner_and_version( $wave, $actor, $expected_version );

		if ( null !== $lock ) {
			return $lock;
		}

		if ( ! $force && ! $wave->all_members_picked() ) {
			// Also accept when every member fulfillment is already in picked+.
			if ( ! $this->members_workflow_picked( $wave ) ) {
				return WaveOutcome::failed( 'incomplete', 'Not all members are picked. Pass force=true to complete with exceptions.' );
			}
		}

		try {
			$wave->complete( $this->clock->now() );
		} catch ( InvalidArgumentException $e ) {
			return WaveOutcome::failed( 'invalid_transition', $e->getMessage() );
		}

		if ( ! $this->waves->save( $wave ) ) {
			return WaveOutcome::failed( 'version_conflict', 'The wave was modified by someone else. Reload and try again.' );
		}

		$this->record_global(
			'wave.completed',
			$actor,
			array(
				'wave_id' => $wave_id,
				'force'   => $force,
			)
		);

		$fresh = $this->waves->find( $wave_id );

		return WaveOutcome::succeeded( $fresh ?? $wave, 'completed', 'Wave completed.' );
	}

	/**
	 * Abandons a wave and releases membership without cancelling fulfillments.
	 *
	 * @param int      $wave_id          Wave id.
	 * @param Actor    $actor            Operator.
	 * @param int|null $expected_version Optional version.
	 */
	public function abandon( int $wave_id, Actor $actor, ?int $expected_version = null ): WaveOutcome {
		$wave = $this->waves->find( $wave_id );

		if ( null === $wave ) {
			return WaveOutcome::failed( 'not_found', "No wave exists with id {$wave_id}." );
		}

		$lock = $this->assert_owner_and_version( $wave, $actor, $expected_version );

		if ( null !== $lock ) {
			return $lock;
		}

		try {
			$wave->abandon( $this->clock->now() );
		} catch ( InvalidArgumentException $e ) {
			return WaveOutcome::failed( 'invalid_transition', $e->getMessage() );
		}

		$this->waves->delete_members( $wave_id );
		$wave->clear_members( $this->clock->now() );

		if ( ! $this->waves->save( $wave ) ) {
			return WaveOutcome::failed( 'version_conflict', 'The wave was modified by someone else. Reload and try again.' );
		}

		$this->record_global( 'wave.abandoned', $actor, array( 'wave_id' => $wave_id ) );

		$fresh = $this->waves->find( $wave_id );

		return WaveOutcome::succeeded( $fresh ?? $wave, 'abandoned', 'Wave abandoned.' );
	}

	/**
	 * Lists open waves as REST-ready resources.
	 *
	 * @param int|null $owner_user_id Owner filter (null = any).
	 * @param int|null $warehouse_id  Warehouse filter.
	 * @return list<array<string, mixed>>
	 */
	public function list_open( ?int $owner_user_id = null, ?int $warehouse_id = null ): array {
		$waves = null === $owner_user_id
			? $this->waves->list_open( $warehouse_id )
			: $this->waves->list_open_for_owner( $owner_user_id, $warehouse_id );

		$out = array();

		foreach ( $waves as $wave ) {
			$progress = $this->get_with_progress( (int) $wave->id() );
			$out[]    = array(
				'id'            => (int) $wave->id(),
				'warehouse_id'  => $wave->warehouse_id(),
				'owner_user_id' => $wave->owner_user_id(),
				'state'         => $wave->state(),
				'version'       => $wave->version(),
				'title'         => $wave->title(),
				'member_count'  => $wave->member_count(),
				'progress'      => $progress['progress'] ?? null,
			);
		}

		return $out;
	}

	/**
	 * Loads a wave with progress / walk summary.
	 *
	 * @param int $wave_id Wave id.
	 * @return array<string, mixed>|null
	 */
	public function get_with_progress( int $wave_id ): ?array {
		$wave = $this->waves->find( $wave_id );

		if ( null === $wave ) {
			return null;
		}

		$walk = $this->build_walk( $wave );

		return array(
			'wave'     => $wave,
			'progress' => array(
				'remaining_lines'        => $walk['remaining_lines'],
				'remaining_qty'          => $walk['remaining_qty'],
				'completed_fulfillments' => $walk['completed_fulfillments'],
				'remaining_fulfillments' => $walk['remaining_fulfillments'],
				'member_count'           => $wave->member_count(),
				'picked_members'         => count(
					array_filter(
						$wave->members(),
						static fn( $m ) => $m->is_picked()
					)
				),
			),
			'walk'     => $walk,
		);
	}

	/**
	 * Builds the combined walk model for a wave.
	 *
	 * @param Wave $wave Wave.
	 * @return array<string, mixed>
	 */
	public function build_walk( Wave $wave ): array {
		$fulfillments = array();
		$items_by_fid = array();

		foreach ( $wave->members() as $member ) {
			$fid = $member->fulfillment_id();
			$f   = $this->fulfillments->find( $fid );

			if ( null === $f ) {
				continue;
			}

			$fulfillments[ $fid ] = $f;
			$items_by_fid[ $fid ] = $this->items->find_for_fulfillment( $fid );
		}

		return $this->walk_builder->build( $fulfillments, $items_by_fid );
	}

	/**
	 * Marks a member picked and optionally auto-completes the wave.
	 *
	 * @param int   $wave_id        Wave id.
	 * @param int   $fulfillment_id Fulfillment id.
	 * @param Actor $actor          Operator.
	 */
	public function mark_member_picked( int $wave_id, int $fulfillment_id, Actor $actor ): WaveOutcome {
		$wave = $this->waves->find( $wave_id );

		if ( null === $wave ) {
			return WaveOutcome::failed( 'not_found', "No wave exists with id {$wave_id}." );
		}

		try {
			$wave->mark_member_picked( $fulfillment_id, $this->clock->now() );
		} catch ( InvalidArgumentException $e ) {
			return WaveOutcome::failed( 'invalid_transition', $e->getMessage() );
		}

		$this->waves->sync_members( $wave );

		if ( ! $this->waves->save( $wave ) ) {
			return WaveOutcome::failed( 'version_conflict', 'The wave was modified by someone else. Reload and try again.' );
		}

		$this->record_global(
			'wave.member_picked',
			$actor,
			array(
				'wave_id'        => $wave_id,
				'fulfillment_id' => $fulfillment_id,
			)
		);

		$fresh = $this->waves->find( $wave_id );

		return WaveOutcome::succeeded( $fresh ?? $wave, 'member_picked', 'Member marked picked.' );
	}

	/**
	 * Shared pause/resume path.
	 *
	 * @param int      $wave_id          Wave id.
	 * @param Actor    $actor            Operator.
	 * @param int|null $expected_version Version.
	 * @param string   $method           Domain method.
	 * @param string   $event_type       Audit event.
	 * @param string   $code             Result code.
	 * @param string   $message          Message.
	 */
	private function lifecycle(
		int $wave_id,
		Actor $actor,
		?int $expected_version,
		string $method,
		string $event_type,
		string $code,
		string $message
	): WaveOutcome {
		$wave = $this->waves->find( $wave_id );

		if ( null === $wave ) {
			return WaveOutcome::failed( 'not_found', "No wave exists with id {$wave_id}." );
		}

		$lock = $this->assert_owner_and_version( $wave, $actor, $expected_version );

		if ( null !== $lock ) {
			return $lock;
		}

		try {
			$wave->{$method}( $this->clock->now() );
		} catch ( InvalidArgumentException $e ) {
			return WaveOutcome::failed( 'invalid_transition', $e->getMessage() );
		}

		if ( ! $this->waves->save( $wave ) ) {
			return WaveOutcome::failed( 'version_conflict', 'The wave was modified by someone else. Reload and try again.' );
		}

		$this->record_global( $event_type, $actor, array( 'wave_id' => $wave_id ) );

		$fresh = $this->waves->find( $wave_id );

		return WaveOutcome::succeeded( $fresh ?? $wave, $code, $message );
	}

	/**
	 * Owner + optional version guard.
	 *
	 * @param Wave     $wave                Wave.
	 * @param Actor    $actor               Operator.
	 * @param int|null $expected_version    Expected version.
	 * @param bool     $allow_draft_unowned Allow draft with null owner.
	 */
	private function assert_owner_and_version( Wave $wave, Actor $actor, ?int $expected_version, bool $allow_draft_unowned = false ): ?WaveOutcome {
		$user_id = (int) $actor->id();

		if ( $user_id <= 0 || Actor::TYPE_USER !== $actor->type() ) {
			return WaveOutcome::failed( 'wave_owned', 'Wave mutations require an authenticated operator.' );
		}

		if ( WaveState::is_open( $wave->state() ) ) {
			$owner = $wave->owner_user_id();

			if ( null !== $owner && $owner !== $user_id ) {
				return WaveOutcome::failed( 'wave_owned', 'This wave is owned by another operator.' );
			}

			if ( null === $owner && ! $allow_draft_unowned && WaveState::DRAFT !== $wave->state() ) {
				return WaveOutcome::failed( 'wave_owned', 'This wave has no owner.' );
			}
		}

		if ( null !== $expected_version && (int) $expected_version !== (int) $wave->version() ) {
			return WaveOutcome::failed( 'version_conflict', 'The wave was modified by someone else. Reload and try again.' );
		}

		return null;
	}

	/**
	 * Whether every member fulfillment is already picked (or beyond).
	 *
	 * @param Wave $wave Wave.
	 */
	private function members_workflow_picked( Wave $wave ): bool {
		$terminal_ok = array( 'picked', 'packing', 'packed', 'shipped', 'delivered', 'completed' );

		foreach ( $wave->members() as $member ) {
			$f = $this->fulfillments->find( $member->fulfillment_id() );

			if ( null === $f || ! in_array( $f->state(), $terminal_ok, true ) ) {
				return false;
			}
		}

		return array() !== $wave->members();
	}

	/**
	 * Appends a global wave audit event.
	 *
	 * @param string               $event_type Event type.
	 * @param Actor                $actor      Actor.
	 * @param array<string, mixed> $payload    Payload.
	 */
	private function record_global( string $event_type, Actor $actor, array $payload ): void {
		$event = DomainEvent::global_event( $event_type, $actor, $this->clock->now(), $payload );
		$this->events->append( $event, null );
		$this->dispatcher->dispatch( $event );
	}
}
