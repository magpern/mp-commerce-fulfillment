<?php
/**
 * Photo event label tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Admin;

use MPCF\Admin\PhotoEventLabels;
use PHPUnit\Framework\TestCase;

/**
 * Operator-facing package photography event labels.
 */
final class PhotoEventLabelsTest extends TestCase {

	public function test_captured_and_deleted_labels_include_kind_and_package(): void {
		self::assertSame(
			'Package photo captured (Sealed package) for package 42.',
			PhotoEventLabels::describe(
				'photo.captured',
				array(
					'kind'       => 'package',
					'package_id' => 42,
				)
			)
		);
		self::assertSame(
			'Package photo deleted (Contents) for package 7.',
			PhotoEventLabels::describe(
				'photo.deleted',
				array(
					'kind'       => 'contents',
					'package_id' => 7,
				)
			)
		);
		self::assertNull( PhotoEventLabels::describe( 'fulfillment.created', array() ) );
	}
}
