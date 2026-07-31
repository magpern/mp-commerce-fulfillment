<?php
/**
 * Composition root.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF;

use MPCF\Infrastructure\Database\Migrator;

/**
 * Wires the object graph by hand and lets each service register its own
 * hooks. There is no container (house convention, see the sibling plugins'
 * `Plugin` classes) — no internal service may instantiate a peer; every
 * dependency is constructor-injected from here.
 *
 * Milestone 0 wires nothing beyond the textdomain and the migration
 * drift-check: no Domain/Engine/Application service exists yet, and none is
 * added here as a placeholder ahead of the milestone that needs it.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether services have been wired.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Returns the shared plugin instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wires services and registers hooks. Idempotent.
	 */
	public function init(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'mp-commerce-fulfillment', false, dirname( plugin_basename( MPCF_PLUGIN_FILE ) ) . '/languages' );
			}
		);

		// Bind-mount deployments update files in place and never fire the
		// activation hook, so schema drift has to be caught on its own (see
		// Infrastructure\Database\Migrator).
		add_action(
			'admin_init',
			static function () {
				( new Migrator() )->maybe_migrate();
			}
		);
	}

	/**
	 * Creates/updates the schema and grants capabilities/roles.
	 */
	public static function activate(): void {
		( new Migrator() )->migrate();

		Capabilities::activate();
	}
}
