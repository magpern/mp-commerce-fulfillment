<?php
/**
 * Tests for the in-process domain-event dispatcher.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application;

use DateTimeImmutable;
use MPCF\Application\EventDispatcher;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Tests\Unit\Application\Doubles\RecordingSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the in-process domain-event dispatcher.
 */
final class EventDispatcherTest extends TestCase {

	public function test_dispatch_calls_every_subscriber_registered_for_the_events_type(): void {
		$dispatcher = new EventDispatcher();
		$subscriber = new RecordingSubscriber();
		$dispatcher->subscribe( 'fulfillment.state_changed', $subscriber );

		$event = DomainEvent::for_fulfillment( 1, 'fulfillment.state_changed', Actor::system(), new DateTimeImmutable() );
		$dispatcher->dispatch( $event );

		self::assertSame( array( $event ), $subscriber->received() );
	}

	public function test_dispatch_calls_subscribers_in_registration_order(): void {
		$dispatcher = new EventDispatcher();
		$order      = array();

		$dispatcher->subscribe(
			'fulfillment.state_changed',
			new class( $order ) implements \MPCF\Application\EventSubscriber {
				/**
				 * @var array<int, string>
				 */
				private array $order;
				public function __construct( array &$order ) {
					$this->order = &$order;
				}
				public function handle( DomainEvent $event ): void {
					$this->order[] = 'first';
				}
			}
		);
		$dispatcher->subscribe(
			'fulfillment.state_changed',
			new class( $order ) implements \MPCF\Application\EventSubscriber {
				/**
				 * @var array<int, string>
				 */
				private array $order;
				public function __construct( array &$order ) {
					$this->order = &$order;
				}
				public function handle( DomainEvent $event ): void {
					$this->order[] = 'second';
				}
			}
		);

		$dispatcher->dispatch( DomainEvent::for_fulfillment( 1, 'fulfillment.state_changed', Actor::system(), new DateTimeImmutable() ) );

		self::assertSame( array( 'first', 'second' ), $order );
	}

	public function test_dispatch_does_not_call_a_subscriber_registered_for_a_different_event_type(): void {
		$dispatcher = new EventDispatcher();
		$subscriber = new RecordingSubscriber();
		$dispatcher->subscribe( 'fulfillment.cancelled', $subscriber );

		$dispatcher->dispatch( DomainEvent::for_fulfillment( 1, 'fulfillment.state_changed', Actor::system(), new DateTimeImmutable() ) );

		self::assertSame( array(), $subscriber->received() );
	}

	public function test_dispatch_is_a_no_op_when_nothing_is_subscribed(): void {
		$dispatcher = new EventDispatcher();

		$dispatcher->dispatch( DomainEvent::for_fulfillment( 1, 'fulfillment.state_changed', Actor::system(), new DateTimeImmutable() ) );

		$this->addToAssertionCount( 1 );
	}
}
