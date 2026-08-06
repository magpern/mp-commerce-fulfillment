<?php
/**
 * Immutable configuration for package photography capture.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Photos;

use MPCF\Settings;

/**
 * Capture limits for package photography. Built-in defaults match
 * Settings schema v8; production uses {@see from_settings()}.
 */
final class PhotoConfig {

	/**
	 * Maximum raw upload size in bytes.
	 *
	 * @var int
	 */
	private int $max_upload_bytes;

	/**
	 * Maximum longest edge for the canonical image.
	 *
	 * @var int
	 */
	private int $max_edge_px;

	/**
	 * Maximum active photos per fulfillment.
	 *
	 * @var int
	 */
	private int $max_photos_per_fulfillment;

	/**
	 * Expected processing pipeline version.
	 *
	 * @var int
	 */
	private int $processing_version;

	/**
	 * Assembles config. Use {@see defaults()} or {@see create()}.
	 *
	 * @param int $max_upload_bytes           Max raw upload size.
	 * @param int $max_edge_px                Max longest edge.
	 * @param int $max_photos_per_fulfillment Max active photos per fulfillment.
	 * @param int $processing_version         Pipeline version.
	 */
	private function __construct(
		int $max_upload_bytes,
		int $max_edge_px,
		int $max_photos_per_fulfillment,
		int $processing_version
	) {
		$this->max_upload_bytes           = $max_upload_bytes;
		$this->max_edge_px                = $max_edge_px;
		$this->max_photos_per_fulfillment = $max_photos_per_fulfillment;
		$this->processing_version         = $processing_version;
	}

	/**
	 * Built-in M6-A defaults.
	 */
	public static function defaults(): self {
		return new self( 12 * 1024 * 1024, 2000, 10, 1 );
	}

	/**
	 * Builds config with explicit values.
	 *
	 * @param int $max_upload_bytes           Max raw upload size.
	 * @param int $max_edge_px                Max longest edge.
	 * @param int $max_photos_per_fulfillment Max active photos per fulfillment.
	 * @param int $processing_version         Pipeline version.
	 */
	public static function create(
		int $max_upload_bytes,
		int $max_edge_px,
		int $max_photos_per_fulfillment,
		int $processing_version
	): self {
		return new self(
			$max_upload_bytes,
			$max_edge_px,
			$max_photos_per_fulfillment,
			$processing_version
		);
	}

	/**
	 * Reads capture limits from plugin settings (processing_version stays 1).
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public static function from_settings( Settings $settings ): self {
		return new self(
			$settings->photos_max_upload_bytes(),
			$settings->photos_max_edge_px(),
			$settings->photos_max_per_fulfillment(),
			1
		);
	}

	/**
	 * Maximum raw upload size in bytes.
	 */
	public function max_upload_bytes(): int {
		return $this->max_upload_bytes;
	}

	/**
	 * Maximum longest edge for the canonical image.
	 */
	public function max_edge_px(): int {
		return $this->max_edge_px;
	}

	/**
	 * Maximum active photos per fulfillment.
	 */
	public function max_photos_per_fulfillment(): int {
		return $this->max_photos_per_fulfillment;
	}

	/**
	 * Expected processing pipeline version.
	 */
	public function processing_version(): int {
		return $this->processing_version;
	}
}
