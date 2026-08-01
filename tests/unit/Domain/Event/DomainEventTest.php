<?php
/**
 * Tests for the pre-persistence audit event value object.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Event;

use DateTimeImmutable;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pre-persistence audit event value object.
 */
final class DomainEventTest extends TestCase {

	public function test_for_fulfillment_scopes_the_event_to_a_fulfillment(): void {
		$now   = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$actor = Actor::user( 7, 'Jane (Operator)' );

		$event = DomainEvent::for_fulfillment( 1, 'fulfillment.state_changed', $actor, $now, array( 'to' => 'picking' ) );

		self::assertSame( 1, $event->fulfillment_id() );
		self::assertSame( 'fulfillment.state_changed', $event->event_type() );
		self::assertSame( $actor, $event->actor() );
		self::assertSame( $now, $event->occurred_at() );
		self::assertSame( array( 'to' => 'picking' ), $event->payload() );
		self::assertSame( 1, $event->schema_version() );
	}

	public function test_global_event_has_no_fulfillment_id(): void {
		$now   = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$event = DomainEvent::global_event( 'settings.changed', Actor::system(), $now );

		self::assertNull( $event->fulfillment_id() );
	}

	public function test_hashable_payload_folds_in_the_schema_version(): void {
		$now   = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$event = DomainEvent::for_fulfillment( 1, 'fulfillment.state_changed', Actor::system(), $now, array( 'to' => 'picking' ), 3 );

		self::assertSame(
			array(
				'v'  => 3,
				'to' => 'picking',
			),
			$event->hashable_payload()
		);
	}

	public function test_hashable_payload_defaults_schema_version_to_one(): void {
		$now   = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$event = DomainEvent::for_fulfillment( 1, 'fulfillment.state_changed', Actor::system(), $now );

		self::assertSame( array( 'v' => 1 ), $event->hashable_payload() );
	}
}
