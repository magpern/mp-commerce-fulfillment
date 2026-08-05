<?php
/**
 * Format-neutral document renderer contract.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Documents;

use MPCF\Domain\Document\DocumentModel;

/**
 * All renderers consume the same immutable {@see DocumentModel}. HTML is
 * the canonical implementation; PDF/ZPL may be added later without changing
 * {@see \MPCF\Application\DocumentService}.
 */
interface DocumentRendererInterface {

	/**
	 * Renders a document model to a string payload (HTML today), or null
	 * when no template can be resolved.
	 *
	 * @param DocumentModel $model Assembled render-time document data.
	 */
	public function render( DocumentModel $model ): ?string;
}
