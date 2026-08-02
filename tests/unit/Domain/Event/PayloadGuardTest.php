<?php
/**
 * Tests for the mechanical PII payload guard.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Event;

use InvalidArgumentException;
use MPCF\Domain\Event\PayloadGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the mechanical PII payload guard.
 */
final class PayloadGuardTest extends TestCase {

	public function test_an_empty_payload_is_safe(): void {
		PayloadGuard::assert_safe( array() );
		$this->addToAssertionCount( 1 );
	}

	public function test_a_payload_referencing_only_ids_and_state_names_is_safe(): void {
		PayloadGuard::assert_safe(
			array(
				'from'     => 'picking',
				'to'       => 'picked',
				'order_id' => 1001,
				'reason'   => 'Customer asked to hold shipment.',
			)
		);
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @dataProvider forbidden_keys
	 */
	public function test_a_forbidden_key_at_the_top_level_is_rejected( string $key ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( "\"{$key}\"" );

		PayloadGuard::assert_safe( array( $key => 'anything' ) );
	}

	/**
	 * @return list<array{0:string}>
	 */
	public static function forbidden_keys(): array {
		return array(
			array( 'email' ),
			array( 'customer_email' ),
			array( 'Email' ),
			array( 'billing_address' ),
			array( 'phone' ),
			array( 'ssn' ),
			array( 'dob' ),
			array( 'date_of_birth' ),
			array( 'street' ),
			array( 'city' ),
			array( 'zip' ),
			array( 'postal_code' ),
			array( 'card_number' ),
			array( 'cvv' ),
		);
	}

	public function test_a_forbidden_key_nested_inside_the_payload_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'customer.email' );

		PayloadGuard::assert_safe(
			array(
				'customer' => array( 'email' => 'jane@example.com' ),
			)
		);
	}

	public function test_an_email_shaped_value_is_rejected_regardless_of_its_key_name(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'looks like an email address' );

		PayloadGuard::assert_safe( array( 'note' => 'jane@example.com' ) );
	}

	public function test_an_email_shaped_value_nested_inside_the_payload_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		PayloadGuard::assert_safe( array( 'details' => array( 'note' => 'jane@example.com' ) ) );
	}

	public function test_a_value_that_merely_contains_an_at_sign_but_is_not_email_shaped_is_safe(): void {
		PayloadGuard::assert_safe( array( 'note' => 'Package left @ front desk' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_non_string_values_are_never_treated_as_email_shaped(): void {
		PayloadGuard::assert_safe(
			array(
				'count'   => 3,
				'enabled' => true,
				'ratio'   => 1.5,
				'nothing' => null,
			)
		);
		$this->addToAssertionCount( 1 );
	}
}
