<?php
/**
 * Stub customer email lookup for notification tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Notifications;

use MPCF\Domain\CustomerEmailLookup;

/**
 * Stub customer email lookup.
 */
final class StubCustomerEmailLookup implements CustomerEmailLookup {

	/**
	 * Email to return.
	 *
	 * @var string|null
	 */
	public ?string $email;

	/**
	 * @param string|null $email Email to return.
	 */
	public function __construct( ?string $email ) {
		$this->email = $email;
	}

	/**
	 * Returns the configured email.
	 *
	 * @param int $order_id Order id.
	 */
	public function email_for_order( int $order_id ): ?string {
		unset( $order_id );

		return $this->email;
	}
}
