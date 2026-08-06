<?php
/**
 * Deterministic scan resolution outcome against one fulfillment's lines.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Scan;

use MPCF\Domain\Barcode\BarcodePayload;
use MPCF\Domain\FulfillmentItem;

/**
 * Architecture Plan Part IX.3. Never silently chooses among ambiguous lines.
 */
final class ScanResolution {

	public const STATUS_ITEM = 'item';

	public const STATUS_FULFILLMENT = 'fulfillment';

	public const STATUS_PACKAGE = 'package';

	public const STATUS_REJECTED = 'rejected';

	/**
	 * Status discriminator.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Machine-readable code (`matched`, `unknown_barcode`, …).
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Operator-readable message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Matched fulfillment item when status is item.
	 *
	 * @var FulfillmentItem|null
	 */
	private ?FulfillmentItem $item;

	/**
	 * Namespaced payload when applicable.
	 *
	 * @var BarcodePayload|null
	 */
	private ?BarcodePayload $payload;

	/**
	 * `mpcf_payload` or `sku`.
	 *
	 * @var string|null
	 */
	private ?string $source;

	/**
	 * Fulfillment or package id for identity resolutions.
	 *
	 * @var int|null
	 */
	private ?int $identity_id;

	/**
	 * Assembles a resolution.
	 *
	 * @param string               $status      STATUS_* constant.
	 * @param string               $code        Machine code.
	 * @param string               $message     Operator message.
	 * @param FulfillmentItem|null $item        Matched item when STATUS_ITEM.
	 * @param BarcodePayload|null  $payload     Namespaced payload when applicable.
	 * @param string|null          $source      Scan source type.
	 * @param int|null             $identity_id Fulfillment/package id when identity.
	 */
	private function __construct(
		string $status,
		string $code,
		string $message,
		?FulfillmentItem $item,
		?BarcodePayload $payload,
		?string $source,
		?int $identity_id
	) {
		$this->status      = $status;
		$this->code        = $code;
		$this->message     = $message;
		$this->item        = $item;
		$this->payload     = $payload;
		$this->source      = $source;
		$this->identity_id = $identity_id;
	}

	/**
	 * Exact item match.
	 *
	 * @param FulfillmentItem     $item    Matched line.
	 * @param string              $source  `mpcf_payload` or `sku`.
	 * @param BarcodePayload|null $payload Namespaced payload when used.
	 */
	public static function for_item( FulfillmentItem $item, string $source, ?BarcodePayload $payload = null ): self {
		return new self(
			self::STATUS_ITEM,
			'matched',
			'Item matched.',
			$item,
			$payload,
			$source,
			null
		);
	}

	/**
	 * Fulfillment identity (navigation / open).
	 *
	 * @param int            $fulfillment_id Fulfillment id.
	 * @param BarcodePayload $payload        Parsed payload.
	 */
	public static function for_fulfillment( int $fulfillment_id, BarcodePayload $payload ): self {
		return new self(
			self::STATUS_FULFILLMENT,
			'fulfillment_identity',
			'Fulfillment barcode recognized.',
			null,
			$payload,
			'mpcf_payload',
			$fulfillment_id
		);
	}

	/**
	 * Package identity (switch active package).
	 *
	 * @param int            $package_id Package id.
	 * @param BarcodePayload $payload    Parsed payload.
	 */
	public static function for_package( int $package_id, BarcodePayload $payload ): self {
		return new self(
			self::STATUS_PACKAGE,
			'package_identity',
			'Package barcode recognized.',
			null,
			$payload,
			'mpcf_payload',
			$package_id
		);
	}

	/**
	 * Rejection with operator-readable messaging.
	 *
	 * @param string              $code    Machine code.
	 * @param string              $message Operator message.
	 * @param BarcodePayload|null $payload Optional parsed payload context.
	 */
	public static function rejected( string $code, string $message, ?BarcodePayload $payload = null ): self {
		return new self( self::STATUS_REJECTED, $code, $message, null, $payload, null, null );
	}

	/**
	 * Whether an item was resolved.
	 */
	public function is_item(): bool {
		return self::STATUS_ITEM === $this->status;
	}

	/**
	 * Whether this is a rejection.
	 */
	public function is_rejected(): bool {
		return self::STATUS_REJECTED === $this->status;
	}

	/**
	 * Status discriminator.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Machine-readable code.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Operator-readable message.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Matched item, or null.
	 */
	public function item(): ?FulfillmentItem {
		return $this->item;
	}

	/**
	 * Namespaced payload, or null.
	 */
	public function payload(): ?BarcodePayload {
		return $this->payload;
	}

	/**
	 * Scan source type, or null.
	 */
	public function source(): ?string {
		return $this->source;
	}

	/**
	 * Identity id for fulfillment/package resolutions.
	 */
	public function identity_id(): ?int {
		return $this->identity_id;
	}
}
