<?php
/**
 * Tests for NotificationStrategy.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Notification;

use MPCF\Domain\Notification\NotificationStrategy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Strategy is an immutable enum — not scattered booleans.
 */
final class NotificationStrategyTest extends TestCase {

	public function test_default_is_completed_email(): void {
		self::assertSame( NotificationStrategy::COMPLETED_EMAIL, NotificationStrategy::default()->value() );
	}

	public function test_from_accepts_every_canonical_value(): void {
		foreach ( NotificationStrategy::values() as $value ) {
			self::assertSame( $value, NotificationStrategy::from( $value )->value() );
		}
	}

	public function test_invalid_strategy_falls_back_to_default(): void {
		self::assertSame(
			NotificationStrategy::COMPLETED_EMAIL,
			NotificationStrategy::from( 'nope' )->value()
		);
		self::assertNull( NotificationStrategy::try_from( 'nope' ) );
		self::assertNull( NotificationStrategy::try_from( array() ) );
	}

	public function test_includes_helpers(): void {
		self::assertTrue( NotificationStrategy::from( NotificationStrategy::COMPLETED_EMAIL )->includes_completed_email() );
		self::assertFalse( NotificationStrategy::from( NotificationStrategy::COMPLETED_EMAIL )->includes_mpcf_shipped() );
		self::assertTrue( NotificationStrategy::from( NotificationStrategy::BOTH )->includes_completed_email() );
		self::assertTrue( NotificationStrategy::from( NotificationStrategy::BOTH )->includes_mpcf_shipped() );
		self::assertTrue( NotificationStrategy::from( NotificationStrategy::DISABLED )->is_disabled() );
	}

	public function test_strategy_is_immutable(): void {
		$reflection = new ReflectionClass( NotificationStrategy::class );

		self::assertTrue( $reflection->isFinal() );
		self::assertTrue( $reflection->getConstructor()->isPrivate() );

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			self::assertDoesNotMatchRegularExpression( '/^set/', $method->getName() );
		}
	}
}
