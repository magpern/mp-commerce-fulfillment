<?php
/**
 * Integration tests for the Settings admin screen (M5-B notifications).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use MPCF\Admin\SettingsPage;
use MPCF\Application\Notifications\NotificationConfigurationService;
use MPCF\Capabilities;
use MPCF\Domain\Notification\NotificationStrategy;
use MPCF\Infrastructure\Carriers\BundledCarrierRegistry;
use MPCF\Plugin;
use MPCF\Settings;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\SectionNavigation;
use WP_UnitTestCase;

/**
 * SettingsPage save path and capability gate for notification configuration.
 */
final class SettingsPageTest extends WP_UnitTestCase {

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @var SettingsPage
	 */
	private SettingsPage $page;

	protected function setUp(): void {
		parent::setUp();
		Plugin::activate();

		$this->settings = new Settings( array() );
		$carriers       = new BundledCarrierRegistry();
		$config         = new NotificationConfigurationService( $this->settings, $carriers );
		$shell          = new AdminPageShell( new SectionNavigation( array() ) );

		$this->page = new SettingsPage(
			$shell,
			new ComponentRenderer(),
			$this->settings,
			$carriers,
			$config
		);
	}

	public function test_render_includes_notifications_card_and_sticky_save(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Notifications', $html );
		self::assertStringContainsString( 'name="notification_strategy"', $html );
		self::assertStringContainsString( 'name="default_carrier_id"', $html );
		self::assertStringContainsString( 'data-mpcf-sticky-root="settings"', $html );
		self::assertStringContainsString( 'data-mpcf-sticky-save', $html );
		self::assertStringContainsString( 'postnord', $html );
	}

	public function test_admin_save_persists_notification_configuration(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'mpcf_settings_nonce'             => wp_create_nonce( 'mpcf_save_settings' ),
			'notification_strategy'           => NotificationStrategy::MPCF_SHIPPED,
			'default_carrier_id'              => 'ups',
			'notification_sender_name'        => 'Warehouse Desk',
			'notification_reply_to'           => 'desk@example.com',
			'notification_email_subject'      => 'On the way',
			'notification_email_introduction' => 'Hi there',
			'notification_tracking_footer'    => 'Footer',
			'notification_email_signature'    => 'Regards',
			'documents_store_name'            => '',
			'documents_address'               => '',
			'documents_footer'                => '',
			'documents_logo_attachment_id'    => '0',
		);

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		unset( $_POST, $_SERVER['REQUEST_METHOD'] );

		self::assertStringContainsString( 'Settings saved.', $html );
		self::assertSame( NotificationStrategy::MPCF_SHIPPED, $this->settings->notification_strategy() );
		self::assertSame( 'ups', $this->settings->default_carrier_id() );
		self::assertSame( 'Warehouse Desk', $this->settings->notification_sender_name() );
		self::assertSame( 'desk@example.com', $this->settings->notification_reply_to() );
		self::assertSame( 'On the way', $this->settings->notification_email_subject() );
	}

	public function test_save_is_forbidden_without_manage_settings_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'mpcf_settings_nonce'   => wp_create_nonce( 'mpcf_save_settings' ),
			'notification_strategy' => NotificationStrategy::DISABLED,
			'default_carrier_id'    => 'dhl',
		);

		ob_start();
		$this->page->render();
		ob_end_clean();

		unset( $_POST, $_SERVER['REQUEST_METHOD'] );

		self::assertSame( NotificationStrategy::COMPLETED_EMAIL, $this->settings->notification_strategy() );
		self::assertSame( '', $this->settings->default_carrier_id() );
	}

	public function test_invalid_strategy_and_carrier_are_coerced_on_save(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'mpcf_settings_nonce'          => wp_create_nonce( 'mpcf_save_settings' ),
			'notification_strategy'        => 'NOT_A_STRATEGY',
			'default_carrier_id'           => 'ghost-carrier',
			'notification_reply_to'        => 'bad-email',
			'documents_store_name'         => '',
			'documents_address'            => '',
			'documents_footer'             => '',
			'documents_logo_attachment_id' => '0',
		);

		ob_start();
		$this->page->render();
		ob_end_clean();

		unset( $_POST, $_SERVER['REQUEST_METHOD'] );

		self::assertSame( NotificationStrategy::COMPLETED_EMAIL, $this->settings->notification_strategy() );
		self::assertSame( 'ghost-carrier', $this->settings->default_carrier_id(), 'Settings stores the raw string; registry fallback is in the configuration service.' );
		self::assertSame( '', $this->settings->notification_reply_to() );

		$config = ( new NotificationConfigurationService( $this->settings, new BundledCarrierRegistry() ) )->get();
		self::assertSame( 'other', $config->default_carrier_id() );
	}
}
