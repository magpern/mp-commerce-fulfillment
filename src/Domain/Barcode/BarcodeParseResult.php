<?php
/**
 * Outcome of attempting to parse a barcode payload string.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Barcode;

/**
 * Discriminated parse outcome — namespaced ok, plain (SKU candidate), empty,
 * or malformed MPCF-shaped input.
 */
final class BarcodeParseResult {

	public const KIND_OK = 'ok';

	public const KIND_NOT_NAMESPACED = 'not_namespaced';

	public const KIND_MALFORMED = 'malformed';

	public const KIND_EMPTY = 'empty';

	/**
	 * Outcome kind.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * Parsed payload when kind is ok.
	 *
	 * @var BarcodePayload|null
	 */
	private ?BarcodePayload $payload;

	/**
	 * Raw trimmed candidate when not namespaced (SKU / order-number text).
	 *
	 * @var string|null
	 */
	private ?string $plain;

	/**
	 * Machine-readable malformed reason.
	 *
	 * @var string|null
	 */
	private ?string $reason;

	/**
	 * Assembles a parse outcome.
	 *
	 * @param string              $kind    KIND_* constant.
	 * @param BarcodePayload|null $payload Parsed payload when ok.
	 * @param string|null         $plain   Plain text when not namespaced.
	 * @param string|null         $reason  Malformed reason code.
	 */
	private function __construct( string $kind, ?BarcodePayload $payload, ?string $plain, ?string $reason ) {
		$this->kind    = $kind;
		$this->payload = $payload;
		$this->plain   = $plain;
		$this->reason  = $reason;
	}

	/**
	 * Successful namespaced parse.
	 *
	 * @param BarcodePayload $payload Parsed payload.
	 */
	public static function ok( BarcodePayload $payload ): self {
		return new self( self::KIND_OK, $payload, null, null );
	}

	/**
	 * Input is not an MPCF namespaced payload (try SKU matching).
	 *
	 * @param string $plain Trimmed plain text.
	 */
	public static function not_namespaced( string $plain ): self {
		return new self( self::KIND_NOT_NAMESPACED, null, $plain, null );
	}

	/**
	 * MPCF-shaped but invalid.
	 *
	 * @param string $reason Reason code.
	 */
	public static function malformed( string $reason ): self {
		return new self( self::KIND_MALFORMED, null, null, $reason );
	}

	/**
	 * Empty / whitespace-only input.
	 */
	public static function empty_input(): self {
		return new self( self::KIND_EMPTY, null, null, 'empty' );
	}

	/**
	 * Outcome kind.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Whether parse produced a namespaced payload.
	 */
	public function is_ok(): bool {
		return self::KIND_OK === $this->kind;
	}

	/**
	 * Whether the string should be treated as a plain SKU/order candidate.
	 */
	public function is_plain(): bool {
		return self::KIND_NOT_NAMESPACED === $this->kind;
	}

	/**
	 * Whether the input was empty.
	 */
	public function is_empty(): bool {
		return self::KIND_EMPTY === $this->kind;
	}

	/**
	 * Whether the input looked like MPCF: but failed validation.
	 */
	public function is_malformed(): bool {
		return self::KIND_MALFORMED === $this->kind;
	}

	/**
	 * Parsed payload, or null.
	 */
	public function payload(): ?BarcodePayload {
		return $this->payload;
	}

	/**
	 * Plain text candidate, or null.
	 */
	public function plain(): ?string {
		return $this->plain;
	}

	/**
	 * Malformed reason, or null.
	 */
	public function reason(): ?string {
		return $this->reason;
	}
}
