<?php
/**
 * Wave scan application service — FIFO multi-order allocation.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Wave;

use MPCF\Application\EventDispatcher;
use MPCF\Application\PackingService;
use MPCF\Application\WorkflowService;
use MPCF\Domain\Barcode\BarcodePayload;
use MPCF\Domain\Clock;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentItemRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Repository\WaveRepository;
use MPCF\Domain\Scan\ScanCorrectionStore;
use MPCF\Domain\Scan\ScanMode;
use MPCF\Domain\Scan\ScanResolver;
use MPCF\Domain\Wave\Wave;
use MPCF\Domain\Wave\WaveState;

/**
 * Architecture Plan Part X.4 — Wave Scan Mode. Mutations go through
 * PackingService absolute +1; never invents a chooser for multi-order SKUs.
 */
final class WaveScanService {

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
	 * Line-item persistence.
	 *
	 * @var FulfillmentItemRepository
	 */
	private FulfillmentItemRepository $items;

	/**
	 * Absolute quantity writer.
	 *
	 * @var PackingService
	 */
	private PackingService $packing;

	/**
	 * Workflow transitions.
	 *
	 * @var WorkflowService
	 */
	private WorkflowService $workflow;

	/**
	 * Wave lifecycle service.
	 *
	 * @var WaveService
	 */
	private WaveService $wave_service;

	/**
	 * Per-fulfillment barcode resolver.
	 *
	 * @var ScanResolver
	 */
	private ScanResolver $resolver;

	/**
	 * Undo correction store.
	 *
	 * @var ScanCorrectionStore
	 */
	private ScanCorrectionStore $corrections;

	/**
	 * Audit log.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * In-process event dispatch.
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
	 * Builds the service.
	 *
	 * @param WaveRepository            $waves         Waves.
	 * @param FulfillmentRepository     $fulfillments  Fulfillments.
	 * @param FulfillmentItemRepository $items         Items.
	 * @param PackingService            $packing       Absolute qty writer.
	 * @param WorkflowService           $workflow      Transitions.
	 * @param WaveService               $wave_service  Wave lifecycle.
	 * @param ScanResolver              $resolver      Per-fulfillment resolver.
	 * @param ScanCorrectionStore       $corrections   Undo store.
	 * @param EventRepository           $events        Audit.
	 * @param EventDispatcher           $dispatcher    Dispatch.
	 * @param Clock                     $clock         Clock.
	 */
	public function __construct(
		WaveRepository $waves,
		FulfillmentRepository $fulfillments,
		FulfillmentItemRepository $items,
		PackingService $packing,
		WorkflowService $workflow,
		WaveService $wave_service,
		ScanResolver $resolver,
		ScanCorrectionStore $corrections,
		EventRepository $events,
		EventDispatcher $dispatcher,
		Clock $clock
	) {
		$this->waves        = $waves;
		$this->fulfillments = $fulfillments;
		$this->items        = $items;
		$this->packing      = $packing;
		$this->workflow     = $workflow;
		$this->wave_service = $wave_service;
		$this->resolver     = $resolver;
		$this->corrections  = $corrections;
		$this->events       = $events;
		$this->dispatcher   = $dispatcher;
		$this->clock        = $clock;
	}

	/**
	 * Resolve-only (no mutation).
	 *
	 * @param int    $wave_id Wave id.
	 * @param string $payload Raw scan.
	 * @param Actor  $actor   Operator.
	 */
	public function resolve( int $wave_id, string $payload, Actor $actor ): WaveOutcome {
		$gate = $this->assert_active_owner( $wave_id, $actor );

		if ( $gate instanceof WaveOutcome ) {
			return $gate;
		}

		assert( $gate instanceof Wave );
		$wave = $gate;

		$allocation = $this->allocate( $wave, $payload );

		if ( isset( $allocation['outcome'] ) && $allocation['outcome'] instanceof WaveOutcome ) {
			return $allocation['outcome'];
		}

		return WaveOutcome::succeeded(
			$wave,
			(string) ( $allocation['code'] ?? 'matched' ),
			(string) ( $allocation['message'] ?? 'Matched.' ),
			$allocation
		);
	}

	/**
	 * Pick +1 via FIFO allocation.
	 *
	 * @param int             $wave_id              Wave id.
	 * @param int             $expected_wave_version Wave version.
	 * @param string          $payload              Raw scan.
	 * @param Actor           $actor                Operator.
	 * @param array<int, int> $fulfillment_versions Optional map fid→version; else uses current.
	 */
	public function scan_pick(
		int $wave_id,
		int $expected_wave_version,
		string $payload,
		Actor $actor,
		array $fulfillment_versions = array()
	): WaveOutcome {
		$gate = $this->assert_active_owner( $wave_id, $actor );

		if ( $gate instanceof WaveOutcome ) {
			return $gate;
		}

		assert( $gate instanceof Wave );
		$wave = $gate;

		if ( (int) $wave->version() !== $expected_wave_version ) {
			return WaveOutcome::failed( 'version_conflict', 'The wave was modified by someone else. Reload and try again.' );
		}

		$allocation = $this->allocate( $wave, $payload );

		if ( isset( $allocation['outcome'] ) && $allocation['outcome'] instanceof WaveOutcome ) {
			return $allocation['outcome'];
		}

		if ( ( $allocation['code'] ?? '' ) === 'wave_identity' || ( $allocation['code'] ?? '' ) === 'fulfillment_identity' ) {
			return WaveOutcome::succeeded( $wave, (string) $allocation['code'], (string) $allocation['message'], $allocation );
		}

		$item           = $allocation['item'] ?? null;
		$fulfillment_id = (int) ( $allocation['fulfillment_id'] ?? 0 );
		$fulfillment    = $this->fulfillments->find( $fulfillment_id );

		if ( ! $item instanceof FulfillmentItem || null === $fulfillment ) {
			return WaveOutcome::failed( 'unknown_barcode', 'No outstanding wave item matches that barcode.' );
		}

		if ( 'picking' !== $fulfillment->state() ) {
			return WaveOutcome::failed( 'wrong_stage', 'Wave picks only apply to members still in picking.' );
		}

		if ( $item->qty_picked() >= $item->qty_ordered() ) {
			return WaveOutcome::failed( 'over_scan', 'That item is already fully picked.' );
		}

		$expected_f_version = isset( $fulfillment_versions[ $fulfillment_id ] )
			? (int) $fulfillment_versions[ $fulfillment_id ]
			: $fulfillment->version();

		$next    = $item->qty_picked() + 1;
		$packing = $this->packing->update_quantities(
			$fulfillment_id,
			$expected_f_version,
			array(
				array(
					'item_id'    => (int) $item->id(),
					'qty_picked' => $next,
				),
			),
			$actor
		);

		if ( ! $packing->is_success() ) {
			return WaveOutcome::failed( (string) $packing->failure_code(), (string) $packing->failure_message() );
		}

		$user_id = (int) $actor->id();
		$this->corrections->remember_wave(
			$user_id,
			$wave_id,
			array(
				'mode'           => ScanMode::WAVE_PICKING,
				'item_id'        => (int) $item->id(),
				'fulfillment_id' => $fulfillment_id,
				'resulting_qty'  => $next,
				'source'         => (string) ( $allocation['source'] ?? 'sku' ),
				'wave_version'   => $wave->version(),
			)
		);

		$this->record_scan_event(
			$fulfillment_id,
			'scan.item_picked',
			$actor,
			array(
				'item_id'                   => (int) $item->id(),
				'product_id'                => $item->product_id(),
				'variation_id'              => $item->variation_id(),
				'mode'                      => ScanMode::WAVE_PICKING,
				'qty_picked'                => $next,
				'source'                    => (string) ( $allocation['source'] ?? 'sku' ),
				'wave_id'                   => $wave_id,
				'allocation_fulfillment_id' => $fulfillment_id,
			)
		);

		$fresh_items    = $this->items->find_for_fulfillment( $fulfillment_id );
		$stage_complete = $this->is_fully_picked( $fresh_items );
		$member_picked  = false;

		if ( $stage_complete ) {
			$transition = $this->workflow->transition( $fulfillment_id, 'picked', $actor );

			if ( $transition->is_success() ) {
				$member_result = $this->wave_service->mark_member_picked( $wave_id, $fulfillment_id, $actor );
				$member_picked = $member_result->is_success();
				if ( $member_result->is_success() && null !== $member_result->wave() ) {
					$wave = $member_result->wave();
				}
			}
		}

		// Bump wave version so concurrent scanners detect staleness even when
		// membership did not change.
		if ( ! $member_picked ) {
			if ( ! $this->waves->save( $wave ) ) {
				return WaveOutcome::failed( 'version_conflict', 'The wave was modified by someone else. Reload and try again.' );
			}
			$reloaded = $this->waves->find( $wave_id );
			if ( null !== $reloaded ) {
				$wave = $reloaded;
			}
		}

		$walk = $this->wave_service->build_walk( $wave );

		return WaveOutcome::succeeded(
			$wave,
			$stage_complete ? 'member_complete' : 'quantity_incremented',
			$stage_complete ? 'Member fully picked.' : 'Quantity incremented.',
			array(
				'fulfillment_id'      => $fulfillment_id,
				'item_id'             => (int) $item->id(),
				'qty_picked'          => $next,
				'fulfillment_version' => (int) $packing->version(),
				'wave_version'        => $wave->version(),
				'stage_complete'      => $stage_complete,
				'progress'            => array(
					'remaining_lines'        => $walk['remaining_lines'],
					'remaining_qty'          => $walk['remaining_qty'],
					'completed_fulfillments' => $walk['completed_fulfillments'],
					'remaining_fulfillments' => $walk['remaining_fulfillments'],
				),
			)
		);
	}

	/**
	 * Undoes the last wave scan for this operator.
	 *
	 * @param int   $wave_id              Wave id.
	 * @param int   $expected_wave_version Wave version.
	 * @param Actor $actor                Operator.
	 */
	public function undo( int $wave_id, int $expected_wave_version, Actor $actor ): WaveOutcome {
		$gate = $this->assert_active_owner( $wave_id, $actor );

		if ( $gate instanceof WaveOutcome ) {
			return $gate;
		}

		assert( $gate instanceof Wave );
		$wave = $gate;

		if ( (int) $wave->version() !== $expected_wave_version ) {
			return WaveOutcome::failed( 'version_conflict', 'The wave was modified by someone else. Reload and try again.' );
		}

		$user_id = (int) $actor->id();
		$entry   = $this->corrections->pull_wave( $user_id, $wave_id );

		if ( null === $entry ) {
			return WaveOutcome::failed( 'undo_unavailable', 'There is no recent wave scan to undo.' );
		}

		$fulfillment_id = (int) ( $entry['fulfillment_id'] ?? 0 );
		$item_id        = (int) ( $entry['item_id'] ?? 0 );
		$expected_qty   = (int) ( $entry['resulting_qty'] ?? -1 );
		$fulfillment    = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment || $item_id <= 0 ) {
			$this->corrections->clear_wave( $user_id, $wave_id );

			return WaveOutcome::failed( 'undo_unavailable', 'Stored wave correction is invalid.' );
		}

		if ( 'picking' !== $fulfillment->state() && 'picked' !== $fulfillment->state() ) {
			$this->corrections->clear_wave( $user_id, $wave_id );

			return WaveOutcome::failed( 'wrong_stage', 'Undo is not available in the current workflow stage.' );
		}

		// If already transitioned to picked, we cannot safely auto-undo the state —
		// only qty while still picking.
		if ( 'picked' === $fulfillment->state() ) {
			$this->corrections->clear_wave( $user_id, $wave_id );

			return WaveOutcome::failed( 'undo_unavailable', 'Cannot undo after the fulfillment left picking; use manual correction.' );
		}

		$items = $this->items->find_for_fulfillment( $fulfillment_id );
		$item  = null;

		foreach ( $items as $candidate ) {
			if ( (int) $candidate->id() === $item_id ) {
				$item = $candidate;
				break;
			}
		}

		if ( null === $item || $item->qty_picked() !== $expected_qty || $item->qty_picked() <= 0 ) {
			$this->corrections->clear_wave( $user_id, $wave_id );

			return WaveOutcome::failed( 'undo_unavailable', 'Quantities changed since the last scan; use manual correction.' );
		}

		$packing = $this->packing->update_quantities(
			$fulfillment_id,
			$fulfillment->version(),
			array(
				array(
					'item_id'    => $item_id,
					'qty_picked' => $expected_qty - 1,
				),
			),
			$actor
		);

		if ( ! $packing->is_success() ) {
			return WaveOutcome::failed( (string) $packing->failure_code(), (string) $packing->failure_message() );
		}

		$this->corrections->clear_wave( $user_id, $wave_id );

		$this->record_scan_event(
			$fulfillment_id,
			'scan.corrected',
			$actor,
			array(
				'item_id'                   => $item_id,
				'mode'                      => ScanMode::WAVE_PICKING,
				'qty_picked'                => $expected_qty - 1,
				'wave_id'                   => $wave_id,
				'allocation_fulfillment_id' => $fulfillment_id,
				'source'                    => (string) ( $entry['source'] ?? 'sku' ),
			)
		);

		if ( ! $this->waves->save( $wave ) ) {
			return WaveOutcome::failed( 'version_conflict', 'The wave was modified by someone else. Reload and try again.' );
		}

		$fresh = $this->waves->find( $wave_id );

		return WaveOutcome::succeeded(
			$fresh ?? $wave,
			'corrected',
			'Last wave scan undone.',
			array(
				'fulfillment_id'      => $fulfillment_id,
				'item_id'             => $item_id,
				'fulfillment_version' => (int) $packing->version(),
			)
		);
	}

	/**
	 * Asserts wave is active and owned by actor. Returns Wave or WaveOutcome failure.
	 *
	 * @param int   $wave_id Wave id.
	 * @param Actor $actor   Operator.
	 * @return Wave|WaveOutcome
	 */
	private function assert_active_owner( int $wave_id, Actor $actor ) {
		$wave = $this->waves->find( $wave_id );

		if ( null === $wave ) {
			return WaveOutcome::failed( 'not_found', "No wave exists with id {$wave_id}." );
		}

		if ( WaveState::PAUSED === $wave->state() ) {
			return WaveOutcome::failed( 'wave_paused', 'Resume the wave before scanning.' );
		}

		if ( WaveState::ACTIVE !== $wave->state() ) {
			return WaveOutcome::failed( 'wrong_stage', 'Wave Scan Mode is only available while the wave is active.' );
		}

		$user_id = (int) $actor->id();

		if ( $user_id <= 0 || (int) $wave->owner_user_id() !== $user_id ) {
			return WaveOutcome::failed( 'wave_owned', 'Only the wave owner may scan this wave.' );
		}

		return $wave;
	}

	/**
	 * Deterministic FIFO allocation across outstanding wave items.
	 *
	 * @param Wave   $wave    Wave.
	 * @param string $payload Raw scan.
	 * @return array<string, mixed>
	 */
	private function allocate( Wave $wave, string $payload ): array {
		$parsed = BarcodePayload::parse( $payload );

		if ( $parsed->is_empty() ) {
			return array( 'outcome' => WaveOutcome::failed( 'empty_payload', 'Scan was empty.' ) );
		}

		if ( $parsed->is_malformed() ) {
			return array( 'outcome' => WaveOutcome::failed( 'malformed_payload', 'Barcode payload is not a valid MPCF code.' ) );
		}

		if ( $parsed->is_ok() ) {
			$bp = $parsed->payload();

			if ( BarcodePayload::TYPE_WAVE === $bp->type() ) {
				if ( $bp->value() !== (int) $wave->id() ) {
					return array( 'outcome' => WaveOutcome::failed( 'wrong_wave', 'This barcode belongs to a different wave.' ) );
				}

				return array(
					'code'    => 'wave_identity',
					'message' => 'Wave barcode recognized.',
					'wave_id' => (int) $wave->id(),
				);
			}

			if ( BarcodePayload::TYPE_PACKAGE === $bp->type() ) {
				return array( 'outcome' => WaveOutcome::failed( 'wrong_mode', 'Package barcodes are not used in Wave Scan Mode.' ) );
			}

			if ( BarcodePayload::TYPE_FULFILLMENT === $bp->type() ) {
				$member = $wave->member( $bp->value() );

				if ( null === $member ) {
					return array( 'outcome' => WaveOutcome::failed( 'wrong_fulfillment', 'That fulfillment is not a member of this wave.' ) );
				}

				return array(
					'code'           => 'fulfillment_identity',
					'message'        => 'Fulfillment barcode recognized — scan an item next.',
					'fulfillment_id' => $bp->value(),
				);
			}
		}

		$candidates = $this->outstanding_items( $wave );

		if ( array() === $candidates ) {
			return array( 'outcome' => WaveOutcome::failed( 'over_scan', 'No outstanding items remain in this wave.' ) );
		}

		// Item identity must belong to a wave member.
		if ( $parsed->is_ok() && BarcodePayload::TYPE_ITEM === $parsed->payload()->type() ) {
			$item_id = $parsed->payload()->value();
			$matches = array_values(
				array_filter(
					$candidates,
					static fn( array $row ): bool => (int) $row['item']->id() === $item_id
				)
			);

			if ( array() === $matches ) {
				return array( 'outcome' => WaveOutcome::failed( 'item_not_on_wave', 'That item is not an outstanding member of this wave.' ) );
			}

			$chosen = $this->fifo_choose( $matches );

			return $this->allocation_payload( $chosen, 'mpcf_payload' );
		}

		// Group candidates by fulfillment and run M7 resolver per fulfillment,
		// then FIFO across successful item matches.
		$by_fid = array();
		foreach ( $candidates as $row ) {
			$by_fid[ $row['fulfillment_id'] ][] = $row;
		}

		$item_matches = array();
		$rejections   = array();

		foreach ( $by_fid as $fid => $rows ) {
			$items      = array_map( static fn( array $r ): FulfillmentItem => $r['item'], $rows );
			$resolution = $this->resolver->resolve( $payload, $items, (int) $fid );

			if ( $resolution->is_item() ) {
				foreach ( $rows as $row ) {
					if ( (int) $row['item']->id() === (int) $resolution->item()->id() ) {
						$row['source']  = (string) $resolution->source();
						$item_matches[] = $row;
						break;
					}
				}
			} elseif ( $resolution->is_rejected() ) {
				$rejections[] = $resolution;
			}
		}

		if ( array() === $item_matches ) {
			// Prefer ambiguity / variation_required from a single fulfillment over unknown.
			foreach ( $rejections as $rejection ) {
				if ( in_array( $rejection->code(), array( 'ambiguous_sku', 'variation_required' ), true ) ) {
					return array( 'outcome' => WaveOutcome::failed( $rejection->code(), $rejection->message() ) );
				}
			}

			return array( 'outcome' => WaveOutcome::failed( 'unknown_barcode', 'No outstanding wave item matches that barcode.' ) );
		}

		// Same-fulfillment ambiguity already handled by resolver. Multi-
		// fulfillment matches → FIFO.
		$by_fulfillment = array();
		foreach ( $item_matches as $match ) {
			$by_fulfillment[ $match['fulfillment_id'] ][] = $match;
		}

		foreach ( $by_fulfillment as $rows ) {
			if ( count( $rows ) > 1 ) {
				return array( 'outcome' => WaveOutcome::failed( 'ambiguous_sku', 'More than one line matches this barcode on the same fulfillment.' ) );
			}
		}

		$chosen = $this->fifo_choose( $item_matches );

		return $this->allocation_payload( $chosen, (string) ( $chosen['source'] ?? 'sku' ) );
	}

	/**
	 * Outstanding picking items across members still in picking.
	 *
	 * @param Wave $wave Wave.
	 * @return list<array{fulfillment_id:int,fulfillment:Fulfillment,item:FulfillmentItem,created_at:string}>
	 */
	private function outstanding_items( Wave $wave ): array {
		$out = array();

		foreach ( $wave->members() as $member ) {
			if ( $member->is_picked() ) {
				continue;
			}

			$fulfillment = $this->fulfillments->find( $member->fulfillment_id() );

			if ( null === $fulfillment || 'picking' !== $fulfillment->state() ) {
				continue;
			}

			foreach ( $this->items->find_for_fulfillment( $member->fulfillment_id() ) as $item ) {
				if ( $item->qty_picked() >= $item->qty_ordered() ) {
					continue;
				}

				$out[] = array(
					'fulfillment_id' => $member->fulfillment_id(),
					'fulfillment'    => $fulfillment,
					'item'           => $item,
					'created_at'     => $fulfillment->created_at()->format( 'Y-m-d H:i:s' ),
				);
			}
		}

		return $out;
	}

	/**
	 * FIFO choose: earliest fulfillment created_at, then lowest item_id.
	 *
	 * @param list<array<string, mixed>> $matches Candidate rows.
	 * @return array<string, mixed>
	 */
	private function fifo_choose( array $matches ): array {
		usort(
			$matches,
			static function ( array $a, array $b ): int {
				$cmp = strcmp( (string) $a['created_at'], (string) $b['created_at'] );

				if ( 0 !== $cmp ) {
					return $cmp;
				}

				return (int) $a['item']->id() <=> (int) $b['item']->id();
			}
		);

		return $matches[0];
	}

	/**
	 * Builds a success allocation payload for the chosen FIFO row.
	 *
	 * @param array<string, mixed> $chosen Chosen row.
	 * @param string               $source Source label.
	 * @return array<string, mixed>
	 */
	private function allocation_payload( array $chosen, string $source ): array {
		$item = $chosen['item'];
		assert( $item instanceof FulfillmentItem );

		return array(
			'code'           => 'matched',
			'message'        => 'Item matched.',
			'fulfillment_id' => (int) $chosen['fulfillment_id'],
			'item'           => $item,
			'item_id'        => (int) $item->id(),
			'source'         => $source,
		);
	}

	/**
	 * Whether every line is fully picked.
	 *
	 * @param array<int, FulfillmentItem> $items Lines.
	 */
	private function is_fully_picked( array $items ): bool {
		if ( array() === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( ! $item->is_fully_picked() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Appends a fulfillment-scoped scan audit event.
	 *
	 * @param int                  $fulfillment_id Fulfillment id.
	 * @param string               $event_type     Event type.
	 * @param Actor                $actor          Actor.
	 * @param array<string, mixed> $payload        Payload.
	 */
	private function record_scan_event( int $fulfillment_id, string $event_type, Actor $actor, array $payload ): void {
		$event     = DomainEvent::for_fulfillment( $fulfillment_id, $event_type, $actor, $this->clock->now(), $payload );
		$prev_hash = $this->events->last_hash_for_fulfillment( $fulfillment_id );
		$this->events->append( $event, $prev_hash );
		$this->dispatcher->dispatch( $event );
	}
}
