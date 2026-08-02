<?php
/**
 * In-process, synchronous domain-event dispatcher.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\Event\DomainEvent;

/**
 * Ordered subscribers per event type, registered in the composition root.
 * Dispatch is synchronous and in-process — this class has no queue, no
 * platform hook, and no knowledge of anything beyond the subscriber list it
 * was given. A platform-integration bridge (`src/Woo/`, invariant I8) that
 * wants to re-broadcast an event to a platform hook is just another
 * subscriber; this class is not the place that happens.
 */
final class EventDispatcher {

	/**
	 * Registered subscribers, keyed by event type, in registration order.
	 *
	 * @var array<string, array<int, EventSubscriber>>
	 */
	private array $subscribers = array();

	/**
	 * Registers a subscriber for an event type. Order of registration is
	 * the order subscribers are called in.
	 *
	 * @param string          $event_type Event type to listen for.
	 * @param EventSubscriber $subscriber Subscriber to register.
	 */
	public function subscribe( string $event_type, EventSubscriber $subscriber ): void {
		$this->subscribers[ $event_type ][] = $subscriber;
	}

	/**
	 * Calls every subscriber registered for this event's type, in
	 * registration order.
	 *
	 * @param DomainEvent $event Event to dispatch.
	 */
	public function dispatch( DomainEvent $event ): void {
		foreach ( $this->subscribers[ $event->event_type() ] ?? array() as $subscriber ) {
			$subscriber->handle( $event );
		}
	}
}
