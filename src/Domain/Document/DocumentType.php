<?php
/**
 * One entry in the document-type registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Document;

/**
 * Immutable value object. The filterable registry itself (`mpcf_document_types`)
 * is a WordPress concern and lives in `Documents\TemplateRegistry` (I8-adjacent
 * confinement of platform symbols to the edge); this class is only the pure
 * data shape one registered entry takes. Milestone 2 registers exactly one:
 * `packing_slip` (§IV.7) — pick list, batch picking list, invoice, customs
 * and label types are later milestones.
 */
final class DocumentType {

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
	 * Assembles a document type. Use {@see create()} instead of calling
	 * this directly.
	 *
	 * @param string $id         Registry key.
	 * @param string $label      Display label.
	 * @param string $paper_size Paper size hint.
	 * @param string $capability Capability required to render it.
	 */
	private function __construct( string $id, string $label, string $paper_size, string $capability ) {
		$this->id         = $id;
		$this->label      = $label;
		$this->paper_size = $paper_size;
		$this->capability = $capability;
	}

	/**
	 * Builds a document type.
	 *
	 * @param string $id         Registry key.
	 * @param string $label      Display label.
	 * @param string $paper_size Paper size hint.
	 * @param string $capability Capability required to render it.
	 */
	public static function create( string $id, string $label, string $paper_size, string $capability ): self {
		return new self( $id, $label, $paper_size, $capability );
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
}
