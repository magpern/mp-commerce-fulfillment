<?php
/**
 * Document event label tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Documents;

use MPCF\Documents\DocumentEventLabels;
use PHPUnit\Framework\TestCase;

/**
 * Operator-facing document event labels.
 */
final class DocumentEventLabelsTest extends TestCase {

	public function test_rendered_and_reprinted_labels(): void {
		self::assertSame(
			'Packing slip printed.',
			DocumentEventLabels::describe( 'document.rendered', array( 'doc_type' => 'packing_slip' ) )
		);
		self::assertSame(
			'Picking list reprinted.',
			DocumentEventLabels::describe( 'document.reprinted', array( 'doc_type' => 'picking_list' ) )
		);
		self::assertNull( DocumentEventLabels::describe( 'fulfillment.created', array() ) );
	}
}
