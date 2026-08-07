<?php
/**
 * Wave state machine unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Wave;

use DateTimeImmutable;
use MPCF\Domain\Wave\Wave;
use MPCF\Domain\Wave\WaveState;
use PHPUnit\Framework\TestCase;

/**
 * Wave aggregate transitions.
 */
final class WaveStateMachineTest extends TestCase {

	public function test_draft_can_activate_and_abandon(): void {
		self::assertTrue( WaveState::can_transition( WaveState::DRAFT, WaveState::ACTIVE ) );
		self::assertTrue( WaveState::can_transition( WaveState::DRAFT, WaveState::ABANDONED ) );
		self::assertFalse( WaveState::can_transition( WaveState::DRAFT, WaveState::PAUSED ) );
	}

	public function test_activate_claims_owner(): void {
		$now  = new DateTimeImmutable( '2026-08-06 12:00:00' );
		$wave = Wave::create( 1, $now );
		$wave->assign_id( 10 );
		$wave->add_member( 100, $now );
		$wave->activate( 7, $now );

		self::assertSame( WaveState::ACTIVE, $wave->state() );
		self::assertSame( 7, $wave->owner_user_id() );
		self::assertNotNull( $wave->activated_at() );
	}

	public function test_cannot_remove_member_while_active(): void {
		$now  = new DateTimeImmutable( '2026-08-06 12:00:00' );
		$wave = Wave::create( 1, $now );
		$wave->assign_id( 10 );
		$wave->add_member( 100, $now );
		$wave->activate( 7, $now );

		$this->expectException( \InvalidArgumentException::class );
		$wave->remove_member( 100, $now );
	}
}
