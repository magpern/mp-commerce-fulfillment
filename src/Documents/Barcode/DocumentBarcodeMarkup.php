<?php
/**
 * Shared barcode markup for document templates.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Documents\Barcode;

/**
 * Renders Code 128 SVG plus optional secondary human-readable label.
 */
final class DocumentBarcodeMarkup {

	/**
	 * HTML fragment for a document barcode block.
	 *
	 * @param string      $payload         Exact encoded payload (e.g. MPCF:F:12).
	 * @param string|null $human_fallback  Secondary human text (order number).
	 */
	public static function render_block( string $payload, ?string $human_fallback = null ): string {
		$svg = Code128SvgRenderer::render( $payload, 2, 48, true );

		$html  = '<div class="mpcf-barcode-block" data-mpcf-barcode-payload="' . self::esc( $payload ) . '">';
		$html .= $svg;

		if ( null !== $human_fallback && '' !== $human_fallback && $human_fallback !== $payload ) {
			$html .= '<div class="mpcf-barcode-human">' . self::esc( $human_fallback ) . '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Escapes text for HTML attributes and text nodes.
	 *
	 * @param string $value Raw value.
	 */
	private static function esc( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
