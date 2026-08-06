<?php
/**
 * Capability and role definitions.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF;

/**
 * Single source of truth for every capability and role this plugin
 * introduces. No permission check anywhere in the plugin names a capability
 * string directly — everything reads from these constants (house
 * convention: never hardcode a role name or a `manage_options` shortcut in
 * a feature check).
 */
final class Capabilities {

	public const VIEW_QUEUE           = 'mpcf_view_queue';
	public const PROCESS_FULFILLMENTS = 'mpcf_process_fulfillments';
	public const MANAGE_SHIPMENTS     = 'mpcf_manage_shipments';
	public const ADD_NOTES            = 'mpcf_add_notes';
	public const CAPTURE_PHOTOS       = 'mpcf_capture_photos';
	public const DELETE_PHOTOS        = 'mpcf_delete_photos';
	public const RENDER_DOCUMENTS     = 'mpcf_render_documents';
	public const CANCEL_FULFILLMENT   = 'mpcf_cancel_fulfillment';
	public const VIEW_AUDIT           = 'mpcf_view_audit';
	public const VIEW_ANALYTICS       = 'mpcf_view_analytics';
	public const VIEW_OPERATOR_STATS  = 'mpcf_view_operator_stats';
	public const MANAGE_SETTINGS      = 'mpcf_manage_settings';

	/**
	 * Custom roles this plugin creates on activation.
	 */
	public const ROLE_OPERATOR = 'mpcf_warehouse_operator';
	public const ROLE_LEAD     = 'mpcf_warehouse_lead';

	/**
	 * Existing platform roles granted every capability (the core
	 * "administrator" role and the shop-management role the required
	 * e-commerce plugin defines).
	 *
	 * @var list<string>
	 */
	private const FULL_ACCESS_ROLES = array( 'administrator', 'shop_manager' );

	/**
	 * Every capability this plugin owns.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::VIEW_QUEUE,
			self::PROCESS_FULFILLMENTS,
			self::MANAGE_SHIPMENTS,
			self::ADD_NOTES,
			self::CAPTURE_PHOTOS,
			self::DELETE_PHOTOS,
			self::RENDER_DOCUMENTS,
			self::CANCEL_FULFILLMENT,
			self::VIEW_AUDIT,
			self::VIEW_ANALYTICS,
			self::VIEW_OPERATOR_STATS,
			self::MANAGE_SETTINGS,
		);
	}

	/**
	 * Capabilities granted to the Warehouse Operator role — queue, process,
	 * ship, notes, photos and documents; no cancel, no photo soft-delete,
	 * no audit, no analytics, no settings (see docs/ARCHITECTURE_PLAN.md
	 * §17.1 / Part VIII).
	 *
	 * @return list<string>
	 */
	public static function operator(): array {
		return array(
			self::VIEW_QUEUE,
			self::PROCESS_FULFILLMENTS,
			self::MANAGE_SHIPMENTS,
			self::ADD_NOTES,
			self::CAPTURE_PHOTOS,
			self::RENDER_DOCUMENTS,
		);
	}

	/**
	 * Every custom role this plugin creates.
	 *
	 * @return list<string>
	 */
	public static function roles(): array {
		return array( self::ROLE_OPERATOR, self::ROLE_LEAD );
	}

	/**
	 * Creates the custom roles and grants every capability to
	 * administrators and shop managers. Idempotent — safe to call on every
	 * activation.
	 */
	public static function activate(): void {
		if ( null === get_role( self::ROLE_OPERATOR ) ) {
			add_role( self::ROLE_OPERATOR, __( 'Warehouse Operator', 'mp-commerce-fulfillment' ), self::capability_map( self::operator() ) );
		}

		if ( null === get_role( self::ROLE_LEAD ) ) {
			add_role( self::ROLE_LEAD, __( 'Warehouse Lead', 'mp-commerce-fulfillment' ), self::capability_map( self::all() ) );
		} else {
			self::grant_missing( self::ROLE_LEAD, self::all() );
		}

		self::grant_missing( self::ROLE_OPERATOR, self::operator() );

		foreach ( self::FULL_ACCESS_ROLES as $role_name ) {
			self::grant_missing( $role_name, self::all() );
		}
	}

	/**
	 * Grants any capability from the list that the role does not already hold.
	 *
	 * @param string             $role_name    Role key.
	 * @param array<int, string> $capabilities Capabilities to ensure.
	 */
	private static function grant_missing( string $role_name, array $capabilities ): void {
		$role = get_role( $role_name );

		if ( null === $role ) {
			return;
		}

		foreach ( $capabilities as $capability ) {
			if ( ! $role->has_cap( $capability ) ) {
				$role->add_cap( $capability );
			}
		}
	}

	/**
	 * Removes the custom roles and every capability this plugin granted,
	 * from every role that holds it. Called only when
	 * `remove_data_on_uninstall` is enabled.
	 */
	public static function uninstall(): void {
		remove_role( self::ROLE_OPERATOR );
		remove_role( self::ROLE_LEAD );

		$roles = wp_roles();

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = $roles->get_role( $role_name );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			foreach ( self::all() as $capability ) {
				if ( $role->has_cap( $capability ) ) {
					$role->remove_cap( $capability );
				}
			}
		}
	}

	/**
	 * Builds a `capability => true` map (plus the base `read` capability)
	 * for `add_role()`.
	 *
	 * @param array<int, string> $capabilities Capabilities to grant.
	 * @return array<string, bool>
	 */
	private static function capability_map( array $capabilities ): array {
		return array_fill_keys( array_merge( array( 'read' ), $capabilities ), true );
	}
}
