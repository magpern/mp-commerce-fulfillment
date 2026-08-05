<?php
/**
 * Tests for NotificationConfigurationService.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Notifications;

use MPCF\Application\Notifications\NotificationConfiguration;
use MPCF\Application\Notifications\NotificationConfigurationService;
use MPCF\Domain\CarrierRegistry;
use MPCF\Domain\Notification\NotificationStrategy;
use MPCF\Infrastructure\Carriers\BundledCarrierRegistry;
use MPCF\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Configuration service validates and never exposes invalid state.
 */
final class NotificationConfigurationServiceTest extends TestCase {

	protected function setUp(): void {
		mpcf_tests_reset_wp_state();
	}

	public function test_defaults_produce_valid_configuration(): void {
		$config = $this->service( new Settings( array() ) )->get();

		self::assertInstanceOf( NotificationConfiguration::class, $config );
		self::assertSame( NotificationStrategy::COMPLETED_EMAIL, $config->strategy()->value() );
		self::assertSame( CarrierRegistry::OTHER, $config->default_carrier_id() );
		self::assertSame( NotificationConfigurationService::DEFAULT_SUBJECT, $config->default_email_subject() );
	}

	public function test_invalid_strategy_never_surfaces(): void {
		$settings = new Settings( array( 'notification_strategy' => 'bogus' ) );

		self::assertSame(
			NotificationStrategy::COMPLETED_EMAIL,
			$this->service( $settings )->strategy()->value()
		);
	}

	public function test_unknown_carrier_falls_back_to_other(): void {
		$settings = new Settings( array( 'default_carrier_id' => 'not-a-real-carrier' ) );

		self::assertSame( CarrierRegistry::OTHER, $this->service( $settings )->default_carrier_id() );
	}

	public function test_empty_carrier_falls_back_to_other(): void {
		$settings = new Settings( array( 'default_carrier_id' => '' ) );

		self::assertSame( CarrierRegistry::OTHER, $this->service( $settings )->default_carrier_id() );
	}

	public function test_known_carrier_is_preserved(): void {
		$settings = new Settings( array( 'default_carrier_id' => 'postnord' ) );

		self::assertSame( 'postnord', $this->service( $settings )->default_carrier_id() );
	}

	public function test_empty_subject_uses_default(): void {
		$settings = new Settings( array( 'notification_email_subject' => '' ) );

		self::assertSame(
			NotificationConfigurationService::DEFAULT_SUBJECT,
			$this->service( $settings )->get()->default_email_subject()
		);
	}

	public function test_configuration_exposes_sanitized_text_fields(): void {
		$settings = new Settings(
			array(
				'notification_strategy'           => NotificationStrategy::MPCF_SHIPPED,
				'default_carrier_id'              => 'dhl',
				'notification_sender_name'        => 'Warehouse',
				'notification_reply_to'           => 'ops@example.com',
				'notification_tracking_footer'    => "Track carefully\nplease",
				'notification_email_subject'      => 'Shipped!',
				'notification_email_introduction' => 'Hello',
				'notification_email_signature'    => 'Bye',
			)
		);

		$config = $this->service( $settings )->get();

		self::assertSame( NotificationStrategy::MPCF_SHIPPED, $config->strategy()->value() );
		self::assertSame( 'dhl', $config->default_carrier_id() );
		self::assertSame( 'Warehouse', $config->sender_name() );
		self::assertSame( 'ops@example.com', $config->reply_to_email() );
		self::assertSame( "Track carefully\nplease", $config->tracking_message_footer() );
		self::assertSame( 'Shipped!', $config->default_email_subject() );
		self::assertSame( 'Hello', $config->email_introduction() );
		self::assertSame( 'Bye', $config->email_signature() );
	}

	public function test_configuration_is_immutable(): void {
		$reflection = new ReflectionClass( NotificationConfiguration::class );

		self::assertTrue( $reflection->isFinal() );

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			self::assertDoesNotMatchRegularExpression( '/^set/', $method->getName() );
		}
	}

	/**
	 * @param Settings $settings Settings fixture.
	 */
	private function service( Settings $settings ): NotificationConfigurationService {
		return new NotificationConfigurationService( $settings, new BundledCarrierRegistry() );
	}
}
