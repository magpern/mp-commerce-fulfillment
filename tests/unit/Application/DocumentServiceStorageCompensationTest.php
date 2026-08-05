<?php
/**
 * DocumentService storage compensation tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application;

use DateTimeImmutable;
use MPCF\Application\DocumentService;
use MPCF\Application\EventDispatcher;
use MPCF\Application\ShippingService;
use MPCF\Documents\HtmlRenderer;
use MPCF\Documents\TemplateRegistry;
use MPCF\Domain\Document\DocumentRecord;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\OrderSnapshot;
use MPCF\Domain\Repository\DocumentRepository;
use MPCF\Infrastructure\Files\ProtectedDocumentStore;
use MPCF\Settings;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryOrderSource;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves DB-failure orphan cleanup after a successful file write.
 */
final class DocumentServiceStorageCompensationTest extends TestCase {

	public function test_db_insert_failure_deletes_orphan_artifact(): void {
		$root         = sys_get_temp_dir() . '/mpcf-comp-' . uniqid( '', true );
		$fulfillments = new InMemoryFulfillmentRepository();
		$items        = new InMemoryFulfillmentItemRepository();
		$orders       = new InMemoryOrderSource();
		$shipments    = new InMemoryShipmentRepository();
		$packages     = new InMemoryPackageRepository();
		$events       = new InMemoryEventRepository();
		$store        = new ProtectedDocumentStore( $root );

		$fid = $fulfillments->insert(
			Fulfillment::intake( 55, 'woocommerce', 1, 'standard', 'packed', '#55', 'X', 1, new DateTimeImmutable() )
		);
		$items->insert_all( array( FulfillmentItem::intake( $fid, 1, 1, 0, 'S', 'N', 1 ) ) );
		$orders->seed( OrderSnapshot::create( 55, 'woocommerce', '#55', 'X', 'processing', array(), array( 'A' ) ) );

		$shipping = new ShippingService(
			$fulfillments,
			$items,
			$shipments,
			$packages,
			new InMemoryPackageItemRepository(),
			$events,
			new EventDispatcher(),
			new FixedClock( new DateTimeImmutable( '2026-08-02 10:00:00' ) )
		);

		$failing_docs = new class() implements DocumentRepository {
			public function insert( DocumentRecord $record ): int {
				throw new RuntimeException( 'db down' );
			}

			public function get( int $id ): ?DocumentRecord {
				return null;
			}

			public function list_for_fulfillment( int $fulfillment_id ): array {
				return array();
			}

			public function latest_for_fulfillment_and_type( int $fulfillment_id, string $doc_type ): ?DocumentRecord {
				return null;
			}

			public function search( array $filters ): array {
				return array(
					'items' => array(),
					'total' => 0,
				);
			}
		};

		$service = new DocumentService(
			$fulfillments,
			$items,
			$orders,
			$shipping,
			new HtmlRenderer( new TemplateRegistry() ),
			$failing_docs,
			$events,
			new EventDispatcher(),
			new FixedClock( new DateTimeImmutable( '2026-08-02 10:00:00' ) ),
			'Store',
			null,
			new Settings( array() ),
			$store
		);

		$outcome = $service->render_packing_slip( $fid, Actor::system() );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'persistence_failed', $outcome->failure_code() );
		self::assertCount( 0, $events->timeline_for_fulfillment( $fid ) );

		$docs_dir   = $root . '/mpcf/documents';
		$html_files = array();
		if ( is_dir( $docs_dir ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $docs_dir, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && str_ends_with( $file->getFilename(), '.html' ) && 'index.html' !== $file->getFilename() ) {
					$html_files[] = $file->getPathname();
				}
			}
		}

		self::assertSame( array(), $html_files, 'Orphan HTML must be removed after DB failure.' );

		$this->rm_tree( $root );
	}

	/**
	 * @param string $path Directory to remove.
	 */
	private function rm_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}

		rmdir( $path );
	}
}
