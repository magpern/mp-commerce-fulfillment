<?php
/**
 * Integration tests for the document generation record repository against
 * a real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Infrastructure;

use DateTimeImmutable;
use MPCF\Domain\Document\DocumentRecord;
use MPCF\Domain\Fulfillment;
use MPCF\Infrastructure\Database\WpdbDocumentRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;

/**
 * Integration tests for the document generation record repository against
 * a real database.
 */
final class WpdbDocumentRepositoryTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	/**
	 * Repository under test.
	 *
	 * @var WpdbDocumentRepository
	 */
	private WpdbDocumentRepository $repository;

	/**
	 * Owning fulfillment's id, created fresh per test.
	 *
	 * @var int
	 */
	private int $fulfillment_id;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
		$this->repository     = new WpdbDocumentRepository();
		$this->fulfillment_id = ( new WpdbFulfillmentRepository() )->insert(
			Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 1, new DateTimeImmutable() )
		);
	}

	public function test_insert_assigns_an_id_and_persists_a_render_to_print_record(): void {
		global $wpdb;

		$record = DocumentRecord::create( $this->fulfillment_id, 'packing_slip', '1.0.0', null, 7, new DateTimeImmutable( '2026-08-02 10:00:00' ) );

		$id = $this->repository->insert( $record );

		self::assertGreaterThan( 0, $id );

		$table = $wpdb->prefix . 'mpcf_documents';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table name is a fixed literal, not user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		self::assertNotNull( $row );
		self::assertSame( $this->fulfillment_id, (int) $row['fulfillment_id'] );
		self::assertSame( 'packing_slip', $row['doc_type'] );
		self::assertSame( '1.0.0', $row['template_version'] );
		self::assertNull( $row['file_path'], 'A render-to-print document must never carry a stored file path.' );
		self::assertSame( 7, (int) $row['rendered_by'] );
	}
}
