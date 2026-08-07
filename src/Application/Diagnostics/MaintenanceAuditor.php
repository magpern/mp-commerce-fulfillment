<?php
/**
 * Emits maintenance.* audit events for repairs.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Diagnostics;

use MPCF\Domain\Clock;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Application\EventDispatcher;

/**
 * Global (non-fulfillment) maintenance audit trail.
 */
final class MaintenanceAuditor {

	/**
	 * Builds the maintenance auditor.
	 *
	 * @param EventRepository $events     Event store.
	 * @param EventDispatcher $dispatcher In-process bus.
	 * @param Clock           $clock      Clock.
	 */
	public function __construct(
		private EventRepository $events,
		private EventDispatcher $dispatcher,
		private Clock $clock
	) {
	}

	/**
	 * Records a maintenance event. Payload must not contain secrets.
	 *
	 * @param string               $action  Short action key (e.g. repair.schedules).
	 * @param array<string, mixed> $payload Structured detail.
	 */
	public function record( string $action, array $payload ): void {
		$event = DomainEvent::global_event(
			'maintenance.' . $action,
			Actor::system( 'mpcf-maintenance' ),
			$this->clock->now(),
			$payload
		);
		$this->events->append( $event, null );
		$this->dispatcher->dispatch( $event );
	}
}
