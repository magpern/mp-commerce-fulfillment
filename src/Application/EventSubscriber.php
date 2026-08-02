<?php
/**
 * Contract for one domain-event listener.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\Event\DomainEvent;

/**
 * Registered against {@see EventDispatcher} for a specific event type.
 * Implementations that need to name a platform-integration symbol belong
 * in `src/Woo/` (invariant I8) even though this interface itself lives in
 * Application.
 */
interface EventSubscriber {

	/**
	 * Handles one dispatched event.
	 *
	 * @param DomainEvent $event The event that was appended to the audit log.
	 */
	public function handle( DomainEvent $event ): void;
}
