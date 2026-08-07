<?php
/**
 * CheckerRegistry + DoctorService unit coverage.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Diagnostics;

use MPCF\Application\Diagnostics\CheckCategory;
use MPCF\Application\Diagnostics\Checker;
use MPCF\Application\Diagnostics\CheckerRegistry;
use MPCF\Application\Diagnostics\CheckResult;
use MPCF\Application\Diagnostics\CheckStatus;
use MPCF\Application\Diagnostics\DoctorService;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic registry behaviour.
 */
final class CheckerRegistryTest extends TestCase {

	public function test_registration_and_deterministic_ordering(): void {
		$registry = new CheckerRegistry();
		$registry->register( $this->stub( 'b', CheckCategory::STORAGE, array( CheckResult::pass( 'storage.z', CheckCategory::STORAGE, 'z' ) ) ) );
		$registry->register( $this->stub( 'a', CheckCategory::ENVIRONMENT, array( CheckResult::pass( 'environment.a', CheckCategory::ENVIRONMENT, 'a' ) ) ) );

		$results = $registry->run_all();
		self::assertSame( 'environment.a', $results[0]->id() );
		self::assertSame( 'storage.z', $results[1]->id() );
	}

	public function test_doctor_reports_failures(): void {
		$registry = new CheckerRegistry();
		$registry->register(
			$this->stub(
				'schema',
				CheckCategory::SCHEMA,
				array(
					CheckResult::fail( 'schema.x', CheckCategory::SCHEMA, 'broken' ),
					CheckResult::warn( 'schema.y', CheckCategory::SCHEMA, 'soft' ),
				)
			)
		);

		$report = ( new DoctorService( $registry ) )->run();
		self::assertTrue( $report->has_failures() );
		self::assertTrue( $report->has_warnings() );
		self::assertSame( 1, $report->fail_count() );
		self::assertSame( 1, $report->warn_count() );
		self::assertSame( CheckStatus::FAIL, $report->results()[0]->status() );
	}

	public function test_categories_cover_approved_set(): void {
		self::assertContains( CheckCategory::ENVIRONMENT, CheckCategory::all() );
		self::assertContains( CheckCategory::CAPACITY, CheckCategory::all() );
		self::assertCount( 9, CheckCategory::all() );
	}

	/**
	 * @param string $id       Checker id.
	 * @param string $category Check category.
	 * @param array  $results  Fixed results.
	 */
	private function stub( string $id, string $category, array $results ): Checker {
		return new class( $id, $category, $results ) implements Checker {
			/**
			 * @param string $id       Checker id.
			 * @param string $category Check category.
			 * @param array  $results  Fixed results.
			 */
			public function __construct(
				private string $id,
				private string $category,
				private array $results
			) {
			}

			public function id(): string {
				return $this->id;
			}

			public function category(): string {
				return $this->category;
			}

			public function run(): array {
				return $this->results;
			}
		};
	}
}
