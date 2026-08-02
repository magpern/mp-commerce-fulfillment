<?php
/**
 * Resolves a document type to its bundled template file.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Documents;

/**
 * Architecture Plan §IV.7: bundled resolution only for Milestone 2 — the
 * filter → theme-directory → bundled override chain a real registry needs
 * is Milestone 3's job (§10). Reading this class's name should not suggest
 * more than it does: there is exactly one lookup step today.
 */
final class TemplateRegistry {

	/**
	 * Resolves a document type to its bundled template file's absolute
	 * path, or null if none is bundled for that type.
	 *
	 * @param string $doc_type Document type registry key.
	 */
	public function resolve( string $doc_type ): ?string {
		if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $doc_type ) ) {
			return null;
		}

		// Doc-type keys use underscores, matching every other registry-style
		// key in this plugin (guard ids, capabilities); template filenames
		// use hyphens, the house convention for this file's own name
		// (`packing-slip.php`) — this is the one place that translates.
		$filename = str_replace( '_', '-', $doc_type );
		$path     = dirname( MPCF_PLUGIN_FILE ) . '/templates/documents/' . $filename . '.php';

		return file_exists( $path ) ? $path : null;
	}
}
