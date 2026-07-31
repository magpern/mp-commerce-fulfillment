<?php
/**
 * Plugin settings.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF;

/**
 * Sole owner of the `mpcf_settings` option.
 *
 * `defaults()` and `sanitize()` are pure: they call no WordPress function,
 * touch no global state and never throw. Invalid input is coerced rather
 * than rejected, so a corrupted option can never make the plugin fatal.
 * That purity is what lets the settings suite run without a WordPress
 * bootstrap (house convention, see the sibling plugins' `Settings` classes).
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'mpcf_settings';

	/**
	 * Shape version of the settings array (not the database schema version —
	 * that is `Infrastructure\Database\Migrator::TARGET`, deliberately in its
	 * own option so a settings reset can never be mistaken for a schema
	 * reset).
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Lazily loaded, sanitized settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $data;

	/**
	 * Builds the settings accessor.
	 *
	 * @param array<string, mixed>|null $data Pre-loaded settings, for tests.
	 */
	public function __construct( ?array $data = null ) {
		$this->data = null === $data ? null : self::sanitize( $data );
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'schema_version'           => self::SCHEMA_VERSION,

			/*
			 * Data retention on uninstall. Default off: fulfillment history
			 * is warehouse evidence, deleting it must be a deliberate act
			 * (invariant I12).
			 */
			'remove_data_on_uninstall' => false,
		);
	}

	/**
	 * Sanitizes a raw settings array into the canonical shape, dropping or
	 * coercing anything invalid rather than rejecting it.
	 *
	 * @param mixed $raw Raw settings, typically from `get_option()`.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::defaults();

		$out['remove_data_on_uninstall'] = ! empty( $raw['remove_data_on_uninstall'] );

		return $out;
	}

	/**
	 * Returns the full sanitized settings array, loading it from the
	 * database on first access.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		if ( null === $this->data ) {
			$this->data = self::sanitize( get_option( self::OPTION, array() ) );
		}

		return $this->data;
	}

	/**
	 * Whether plugin-owned data should be removed on uninstall.
	 */
	public function remove_data_on_uninstall(): bool {
		return (bool) $this->get()['remove_data_on_uninstall'];
	}

	/**
	 * Persists the given settings, sanitized.
	 *
	 * @param array<string, mixed> $data Settings to save.
	 */
	public function save( array $data ): void {
		$this->data = self::sanitize( $data );

		update_option( self::OPTION, $this->data );
	}
}
