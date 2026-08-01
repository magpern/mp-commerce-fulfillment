<?php
/**
 * An internal note attached to a fulfillment.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

use DateTimeImmutable;

/**
 * Internal only — never customer-facing, never synced anywhere outside
 * this plugin's own storage.
 */
final class Note {

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
	 * Author's user id.
	 *
	 * @var int
	 */
	private int $author_id;

	/**
	 * Note text.
	 *
	 * @var string
	 */
	private string $body;

	/**
	 * Whether this note is pinned to the top of the list.
	 *
	 * @var bool
	 */
	private bool $is_pinned;

	/**
	 * When this note was written.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $created_at;

	/**
	 * Assembles a note. Use {@see create()} instead of calling this directly.
	 *
	 * @param int|null          $id             Own id, or null before insert.
	 * @param int               $fulfillment_id Owning fulfillment's id.
	 * @param int               $author_id      Author's user id.
	 * @param string            $body           Note text.
	 * @param bool              $is_pinned      Whether pinned.
	 * @param DateTimeImmutable $created_at     When written.
	 */
	private function __construct(
		?int $id,
		int $fulfillment_id,
		int $author_id,
		string $body,
		bool $is_pinned,
		DateTimeImmutable $created_at
	) {
		$this->id             = $id;
		$this->fulfillment_id = $fulfillment_id;
		$this->author_id      = $author_id;
		$this->body           = $body;
		$this->is_pinned      = $is_pinned;
		$this->created_at     = $created_at;
	}

	/**
	 * Builds a new note.
	 *
	 * @param int               $fulfillment_id Owning fulfillment's id.
	 * @param int               $author_id      Author's user id.
	 * @param string            $body           Note text.
	 * @param DateTimeImmutable $now            Current time.
	 * @param bool              $is_pinned      Whether pinned.
	 */
	public static function create( int $fulfillment_id, int $author_id, string $body, DateTimeImmutable $now, bool $is_pinned = false ): self {
		return new self( null, $fulfillment_id, $author_id, $body, $is_pinned, $now );
	}

	/**
	 * Rebuilds a note from its array shape.
	 *
	 * @param array{id?:int|null,fulfillment_id:int,author_id:int,body:string,is_pinned:bool,created_at:DateTimeImmutable} $data Array shape produced by {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['id'] ) ? (int) $data['id'] : null,
			(int) $data['fulfillment_id'],
			(int) $data['author_id'],
			(string) $data['body'],
			(bool) $data['is_pinned'],
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
			'id'             => $this->id,
			'fulfillment_id' => $this->fulfillment_id,
			'author_id'      => $this->author_id,
			'body'           => $this->body,
			'is_pinned'      => $this->is_pinned,
			'created_at'     => $this->created_at,
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
	 * Author's user id.
	 */
	public function author_id(): int {
		return $this->author_id;
	}

	/**
	 * Note text.
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Whether this note is pinned to the top of the list.
	 */
	public function is_pinned(): bool {
		return $this->is_pinned;
	}

	/**
	 * When this note was written.
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * Pins this note.
	 */
	public function pin(): void {
		$this->is_pinned = true;
	}

	/**
	 * Unpins this note.
	 */
	public function unpin(): void {
		$this->is_pinned = false;
	}
}
