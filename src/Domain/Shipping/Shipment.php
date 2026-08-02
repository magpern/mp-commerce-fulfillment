<?php
/**
 * A shipment — one carrier handover (consignment) for a fulfillment.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Shipping;

use DateTimeImmutable;

/**
 * Architecture Plan §IV.6: `pending` -> `shipped` -> `delivered`, with
 * `exception` reachable from `shipped`. This is a four-value field with
 * three legal moves, mutated only by `Application\ShippingService` and
 * always audited — deliberately not routed through the fulfillment
 * `WorkflowEngine` (that engine governs `mpcf_fulfillments.state` only,
 * invariant I4; a second `WorkflowDefinition` for a four-state field would
 * be a second engine for no benefit). Multiple shipments per fulfillment
 * are native from day one (§7.1) — nothing here assumes there is only one.
 */
final class Shipment {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_SHIPPED   = 'shipped';
	public const STATUS_DELIVERED = 'delivered';
	public const STATUS_EXCEPTION = 'exception';

	/**
	 * Own id, or null before the repository assigns one.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Owning fulfillment's id.
	 *
	 * @var int
	 */
	private int $fulfillment_id;

	/**
	 * Carrier registry key, or empty string until an operator chooses one.
	 *
	 * @var string
	 */
	private string $carrier_id;

	/**
	 * Carrier service name (e.g. "Express"), or empty string.
	 *
	 * @var string
	 */
	private string $service;

	/**
	 * Consignment-level tracking.
	 *
	 * @var TrackingReference
	 */
	private TrackingReference $tracking;

	/**
	 * Lifecycle status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * When this shipment was marked shipped, if it has been.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $shipped_at;

	/**
	 * When this shipment was marked delivered, if it has been.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $delivered_at;

	/**
	 * When this shipment was created.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $created_at;

	/**
	 * Assembles a shipment. Use {@see create()} or {@see from_array()}
	 * instead of calling this directly.
	 *
	 * @param int|null               $id             Own id, or null before insert.
	 * @param int                    $fulfillment_id Owning fulfillment's id.
	 * @param string                 $carrier_id     Carrier registry key.
	 * @param string                 $service        Carrier service name.
	 * @param TrackingReference      $tracking       Consignment-level tracking.
	 * @param string                 $status         Lifecycle status.
	 * @param DateTimeImmutable|null $shipped_at     When marked shipped, if ever.
	 * @param DateTimeImmutable|null $delivered_at   When marked delivered, if ever.
	 * @param DateTimeImmutable      $created_at     When created.
	 */
	private function __construct(
		?int $id,
		int $fulfillment_id,
		string $carrier_id,
		string $service,
		TrackingReference $tracking,
		string $status,
		?DateTimeImmutable $shipped_at,
		?DateTimeImmutable $delivered_at,
		DateTimeImmutable $created_at
	) {
		$this->id             = $id;
		$this->fulfillment_id = $fulfillment_id;
		$this->carrier_id     = $carrier_id;
		$this->service        = $service;
		$this->tracking       = $tracking;
		$this->status         = $status;
		$this->shipped_at     = $shipped_at;
		$this->delivered_at   = $delivered_at;
		$this->created_at     = $created_at;
	}

	/**
	 * Creates a brand-new pending shipment — no carrier or tracking yet.
	 * The first edit to any shipment field is what creates the row at all
	 * (Architecture Plan §IV.5.8); this factory is that first write.
	 *
	 * @param int               $fulfillment_id Owning fulfillment's id.
	 * @param DateTimeImmutable $now            Current time.
	 */
	public static function create( int $fulfillment_id, DateTimeImmutable $now ): self {
		return new self( null, $fulfillment_id, '', '', TrackingReference::none(), self::STATUS_PENDING, null, null, $now );
	}

	/**
	 * Rebuilds a shipment from its array shape.
	 *
	 * @param array<string, mixed> $data Array shape produced by {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['id'] ) ? (int) $data['id'] : null,
			(int) $data['fulfillment_id'],
			(string) $data['carrier_id'],
			(string) ( $data['service'] ?? '' ),
			TrackingReference::create(
				isset( $data['tracking_number'] ) ? (string) $data['tracking_number'] : null,
				isset( $data['tracking_url'] ) ? (string) $data['tracking_url'] : null
			),
			(string) $data['status'],
			$data['shipped_at'] ?? null,
			$data['delivered_at'] ?? null,
			$data['created_at']
		);
	}

	/**
	 * The array shape {@see from_array()} rebuilds from.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'fulfillment_id'  => $this->fulfillment_id,
			'carrier_id'      => $this->carrier_id,
			'service'         => $this->service,
			'tracking_number' => $this->tracking->number(),
			'tracking_url'    => $this->tracking->url(),
			'status'          => $this->status,
			'shipped_at'      => $this->shipped_at,
			'delivered_at'    => $this->delivered_at,
			'created_at'      => $this->created_at,
		);
	}

	/**
	 * Own id, or null before the repository assigns one.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Owning fulfillment's id.
	 */
	public function fulfillment_id(): int {
		return $this->fulfillment_id;
	}

	/**
	 * Carrier registry key, or empty string until an operator chooses one.
	 */
	public function carrier_id(): string {
		return $this->carrier_id;
	}

	/**
	 * Carrier service name, or empty string.
	 */
	public function service(): string {
		return $this->service;
	}

	/**
	 * Consignment-level tracking.
	 */
	public function tracking(): TrackingReference {
		return $this->tracking;
	}

	/**
	 * Lifecycle status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Whether this shipment has a carrier and a tracking number recorded —
	 * the real data source for the `has_shipment` transition guard.
	 */
	public function is_ready(): bool {
		return '' !== $this->carrier_id && $this->tracking->is_present();
	}

	/**
	 * Whether this shipment has a tracking number recorded — the real data
	 * source for the `has_tracking` transition guard.
	 */
	public function has_tracking(): bool {
		return $this->tracking->is_present();
	}

	/**
	 * When this shipment was marked shipped, if it has been.
	 */
	public function shipped_at(): ?DateTimeImmutable {
		return $this->shipped_at;
	}

	/**
	 * When this shipment was marked delivered, if it has been.
	 */
	public function delivered_at(): ?DateTimeImmutable {
		return $this->delivered_at;
	}

	/**
	 * When this shipment was created.
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * Records the carrier and service.
	 *
	 * @param string $carrier_id Carrier registry key.
	 * @param string $service    Carrier service name.
	 */
	public function set_carrier( string $carrier_id, string $service = '' ): void {
		$this->carrier_id = $carrier_id;
		$this->service    = $service;
	}

	/**
	 * Records the consignment-level tracking reference.
	 *
	 * @param TrackingReference $tracking New tracking reference.
	 */
	public function set_tracking( TrackingReference $tracking ): void {
		$this->tracking = $tracking;
	}

	/**
	 * Marks this shipment shipped.
	 *
	 * @param DateTimeImmutable $now Current time.
	 */
	public function mark_shipped( DateTimeImmutable $now ): void {
		$this->status     = self::STATUS_SHIPPED;
		$this->shipped_at = $now;
	}

	/**
	 * Marks this shipment delivered.
	 *
	 * @param DateTimeImmutable $now Current time.
	 */
	public function mark_delivered( DateTimeImmutable $now ): void {
		$this->status       = self::STATUS_DELIVERED;
		$this->delivered_at = $now;
	}

	/**
	 * Marks this shipment as having a carrier-side exception. A shipped
	 * shipment is corrected, never deleted (Architecture Plan §IV.6).
	 */
	public function mark_exception(): void {
		$this->status = self::STATUS_EXCEPTION;
	}

	/**
	 * Whether this shipment may still be deleted outright (only while
	 * `pending` — Architecture Plan §IV.6).
	 */
	public function is_deletable(): bool {
		return self::STATUS_PENDING === $this->status;
	}
}
