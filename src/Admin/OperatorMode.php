<?php
/**
 * Reduces wp-admin chrome to the Fulfillment workspace for operators.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Capabilities;
use MPCF\Settings;

/**
 * Architecture Plan Sec9.1: "operators are not WordPress users culturally,
 * and every visible menu is a support ticket." Default off
 * ({@see Settings::operator_mode_enabled()}); when on, adds a body class
 * (`assets/admin/css/mpcf-admin.css` hides the rest of wp-admin's own
 * navigation menu for it) to genuinely operator-tier accounts only.
 *
 * "Operator-tier" is decided entirely by capability, never a role-name
 * string: a user who can `manage_options` (the core administrator
 * capability) always keeps full chrome regardless of this setting — the
 * safe escape hatch an admin who enabled this setting still needs — and a
 * Warehouse Lead (who holds every `mpcf_*` capability, including
 * cancellation) is excluded the same way. Only a user who can view the
 * Queue but cannot cancel a fulfillment — exactly the Warehouse Operator
 * capability set — gets the reduced chrome.
 */
final class OperatorMode {

	/**
	 * The outbound-enabled toggle.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Builds the service.
	 *
	 * @param Settings $settings Operator Mode's own toggle.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_filter( 'admin_body_class', array( $this, 'maybe_add_body_class' ) );
	}

	/**
	 * Adds the chrome-reducing body class for an operator-tier user, when
	 * the setting is on.
	 *
	 * @param string $classes Space-separated existing body classes.
	 */
	public function maybe_add_body_class( string $classes ): string {
		if ( ! $this->settings->operator_mode_enabled() ) {
			return $classes;
		}

		if ( ! self::current_user_is_operator_tier() ) {
			return $classes;
		}

		return $classes . ' mpcf-operator-mode';
	}

	/**
	 * Whether the current user is operator-tier: can view the Queue, but
	 * cannot cancel a fulfillment and is not a site administrator.
	 */
	private static function current_user_is_operator_tier(): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return false;
		}

		return current_user_can( Capabilities::VIEW_QUEUE ) && ! current_user_can( Capabilities::CANCEL_FULFILLMENT );
	}
}
