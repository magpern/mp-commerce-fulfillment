<?php
/**
 * Composition root.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF;

use MPCF\Application\EventDispatcher;
use MPCF\Application\IntakeService;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\SystemClock;
use MPCF\Woo\IntakeHooks;
use MPCF\Woo\WooOrderSource;

/**
 * Wires the object graph by hand and lets each service register its own
 * hooks. There is no container (house convention, see the sibling plugins'
 * `Plugin` classes) — no internal service may instantiate a peer; every
 * dependency is constructor-injected from here.
 *
 * Milestone 0 wired nothing beyond the textdomain and the migration
 * drift-check. Milestone 1 adds its first real service graph — intake — in
 * {@see wire_intake()}: every collaborator is constructed here, by name, and
 * handed to the next; none is stored as a property (see
 * `CompositionRootTest::test_plugin_declares_only_singleton_bookkeeping_properties()`),
 * so this class still owns no service-holding state of its own.
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

		$this->wire_intake();
	}

	/**
	 * Creates/updates the schema and grants capabilities/roles.
	 */
	public static function activate(): void {
		( new Migrator() )->migrate();

		Capabilities::activate();
	}

	/**
	 * Builds intake's collaborators and registers its platform hooks. Runs
	 * unconditionally from `init()` (itself gated on the order platform
	 * being active by the main plugin file) — registering the hooks here, rather
	 * than deferring to a later action, is what makes them present in time
	 * for `woocommerce_payment_complete`/`woocommerce_order_status_processing`
	 * to fire during the same request's checkout processing.
	 */
	private function wire_intake(): void {
		$fulfillments = new WpdbFulfillmentRepository();
		$items        = new WpdbFulfillmentItemRepository();
		$events       = new WpdbEventRepository();

		$intake = new IntakeService(
			new WooOrderSource(),
			$fulfillments,
			$items,
			$events,
			new EventDispatcher(),
			new SystemClock(),
			StandardWorkflow::definition()
		);

		( new IntakeHooks( $intake ) )->register();
	}
}
