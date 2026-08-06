<?php
/**
 * Wave aggregate root — multi-fulfillment warehouse walk.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Wave;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Architecture Plan Part X.2. Quantities live on fulfillment items; this
 * aggregate owns lifecycle, ownership, membership, and optimistic version.
 */
final class Wave {

	/**
	 * Own id, or null before insert.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Warehouse this wave belongs to.
	 *
	 * @var int
	 */
	private int $warehouse_id;

	/**
	 * Exclusive owner user id (set on activate; required while active/paused).
	 *
	 * @var int|null
	 */
	private ?int $owner_user_id;

	/**
	 * Lifecycle state.
	 *
	 * @var string
	 */
	private string $state;

	/**
	 * Optimistic concurrency version.
	 *
	 * @var int
	 */
	private int $version;

	/**
	 * Optional operator label.
	 *
	 * @var string
	 */
	private string $title;

	/**
	 * Settings / grouping snapshot for auditability.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings_snapshot;

	/**
	 * Creation time.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $created_at;

	/**
	 * Last update time.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $updated_at;

	/**
	 * Activation time, or null.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $activated_at;

	/**
	 * Completion time, or null.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $completed_at;

	/**
	 * Abandon time, or null.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $abandoned_at;

	/**
	 * In-memory members (position-ordered).
	 *
	 * @var list<WaveMember>
	 */
	private array $members;

	/**
	 * Assembles a wave. Use {@see create()} / {@see from_array()}.
	 *
	 * @param int|null               $id                Own id.
	 * @param int                    $warehouse_id      Warehouse id.
	 * @param int|null               $owner_user_id     Owner user id.
	 * @param string                 $state             Lifecycle state.
	 * @param int                    $version           Optimistic version.
	 * @param string                 $title             Label.
	 * @param array<string, mixed>   $settings_snapshot Settings snapshot.
	 * @param DateTimeImmutable      $created_at        Created at.
	 * @param DateTimeImmutable      $updated_at        Updated at.
	 * @param DateTimeImmutable|null $activated_at      Activated at.
	 * @param DateTimeImmutable|null $completed_at      Completed at.
	 * @param DateTimeImmutable|null $abandoned_at      Abandoned at.
	 * @param array<int, WaveMember> $members           Members.
	 */
	private function __construct(
		?int $id,
		int $warehouse_id,
		?int $owner_user_id,
		string $state,
		int $version,
		string $title,
		array $settings_snapshot,
		DateTimeImmutable $created_at,
		DateTimeImmutable $updated_at,
		?DateTimeImmutable $activated_at,
		?DateTimeImmutable $completed_at,
		?DateTimeImmutable $abandoned_at,
		array $members
	) {
		$this->id                = $id;
		$this->warehouse_id      = $warehouse_id;
		$this->owner_user_id     = $owner_user_id;
		$this->state             = $state;
		$this->version           = $version;
		$this->title             = $title;
		$this->settings_snapshot = $settings_snapshot;
		$this->created_at        = $created_at;
		$this->updated_at        = $updated_at;
		$this->activated_at      = $activated_at;
		$this->completed_at      = $completed_at;
		$this->abandoned_at      = $abandoned_at;
		$this->members           = array_values( $members );
	}

	/**
	 * Creates a draft wave.
	 *
	 * @param int                  $warehouse_id      Warehouse id.
	 * @param DateTimeImmutable    $now               Current time.
	 * @param int|null             $owner_user_id     Optional draft owner hint.
	 * @param string               $title             Optional label.
	 * @param array<string, mixed> $settings_snapshot Settings snapshot.
	 */
	public static function create(
		int $warehouse_id,
		DateTimeImmutable $now,
		?int $owner_user_id = null,
		string $title = '',
		array $settings_snapshot = array()
	): self {
		return new self(
			null,
			$warehouse_id,
			$owner_user_id,
			WaveState::DRAFT,
			1,
			$title,
			$settings_snapshot,
			$now,
			$now,
			null,
			null,
			null,
			array()
		);
	}

	/**
	 * Rebuilds from array shape.
	 *
	 * @param array<string, mixed> $data Array shape from {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		$members = array();

		if ( isset( $data['members'] ) && is_array( $data['members'] ) ) {
			foreach ( $data['members'] as $member ) {
				$members[] = $member instanceof WaveMember
					? $member
					: WaveMember::from_array( (array) $member );
			}
		}

		$snapshot = $data['settings_snapshot'] ?? array();
		if ( is_string( $snapshot ) ) {
			$decoded  = json_decode( $snapshot, true );
			$snapshot = is_array( $decoded ) ? $decoded : array();
		}

		return new self(
			isset( $data['id'] ) ? (int) $data['id'] : null,
			(int) $data['warehouse_id'],
			isset( $data['owner_user_id'] ) && null !== $data['owner_user_id'] && '' !== $data['owner_user_id']
				? (int) $data['owner_user_id']
				: null,
			(string) $data['state'],
			(int) ( $data['version'] ?? 1 ),
			(string) ( $data['title'] ?? '' ),
			is_array( $snapshot ) ? $snapshot : array(),
			self::as_datetime( $data['created_at'] ),
			self::as_datetime( $data['updated_at'] ?? $data['created_at'] ),
			self::nullable_datetime( $data['activated_at'] ?? null ),
			self::nullable_datetime( $data['completed_at'] ?? null ),
			self::nullable_datetime( $data['abandoned_at'] ?? null ),
			$members
		);
	}

	/**
	 * Array shape for persistence / tests.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                => $this->id,
			'warehouse_id'      => $this->warehouse_id,
			'owner_user_id'     => $this->owner_user_id,
			'state'             => $this->state,
			'version'           => $this->version,
			'title'             => $this->title,
			'settings_snapshot' => $this->settings_snapshot,
			'created_at'        => $this->created_at,
			'updated_at'        => $this->updated_at,
			'activated_at'      => $this->activated_at,
			'completed_at'      => $this->completed_at,
			'abandoned_at'      => $this->abandoned_at,
			'members'           => array_map(
				static fn( WaveMember $member ): array => $member->to_array(),
				$this->members
			),
		);
	}

	/**
	 * Own id, or null before insert.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Warehouse id.
	 */
	public function warehouse_id(): int {
		return $this->warehouse_id;
	}

	/**
	 * Owner user id, or null.
	 */
	public function owner_user_id(): ?int {
		return $this->owner_user_id;
	}

	/**
	 * Lifecycle state.
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * Optimistic concurrency version.
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Optional label.
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Settings snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_snapshot(): array {
		return $this->settings_snapshot;
	}

	/**
	 * Created at.
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * Updated at.
	 */
	public function updated_at(): DateTimeImmutable {
		return $this->updated_at;
	}

	/**
	 * Activated at, or null.
	 */
	public function activated_at(): ?DateTimeImmutable {
		return $this->activated_at;
	}

	/**
	 * Completed at, or null.
	 */
	public function completed_at(): ?DateTimeImmutable {
		return $this->completed_at;
	}

	/**
	 * Abandoned at, or null.
	 */
	public function abandoned_at(): ?DateTimeImmutable {
		return $this->abandoned_at;
	}

	/**
	 * Members in position order.
	 *
	 * @return list<WaveMember>
	 */
	public function members(): array {
		return $this->members;
	}

	/**
	 * Whether the wave still holds exclusive membership.
	 */
	public function is_open(): bool {
		return WaveState::is_open( $this->state );
	}

	/**
	 * Member count.
	 */
	public function member_count(): int {
		return count( $this->members );
	}

	/**
	 * Finds a member by fulfillment id.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function member( int $fulfillment_id ): ?WaveMember {
		foreach ( $this->members as $member ) {
			if ( $member->fulfillment_id() === $fulfillment_id ) {
				return $member;
			}
		}

		return null;
	}

	/**
	 * Whether `$user_id` owns this wave (or draft has no owner yet).
	 *
	 * @param int $user_id Operator user id.
	 */
	public function is_owned_by( int $user_id ): bool {
		if ( null === $this->owner_user_id ) {
			return WaveState::DRAFT === $this->state;
		}

		return $this->owner_user_id === $user_id;
	}

	/**
	 * Adds a member. Caller enforces eligibility / exclusivity / caps.
	 *
	 * @param int               $fulfillment_id Fulfillment id.
	 * @param DateTimeImmutable $now            Join time.
	 * @throws InvalidArgumentException When already a member or wave is not editable.
	 */
	public function add_member( int $fulfillment_id, DateTimeImmutable $now ): WaveMember {
		if ( ! in_array( $this->state, array( WaveState::DRAFT, WaveState::PAUSED ), true ) ) {
			throw new InvalidArgumentException( 'Members can only be added while draft or paused.' );
		}

		if ( null !== $this->member( $fulfillment_id ) ) {
			throw new InvalidArgumentException( 'Fulfillment is already a member of this wave.' );
		}

		$position = 0;
		foreach ( $this->members as $existing ) {
			$position = max( $position, $existing->position() );
		}

		$member          = WaveMember::create( (int) ( $this->id ?? 0 ), $fulfillment_id, $position + 1, $now );
		$this->members[] = $member;
		$this->touch( $now );

		return $member;
	}

	/**
	 * Removes a member that is not yet picked.
	 *
	 * @param int               $fulfillment_id Fulfillment id.
	 * @param DateTimeImmutable $now            Update time.
	 * @throws InvalidArgumentException When not removable.
	 */
	public function remove_member( int $fulfillment_id, DateTimeImmutable $now ): void {
		if ( ! in_array( $this->state, array( WaveState::DRAFT, WaveState::PAUSED ), true ) ) {
			throw new InvalidArgumentException( 'Members can only be removed while draft or paused.' );
		}

		$kept  = array();
		$found = false;

		foreach ( $this->members as $member ) {
			if ( $member->fulfillment_id() !== $fulfillment_id ) {
				$kept[] = $member;
				continue;
			}

			$found = true;

			if ( $member->is_picked() ) {
				throw new InvalidArgumentException( 'Cannot remove a member that is already picked.' );
			}
		}

		if ( ! $found ) {
			throw new InvalidArgumentException( 'Fulfillment is not a member of this wave.' );
		}

		$this->members = $kept;
		$this->touch( $now );
	}

	/**
	 * Activates the wave and claims ownership.
	 *
	 * @param int               $owner_user_id Owner user id.
	 * @param DateTimeImmutable $now           Activation time.
	 * @throws InvalidArgumentException When transition is illegal.
	 */
	public function activate( int $owner_user_id, DateTimeImmutable $now ): void {
		$this->assert_transition( WaveState::ACTIVE );
		$this->state         = WaveState::ACTIVE;
		$this->owner_user_id = $owner_user_id;
		$this->activated_at  = $this->activated_at ?? $now;
		$this->touch( $now );
	}

	/**
	 * Pauses an active wave.
	 *
	 * @param DateTimeImmutable $now Pause time.
	 * @throws InvalidArgumentException When transition is illegal.
	 */
	public function pause( DateTimeImmutable $now ): void {
		$this->assert_transition( WaveState::PAUSED );
		$this->state = WaveState::PAUSED;
		$this->touch( $now );
	}

	/**
	 * Resumes a paused wave.
	 *
	 * @param DateTimeImmutable $now Resume time.
	 * @throws InvalidArgumentException When transition is illegal.
	 */
	public function resume( DateTimeImmutable $now ): void {
		$this->assert_transition( WaveState::ACTIVE );
		$this->state = WaveState::ACTIVE;
		$this->touch( $now );
	}

	/**
	 * Completes the wave.
	 *
	 * @param DateTimeImmutable $now Completion time.
	 * @throws InvalidArgumentException When transition is illegal.
	 */
	public function complete( DateTimeImmutable $now ): void {
		$this->assert_transition( WaveState::COMPLETED );
		$this->state        = WaveState::COMPLETED;
		$this->completed_at = $now;
		$this->touch( $now );
	}

	/**
	 * Abandons the wave (membership released by the service).
	 *
	 * @param DateTimeImmutable $now Abandon time.
	 * @throws InvalidArgumentException When transition is illegal.
	 */
	public function abandon( DateTimeImmutable $now ): void {
		$this->assert_transition( WaveState::ABANDONED );
		$this->state        = WaveState::ABANDONED;
		$this->abandoned_at = $now;
		$this->touch( $now );
	}

	/**
	 * Marks a member picked under this wave.
	 *
	 * @param int               $fulfillment_id Fulfillment id.
	 * @param DateTimeImmutable $now            Picked time.
	 * @throws InvalidArgumentException When the fulfillment is not a member.
	 */
	public function mark_member_picked( int $fulfillment_id, DateTimeImmutable $now ): void {
		$member = $this->member( $fulfillment_id );

		if ( null === $member ) {
			throw new InvalidArgumentException( 'Fulfillment is not a member of this wave.' );
		}

		$member->mark_picked( $now );
		$this->touch( $now );
	}

	/**
	 * Whether every member has been marked picked.
	 */
	public function all_members_picked(): bool {
		if ( array() === $this->members ) {
			return false;
		}

		foreach ( $this->members as $member ) {
			if ( ! $member->is_picked() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Clears membership after abandon (in-memory).
	 *
	 * @param DateTimeImmutable $now Update time.
	 */
	public function clear_members( DateTimeImmutable $now ): void {
		$this->members = array();
		$this->touch( $now );
	}

	/**
	 * Bumps optimistic version after a successful repository write.
	 */
	public function increment_version(): void {
		++$this->version;
	}

	/**
	 * Assigns the persistence id after insert.
	 *
	 * @param int $id Assigned id.
	 */
	public function assign_id( int $id ): void {
		$this->id = $id;

		foreach ( $this->members as $member ) {
			$member->bind_wave_id( $id );
		}
	}

	/**
	 * Replaces the in-memory member list (repository hydration).
	 *
	 * @param array<int, WaveMember> $members Members.
	 */
	public function replace_members( array $members ): void {
		$this->members = array_values( $members );
	}

	/**
	 * Asserts a legal transition.
	 *
	 * @param string $to Target state.
	 * @throws InvalidArgumentException When illegal.
	 */
	private function assert_transition( string $to ): void {
		if ( ! WaveState::can_transition( $this->state, $to ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Cannot transition wave from %s to %s.', $this->state, $to )
			);
		}
	}

	/**
	 * Updates `updated_at`.
	 *
	 * @param DateTimeImmutable $now Current time.
	 */
	private function touch( DateTimeImmutable $now ): void {
		$this->updated_at = $now;
	}

	/**
	 * Coerces a datetime value.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function as_datetime( mixed $value ): DateTimeImmutable {
		if ( $value instanceof DateTimeImmutable ) {
			return $value;
		}

		return new DateTimeImmutable( (string) $value );
	}

	/**
	 * Coerces a nullable datetime.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function nullable_datetime( mixed $value ): ?DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return self::as_datetime( $value );
	}
}
