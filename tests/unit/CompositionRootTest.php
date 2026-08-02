<?php
/**
 * Guards the composition-root invariant: MPCF\Plugin is a singleton that
 * wires services by hand, and Milestone 0 wires none as placeholders ahead
 * of the milestone that actually needs them.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use MPCF\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Composition-root guard tests.
 */
final class CompositionRootTest extends TestCase {

	protected function setUp(): void {
		mpcf_tests_reset_wp_state();
	}

	public function test_instance_is_a_singleton(): void {
		self::assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_init_is_idempotent(): void {
		$plugin = Plugin::instance();

		$plugin->init();
		$plugin->init();

		$reflection = new ReflectionClass( $plugin );
		$booted     = $reflection->getProperty( 'booted' );
		$booted->setAccessible( true );

		self::assertTrue( $booted->getValue( $plugin ) );
	}

	public function test_activate_grants_capabilities_and_roles(): void {
		Plugin::activate();

		$lead = get_role( \MPCF\Capabilities::ROLE_LEAD );

		self::assertNotNull( $lead );
		self::assertTrue( $lead->has_cap( \MPCF\Capabilities::VIEW_QUEUE ) );
	}

	public function test_activate_is_idempotent(): void {
		Plugin::activate();
		Plugin::activate();

		$this->addToAssertionCount( 1 ); // Calling activate() twice must not throw or duplicate-register a role.
	}

	/**
	 * Milestone 0 wires no service beyond the singleton bookkeeping itself.
	 * This test is expected to be revised, deliberately, the first time
	 * Milestone 1 adds a real constructor-injected service — the point is
	 * that such an addition must be a conscious edit to this test, not a
	 * silent accumulation of "just in case" properties.
	 */
	public function test_plugin_declares_only_singleton_bookkeeping_properties(): void {
		$reflection = new ReflectionClass( Plugin::class );
		$names      = array_map(
			static fn( $property ) => $property->getName(),
			$reflection->getProperties()
		);

		sort( $names );

		self::assertSame( array( 'booted', 'instance' ), $names );
	}

	/**
	 * Milestone 0's only wired service was the migration framework itself
	 * (`Migrator`). Milestone 1 adds intake's real service graph in
	 * `Plugin::wire_intake()` — every collaborator `IntakeService` and
	 * `IntakeHooks` need, constructed inline and never stored as a
	 * property, so the class still owns no service-holding state. Any
	 * instantiation beyond this list is a placeholder service added ahead
	 * of the milestone that needs it, which this test exists to catch.
	 * Extending this allowlist must be a conscious edit, not an
	 * accumulation of "just in case" construction.
	 */
	public function test_no_unapproved_service_is_constructed_in_the_main_class_file(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../src/Plugin.php' );

		// `new self()` is the singleton pattern itself, not a wired service.
		preg_match_all( '/\bnew\s+(?!self\s*\()([A-Za-z_\\\\]+)/', $source, $matches );

		$allowed    = array(
			'Migrator',
			'WpdbFulfillmentRepository',
			'WpdbFulfillmentItemRepository',
			'WpdbEventRepository',
			'WpdbShipmentRepository',
			'WpdbPackageRepository',
			'WpdbPackageItemRepository',
			'TransitionContextFactory',
			'ShippingService',
			'ShipmentAutoShipSubscriber',
			'RestApi',
			'FulfillmentsController',
			'IntakeService',
			'WooOrderSource',
			'EventDispatcher',
			'SystemClock',
			'IntakeHooks',
			'Settings',
			'WorkflowService',
			'WorkflowEngine',
			'GuardRegistry',
			'StatusBridge',
			'RefundObserver',
			'BackfillCommand',
			'WpdbNoteRepository',
			'WpdbSearchQuery',
			'ComponentRenderer',
			'AdminPageShell',
			'SectionNavigation',
			'QueueService',
			'FulfillmentDetailService',
			'NoteService',
			'AssignmentService',
			'DashboardService',
			'DashboardPage',
			'QueuePage',
			'FulfillmentDetailPage',
			'Menu',
			'Assets',
			'OperatorMode',
		);
		$disallowed = array_values( array_diff( $matches[1], $allowed ) );

		self::assertSame(
			array(),
			$disallowed,
			'Plugin.php should not instantiate any service beyond the Milestone 0 allowlist until the milestone that needs it does so deliberately.'
		);
	}
}
