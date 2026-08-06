<?php
/**
 * One row of package photography evidence (Part VIII).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Media;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable evidence record. Capture creates; soft-delete sets
 * `deleted_at` without removing bytes (retention purge is M6-D).
 */
final class PhotoRecord {

	/**
	 * Own id, or null before the repository assigns one.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Owning fulfillment (audit root).
	 *
	 * @var int
	 */
	private int $fulfillment_id;

	/**
	 * Owning package id (required in M6).
	 *
	 * @var int
	 */
	private int $package_id;

	/**
	 * Allow-listed kind ({@see PhotoKind}).
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * Relative path to the canonical JPEG.
	 *
	 * @var string
	 */
	private string $file_path;

	/**
	 * Relative path to the gallery thumbnail.
	 *
	 * @var string
	 */
	private string $thumb_path;

	/**
	 * Canonical MIME type.
	 *
	 * @var string
	 */
	private string $mime;

	/**
	 * Canonical byte length.
	 *
	 * @var int
	 */
	private int $bytes;

	/**
	 * SHA-256 of the canonical bytes.
	 *
	 * @var string
	 */
	private string $sha256;

	/**
	 * Pipeline version that produced the bytes.
	 *
	 * @var int
	 */
	private int $processing_version;

	/**
	 * Canonical width in pixels.
	 *
	 * @var int
	 */
	private int $width;

	/**
	 * Canonical height in pixels.
	 *
	 * @var int
	 */
	private int $height;

	/**
	 * Per-fulfillment capture sequence.
	 *
	 * @var int
	 */
	private int $seq;

	/**
	 * Capturing user id, or null for system actors.
	 *
	 * @var int|null
	 */
	private ?int $captured_by;

	/**
	 * When this photo was captured.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $created_at;

	/**
	 * Soft-delete timestamp, or null while active.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $deleted_at;

	/**
	 * Retention-purge timestamp, or null until purged (M6-D).
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $purged_at;

	/**
	 * Assembles a record. Use {@see create()} or {@see from_array()}.
	 *
	 * @param int|null               $id                  Own id, or null before insert.
	 * @param int                    $fulfillment_id      Owning fulfillment.
	 * @param int                    $package_id          Owning package.
	 * @param string                 $kind                Allow-listed kind.
	 * @param string                 $file_path           Relative canonical path.
	 * @param string                 $thumb_path          Relative thumbnail path.
	 * @param string                 $mime                Canonical MIME.
	 * @param int                    $bytes               Canonical byte length.
	 * @param string                 $sha256              SHA-256 hex digest.
	 * @param int                    $processing_version  Pipeline version.
	 * @param int                    $width               Canonical width.
	 * @param int                    $height              Canonical height.
	 * @param int                    $seq                 Per-fulfillment sequence.
	 * @param int|null               $captured_by         Capturing user id.
	 * @param DateTimeImmutable      $created_at          Capture time.
	 * @param DateTimeImmutable|null $deleted_at          Soft-delete time.
	 * @param DateTimeImmutable|null $purged_at           Retention-purge time.
	 */
	private function __construct(
		?int $id,
		int $fulfillment_id,
		int $package_id,
		string $kind,
		string $file_path,
		string $thumb_path,
		string $mime,
		int $bytes,
		string $sha256,
		int $processing_version,
		int $width,
		int $height,
		int $seq,
		?int $captured_by,
		DateTimeImmutable $created_at,
		?DateTimeImmutable $deleted_at,
		?DateTimeImmutable $purged_at
	) {
		self::assert_relative_path( $file_path, 'file_path' );
		self::assert_relative_path( $thumb_path, 'thumb_path' );
		PhotoKind::assert_valid( $kind );

		if ( $fulfillment_id <= 0 ) {
			throw new InvalidArgumentException( 'fulfillment_id must be greater than zero.' );
		}

		if ( $package_id <= 0 ) {
			throw new InvalidArgumentException( 'package_id must be greater than zero.' );
		}

		if ( null !== $id && $id <= 0 ) {
			throw new InvalidArgumentException( 'id must be greater than zero when set.' );
		}

		if ( 64 !== strlen( $sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			throw new InvalidArgumentException( 'sha256 must be a 64-character lowercase hex digest.' );
		}

		if ( $processing_version < 1 ) {
			throw new InvalidArgumentException( 'processing_version must be at least 1.' );
		}

		if ( '' === $mime ) {
			throw new InvalidArgumentException( 'mime must not be empty.' );
		}

		if ( $bytes < 1 || $width < 1 || $height < 1 || $seq < 1 ) {
			throw new InvalidArgumentException( 'bytes, width, height and seq must be at least 1.' );
		}

		if ( null !== $captured_by && $captured_by <= 0 ) {
			throw new InvalidArgumentException( 'captured_by must be greater than zero when set.' );
		}

		$this->id                 = $id;
		$this->fulfillment_id     = $fulfillment_id;
		$this->package_id         = $package_id;
		$this->kind               = $kind;
		$this->file_path          = $file_path;
		$this->thumb_path         = $thumb_path;
		$this->mime               = $mime;
		$this->bytes              = $bytes;
		$this->sha256             = $sha256;
		$this->processing_version = $processing_version;
		$this->width              = $width;
		$this->height             = $height;
		$this->seq                = $seq;
		$this->captured_by        = $captured_by;
		$this->created_at         = $created_at;
		$this->deleted_at         = $deleted_at;
		$this->purged_at          = $purged_at;
	}

	/**
	 * Creates a brand-new active photo record.
	 *
	 * @param int               $fulfillment_id     Owning fulfillment.
	 * @param int               $package_id         Owning package.
	 * @param string            $kind               Allow-listed kind.
	 * @param string            $file_path          Relative canonical path.
	 * @param string            $thumb_path         Relative thumbnail path.
	 * @param string            $mime               Canonical MIME.
	 * @param int               $bytes              Canonical byte length.
	 * @param string            $sha256             SHA-256 hex digest.
	 * @param int               $processing_version Pipeline version.
	 * @param int               $width              Canonical width.
	 * @param int               $height             Canonical height.
	 * @param int               $seq                Per-fulfillment sequence.
	 * @param int|null          $captured_by        Capturing user id.
	 * @param DateTimeImmutable $now                Capture time.
	 */
	public static function create(
		int $fulfillment_id,
		int $package_id,
		string $kind,
		string $file_path,
		string $thumb_path,
		string $mime,
		int $bytes,
		string $sha256,
		int $processing_version,
		int $width,
		int $height,
		int $seq,
		?int $captured_by,
		DateTimeImmutable $now
	): self {
		return new self(
			null,
			$fulfillment_id,
			$package_id,
			$kind,
			$file_path,
			$thumb_path,
			$mime,
			$bytes,
			$sha256,
			$processing_version,
			$width,
			$height,
			$seq,
			$captured_by,
			$now,
			null,
			null
		);
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
			(int) $data['package_id'],
			(string) $data['kind'],
			(string) $data['file_path'],
			(string) $data['thumb_path'],
			(string) $data['mime'],
			(int) $data['bytes'],
			(string) $data['sha256'],
			(int) $data['processing_version'],
			(int) $data['width'],
			(int) $data['height'],
			(int) $data['seq'],
			array_key_exists( 'captured_by', $data ) && null !== $data['captured_by']
				? (int) $data['captured_by']
				: null,
			$data['created_at'] instanceof DateTimeImmutable
				? $data['created_at']
				: new DateTimeImmutable( (string) $data['created_at'] ),
			isset( $data['deleted_at'] ) && null !== $data['deleted_at']
				? ( $data['deleted_at'] instanceof DateTimeImmutable
					? $data['deleted_at']
					: new DateTimeImmutable( (string) $data['deleted_at'] ) )
				: null,
			isset( $data['purged_at'] ) && null !== $data['purged_at']
				? ( $data['purged_at'] instanceof DateTimeImmutable
					? $data['purged_at']
					: new DateTimeImmutable( (string) $data['purged_at'] ) )
				: null
		);
	}

	/**
	 * The array shape {@see from_array()} rebuilds from.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                 => $this->id,
			'fulfillment_id'     => $this->fulfillment_id,
			'package_id'         => $this->package_id,
			'kind'               => $this->kind,
			'file_path'          => $this->file_path,
			'thumb_path'         => $this->thumb_path,
			'mime'               => $this->mime,
			'bytes'              => $this->bytes,
			'sha256'             => $this->sha256,
			'processing_version' => $this->processing_version,
			'width'              => $this->width,
			'height'             => $this->height,
			'seq'                => $this->seq,
			'captured_by'        => $this->captured_by,
			'created_at'         => $this->created_at,
			'deleted_at'         => $this->deleted_at,
			'purged_at'          => $this->purged_at,
		);
	}

	/**
	 * Whether the photo is active (not soft-deleted).
	 */
	public function is_active(): bool {
		return null === $this->deleted_at;
	}

	/**
	 * Whether the photo has been soft-deleted.
	 */
	public function is_deleted(): bool {
		return null !== $this->deleted_at;
	}

	/**
	 * Own id, or null before insert.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Owning fulfillment id.
	 */
	public function fulfillment_id(): int {
		return $this->fulfillment_id;
	}

	/**
	 * Owning package id.
	 */
	public function package_id(): int {
		return $this->package_id;
	}

	/**
	 * Allow-listed kind.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Relative canonical path.
	 */
	public function file_path(): string {
		return $this->file_path;
	}

	/**
	 * Relative thumbnail path.
	 */
	public function thumb_path(): string {
		return $this->thumb_path;
	}

	/**
	 * Canonical MIME.
	 */
	public function mime(): string {
		return $this->mime;
	}

	/**
	 * Canonical byte length.
	 */
	public function bytes(): int {
		return $this->bytes;
	}

	/**
	 * SHA-256 of canonical bytes.
	 */
	public function sha256(): string {
		return $this->sha256;
	}

	/**
	 * Pipeline version.
	 */
	public function processing_version(): int {
		return $this->processing_version;
	}

	/**
	 * Canonical width.
	 */
	public function width(): int {
		return $this->width;
	}

	/**
	 * Canonical height.
	 */
	public function height(): int {
		return $this->height;
	}

	/**
	 * Per-fulfillment sequence.
	 */
	public function seq(): int {
		return $this->seq;
	}

	/**
	 * Capturing user id, or null.
	 */
	public function captured_by(): ?int {
		return $this->captured_by;
	}

	/**
	 * Capture time.
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * Soft-delete time, or null.
	 */
	public function deleted_at(): ?DateTimeImmutable {
		return $this->deleted_at;
	}

	/**
	 * Retention-purge time, or null.
	 */
	public function purged_at(): ?DateTimeImmutable {
		return $this->purged_at;
	}

	/**
	 * Asserts a relative storage path is non-empty and traversal-safe.
	 *
	 * @param string $path  Relative path.
	 * @param string $field Field name for the exception message.
	 * @throws InvalidArgumentException When the path is unsafe.
	 */
	private static function assert_relative_path( string $path, string $field ): void {
		$path = str_replace( '\\', '/', $path );

		if ( '' === $path || str_starts_with( $path, '/' ) || str_contains( $path, '..' ) || str_contains( $path, "\0" ) ) {
			throw new InvalidArgumentException( sprintf( '%s must be a non-empty relative path without traversal.', $field ) );
		}
	}
}
