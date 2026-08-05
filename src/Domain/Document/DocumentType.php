<?php
/**
 * One entry in the document-type registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Document;

/**
 * Immutable value object for a registered document type. The filterable
 * registry (`mpcf_document_types`) lives at the Documents edge; this class
 * is only the pure data shape one entry takes.
 *
 * M4 ships exactly two keys: `packing_slip` and `picking_list`. Keep this
 * shape small — no plugin framework.
 */
final class DocumentType {

	/**
	 * Storage policy: render-to-print (file_path stays NULL). Protected
	 * file storage arrives in M4-B.
	 */
	public const STORAGE_PRINT = 'print';

	/**
	 * Storage policy: persist HTML bytes under the protected store (M4-B).
	 */
	public const STORAGE_STORE = 'store';

	/**
	 * Canonical renderer key — HTML is the architectural center.
	 */
	public const RENDERER_HTML = 'html';

	/**
	 * Registry key.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Display label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Assembler identifier (maps to a known Engine assembler).
	 *
	 * @var string
	 */
	private string $assembler;

	/**
	 * Template key (underscore form; filenames use hyphens).
	 *
	 * @var string
	 */
	private string $template_key;

	/**
	 * Renderer key (`html` today; future pdf/zpl without renaming this VO).
	 *
	 * @var string
	 */
	private string $renderer;

	/**
	 * Paper size hint for the print stylesheet (e.g. "A4").
	 *
	 * @var string
	 */
	private string $paper_size;

	/**
	 * Capability required to render this document type.
	 *
	 * @var string
	 */
	private string $capability;

	/**
	 * Working states that may generate this document (exception states use
	 * {@see \MPCF\Domain\Fulfillment::return_to_state()}).
	 *
	 * @var list<string>
	 */
	private array $allowed_states;

	/**
	 * Storage policy (`print` | `store`).
	 *
	 * @var string
	 */
	private string $storage_policy;

	/**
	 * Explicit template version recorded on every render (never mtime).
	 *
	 * @var string
	 */
	private string $template_version;

	/**
	 * Assembles a document type. Use {@see define()} or {@see create()}.
	 *
	 * @param string   $id               Registry key.
	 * @param string   $label            Display label.
	 * @param string   $assembler        Assembler identifier.
	 * @param string   $template_key     Template key.
	 * @param string   $renderer         Renderer key.
	 * @param string   $paper_size       Paper size hint.
	 * @param string   $capability       Capability required to render it.
	 * @param string[] $allowed_states   Stage-policy working states.
	 * @param string   $storage_policy   Storage policy.
	 * @param string   $template_version Explicit template version.
	 */
	private function __construct(
		string $id,
		string $label,
		string $assembler,
		string $template_key,
		string $renderer,
		string $paper_size,
		string $capability,
		array $allowed_states,
		string $storage_policy,
		string $template_version
	) {
		$this->id               = $id;
		$this->label            = $label;
		$this->assembler        = $assembler;
		$this->template_key     = $template_key;
		$this->renderer         = $renderer;
		$this->paper_size       = $paper_size;
		$this->capability       = $capability;
		$this->allowed_states   = $allowed_states;
		$this->storage_policy   = $storage_policy;
		$this->template_version = $template_version;
	}

	/**
	 * Builds a document type from a validated definition array.
	 *
	 * @param array<string, mixed> $definition Definition fields.
	 */
	public static function define( array $definition ): self {
		$allowed = array();
		if ( isset( $definition['allowed_states'] ) && is_array( $definition['allowed_states'] ) ) {
			foreach ( $definition['allowed_states'] as $state ) {
				if ( is_string( $state ) && '' !== $state ) {
					$allowed[] = $state;
				}
			}
		}

		return new self(
			(string) ( $definition['id'] ?? $definition['key'] ?? '' ),
			(string) ( $definition['label'] ?? '' ),
			(string) ( $definition['assembler'] ?? '' ),
			(string) ( $definition['template_key'] ?? $definition['id'] ?? $definition['key'] ?? '' ),
			(string) ( $definition['renderer'] ?? self::RENDERER_HTML ),
			(string) ( $definition['paper_size'] ?? $definition['paper'] ?? 'A4' ),
			(string) ( $definition['capability'] ?? 'mpcf_render_documents' ),
			$allowed,
			(string) ( $definition['storage_policy'] ?? self::STORAGE_PRINT ),
			(string) ( $definition['template_version'] ?? '1' )
		);
	}

	/**
	 * Backward-compatible factory used by early unit tests — defaults match
	 * packing_slip stage policy and print storage.
	 *
	 * @param string $id         Registry key.
	 * @param string $label      Display label.
	 * @param string $paper_size Paper size hint.
	 * @param string $capability Capability required to render it.
	 */
	public static function create( string $id, string $label, string $paper_size, string $capability ): self {
		return self::define(
			array(
				'id'               => $id,
				'label'            => $label,
				'assembler'        => $id,
				'template_key'     => $id,
				'renderer'         => self::RENDERER_HTML,
				'paper_size'       => $paper_size,
				'capability'       => $capability,
				'allowed_states'   => array( 'packing', 'packed', 'shipped', 'delivered', 'completed' ),
				'storage_policy'   => self::STORAGE_PRINT,
				'template_version' => '1',
			)
		);
	}

	/**
	 * Whether this definition has every field required to render.
	 */
	public function is_valid(): bool {
		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $this->id ) ) {
			return false;
		}
		if ( '' === $this->label || '' === $this->assembler || '' === $this->template_key ) {
			return false;
		}
		if ( ! in_array( $this->renderer, array( self::RENDERER_HTML ), true ) && 'pdf' !== $this->renderer && 'zpl' !== $this->renderer ) {
			// Allow known future renderer keys in definitions; unknown keys fail.
			if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $this->renderer ) ) {
				return false;
			}
		}
		if ( array() === $this->allowed_states ) {
			return false;
		}
		if ( ! in_array( $this->storage_policy, array( self::STORAGE_PRINT, self::STORAGE_STORE ), true ) ) {
			return false;
		}
		if ( '' === $this->template_version || strlen( $this->template_version ) > 32 ) {
			return false;
		}

		return true;
	}

	/**
	 * Array shape for filters and tests.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'               => $this->id,
			'key'              => $this->id,
			'label'            => $this->label,
			'assembler'        => $this->assembler,
			'template_key'     => $this->template_key,
			'renderer'         => $this->renderer,
			'paper_size'       => $this->paper_size,
			'capability'       => $this->capability,
			'allowed_states'   => $this->allowed_states,
			'storage_policy'   => $this->storage_policy,
			'template_version' => $this->template_version,
		);
	}

	/**
	 * Registry key.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Display label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Assembler identifier.
	 */
	public function assembler(): string {
		return $this->assembler;
	}

	/**
	 * Template key.
	 */
	public function template_key(): string {
		return $this->template_key;
	}

	/**
	 * Renderer key.
	 */
	public function renderer(): string {
		return $this->renderer;
	}

	/**
	 * Paper size hint for the print stylesheet.
	 */
	public function paper_size(): string {
		return $this->paper_size;
	}

	/**
	 * Capability required to render this document type.
	 */
	public function capability(): string {
		return $this->capability;
	}

	/**
	 * Working states that may generate this document.
	 *
	 * @return list<string>
	 */
	public function allowed_states(): array {
		return $this->allowed_states;
	}

	/**
	 * Storage policy.
	 */
	public function storage_policy(): string {
		return $this->storage_policy;
	}

	/**
	 * Explicit template version.
	 */
	public function template_version(): string {
		return $this->template_version;
	}
}
