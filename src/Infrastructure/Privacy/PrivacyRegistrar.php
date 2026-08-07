<?php
/**
 * Registers WP privacy exporters/erasers.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Privacy;

/**
 * Thin registration adapter (WP privacy filters only — WC hooks live in Woo\PrivacyHooks).
 */
final class PrivacyRegistrar {

	/**
	 * Builds the privacy registrar.
	 *
	 * @param PrivacyExporter $exporter Exporter.
	 * @param PrivacyEraser   $eraser   Eraser.
	 */
	public function __construct(
		private PrivacyExporter $exporter,
		private PrivacyEraser $eraser
	) {
	}

	/**
	 * Hooks privacy filters.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Registers the MPCF personal data exporter.
	 *
	 * @param array<string, mixed> $exporters Existing exporters.
	 * @return array<string, mixed>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ PrivacyExporter::EXPORTER_EMAIL ] = array(
			'exporter'             => __( 'MP Commerce Fulfillment', 'mp-commerce-fulfillment' ),
			'callback'             => array( $this->exporter, 'export' ),
			'exporter_description' => __( 'Fulfillment history, notes, and photo metadata.', 'mp-commerce-fulfillment' ),
		);

		return $exporters;
	}

	/**
	 * Registers the MPCF personal data eraser.
	 *
	 * @param array<string, mixed> $erasers Existing erasers.
	 * @return array<string, mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ PrivacyEraser::ERASER_EMAIL ] = array(
			'eraser_friendly_name' => __( 'MP Commerce Fulfillment', 'mp-commerce-fulfillment' ),
			'callback'             => array( $this->eraser, 'erase' ),
		);

		return $erasers;
	}
}
