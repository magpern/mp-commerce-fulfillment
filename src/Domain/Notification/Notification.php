<?php
/**
 * Complete outbound communication request (transport-independent).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Notification;

/**
 * Immutable domain object produced by NotificationFactory. The Dispatcher
 * knows nothing about shipments — only Notifications and Channels.
 */
final class Notification {

	/**
	 * Recipient email.
	 *
	 * @var string
	 */
	private string $recipient_email;

	/**
	 * Subject line.
	 *
	 * @var string
	 */
	private string $subject;

	/**
	 * HTML body.
	 *
	 * @var string
	 */
	private string $html_body;

	/**
	 * Plain-text body.
	 *
	 * @var string
	 */
	private string $text_body;

	/**
	 * Fulfillment id.
	 *
	 * @var int
	 */
	private int $fulfillment_id;

	/**
	 * Shipment id.
	 *
	 * @var int
	 */
	private int $shipment_id;

	/**
	 * Order id.
	 *
	 * @var int
	 */
	private int $order_id;

	/**
	 * Carrier registry id.
	 *
	 * @var string
	 */
	private string $carrier_id;

	/**
	 * Carrier display label.
	 *
	 * @var string
	 */
	private string $carrier_label;

	/**
	 * Primary tracking number.
	 *
	 * @var string
	 */
	private string $tracking_number;

	/**
	 * Primary tracking URL.
	 *
	 * @var string|null
	 */
	private ?string $tracking_url;

	/**
	 * Package-level tracking rows.
	 *
	 * @var list<array{tracking_number: string, tracking_url: string|null}>
	 */
	private array $package_tracking;

	/**
	 * Strategy value.
	 *
	 * @var string
	 */
	private string $strategy;

	/**
	 * Extra non-PII metadata.
	 *
	 * @var array<string, mixed>
	 */
	private array $metadata;

	/**
	 * Assembles a notification.
	 *
	 * @param string                                                                $recipient_email  Recipient address.
	 * @param string                                                                $subject          Subject line.
	 * @param string                                                                $html_body        HTML body.
	 * @param string                                                                $text_body        Plain-text body.
	 * @param int                                                                   $fulfillment_id   Fulfillment id.
	 * @param int                                                                   $shipment_id      Shipment id.
	 * @param int                                                                   $order_id         Order id.
	 * @param string                                                                $carrier_id       Carrier registry id.
	 * @param string                                                                $carrier_label    Carrier display label.
	 * @param string                                                                $tracking_number  Primary tracking number.
	 * @param string|null                                                           $tracking_url     Primary tracking URL.
	 * @param array<int, array{tracking_number: string, tracking_url: string|null}> $package_tracking Colli tracking.
	 * @param string                                                                $strategy         Strategy value.
	 * @param array<string, mixed>                                                  $metadata         Extra non-PII metadata.
	 */
	public function __construct(
		string $recipient_email,
		string $subject,
		string $html_body,
		string $text_body,
		int $fulfillment_id,
		int $shipment_id,
		int $order_id,
		string $carrier_id,
		string $carrier_label,
		string $tracking_number,
		?string $tracking_url,
		array $package_tracking,
		string $strategy,
		array $metadata = array()
	) {
		$this->recipient_email  = $recipient_email;
		$this->subject          = $subject;
		$this->html_body        = $html_body;
		$this->text_body        = $text_body;
		$this->fulfillment_id   = $fulfillment_id;
		$this->shipment_id      = $shipment_id;
		$this->order_id         = $order_id;
		$this->carrier_id       = $carrier_id;
		$this->carrier_label    = $carrier_label;
		$this->tracking_number  = $tracking_number;
		$this->tracking_url     = $tracking_url;
		$this->package_tracking = $package_tracking;
		$this->strategy         = $strategy;
		$this->metadata         = $metadata;
	}

	/** Recipient email. */
	public function recipient_email(): string {
		return $this->recipient_email;
	}

	/** Subject. */
	public function subject(): string {
		return $this->subject;
	}

	/** HTML body. */
	public function html_body(): string {
		return $this->html_body;
	}

	/** Plain-text body. */
	public function text_body(): string {
		return $this->text_body;
	}

	/** Fulfillment id. */
	public function fulfillment_id(): int {
		return $this->fulfillment_id;
	}

	/** Shipment id. */
	public function shipment_id(): int {
		return $this->shipment_id;
	}

	/** Order id. */
	public function order_id(): int {
		return $this->order_id;
	}

	/** Carrier id. */
	public function carrier_id(): string {
		return $this->carrier_id;
	}

	/** Carrier label. */
	public function carrier_label(): string {
		return $this->carrier_label;
	}

	/** Primary tracking number. */
	public function tracking_number(): string {
		return $this->tracking_number;
	}

	/** Primary tracking URL. */
	public function tracking_url(): ?string {
		return $this->tracking_url;
	}

	/**
	 * Package-level tracking rows.
	 *
	 * @return list<array{tracking_number: string, tracking_url: string|null}>
	 */
	public function package_tracking(): array {
		return $this->package_tracking;
	}

	/** Strategy value. */
	public function strategy(): string {
		return $this->strategy;
	}

	/**
	 * Extra metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * Audit-safe shipment snapshot (no recipient).
	 *
	 * @return array{
	 *     fulfillment_id: int,
	 *     shipment_id: int,
	 *     order_id: int,
	 *     carrier_id: string,
	 *     tracking_number: string,
	 *     strategy: string
	 * }
	 */
	public function shipment_snapshot(): array {
		return array(
			'fulfillment_id'  => $this->fulfillment_id,
			'shipment_id'     => $this->shipment_id,
			'order_id'        => $this->order_id,
			'carrier_id'      => $this->carrier_id,
			'tracking_number' => $this->tracking_number,
			'strategy'        => $this->strategy,
		);
	}
}
