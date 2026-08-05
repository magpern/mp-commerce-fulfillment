<?php
/**
 * Tests for the immutable Carrier value object.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Shipping;

use MPCF\Domain\Shipping\Carrier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Carrier definitions are immutable and validated like DocumentType.
 */
final class CarrierTest extends TestCase {

	public function test_valid_carrier_definition(): void {
		$carrier = Carrier::define(
			array(
				'id'                      => 'postnord',
				'label'                   => 'PostNord',
				'tracking_url_template'   => 'https://tracking.example/?id={tracking}',
				'tracking_number_pattern' => '^[A-Z0-9]+$',
				'phone_required'          => false,
			)
		);

		self::assertTrue( $carrier->is_valid() );
		self::assertSame( 'postnord', $carrier->id() );
		self::assertSame( 'PostNord', $carrier->label() );
		self::assertSame( 'https://tracking.example/?id={tracking}', $carrier->tracking_url_template() );
		self::assertSame( '^[A-Z0-9]+$', $carrier->tracking_number_pattern() );
		self::assertFalse( $carrier->phone_required() );
	}

	public function test_other_without_template_is_valid(): void {
		$carrier = Carrier::define(
			array(
				'id'    => 'other',
				'label' => 'Other',
			)
		);

		self::assertTrue( $carrier->is_valid() );
		self::assertNull( $carrier->tracking_url_template() );
	}

	public function test_invalid_id_is_rejected(): void {
		$carrier = Carrier::define(
			array(
				'id'    => '!!!',
				'label' => 'Broken',
			)
		);

		self::assertFalse( $carrier->is_valid() );
	}

	public function test_empty_label_is_rejected(): void {
		$carrier = Carrier::define(
			array(
				'id'    => 'broken',
				'label' => '',
			)
		);

		self::assertFalse( $carrier->is_valid() );
	}

	public function test_template_without_placeholder_is_rejected(): void {
		$carrier = Carrier::define(
			array(
				'id'                    => 'broken',
				'label'                 => 'Broken',
				'tracking_url_template' => 'https://example.com/track',
			)
		);

		self::assertFalse( $carrier->is_valid() );
	}

	public function test_non_https_template_is_rejected(): void {
		$carrier = Carrier::define(
			array(
				'id'                    => 'broken',
				'label'                 => 'Broken',
				'tracking_url_template' => 'http://example.com/{tracking}',
			)
		);

		self::assertFalse( $carrier->is_valid() );
	}

	public function test_malformed_pattern_is_rejected(): void {
		$carrier = Carrier::define(
			array(
				'id'                      => 'broken',
				'label'                   => 'Broken',
				'tracking_number_pattern' => '(unclosed',
			)
		);

		self::assertFalse( $carrier->is_valid() );
	}

	public function test_carrier_has_no_mutators(): void {
		$methods = ( new ReflectionClass( Carrier::class ) )->getMethods();

		foreach ( $methods as $method ) {
			self::assertDoesNotMatchRegularExpression(
				'/^set/',
				$method->getName(),
				'Carrier definitions must be immutable after registration.'
			);
		}
	}
}
