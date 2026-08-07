<?php
/**
 * Audit chain verifier + privacy actor scrub preserve hashes.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Diagnostics;

use DateTimeImmutable;
use MPCF\Application\Diagnostics\AuditChainVerifier;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\Canonicalizer;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use PHPUnit\Framework\TestCase;

/**
 * Hash chain stays valid when actor columns change.
 */
final class AuditChainVerifierTest extends TestCase {

	public function test_valid_chain_passes(): void {
		$events = new InMemoryEventRepository();
		$prev   = null;
		for ( $i = 0; $i < 3; $i++ ) {
			$event = DomainEvent::for_fulfillment(
				42,
				'fulfillment.state_changed',
				Actor::user( 7, 'Alice' ),
				new DateTimeImmutable( '2026-01-0' . ( $i + 1 ) . 'T12:00:00+00:00' ),
				array(
					'from' => 'queued',
					'to'   => 'picking',
					'i'    => $i,
				)
			);
			$prev  = $events->append( $event, $prev );
		}

		$verifier = new AuditChainVerifier( $events );
		$result   = $verifier->verify_fulfillment( 42 );
		self::assertTrue( $result['ok'] );
		self::assertSame( 3, $result['events'] );
	}

	public function test_actor_anonymization_does_not_break_hash(): void {
		$events = new InMemoryEventRepository();
		$event  = DomainEvent::for_fulfillment(
			9,
			'fulfillment.state_changed',
			Actor::user( 3, 'Bob' ),
			new DateTimeImmutable( '2026-02-01T12:00:00+00:00' ),
			array(
				'from' => 'queued',
				'to'   => 'picking',
			)
		);
		$events->append( $event, null );

		// Simulate privacy eraser: mutate actor fields outside the hashable payload.
		$ref  = new \ReflectionClass( $events );
		$prop = $ref->getProperty( 'rows' );
		$prop->setAccessible( true );
		$rows                            = $prop->getValue( $events );
		$rows[0]['actor_id']             = null;
		$rows[0]['actor_label_snapshot'] = '[erased]';
		$prop->setValue( $events, $rows );

		$verifier = new AuditChainVerifier( $events );
		self::assertTrue( $verifier->verify_fulfillment( 9 )['ok'] );

		// Confirm hash still matches payload-only recomputation.
		$payload = array( 'v' => 1 ) + array(
			'from' => 'queued',
			'to'   => 'picking',
		);
		self::assertSame( $rows[0]['hash'], Canonicalizer::hash( null, $payload ) );
	}

	public function test_corruption_fails(): void {
		$events = new InMemoryEventRepository();
		$event  = DomainEvent::for_fulfillment(
			1,
			'fulfillment.state_changed',
			Actor::system(),
			new DateTimeImmutable( '2026-03-01T12:00:00+00:00' ),
			array(
				'from' => 'queued',
				'to'   => 'picking',
			)
		);
		$events->append( $event, null );

		$ref  = new \ReflectionClass( $events );
		$prop = $ref->getProperty( 'rows' );
		$prop->setAccessible( true );
		$rows            = $prop->getValue( $events );
		$rows[0]['hash'] = str_repeat( '0', 64 );
		$prop->setValue( $events, $rows );

		$verifier = new AuditChainVerifier( $events );
		self::assertFalse( $verifier->verify_fulfillment( 1 )['ok'] );
	}
}
