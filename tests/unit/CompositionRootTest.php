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

	public function test_activate_does_not_throw(): void {
		Plugin::activate();

		$this->addToAssertionCount( 1 );
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

	public function test_no_service_is_constructed_in_the_main_class_file_yet(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../src/Plugin.php' );

		// `new self()` is the singleton pattern itself, not a wired service —
		// excluded so this guard tracks service construction specifically.
		preg_match_all( '/\bnew\s+(?!self\s*\()[A-Za-z_\\\\]+/', $source, $matches );

		self::assertSame(
			array(),
			$matches[0],
			'Plugin.php should not instantiate any service until the milestone that needs it does so deliberately.'
		);
	}
}
