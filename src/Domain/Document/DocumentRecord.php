<?php
/**
 * One row of the document generation record (§10).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Document;

use DateTimeImmutable;

/**
 * Append-only, mirroring the audit log's own contract (I5's discipline
 * extended to documents): "documents printed" must be a reliable fact, so
 * a rendered document is recorded and never edited or deleted.
 * `file_path` stays null for a rendered-to-print document — every
 * Milestone 2 packing slip; a future milestone's stored renders populate
 * it.
 */
final class DocumentRecord {

	/**
	 * Own id, or null before the repository assigns one.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * The fulfillment this document describes.
	 *
	 * @var int
	 */
	private int $fulfillment_id;

	/**
	 * Document type registry key.
	 *
	 * @var string
	 */
	private string $doc_type;

	/**
	 * The template version that produced this render, so a reprint can
	 * always say exactly what the original looked like.
	 *
	 * @var string
	 */
	private string $template_version;

	/**
	 * Stored file path, or null for render-to-print.
	 *
	 * @var string|null
	 */
	private ?string $file_path;

	/**
	 * User id who rendered this document.
	 *
	 * @var int
	 */
	private int $rendered_by;

	/**
	 * When this document was rendered.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $created_at;

	/**
	 * Assembles a record. Use {@see create()} instead of calling this
	 * directly.
	 *
	 * @param int|null          $id               Own id, or null before insert.
	 * @param int               $fulfillment_id   The fulfillment this document describes.
	 * @param string            $doc_type         Document type registry key.
	 * @param string            $template_version The template version that produced this render.
	 * @param string|null       $file_path        Stored file path, or null for render-to-print.
	 * @param int               $rendered_by      User id who rendered this document.
	 * @param DateTimeImmutable $created_at       When rendered.
	 */
	private function __construct(
		?int $id,
		int $fulfillment_id,
		string $doc_type,
		string $template_version,
		?string $file_path,
		int $rendered_by,
		DateTimeImmutable $created_at
	) {
		$this->id               = $id;
		$this->fulfillment_id   = $fulfillment_id;
		$this->doc_type         = $doc_type;
		$this->template_version = $template_version;
		$this->file_path        = $file_path;
		$this->rendered_by      = $rendered_by;
		$this->created_at       = $created_at;
	}

	/**
	 * Creates a brand-new document record.
	 *
	 * @param int               $fulfillment_id   The fulfillment this document describes.
	 * @param string            $doc_type         Document type registry key.
	 * @param string            $template_version The template version that produced this render.
	 * @param string|null       $file_path        Stored file path, or null for render-to-print.
	 * @param int               $rendered_by      User id who rendered this document.
	 * @param DateTimeImmutable $now              Current time.
	 */
	public static function create(
		int $fulfillment_id,
		string $doc_type,
		string $template_version,
		?string $file_path,
		int $rendered_by,
		DateTimeImmutable $now
	): self {
		return new self( null, $fulfillment_id, $doc_type, $template_version, $file_path, $rendered_by, $now );
	}

	/**
	 * Rebuilds a record from its array shape.
	 *
	 * @param array<string, mixed> $data Array shape produced by {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['id'] ) ? (int) $data['id'] : null,
			(int) $data['fulfillment_id'],
			(string) $data['doc_type'],
			(string) $data['template_version'],
			isset( $data['file_path'] ) ? (string) $data['file_path'] : null,
			(int) $data['rendered_by'],
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
			'id'               => $this->id,
			'fulfillment_id'   => $this->fulfillment_id,
			'doc_type'         => $this->doc_type,
			'template_version' => $this->template_version,
			'file_path'        => $this->file_path,
			'rendered_by'      => $this->rendered_by,
			'created_at'       => $this->created_at,
		);
	}

	/**
	 * Own id, or null before the repository assigns one.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * The fulfillment this document describes.
	 */
	public function fulfillment_id(): int {
		return $this->fulfillment_id;
	}

	/**
	 * Document type registry key.
	 */
	public function doc_type(): string {
		return $this->doc_type;
	}

	/**
	 * The template version that produced this render.
	 */
	public function template_version(): string {
		return $this->template_version;
	}

	/**
	 * Stored file path, or null for render-to-print.
	 */
	public function file_path(): ?string {
		return $this->file_path;
	}

	/**
	 * User id who rendered this document.
	 */
	public function rendered_by(): int {
		return $this->rendered_by;
	}

	/**
	 * When this document was rendered.
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}
}
