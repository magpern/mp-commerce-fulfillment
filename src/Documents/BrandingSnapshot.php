<?php
/**
 * Captures store branding into an immutable render-time snapshot.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Documents;

use MPCF\Settings;

/**
 * Branding must be frozen at render time so historical HTML does not change
 * when site name, address, footer, or logo later change (M4-B / Part VI).
 *
 * Logo historical guarantee: when the attachment is a readable image under
 * {@see MAX_LOGO_BYTES}, it is embedded as a `data:` URI inside the
 * snapshot (and therefore inside stored HTML). Otherwise the logo fields
 * are left empty — we never store a mutable public attachment URL as the
 * sole historical representation.
 */
final class BrandingSnapshot {

	/**
	 * Maximum logo binary size embedded into a document snapshot.
	 */
	public const MAX_LOGO_BYTES = 262144;

	/**
	 * Allowed logo MIME types for data-URI embedding.
	 *
	 * @var list<string>
	 */
	private const LOGO_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	);

	/**
	 * Builds a branding snapshot from settings + site-name fallback.
	 *
	 * @param Settings $settings           Plugin settings.
	 * @param string   $blog_name_fallback WordPress site name when store name is empty.
	 * @return array{
	 *     store_name: string,
	 *     address_lines: list<string>,
	 *     footer: string,
	 *     logo_attachment_id: int,
	 *     logo_data_uri: string,
	 *     logo_mime: string
	 * }
	 */
	public static function capture( Settings $settings, string $blog_name_fallback ): array {
		$configured = $settings->documents_store_name();
		$store_name = '' !== $configured ? $configured : trim( $blog_name_fallback );

		$address_raw   = $settings->documents_address();
		$address_lines = array();

		if ( '' !== $address_raw ) {
			foreach ( explode( "\n", $address_raw ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$address_lines[] = $line;
				}
			}
		}

		$logo_id       = $settings->documents_logo_attachment_id();
		$logo_data_uri = '';
		$logo_mime     = '';

		if ( $logo_id > 0 ) {
			$embedded = self::embed_logo( $logo_id );
			if ( null !== $embedded ) {
				$logo_data_uri = $embedded['data_uri'];
				$logo_mime     = $embedded['mime'];
			}
		}

		return array(
			'store_name'         => $store_name,
			'address_lines'      => $address_lines,
			'footer'             => $settings->documents_footer(),
			'logo_attachment_id' => $logo_id,
			'logo_data_uri'      => $logo_data_uri,
			'logo_mime'          => $logo_mime,
		);
	}

	/**
	 * Attempts to embed an attachment as a data URI.
	 *
	 * @param int $attachment_id Attachment post id.
	 * @return array{data_uri: string, mime: string}|null
	 */
	private static function embed_logo( int $attachment_id ): ?array {
		if ( ! function_exists( 'get_attached_file' ) || ! function_exists( 'get_post_mime_type' ) ) {
			return null;
		}

		$mime = (string) get_post_mime_type( $attachment_id );

		if ( ! in_array( $mime, self::LOGO_MIME_TYPES, true ) ) {
			return null;
		}

		$path = get_attached_file( $attachment_id );

		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) || ! is_file( $path ) ) {
			return null;
		}

		$size = filesize( $path );

		if ( false === $size || $size <= 0 || $size > self::MAX_LOGO_BYTES ) {
			return null;
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local attachment path, bounded by MAX_LOGO_BYTES.

		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return null;
		}

		return array(
			'data_uri' => 'data:' . $mime . ';base64,' . base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Data-URI embedding for immutable historical logo snapshot, not obfuscation.
			'mime'     => $mime,
		);
	}
}
