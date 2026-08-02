<?php
/**
 * Tests for the Queue search term classifier.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain;

use MPCF\Domain\SearchTermClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Queue search term classifier.
 */
final class SearchTermClassifierTest extends TestCase {

	public function test_a_purely_numeric_term_classifies_as_numeric(): void {
		self::assertSame( SearchTermClassifier::NUMERIC, SearchTermClassifier::classify( '1001' ) );
	}

	public function test_an_alphanumeric_term_with_a_letter_and_a_digit_classifies_as_sku(): void {
		self::assertSame( SearchTermClassifier::SKU, SearchTermClassifier::classify( 'SKU-123' ) );
		self::assertSame( SearchTermClassifier::SKU, SearchTermClassifier::classify( 'ABC123' ) );
		self::assertSame( SearchTermClassifier::SKU, SearchTermClassifier::classify( 'widget_9' ) );
	}

	public function test_a_purely_alphabetic_term_classifies_as_name(): void {
		self::assertSame( SearchTermClassifier::NAME, SearchTermClassifier::classify( 'Jane' ) );
	}

	public function test_a_multi_word_term_classifies_as_name(): void {
		self::assertSame( SearchTermClassifier::NAME, SearchTermClassifier::classify( 'Jane Doe' ) );
	}

	public function test_a_tracking_shaped_term_has_no_dedicated_classification(): void {
		// M1 has no shipments/packages table for a tracking lookup to
		// query, and this class deliberately has no TRACKING case (see its
		// docblock) — an alphanumeric tracking number is indistinguishable
		// from a SKU by shape alone, so it lands in the SKU bucket like any
		// other alphanumeric term. Either bucket correctly returns no
		// results for it once WpdbSearchQuery runs the actual lookup —
		// what matters is that no branch throws or requires schema that
		// does not exist yet, not which specific bucket it lands in.
		self::assertSame( SearchTermClassifier::SKU, SearchTermClassifier::classify( '1Z999AA10123456784' ) );
	}
}
