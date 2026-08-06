<?php
/**
 * Architecture guards for M6-A package photography foundation.
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
 * Proves evidence integrity boundaries: no Media Library registration,
 * MediaRepository stays create/get/list/soft_delete/mark_purged only,
 * PhotoService + PhotoRetentionService are the sole mutators, and
 * Domain/Media stays free of WooCommerce/wpdb.
 */
final class MediaFoundationGuardTest extends TestCase {

	public function test_protected_photo_store_and_photo_service_never_touch_media_library(): void {
		$files = array(
			dirname( __DIR__, 2 ) . '/src/Infrastructure/Files/ProtectedPhotoStore.php',
			dirname( __DIR__, 2 ) . '/src/Application/Photos/PhotoService.php',
			dirname( __DIR__, 2 ) . '/src/Application/Photos/PhotoRetentionService.php',
		);

		foreach ( $files as $file ) {
			$contents = (string) file_get_contents( $file );
			self::assertStringNotContainsString( 'wp_insert_attachment', $contents, basename( $file ) );
			self::assertStringNotContainsString( 'wp_generate_attachment_metadata', $contents, basename( $file ) );
		}
	}

	public function test_media_repository_interface_has_no_hard_delete_or_generic_update(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Domain/Repository/MediaRepository.php' );

		self::assertStringNotContainsString( 'hard_delete', $source );
		self::assertStringNotContainsString( 'function update', $source );
		self::assertStringContainsString( 'function mark_purged', $source );
		self::assertStringContainsString( 'function list_purge_candidates', $source );
	}

	public function test_only_photo_services_in_application_call_media_repository_mutations(): void {
		$app_root   = dirname( __DIR__, 2 ) . '/src/Application';
		$violations = array();
		$allowed    = array(
			'Photos/PhotoService.php',
			'Photos/PhotoRetentionService.php',
		);

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $app_root, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$relative = ltrim( str_replace( $app_root, '', $file->getPathname() ), '/' );

			if ( in_array( $relative, $allowed, true ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			if ( preg_match( '/\$\w+->(?:insert|soft_delete|mark_purged)\s*\(/', $contents )
				&& str_contains( $contents, 'MediaRepository' ) ) {
				$violations[] = $relative;
			}
		}

		self::assertSame( array(), $violations, 'Only PhotoService/PhotoRetentionService may call MediaRepository mutations.' );
	}

	public function test_photos_controller_and_retention_service_exist_without_standalone_photos_admin_pages(): void {
		$root = dirname( __DIR__, 2 ) . '/src';

		self::assertFileExists( $root . '/Api/Rest/PhotosController.php' );
		self::assertFileExists( $root . '/Application/Photos/PhotoRetentionService.php' );
		self::assertFileExists( $root . '/Infrastructure/Scheduling/PhotoRetentionScheduler.php' );
		self::assertFileDoesNotExist( $root . '/Admin/PhotosPage.php' );
		self::assertFileDoesNotExist( $root . '/Admin/PhotoGalleryPage.php' );

		$controller = (string) file_get_contents( $root . '/Api/Rest/PhotosController.php' );
		self::assertStringContainsString( 'PhotoService', $controller );
		self::assertStringNotContainsString( 'MediaRepository', $controller );
		self::assertStringNotContainsString( 'ProtectedPhotoStore', $controller );
		self::assertStringNotContainsString( 'WpdbMediaRepository', $controller );
		self::assertDoesNotMatchRegularExpression( '/\b(wpdb|\$wpdb)\b/', $controller );
		self::assertStringNotContainsString( "'file_path'", $controller );
		self::assertStringNotContainsString( "'thumb_path'", $controller );
		self::assertStringNotContainsString( '->file_path(', $controller );
		self::assertStringNotContainsString( '->thumb_path(', $controller );
		self::assertStringContainsString( 'Capabilities::DELETE_PHOTOS', $controller );
		self::assertStringContainsString( 'capture_with_version', $controller );
		self::assertStringContainsString( 'soft_delete_with_version', $controller );

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/Admin', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$contents = (string) file_get_contents( $file->getPathname() );
			self::assertStringNotContainsString( 'PhotoService', $contents, $file->getPathname() );
			self::assertStringNotContainsString( 'mpcf_media', $contents, $file->getPathname() );
			self::assertStringNotContainsString( 'PhotoRetentionService', $contents, $file->getPathname() );
		}
	}

	public function test_domain_media_has_no_woocommerce_or_wpdb_tokens(): void {
		$dir      = dirname( __DIR__, 2 ) . '/src/Domain/Media';
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );
			self::assertDoesNotMatchRegularExpression( '/\b(WooCommerce|WC_|wpdb|\$wpdb)\b/', $contents, $file->getPathname() );
		}
	}

	public function test_photo_record_requires_package_id_and_processing_version_with_sha256(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Domain/Media/PhotoRecord.php' );

		self::assertStringContainsString( 'int $package_id', $source );
		self::assertStringContainsString( 'processing_version', $source );
		self::assertStringContainsString( 'sha256', $source );
		self::assertDoesNotMatchRegularExpression( '/\?int\s+\$package_id/', $source );
	}

	public function test_protected_photo_store_does_not_emit_public_urls(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Infrastructure/Files/ProtectedPhotoStore.php' );

		self::assertStringNotContainsString( 'wp_get_attachment_url', $source );
		self::assertStringNotContainsString( 'wp_get_attachment_image', $source );
		self::assertStringNotContainsString( 'baseurl', $source );
		self::assertDoesNotMatchRegularExpression( '/https?:\/\//', $source );
	}

	public function test_no_inventory_receiving_ocr_or_ai_coupling_in_media_stack(): void {
		$roots = array(
			dirname( __DIR__, 2 ) . '/src/Domain/Media',
			dirname( __DIR__, 2 ) . '/src/Application/Photos',
			dirname( __DIR__, 2 ) . '/src/Infrastructure/Media',
			dirname( __DIR__, 2 ) . '/src/Infrastructure/Files/ProtectedPhotoStore.php',
			dirname( __DIR__, 2 ) . '/src/Infrastructure/Database/WpdbMediaRepository.php',
			dirname( __DIR__, 2 ) . '/assets/admin/js/photos.js',
			dirname( __DIR__, 2 ) . '/src/Admin/SettingsPage.php',
		);

		$forbidden = '/\b(Inventory|Receiving|PurchaseOrder|Supplier|OCR|OpenAI|Vision|Tesseract|ffmpeg|Video|getUserMedia)\b/';

		foreach ( $roots as $root ) {
			if ( is_file( $root ) ) {
				self::assertDoesNotMatchRegularExpression( $forbidden, (string) file_get_contents( $root ), $root );
				continue;
			}

			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( 'php' !== strtolower( $file->getExtension() ) ) {
					continue;
				}
				self::assertDoesNotMatchRegularExpression( $forbidden, (string) file_get_contents( $file->getPathname() ), $file->getPathname() );
			}
		}
	}
}
