<?php
/**
 * Renders a Code 128 barcode as inline SVG markup.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Documents\Barcode;

/**
 * Deterministic SVG bars + human-readable value. Safe for HTML document
 * templates when the caller escapes the human text separately if needed —
 * this method HTML-escapes attributes and text itself.
 */
final class Code128SvgRenderer {

	/**
	 * Builds an SVG barcode for `$payload`.
	 *
	 * @param string $payload     Exact barcode payload string.
	 * @param int    $module_width Bar module width in SVG units.
	 * @param int    $height      Bar height in SVG units.
	 * @param bool   $show_text   Whether to render human-readable text under bars.
	 */
	public static function render( string $payload, int $module_width = 2, int $height = 48, bool $show_text = true ): string {
		$widths    = Code128Encoder::encode_widths( $payload );
		$modules   = Code128Encoder::module_count( $widths );
		$quiet     = 10;
		$bar_width = max( 1, $module_width );
		$content_w = ( $modules + ( 2 * $quiet ) ) * $bar_width;
		$text_h    = $show_text ? 14 : 0;
		$total_h   = $height + ( $text_h > 0 ? $text_h : 0 );
		$x         = $quiet * $bar_width;
		$is_bar    = true;
		$rects     = '';
		$len       = strlen( $widths );

		for ( $i = 0; $i < $len; $i++ ) {
			$w = (int) $widths[ $i ] * $bar_width;

			if ( $is_bar ) {
				$rects .= sprintf(
					'<rect x="%d" y="0" width="%d" height="%d" fill="#000"/>',
					$x,
					$w,
					$height
				);
			}

			$x     += $w;
			$is_bar = ! $is_bar;
		}

		$text = '';

		if ( $show_text ) {
			$text = sprintf(
				'<text x="%d" y="%d" text-anchor="middle" font-family="ui-monospace, monospace" font-size="12" fill="#000">%s</text>',
				(int) ( $content_w / 2 ),
				$height + 12,
				self::esc( $payload )
			);
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="%s" width="%d" height="%d" viewBox="0 0 %d %d">%s%s</svg>',
			self::esc( $payload ),
			$content_w,
			$total_h,
			$content_w,
			$total_h,
			$rects,
			$text
		);
	}

	/**
	 * Escapes text for HTML / SVG attribute and text nodes.
	 *
	 * @param string $value Raw value.
	 */
	private static function esc( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
