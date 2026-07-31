<?php
/**
 * Smoke test for the vendored, rewritten MP Admin Design System renderer.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use MPCF\Vendor\Mpds\ComponentRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Proves bin/sync-mpds.sh's rewrite actually took effect at runtime: the
 * vendored, namespace-rewritten class is autoloadable under MPCF\Vendor\Mpds
 * (no separate composer.json mapping needed — it falls under the existing
 * MPCF\ -> src/ PSR-4 rule), and every class it emits is mpcf-ui-*, never
 * mpds-ui-*.
 */
final class MpdsRendererSmokeTest extends TestCase {

	public function test_rewritten_renderer_emits_mpcf_ui_classes_and_no_mpds_remnants(): void {
		$renderer = new ComponentRenderer();

		$html = $renderer->page_intro( 'Title', 'Description' )
			. $renderer->status_badge( 'Active', 'active' )
			. $renderer->sticky_save_bar( 'settings' );

		self::assertStringContainsString( 'mpcf-ui-page-intro', $html );
		self::assertStringContainsString( 'mpcf-ui-status-badge', $html );
		self::assertStringContainsString( 'data-mpcf-sticky-save', $html );
		self::assertStringNotContainsString( 'mpds-ui-', $html );
		self::assertStringNotContainsString( 'data-mpds-', $html );
	}
}
