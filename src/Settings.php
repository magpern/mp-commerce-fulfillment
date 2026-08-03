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
	 * reset). Milestone 1 raised this to 3: 2 added the bridge-behavior
	 * keys, 3 added `operator_mode_enabled` (D18). Milestone 2 raises it to
	 * 4: `auto_advance_after_ship`, `default_carrier_id` and
	 * `require_tracking_before_ship` (Architecture Plan §IV.5.3/§IV.6/F21).
	 * Each bump is purely informational (`sanitize()` always rebuilds the
	 * canonical shape from `defaults()`, so there is no destructive
	 * migration step to write for a purely additive change like any of
	 * these).
	 */
	public const SCHEMA_VERSION = 4;

	/**
	 * Inbound cancel/refund behavior: move the fulfillment straight to
	 * `cancelled`.
	 */
	public const BRIDGE_BEHAVIOR_CANCEL = 'cancel';

	/**
	 * Inbound cancel/refund behavior: flag the fulfillment into `problem`
	 * for a human to review, rather than deciding on its behalf.
	 */
	public const BRIDGE_BEHAVIOR_FLAG = 'flag';

	/**
	 * The only values {@see BRIDGE_BEHAVIOR_CANCEL}/{@see BRIDGE_BEHAVIOR_FLAG}
	 * settings accept.
	 */
	private const BRIDGE_BEHAVIORS = array( self::BRIDGE_BEHAVIOR_CANCEL, self::BRIDGE_BEHAVIOR_FLAG );

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
			'schema_version'               => self::SCHEMA_VERSION,

			/*
			 * Data retention on uninstall. Default off: fulfillment history
			 * is warehouse evidence, deleting it must be a deliberate act
			 * (invariant I12).
			 */
			'remove_data_on_uninstall'     => false,

			/*
			 * Outbound bridge (Architecture Plan §6.6, open decision P2):
			 * "all fulfillments for the order shipped" -> WC order
			 * `completed`. Default on, prominently configurable, off means
			 * the bridge never touches WC order status.
			 */
			'outbound_bridge_enabled'      => true,

			/*
			 * Inbound bridge, per §6.6: default automatic for cancellation
			 * (WC cancelled -> fulfillment cancelled outright) and flagged
			 * for review on a full refund (WC refunded -> fulfillment
			 * `problem`, a human decides) — refunding after work has begun
			 * is the case that most often needs a human look, cancelling
			 * before it has isn't.
			 */
			'inbound_cancel_behavior'      => self::BRIDGE_BEHAVIOR_CANCEL,
			'inbound_refund_behavior'      => self::BRIDGE_BEHAVIOR_FLAG,

			/*
			 * Operator Mode (D18, Architecture Plan Sec9.1): default off.
			 * When on, Warehouse Operator users see reduced wp-admin chrome
			 * (Admin\OperatorMode) — administrators always keep full chrome
			 * regardless of this setting.
			 */
			'operator_mode_enabled'        => false,

			/*
			 * Packing Workspace, Architecture Plan §IV.5.3/F21. Default off:
			 * auto-advancing to the next queued fulfillment after a ship is
			 * a surprise the operator did not ask for (P0 principle 6 — "no
			 * surprise" is the default, reserved for the exception band).
			 */
			'auto_advance_after_ship'      => false,

			/*
			 * Pre-selects a carrier in the workspace's shipment panel
			 * (§IV.6) so a single-carrier merchant never has to pick one
			 * per pack. `''` means no default — any string is otherwise a
			 * valid carrier id (`Domain\CarrierRegistry`'s own contract),
			 * so this is not validated against the bundled set.
			 */
			'default_carrier_id'           => '',

			/*
			 * Blocks `packed -> shipped` when no tracking number has been
			 * recorded (`Engine\Guard\HasTrackingGuard`, wired ahead of
			 * this setting since Phase C). Default off: not every merchant
			 * tracks every shipment, and the guard should not reject a
			 * legitimate `packed -> shipped` for a store that never asked
			 * for tracking numbers in the first place.
			 */
			'require_tracking_before_ship' => false,
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

		$out['remove_data_on_uninstall']     = ! empty( $raw['remove_data_on_uninstall'] );
		$out['outbound_bridge_enabled']      = ! isset( $raw['outbound_bridge_enabled'] ) || ! empty( $raw['outbound_bridge_enabled'] );
		$out['inbound_cancel_behavior']      = self::sanitize_behavior( $raw['inbound_cancel_behavior'] ?? null, self::BRIDGE_BEHAVIOR_CANCEL );
		$out['inbound_refund_behavior']      = self::sanitize_behavior( $raw['inbound_refund_behavior'] ?? null, self::BRIDGE_BEHAVIOR_FLAG );
		$out['operator_mode_enabled']        = ! empty( $raw['operator_mode_enabled'] );
		$out['auto_advance_after_ship']      = ! empty( $raw['auto_advance_after_ship'] );
		$out['default_carrier_id']           = isset( $raw['default_carrier_id'] ) ? (string) $raw['default_carrier_id'] : '';
		$out['require_tracking_before_ship'] = ! empty( $raw['require_tracking_before_ship'] );

		return $out;
	}

	/**
	 * Coerces a raw value into one of {@see BRIDGE_BEHAVIORS}, falling back
	 * to `$default` for anything else (an unrecognized string, a missing
	 * key, a wrong type) — malformed input degrades to the safe default
	 * rather than propagating an invalid behavior value anywhere else.
	 *
	 * @param mixed  $raw          Raw behavior value.
	 * @param string $fallback     Value to use when `$raw` is not a recognized behavior.
	 */
	private static function sanitize_behavior( mixed $raw, string $fallback ): string {
		return in_array( $raw, self::BRIDGE_BEHAVIORS, true ) ? $raw : $fallback;
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
	 * Whether the outbound bridge may advance a WC order to `completed`
	 * when every fulfillment for it has shipped.
	 */
	public function outbound_bridge_enabled(): bool {
		return (bool) $this->get()['outbound_bridge_enabled'];
	}

	/**
	 * Whether an inbound WC cancellation should move the fulfillment
	 * straight to `cancelled` ({@see BRIDGE_BEHAVIOR_CANCEL}) or flag it
	 * into `problem` for review ({@see BRIDGE_BEHAVIOR_FLAG}).
	 */
	public function inbound_cancel_behavior(): string {
		return (string) $this->get()['inbound_cancel_behavior'];
	}

	/**
	 * Whether an inbound full WC refund should move the fulfillment
	 * straight to `cancelled` or flag it into `problem` for review.
	 */
	public function inbound_refund_behavior(): string {
		return (string) $this->get()['inbound_refund_behavior'];
	}

	/**
	 * Whether Operator Mode is enabled — reduced wp-admin chrome for
	 * Warehouse Operator users ({@see \MPCF\Admin\OperatorMode}).
	 */
	public function operator_mode_enabled(): bool {
		return (bool) $this->get()['operator_mode_enabled'];
	}

	/**
	 * Whether the workspace should offer to auto-advance to the next
	 * fulfillment in the queue slice after a successful ship, rather than
	 * only offering it via the "Next order" toast action.
	 */
	public function auto_advance_after_ship(): bool {
		return (bool) $this->get()['auto_advance_after_ship'];
	}

	/**
	 * The carrier id pre-selected in the workspace's shipment panel, or
	 * `''` for no default.
	 */
	public function default_carrier_id(): string {
		return (string) $this->get()['default_carrier_id'];
	}

	/**
	 * Whether `Engine\Guard\HasTrackingGuard` blocks `packed -> shipped`
	 * for a fulfillment with no recorded tracking number.
	 */
	public function require_tracking_before_ship(): bool {
		return (bool) $this->get()['require_tracking_before_ship'];
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
