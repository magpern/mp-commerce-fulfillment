<?php
/**
 * Guards invariant I5: mpcf_events is append-only — no update, no delete.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Append-only audit log guard. `WpdbEventRepository` is the only class
 * allowed to name `Schema::EVENTS` (mirroring `WpdbFulfillmentRepository`'s
 * own "only class that reads or writes" convention), and that one class
 * must never contain an `UPDATE`/`DELETE` mutation against it.
 */
final class AuditAppendOnlyGuardTest extends TestCase {

	private const OWNING_FILE = 'Infrastructure/Database/WpdbEventRepository.php';

	public function test_only_wpdbeventrepository_names_the_events_table(): void {
		$violations = $this->scan_for_events_table_reference( dirname( __DIR__, 2 ) . '/src' );

		self::assertSame( array(), $violations );
	}

	public function test_wpdbeventrepository_contains_no_update_or_delete(): void {
		$contents = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/' . self::OWNING_FILE );

		self::assertSame( array(), $this->mutation_violations( $contents, self::OWNING_FILE ) );
	}

	public function test_the_table_reference_scan_catches_a_second_class_naming_the_events_table(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-audit-append-only-fixture-' . uniqid();
		$woo_dir      = $fixture_root . '/Woo';
		mkdir( $woo_dir, 0777, true );

		file_put_contents(
			$woo_dir . '/Tainted.php',
			"<?php\nnamespace MPCF\\Woo;\nuse MPCF\\Infrastructure\\Database\\Schema;\nfinal class Tainted {\n\tpublic function whatever(): string {\n\t\treturn Schema::EVENTS;\n\t}\n}\n"
		);

		$violations = $this->scan_for_events_table_reference( $fixture_root );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch a second class naming Schema::EVENTS.' );
	}

	public function test_the_mutation_scan_catches_an_update_call(): void {
		$violations = $this->mutation_violations( '<?php $wpdb->update( $table, array(), array() );', 'fixture.php' );

		self::assertNotSame( array(), $violations, 'The scan must catch an $wpdb->update() call.' );
	}

	public function test_the_mutation_scan_catches_a_delete_call(): void {
		$violations = $this->mutation_violations( '<?php $wpdb->delete( $table, array() );', 'fixture.php' );

		self::assertNotSame( array(), $violations, 'The scan must catch an $wpdb->delete() call.' );
	}

	/**
	 * @return list<string>
	 */
	private function scan_for_events_table_reference( string $src_root ): array {
		if ( ! is_dir( $src_root ) ) {
			return array();
		}

		$violations = array();
		$iterator   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src_root, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$relative = ltrim( str_replace( $src_root, '', $file->getPathname() ), '/' );

			// Schema.php itself defines and creates the table (migration
			// step 1) — naming the constant there is the definition, not a
			// second reader/writer.
			if ( self::OWNING_FILE === $relative || 'Infrastructure/Database/Schema.php' === $relative ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			if ( str_contains( $contents, 'Schema::EVENTS' ) ) {
				$violations[] = $file->getPathname() . ' names Schema::EVENTS outside ' . self::OWNING_FILE;
			}
		}

		return $violations;
	}

	/**
	 * @return list<string>
	 */
	private function mutation_violations( string $contents, string $label ): array {
		$violations = array();

		foreach ( array( '->update(', '->delete(' ) as $forbidden ) {
			if ( str_contains( $contents, $forbidden ) ) {
				$violations[] = "{$label} contains a forbidden {$forbidden} call against the append-only audit log.";
			}
		}

		foreach ( array( 'UPDATE ', 'DELETE FROM' ) as $forbidden ) {
			if ( false !== stripos( $contents, $forbidden ) ) {
				$violations[] = "{$label} contains a forbidden raw \"{$forbidden}\" statement against the append-only audit log.";
			}
		}

		return $violations;
	}

	private function remove_directory( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}

		rmdir( $path );
	}
}
