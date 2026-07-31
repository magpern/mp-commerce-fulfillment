<?php
/**
 * Minimal WP_Role stand-in for unit tests without WordPress loaded.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Stubs the real WordPress core class name so `instanceof \WP_Role` checks in production code work unchanged under test.
if ( ! class_exists( 'WP_Role' ) ) {
	/**
	 * A role name plus a capability map.
	 */
	class WP_Role {

		/**
		 * @param string              $name         Role slug.
		 * @param array<string, bool> $capabilities Capability map.
		 */
		public function __construct(
			public string $name,
			public array $capabilities = array()
		) {
		}

		/**
		 * Whether this role holds the given capability.
		 *
		 * @param string $capability Capability slug.
		 */
		public function has_cap( string $capability ): bool {
			return ! empty( $this->capabilities[ $capability ] );
		}

		/**
		 * Grants a capability.
		 *
		 * @param string $capability Capability slug.
		 * @param bool   $grant      Whether to grant (true) or explicitly deny (false).
		 */
		public function add_cap( string $capability, bool $grant = true ): void {
			$this->capabilities[ $capability ] = $grant;
		}

		/**
		 * Revokes a capability.
		 *
		 * @param string $capability Capability slug.
		 */
		public function remove_cap( string $capability ): void {
			unset( $this->capabilities[ $capability ] );
		}
	}
}
// phpcs:enable
