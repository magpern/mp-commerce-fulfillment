<?php
/**
 * Renders an assembled document model to a print-ready HTML string.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Documents;

use MPCF\Domain\Document\DocumentModel;

/**
 * The one class that turns a {@see DocumentModel} into markup — pure
 * WordPress-edge concern (escaping, `include`), never business logic;
 * assembly already happened in `Engine\DocumentAssembler\*` (§10:
 * "assembly != rendering"). The bundled template file receives `$model`
 * in scope and nothing else — it renders from that object's getters only.
 *
 * HTML is the canonical rendered representation (M4). Implements the
 * format-neutral {@see DocumentRendererInterface} so PDF/thermal renderers
 * can be added later without renaming the orchestrator contract.
 */
final class HtmlRenderer implements DocumentRendererInterface {

	/**
	 * Resolves a document type to its template file.
	 *
	 * @var TemplateRegistry
	 */
	private TemplateRegistry $templates;

	/**
	 * Builds the renderer.
	 *
	 * @param TemplateRegistry $templates Resolves a document type to its template file.
	 */
	public function __construct( TemplateRegistry $templates ) {
		$this->templates = $templates;
	}

	/**
	 * Renders a document model to an HTML string, or null if no template
	 * is bundled for its type.
	 *
	 * @param DocumentModel $model Assembled document data.
	 */
	public function render( DocumentModel $model ): ?string {
		$template = $this->templates->resolve( $model->doc_type() );

		if ( null === $template ) {
			return null;
		}

		return $this->render_template( $template, $model );
	}

	/**
	 * Canonical format key.
	 */
	public function format(): string {
		return 'html';
	}

	/**
	 * MIME type of rendered HTML.
	 */
	public function mime_type(): string {
		return 'text/html; charset=UTF-8';
	}

	/**
	 * Isolates the `include`'s local scope to exactly `$template`/`$model`
	 * — the template file never sees this class's own properties.
	 *
	 * @param string        $template Absolute path to the template file.
	 * @param DocumentModel $model    Assembled document data.
	 */
	private function render_template( string $template, DocumentModel $model ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $model is used by the included template file via PHP's automatic local-scope sharing, invisible to static analysis of this function body alone.
		ob_start();
		include $template;

		return (string) ob_get_clean();
	}
}
