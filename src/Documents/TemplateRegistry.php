<?php
/**
 * Resolves a document type to its template file.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Documents;

/**
 * Template resolution order (M4-A):
 * 1. `mpcf_document_template` filter (explicit path)
 * 2. Theme override: `{stylesheet}/mp-commerce-fulfillment/documents/{type}.php`
 * 3. Bundled: `templates/documents/{type}.php`
 *
 * Paths are validated against traversal and must be readable `.php` files.
 * Template versioning is explicit on the document-type definition — never
 * file mtime alone.
 */
final class TemplateRegistry {

	/**
	 * Optional theme documents directory for tests (absolute).
	 *
	 * @var string|null
	 */
	private ?string $theme_documents_root;

	/**
	 * Builds the registry.
	 *
	 * @param string|null $theme_documents_root Optional theme documents dir for tests.
	 */
	public function __construct( ?string $theme_documents_root = null ) {
		$this->theme_documents_root = $theme_documents_root;
	}

	/**
	 * Resolves a document type to an absolute template path, or null.
	 *
	 * @param string $doc_type Document type registry key (underscore form).
	 */
	public function resolve( string $doc_type ): ?string {
		if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $doc_type ) ) {
			return null;
		}

		$filename = str_replace( '_', '-', $doc_type ) . '.php';

		/**
		 * Filters the resolved template path for a document type.
		 *
		 * Return an absolute readable `.php` path, or null/empty to continue
		 * the default chain (theme → bundled).
		 *
		 * @since 0.4.0
		 *
		 * @param string|null $path     Candidate path, or null.
		 * @param string      $doc_type Document type key.
		 */
		$filtered = apply_filters( 'mpcf_document_template', null, $doc_type );

		if ( is_string( $filtered ) && '' !== $filtered ) {
			$validated = $this->validate_template_path( $filtered, null );

			if ( null !== $validated ) {
				return $validated;
			}
		}

		$theme = $this->theme_candidate( $filename );

		if ( null !== $theme ) {
			return $theme;
		}

		$bundled = dirname( MPCF_PLUGIN_FILE ) . '/templates/documents/' . $filename;

		return $this->validate_template_path( $bundled, null );
	}

	/**
	 * Deterministic template version for storage/audit.
	 *
	 * Bundled templates use the registry's explicit version. Theme or filter
	 * overrides use `override-` plus a content SHA-256 prefix — never mtime.
	 *
	 * @param string $doc_type         Document type key.
	 * @param string $bundled_version  Version from the document-type definition.
	 */
	public function template_version( string $doc_type, string $bundled_version ): string {
		$path = $this->resolve( $doc_type );

		if ( null === $path ) {
			return $bundled_version;
		}

		$bundled_dir = realpath( dirname( MPCF_PLUGIN_FILE ) . '/templates/documents' );

		if ( false !== $bundled_dir && str_starts_with( $path, $bundled_dir . DIRECTORY_SEPARATOR ) ) {
			return $bundled_version;
		}

		$hash = hash_file( 'sha256', $path );

		if ( ! is_string( $hash ) || '' === $hash ) {
			$hash = hash( 'sha256', $path );
		}

		return 'override-' . substr( $hash, 0, 12 );
	}

	/**
	 * Theme override candidate when a theme documents root is available.
	 *
	 * @param string $filename Hyphenated template filename including .php.
	 */
	private function theme_candidate( string $filename ): ?string {
		$root = $this->theme_documents_root;

		if ( null === $root && function_exists( 'get_stylesheet_directory' ) ) {
			$stylesheet = get_stylesheet_directory();
			if ( is_string( $stylesheet ) && '' !== $stylesheet ) {
				$root = $stylesheet . '/mp-commerce-fulfillment/documents';
			}
		}

		if ( null === $root || '' === $root || ! is_dir( $root ) ) {
			return null;
		}

		return $this->validate_template_path( $root . '/' . $filename, $root );
	}

	/**
	 * Ensures the path is a readable PHP file under an optional root.
	 *
	 * @param string      $path         Candidate path.
	 * @param string|null $allowed_root When set, realpath must stay under this directory.
	 */
	private function validate_template_path( string $path, ?string $allowed_root ): ?string {
		if ( '' === $path || str_contains( $path, "\0" ) ) {
			return null;
		}

		if ( ! str_ends_with( strtolower( $path ), '.php' ) ) {
			return null;
		}

		$real = realpath( $path );

		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return null;
		}

		if ( null !== $allowed_root ) {
			$root_real = realpath( $allowed_root );

			if ( false === $root_real || ! str_starts_with( $real, $root_real . DIRECTORY_SEPARATOR ) ) {
				return null;
			}
		}

		return $real;
	}
}
