<?php
/**
 * The outcome of one DocumentService render.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

/**
 * Same shape as {@see ShippingOutcome}/{@see PackingOutcome}, for the same
 * reason. Success carries optional metadata for REST/UI (M4-C).
 */
final class DocumentOutcome {

	/**
	 * Whether the render succeeded.
	 *
	 * @var bool
	 */
	private bool $succeeded;

	/**
	 * The rendered HTML, if it succeeded.
	 *
	 * @var string|null
	 */
	private ?string $html;

	/**
	 * The recorded document's id, if it succeeded.
	 *
	 * @var int|null
	 */
	private ?int $document_id;

	/**
	 * Machine-readable failure code, if it did not succeed.
	 *
	 * @var string|null
	 */
	private ?string $failure_code;

	/**
	 * Human-readable failure message, if it did not succeed.
	 *
	 * @var string|null
	 */
	private ?string $failure_message;

	/**
	 * Success metadata (document_type, template_version, stored, …).
	 *
	 * @var array<string, mixed>
	 */
	private array $meta;

	/**
	 * Assembles an outcome. Use {@see succeeded()} or {@see failed()}
	 * instead of calling this directly.
	 *
	 * @param bool                 $succeeded       Whether the render succeeded.
	 * @param string|null          $html            The rendered HTML, if it succeeded.
	 * @param int|null             $document_id     The recorded document's id, if it succeeded.
	 * @param string|null          $failure_code    Machine-readable failure code, if not.
	 * @param string|null          $failure_message Human-readable failure message, if not.
	 * @param array<string, mixed> $meta            Success metadata.
	 */
	private function __construct( bool $succeeded, ?string $html, ?int $document_id, ?string $failure_code, ?string $failure_message, array $meta = array() ) {
		$this->succeeded       = $succeeded;
		$this->html            = $html;
		$this->document_id     = $document_id;
		$this->failure_code    = $failure_code;
		$this->failure_message = $failure_message;
		$this->meta            = $meta;
	}

	/**
	 * Builds a success outcome.
	 *
	 * @param string               $html        The rendered HTML.
	 * @param int                  $document_id The recorded document's id.
	 * @param array<string, mixed> $meta        Optional metadata for REST/UI.
	 */
	public static function succeeded( string $html, int $document_id, array $meta = array() ): self {
		return new self( true, $html, $document_id, null, null, $meta );
	}

	/**
	 * Builds a failure outcome.
	 *
	 * @param string $code    Machine-readable failure code (`not_found`, `invalid_payload`).
	 * @param string $message Human-readable failure message.
	 */
	public static function failed( string $code, string $message ): self {
		return new self( false, null, null, $code, $message );
	}

	/**
	 * Whether the render succeeded.
	 */
	public function is_success(): bool {
		return $this->succeeded;
	}

	/**
	 * The rendered HTML, if it succeeded.
	 */
	public function html(): ?string {
		return $this->html;
	}

	/**
	 * The recorded document's id, if it succeeded.
	 */
	public function document_id(): ?int {
		return $this->document_id;
	}

	/**
	 * Machine-readable failure code, if it did not succeed.
	 */
	public function failure_code(): ?string {
		return $this->failure_code;
	}

	/**
	 * Human-readable failure message, if it did not succeed.
	 */
	public function failure_message(): ?string {
		return $this->failure_message;
	}

	/**
	 * Success metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		return $this->meta;
	}
}
