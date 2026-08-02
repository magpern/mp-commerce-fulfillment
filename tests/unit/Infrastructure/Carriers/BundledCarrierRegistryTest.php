<?php
/**
 * Tests for the bundled carrier registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Infrastructure\Carriers;

use MPCF\Infrastructure\Carriers\BundledCarrierRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the bundled carrier registry.
 */
final class BundledCarrierRegistryTest extends TestCase {

	/**
	 * Registry under test.
	 *
	 * @var BundledCarrierRegistry
	 */
	private BundledCarrierRegistry $registry;

	protected function setUp(): void {
		$this->registry = new BundledCarrierRegistry();
	}

	public function test_all_includes_other_as_the_universal_fallback(): void {
		$ids = array_column( $this->registry->all(), 'id' );

		self::assertContains( BundledCarrierRegistry::OTHER, $ids, 'A merchant must never be blocked on an unbundled carrier.' );
	}

	public function test_label_for_known_carrier(): void {
		self::assertSame( 'PostNord', $this->registry->label_for( 'postnord' ) );
	}

	public function test_label_for_unknown_carrier_falls_back_to_the_id_itself(): void {
		self::assertSame( 'unbundled-carrier', $this->registry->label_for( 'unbundled-carrier' ) );
	}

	public function test_tracking_url_for_known_carrier_substitutes_the_tracking_number(): void {
		$url = $this->registry->tracking_url_for( 'ups', 'ABC 123' );

		self::assertNotNull( $url );
		self::assertStringContainsString( 'ABC%20123', $url, 'The tracking number must be URL-encoded into the template.' );
	}

	public function test_tracking_url_for_other_is_always_null(): void {
		self::assertNull( $this->registry->tracking_url_for( BundledCarrierRegistry::OTHER, 'ABC123' ) );
	}

	public function test_tracking_url_for_unknown_carrier_is_null(): void {
		self::assertNull( $this->registry->tracking_url_for( 'unbundled-carrier', 'ABC123' ) );
	}

	public function test_tracking_url_for_empty_tracking_number_is_null(): void {
		self::assertNull( $this->registry->tracking_url_for( 'postnord', '' ) );
	}
}
