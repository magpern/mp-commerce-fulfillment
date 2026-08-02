<?php
/**
 * One entry in the append-only, hash-chained audit log.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Event;

use DateTimeImmutable;

/**
 * Immutable value object. This is the pre-persistence shape the Engine and
 * Application layers produce and pass to the audit repository — hashing
 * (`prev_hash`/`hash`) is computed at the point of storage by
 * {@see Canonicalizer}, not here, since it depends on the previous event in
 * the same fulfillment's chain, which this object has no way to know.
 *
 * `fulfillment_id` is nullable for global (non-fulfillment-scoped) events.
 * `schema_version` lets a later milestone change a given `event_type`'s
 * payload shape without invalidating history already written under the old
 * shape.
 */
final class DomainEvent {

	/**
	 * Owning fulfillment's id, or null for a global event.
	 *
	 * @var int|null
	 */
	private ?int $fulfillment_id;

	/**
	 * Event type identifier, e.g. "fulfillment.state_changed".
	 *
	 * @var string
	 */
	private string $event_type;

	/**
	 * Who or what caused this event.
	 *
	 * @var Actor
	 */
	private Actor $actor;

	/**
	 * When this event occurred.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $occurred_at;

	/**
	 * Event-type-specific data.
	 *
	 * @var array<string, mixed>
	 */
	private array $payload;

	/**
	 * Payload schema version for this event type.
	 *
	 * @var int
	 */
	private int $schema_version;

	/**
	 * Assembles an event. Use the named factories ({@see for_fulfillment()},
	 * {@see global_event()}) instead of calling this directly.
	 *
	 * @param int|null             $fulfillment_id  Owning fulfillment's id, or null for a global event.
	 * @param string               $event_type      Event type identifier.
	 * @param Actor                $actor           Who or what caused this event.
	 * @param DateTimeImmutable    $occurred_at     When this event occurred.
	 * @param array<string, mixed> $payload         Event-type-specific data.
	 * @param int                  $schema_version  Payload schema version.
	 */
	private function __construct(
		?int $fulfillment_id,
		string $event_type,
		Actor $actor,
		DateTimeImmutable $occurred_at,
		array $payload,
		int $schema_version
	) {
		$this->fulfillment_id = $fulfillment_id;
		$this->event_type     = $event_type;
		$this->actor          = $actor;
		$this->occurred_at    = $occurred_at;
		$this->payload        = $payload;
		$this->schema_version = $schema_version;
	}

	/**
	 * Builds an event scoped to one fulfillment.
	 *
	 * @param int                  $fulfillment_id Owning fulfillment's id.
	 * @param string               $event_type     Event type identifier.
	 * @param Actor                $actor          Who or what caused this event.
	 * @param DateTimeImmutable    $occurred_at    When this event occurred.
	 * @param array<string, mixed> $payload        Event-type-specific data.
	 * @param int                  $schema_version Payload schema version.
	 */
	public static function for_fulfillment(
		int $fulfillment_id,
		string $event_type,
		Actor $actor,
		DateTimeImmutable $occurred_at,
		array $payload = array(),
		int $schema_version = 1
	): self {
		PayloadGuard::assert_safe( $payload );

		return new self( $fulfillment_id, $event_type, $actor, $occurred_at, $payload, $schema_version );
	}

	/**
	 * Builds a global (non-fulfillment-scoped) event.
	 *
	 * @param string               $event_type     Event type identifier.
	 * @param Actor                $actor          Who or what caused this event.
	 * @param DateTimeImmutable    $occurred_at    When this event occurred.
	 * @param array<string, mixed> $payload        Event-type-specific data.
	 * @param int                  $schema_version Payload schema version.
	 */
	public static function global_event(
		string $event_type,
		Actor $actor,
		DateTimeImmutable $occurred_at,
		array $payload = array(),
		int $schema_version = 1
	): self {
		PayloadGuard::assert_safe( $payload );

		return new self( null, $event_type, $actor, $occurred_at, $payload, $schema_version );
	}

	/**
	 * Owning fulfillment's id, or null for a global event.
	 */
	public function fulfillment_id(): ?int {
		return $this->fulfillment_id;
	}

	/**
	 * Event type identifier.
	 */
	public function event_type(): string {
		return $this->event_type;
	}

	/**
	 * Who or what caused this event.
	 */
	public function actor(): Actor {
		return $this->actor;
	}

	/**
	 * When this event occurred.
	 */
	public function occurred_at(): DateTimeImmutable {
		return $this->occurred_at;
	}

	/**
	 * Event-type-specific data.
	 *
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * Payload schema version for this event type.
	 */
	public function schema_version(): int {
		return $this->schema_version;
	}

	/**
	 * The payload with the schema version folded in — what actually gets
	 * canonicalized and hashed, so a payload-shape change is itself part of
	 * what the hash chain proves was never tampered with.
	 *
	 * @return array<string, mixed>
	 */
	public function hashable_payload(): array {
		return array( 'v' => $this->schema_version ) + $this->payload;
	}
}
