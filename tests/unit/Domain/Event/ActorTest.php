<?php
/**
 * Tests for the audit-event actor value object.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Event;

use MPCF\Domain\Event\Actor;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the audit-event actor value object.
 */
final class ActorTest extends TestCase {

	public function test_user_actor(): void {
		$actor = Actor::user( 7, 'Jane (Operator)' );

		self::assertSame( Actor::TYPE_USER, $actor->type() );
		self::assertSame( 7, $actor->id() );
		self::assertSame( 'Jane (Operator)', $actor->label() );
	}

	public function test_system_actor_has_no_id_and_defaults_its_label(): void {
		$actor = Actor::system();

		self::assertSame( Actor::TYPE_SYSTEM, $actor->type() );
		self::assertNull( $actor->id() );
		self::assertSame( 'System', $actor->label() );
	}

	public function test_api_actor_has_no_id_and_defaults_its_label(): void {
		$actor = Actor::api();

		self::assertSame( Actor::TYPE_API, $actor->type() );
		self::assertNull( $actor->id() );
		self::assertSame( 'API', $actor->label() );
	}

	public function test_to_array_and_from_array_round_trip(): void {
		$actor   = Actor::user( 7, 'Jane (Operator)' );
		$rebuilt = Actor::from_array( $actor->to_array() );

		self::assertSame( $actor->to_array(), $rebuilt->to_array() );
	}

	public function test_from_array_defaults_id_to_null_when_absent(): void {
		$actor = Actor::from_array(
			array(
				'type'  => Actor::TYPE_SYSTEM,
				'label' => 'System',
			)
		);

		self::assertNull( $actor->id() );
	}
}
