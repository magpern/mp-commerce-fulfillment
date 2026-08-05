<?php
/**
 * Tests for the bundled carrier registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Infrastructure\Carriers;

use MPCF\Domain\Shipping\Carrier;
use MPCF\Infrastructure\Carriers\BundledCarrierRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Immutable EU registry: filter → validate → reject/log/continue.
 */
final class BundledCarrierRegistryTest extends TestCase {

	/**
	 * Captured rejection messages.
	 *
	 * @var list<string>
	 */
	private array $rejections = array();

	/**
	 * Registry under test.
	 *
	 * @var BundledCarrierRegistry
	 */
	private BundledCarrierRegistry $registry;

	protected function setUp(): void {
		$this->rejections = array();
		$this->registry   = new BundledCarrierRegistry(
			null,
			function ( string $message ): void {
				$this->rejections[] = $message;
			}
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'mpcf_carriers' );
		parent::tearDown();
	}

	public function test_bundled_set_includes_eu_carriers_and_other(): void {
		$ids = array_column( $this->registry->all(), 'id' );

		foreach ( array( 'postnord', 'dhl', 'bring', 'dpd', 'gls', 'ups', 'db_schenker', 'budbee', 'instabox', 'other' ) as $expected ) {
			self::assertContains( $expected, $ids );
		}

		self::assertSame( BundledCarrierRegistry::OTHER, end( $ids ) );
		self::assertCount( 10, $ids );
	}

	public function test_all_exposes_additive_metadata(): void {
		$postnord = null;
		foreach ( $this->registry->all() as $carrier ) {
			if ( 'postnord' === $carrier['id'] ) {
				$postnord = $carrier;
				break;
			}
		}

		self::assertNotNull( $postnord );
		self::assertArrayHasKey( 'tracking_url_template', $postnord );
		self::assertArrayHasKey( 'tracking_number_pattern', $postnord );
		self::assertArrayHasKey( 'phone_required', $postnord );
		self::assertStringContainsString( '{tracking}', (string) $postnord['tracking_url_template'] );
		self::assertFalse( $postnord['phone_required'] );
	}

	public function test_all_includes_other_as_the_universal_fallback(): void {
		$ids = array_column( $this->registry->all(), 'id' );

		self::assertContains( BundledCarrierRegistry::OTHER, $ids, 'A merchant must never be blocked on an unbundled carrier.' );
	}

	public function test_other_is_restored_when_filter_removes_it(): void {
		add_filter(
			'mpcf_carriers',
			static function ( array $carriers ): array {
				unset( $carriers['other'] );
				return $carriers;
			}
		);

		$ids = array_column( $this->registry->all(), 'id' );

		self::assertContains( BundledCarrierRegistry::OTHER, $ids );
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

	public function test_malformed_filtered_definition_is_rejected_logged_and_skipped(): void {
		add_filter(
			'mpcf_carriers',
			static function ( array $carriers ): array {
				$carriers['broken'] = array(
					'id'    => '!!!',
					'label' => '',
				);

				return $carriers;
			}
		);

		$types = $this->registry->all();
		$ids   = array_column( $types, 'id' );

		self::assertNotContains( 'broken', $ids );
		self::assertNotContains( '!!!', $ids );
		self::assertContains( 'postnord', $ids );
		self::assertNotEmpty( $this->rejections );
		self::assertStringContainsString( 'Rejected malformed', $this->rejections[0] );
	}

	public function test_malformed_metadata_is_rejected_without_breaking_registry(): void {
		add_filter(
			'mpcf_carriers',
			static function ( array $carriers ): array {
				$carriers['bad_template'] = array(
					'id'                    => 'bad_template',
					'label'                 => 'Bad template',
					'tracking_url_template' => 'https://example.com/no-placeholder',
				);
				$carriers['bad_pattern']  = array(
					'id'                      => 'bad_pattern',
					'label'                   => 'Bad pattern',
					'tracking_number_pattern' => '(unclosed',
				);

				return $carriers;
			}
		);

		$ids = array_column( $this->registry->all(), 'id' );

		self::assertNotContains( 'bad_template', $ids );
		self::assertNotContains( 'bad_pattern', $ids );
		self::assertContains( 'ups', $ids );
		self::assertGreaterThanOrEqual( 2, count( $this->rejections ) );
	}

	public function test_duplicate_ids_log_and_last_definition_wins(): void {
		add_filter(
			'mpcf_carriers',
			static function ( array $carriers ): array {
				$carriers['postnord_dupe'] = array(
					'id'                    => 'postnord',
					'label'                 => 'PostNord Override',
					'tracking_url_template' => 'https://example.com/override?id={tracking}',
				);

				return $carriers;
			}
		);

		$carrier = $this->registry->get( 'postnord' );

		self::assertNotNull( $carrier );
		self::assertSame( 'PostNord Override', $carrier->label() );
		self::assertNotEmpty( $this->rejections );
		self::assertStringContainsString( 'Duplicate carrier id', $this->rejections[0] );
	}

	public function test_filter_may_amend_a_valid_definition(): void {
		add_filter(
			'mpcf_carriers',
			static function ( array $carriers ): array {
				$carriers['postnord']['label'] = 'PostNord Custom';

				return $carriers;
			}
		);

		self::assertSame( 'PostNord Custom', $this->registry->label_for( 'postnord' ) );
	}

	public function test_filter_may_add_a_valid_carrier(): void {
		add_filter(
			'mpcf_carriers',
			static function ( array $carriers ): array {
				$carriers['custom_eu'] = array(
					'id'                    => 'custom_eu',
					'label'                 => 'Custom EU',
					'tracking_url_template' => 'https://track.example/{tracking}',
					'phone_required'        => true,
				);

				return $carriers;
			}
		);

		$carrier = $this->registry->get( 'custom_eu' );

		self::assertInstanceOf( Carrier::class, $carrier );
		self::assertTrue( $carrier->phone_required() );
	}

	public function test_non_array_filter_result_reverts_to_bundled(): void {
		add_filter(
			'mpcf_carriers',
			static function () {
				return 'nope';
			}
		);

		self::assertCount( 10, $this->registry->all() );
		self::assertNotEmpty( $this->rejections );
	}
}
