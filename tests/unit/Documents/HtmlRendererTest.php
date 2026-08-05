<?php
/**
 * Tests for the document HTML renderer.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Documents;

use MPCF\Domain\Document\DocumentModel;
use MPCF\Documents\HtmlRenderer;
use MPCF\Documents\TemplateRegistry;
use MPCF\Engine\DocumentAssembler\PackingSlipAssembler;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the document HTML renderer.
 */
final class HtmlRendererTest extends TestCase {

	private function model(): DocumentModel {
		return new DocumentModel(
			PackingSlipAssembler::DOC_TYPE,
			42,
			'#1042',
			'Anna Andersson',
			array( 'Anna Andersson', 'Storgatan 1', '111 22 Stockholm', 'SE' ),
			'Acme Store',
			array( array( 'sku' => 'SKU-1', 'name' => 'Blue Widget', 'qty_ordered' => 3 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array( 'seq' => 1, 'weight_grams' => 1200, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null, 'tracking_number' => 'COLLI-1' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'#1042'
		);
	}

	public function test_render_produces_html_containing_the_models_data(): void {
		$html = ( new HtmlRenderer( new TemplateRegistry() ) )->render( $this->model() );

		self::assertNotNull( $html );
		self::assertStringContainsString( '#1042', $html );
		self::assertStringContainsString( 'Anna Andersson', $html );
		self::assertStringContainsString( 'Storgatan 1', $html );
		self::assertStringContainsString( 'SKU-1', $html );
		self::assertStringContainsString( 'Blue Widget', $html );
		self::assertStringContainsString( 'COLLI-1', $html );
		self::assertStringContainsString( 'Acme Store', $html );
		self::assertStringContainsString( '<style>', $html, 'The print stylesheet must be inlined, never a separate request.' );
	}

	public function test_render_returns_null_for_an_unbundled_doc_type(): void {
		$model = new DocumentModel( 'pick_list', 42, '#1042', 'Anna', array(), 'Acme', array(), array(), '#1042' );

		self::assertNull( ( new HtmlRenderer( new TemplateRegistry() ) )->render( $model ) );
	}

	public function test_renderer_exposes_html_format_metadata(): void {
		$renderer = new HtmlRenderer( new TemplateRegistry() );

		self::assertSame( 'html', $renderer->format() );
		self::assertSame( 'text/html; charset=UTF-8', $renderer->mime_type() );
	}
}
