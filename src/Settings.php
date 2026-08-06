<?php
/**
 * Plugin settings.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF;

use MPCF\Domain\Notification\NotificationStrategy;

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
	 * 5: Documents branding (`documents_store_name`, `documents_address`,
	 * `documents_footer`, `documents_logo_attachment_id`) for M4-B.
	 * 6: Notification configuration (M5-B): `notification_strategy`,
	 * sender/reply/footer/subject/introduction/signature keys. Carrier
	 * default remains `default_carrier_id`; registry fallback to `other`
	 * is applied by NotificationConfigurationService, not here (purity).
	 * 7: `photos_required` (M6-B / Part VIII) — when true, packing→packed
	 * requires ≥1 active kind=package photo via PhotoRequiredGuard.
	 * 8: Package photography limits (M6-C): `photos_max_per_fulfillment`,
	 * `photos_max_upload_bytes`, `photos_max_edge_px`,
	 * `photos_retention_months` (stored for M6-D; no purge execution here).
	 * Each bump is purely informational (`sanitize()` always rebuilds the
	 * canonical shape from `defaults()`, so there is no destructive
	 * migration step to write for a purely additive change like any of
	 * these).
	 */
	public const SCHEMA_VERSION = 8;

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
			'schema_version'                  => self::SCHEMA_VERSION,

			/*
			 * Data retention on uninstall. Default off: fulfillment history
			 * is warehouse evidence, deleting it must be a deliberate act
			 * (invariant I12).
			 */
			'remove_data_on_uninstall'        => false,

			/*
			 * Outbound bridge (Architecture Plan §6.6, open decision P2):
			 * "all fulfillments for the order shipped" -> WC order
			 * `completed`. Default on, prominently configurable, off means
			 * the bridge never touches WC order status.
			 */
			'outbound_bridge_enabled'         => true,

			/*
			 * Inbound bridge, per §6.6: default automatic for cancellation
			 * (WC cancelled -> fulfillment cancelled outright) and flagged
			 * for review on a full refund (WC refunded -> fulfillment
			 * `problem`, a human decides) — refunding after work has begun
			 * is the case that most often needs a human look, cancelling
			 * before it has isn't.
			 */
			'inbound_cancel_behavior'         => self::BRIDGE_BEHAVIOR_CANCEL,
			'inbound_refund_behavior'         => self::BRIDGE_BEHAVIOR_FLAG,

			/*
			 * Operator Mode (D18, Architecture Plan Sec9.1): default off.
			 * When on, Warehouse Operator users see reduced wp-admin chrome
			 * (Admin\OperatorMode) — administrators always keep full chrome
			 * regardless of this setting.
			 */
			'operator_mode_enabled'           => false,

			/*
			 * Packing Workspace, Architecture Plan §IV.5.3/F21. Default off:
			 * auto-advancing to the next queued fulfillment after a ship is
			 * a surprise the operator did not ask for (P0 principle 6 — "no
			 * surprise" is the default, reserved for the exception band).
			 */
			'auto_advance_after_ship'         => false,

			/*
			 * Pre-selects a carrier in the workspace's shipment panel
			 * (§IV.6) so a single-carrier merchant never has to pick one
			 * per pack. `''` means no default — any string is otherwise a
			 * valid carrier id (`Domain\CarrierRegistry`'s own contract),
			 * so this is not validated against the bundled set.
			 */
			'default_carrier_id'              => '',

			/*
			 * Blocks `packed -> shipped` when no tracking number has been
			 * recorded (`Engine\Guard\HasTrackingGuard`, wired ahead of
			 * this setting since Phase C). Default off: not every merchant
			 * tracks every shipment, and the guard should not reject a
			 * legitimate `packed -> shipped` for a store that never asked
			 * for tracking numbers in the first place.
			 */
			'require_tracking_before_ship'    => false,

			/*
			 * Documents branding (M4-B). Empty display name falls back to the
			 * WordPress site name at render time. Address/footer/logo are
			 * optional — empty fields render nothing. Logo is an attachment
			 * id captured into a data-URI snapshot at render time (ADR-0004:
			 * historical HTML must not depend on a mutable public URL).
			 */
			'documents_store_name'            => '',
			'documents_address'               => '',
			'documents_footer'                => '',
			'documents_logo_attachment_id'    => 0,

			/*
			 * Notification configuration (M5-B). Strategy is a single enum
			 * ({@see NotificationStrategy}); defaults prefer WC Completed
			 * tracking when the outbound bridge is on (its own default).
			 * Subject falls back to the configuration service default when
			 * empty at read time.
			 */
			'notification_strategy'           => NotificationStrategy::COMPLETED_EMAIL,
			'notification_sender_name'        => '',
			'notification_reply_to'           => '',
			'notification_tracking_footer'    => '',
			'notification_email_subject'      => 'Your order has shipped',
			'notification_email_introduction' => '',
			'notification_email_signature'    => '',

			/*
			 * When true, packing→packed requires ≥1 active kind=package
			 * photo (Part VIII / PhotoRequiredGuard). Default off.
			 */
			'photos_required'                 => false,

			/*
			 * Package photography limits (M6-C). Defaults match
			 * PhotoConfig::defaults(). Retention months are stored for
			 * M6-D; this milestone does not run a purge job.
			 */
			'photos_max_per_fulfillment'      => 10,
			'photos_max_upload_bytes'         => 12582912,
			'photos_max_edge_px'              => 2000,
			'photos_retention_months'         => 12,
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
		$out['documents_store_name']         = self::sanitize_plain_text( $raw['documents_store_name'] ?? '', 191 );
		$out['documents_address']            = self::sanitize_multiline_text( $raw['documents_address'] ?? '', 2000 );
		$out['documents_footer']             = self::sanitize_multiline_text( $raw['documents_footer'] ?? '', 2000 );
		$out['documents_logo_attachment_id'] = self::sanitize_attachment_id( $raw['documents_logo_attachment_id'] ?? 0 );

		$out['notification_strategy']           = NotificationStrategy::from( $raw['notification_strategy'] ?? null )->value();
		$out['notification_sender_name']        = self::sanitize_plain_text( $raw['notification_sender_name'] ?? '', 191 );
		$out['notification_reply_to']           = self::sanitize_email_address( $raw['notification_reply_to'] ?? '' );
		$out['notification_tracking_footer']    = self::sanitize_multiline_text( $raw['notification_tracking_footer'] ?? '', 2000 );
		$out['notification_email_subject']      = self::sanitize_plain_text(
			array_key_exists( 'notification_email_subject', $raw )
				? $raw['notification_email_subject']
				: $out['notification_email_subject'],
			191
		);
		$out['notification_email_introduction'] = self::sanitize_multiline_text( $raw['notification_email_introduction'] ?? '', 2000 );
		$out['notification_email_signature']    = self::sanitize_multiline_text( $raw['notification_email_signature'] ?? '', 2000 );
		$out['photos_required']                 = ! empty( $raw['photos_required'] );
		$out['photos_max_per_fulfillment']      = self::sanitize_int_range( $raw['photos_max_per_fulfillment'] ?? null, 10, 1, 100 );
		$out['photos_max_upload_bytes']         = self::sanitize_int_range( $raw['photos_max_upload_bytes'] ?? null, 12582912, 1048576, 52428800 );
		$out['photos_max_edge_px']              = self::sanitize_int_range( $raw['photos_max_edge_px'] ?? null, 2000, 500, 8000 );
		$out['photos_retention_months']         = self::sanitize_int_range( $raw['photos_retention_months'] ?? null, 12, 1, 120 );

		return $out;
	}

	/**
	 * Coerces an integer into an inclusive range, falling back to `$fallback`
	 * when the raw value is not numeric.
	 *
	 * @param mixed $raw      Raw value.
	 * @param int   $fallback Fallback when not numeric.
	 * @param int   $min      Inclusive minimum.
	 * @param int   $max      Inclusive maximum.
	 */
	private static function sanitize_int_range( mixed $raw, int $fallback, int $min, int $max ): int {
		if ( ! is_numeric( $raw ) ) {
			return $fallback;
		}

		$value = (int) $raw;

		if ( $value < $min ) {
			return $min;
		}

		if ( $value > $max ) {
			return $max;
		}

		return $value;
	}

	/**
	 * Coerces a single-line branding string, capping length.
	 *
	 * @param mixed $raw   Raw value.
	 * @param int   $max   Maximum characters retained.
	 */
	private static function sanitize_plain_text( mixed $raw, int $max ): string {
		if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
			return '';
		}

		$text = trim( (string) $raw );
		$text = preg_replace( '/[\x00-\x1F\x7F]+/', '', $text ) ?? '';

		if ( strlen( $text ) > $max ) {
			$text = substr( $text, 0, $max );
		}

		return $text;
	}

	/**
	 * Coerces multiline branding text (address/footer), preserving newlines.
	 *
	 * @param mixed $raw Raw value.
	 * @param int   $max Maximum characters retained.
	 */
	private static function sanitize_multiline_text( mixed $raw, int $max ): string {
		if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
			return '';
		}

		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $raw );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $text ) ?? '';
		$text = trim( $text );

		if ( strlen( $text ) > $max ) {
			$text = substr( $text, 0, $max );
		}

		return $text;
	}

	/**
	 * Coerces an optional email address; invalid non-empty values become ''.
	 *
	 * @param mixed $raw Raw value.
	 */
	private static function sanitize_email_address( mixed $raw ): string {
		if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
			return '';
		}

		$email = trim( (string) $raw );

		if ( '' === $email ) {
			return '';
		}

		if ( strlen( $email ) > 191 ) {
			$email = substr( $email, 0, 191 );
		}

		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
	}

	/**
	 * Coerces a media attachment id; non-positive values become 0 (no logo).
	 *
	 * @param mixed $raw Raw value.
	 */
	private static function sanitize_attachment_id( mixed $raw ): int {
		if ( is_numeric( $raw ) ) {
			$id = (int) $raw;

			return $id > 0 ? $id : 0;
		}

		return 0;
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
	 * Documents branding: optional store display name override.
	 * Empty means "use the WordPress site name at render time".
	 */
	public function documents_store_name(): string {
		return (string) $this->get()['documents_store_name'];
	}

	/**
	 * Documents branding: optional address block (newline-separated lines).
	 */
	public function documents_address(): string {
		return (string) $this->get()['documents_address'];
	}

	/**
	 * Documents branding: optional footer / legal text.
	 */
	public function documents_footer(): string {
		return (string) $this->get()['documents_footer'];
	}

	/**
	 * Documents branding: optional logo attachment id (0 = none).
	 */
	public function documents_logo_attachment_id(): int {
		return (int) $this->get()['documents_logo_attachment_id'];
	}

	/**
	 * Notification delivery strategy value ({@see NotificationStrategy}).
	 */
	public function notification_strategy(): string {
		return (string) $this->get()['notification_strategy'];
	}

	/**
	 * Notification sender display name override.
	 */
	public function notification_sender_name(): string {
		return (string) $this->get()['notification_sender_name'];
	}

	/**
	 * Notification Reply-To address, or empty for store default.
	 */
	public function notification_reply_to(): string {
		return (string) $this->get()['notification_reply_to'];
	}

	/**
	 * Footer text under tracking links in notification emails.
	 */
	public function notification_tracking_footer(): string {
		return (string) $this->get()['notification_tracking_footer'];
	}

	/**
	 * Default notification email subject.
	 */
	public function notification_email_subject(): string {
		return (string) $this->get()['notification_email_subject'];
	}

	/**
	 * Optional notification email introduction.
	 */
	public function notification_email_introduction(): string {
		return (string) $this->get()['notification_email_introduction'];
	}

	/**
	 * Optional notification email signature.
	 */
	public function notification_email_signature(): string {
		return (string) $this->get()['notification_email_signature'];
	}

	/**
	 * Whether packing→packed requires ≥1 active kind=package photo
	 * (Part VIII / {@see \MPCF\Engine\Guard\PhotoRequiredGuard}).
	 */
	public function photos_required(): bool {
		return (bool) $this->get()['photos_required'];
	}

	/**
	 * Maximum active photos allowed per fulfillment.
	 */
	public function photos_max_per_fulfillment(): int {
		return (int) $this->get()['photos_max_per_fulfillment'];
	}

	/**
	 * Maximum raw photo upload size in bytes.
	 */
	public function photos_max_upload_bytes(): int {
		return (int) $this->get()['photos_max_upload_bytes'];
	}

	/**
	 * Maximum longest edge (pixels) for the canonical processed image.
	 */
	public function photos_max_edge_px(): int {
		return (int) $this->get()['photos_max_edge_px'];
	}

	/**
	 * Soft-delete retention horizon in months (consumed by M6-D purge).
	 */
	public function photos_retention_months(): int {
		return (int) $this->get()['photos_retention_months'];
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
