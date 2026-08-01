<?php
/**
 * Tests for the audit-log payload canonicalization and hashing.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Event;

use MPCF\Domain\Event\Canonicalizer;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the strategy decision recorded in the M1 execution plan
 * (Architecture Plan Part III §III.2.1): canonicalization must be
 * deterministic regardless of PHP array insertion order, since that is the
 * entire tamper-evidence guarantee the hash chain rests on.
 */
final class CanonicalizerTest extends TestCase {

	public function test_canonical_payload_is_identical_regardless_of_key_order(): void {
		$a = array(
			'to'   => 'picking',
			'from' => 'queued',
		);
		$b = array(
			'from' => 'queued',
			'to'   => 'picking',
		);

		self::assertSame( Canonicalizer::canonical_payload( $a ), Canonicalizer::canonical_payload( $b ) );
	}

	public function test_canonical_payload_sorts_nested_arrays_too(): void {
		$a = array(
			'outer' => array(
				'z' => 1,
				'a' => 2,
			),
		);
		$b = array(
			'outer' => array(
				'a' => 2,
				'z' => 1,
			),
		);

		self::assertSame( Canonicalizer::canonical_payload( $a ), Canonicalizer::canonical_payload( $b ) );
	}

	public function test_canonical_payload_does_not_reorder_list_arrays(): void {
		$payload = array( 'items' => array( 'b', 'a' ) );

		self::assertStringContainsString( '["b","a"]', Canonicalizer::canonical_payload( $payload ) );
	}

	public function test_hash_is_deterministic_for_the_same_prev_hash_and_payload(): void {
		$payload = array( 'to' => 'picking' );

		self::assertSame(
			Canonicalizer::hash( 'abc123', $payload ),
			Canonicalizer::hash( 'abc123', $payload )
		);
	}

	public function test_hash_changes_when_prev_hash_changes(): void {
		$payload = array( 'to' => 'picking' );

		self::assertNotSame(
			Canonicalizer::hash( 'abc123', $payload ),
			Canonicalizer::hash( 'def456', $payload )
		);
	}

	public function test_hash_changes_when_payload_changes(): void {
		self::assertNotSame(
			Canonicalizer::hash( null, array( 'to' => 'picking' ) ),
			Canonicalizer::hash( null, array( 'to' => 'packing' ) )
		);
	}

	public function test_hash_is_identical_for_logically_equal_payloads_in_different_key_order(): void {
		$a = array(
			'to'   => 'picking',
			'from' => 'queued',
		);
		$b = array(
			'from' => 'queued',
			'to'   => 'picking',
		);

		self::assertSame( Canonicalizer::hash( 'seed', $a ), Canonicalizer::hash( 'seed', $b ) );
	}

	public function test_hash_returns_a_sha256_hex_digest(): void {
		$hash = Canonicalizer::hash( null, array( 'to' => 'picking' ) );

		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $hash );
	}
}
