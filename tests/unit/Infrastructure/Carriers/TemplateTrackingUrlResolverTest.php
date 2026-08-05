<?php
/**
 * Tests for the default TrackingUrlResolver.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Infrastructure\Carriers;

use MPCF\Domain\Shipping\Carrier;
use MPCF\Infrastructure\Carriers\TemplateTrackingUrlResolver;
use PHPUnit\Framework\TestCase;

/**
 * Template expansion only — no external APIs.
 */
final class TemplateTrackingUrlResolverTest extends TestCase {

	/**
	 * Resolver under test.
	 *
	 * @var TemplateTrackingUrlResolver
	 */
	private TemplateTrackingUrlResolver $resolver;

	protected function setUp(): void {
		$this->resolver = new TemplateTrackingUrlResolver();
	}

	public function test_expands_tracking_placeholder_with_url_encoding(): void {
		$carrier = Carrier::define(
			array(
				'id'                    => 'ups',
				'label'                 => 'UPS',
				'tracking_url_template' => 'https://www.ups.com/track?tracknum={tracking}',
			)
		);

		$url = $this->resolver->resolve( $carrier, 'ABC 123' );

		self::assertSame( 'https://www.ups.com/track?tracknum=ABC%20123', $url );
	}

	public function test_missing_template_returns_null(): void {
		$carrier = Carrier::define(
			array(
				'id'    => 'other',
				'label' => 'Other',
			)
		);

		self::assertNull( $this->resolver->resolve( $carrier, 'ABC123' ) );
	}

	public function test_empty_tracking_number_returns_null(): void {
		$carrier = Carrier::define(
			array(
				'id'                    => 'postnord',
				'label'                 => 'PostNord',
				'tracking_url_template' => 'https://tracking.postnord.com/en/?id={tracking}',
			)
		);

		self::assertNull( $this->resolver->resolve( $carrier, '' ) );
	}
}
