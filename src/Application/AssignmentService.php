<?php
/**
 * Application-layer facade for fulfillment assignment.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\Repository\FulfillmentRepository;

/**
 * Assignment (`assignee_type`/`assignee_id`) is plain fulfillment metadata,
 * not a workflow transition — it never touches {@see WorkflowService} or
 * the engine, and is never itself guard-checked. A failed
 * {@see FulfillmentRepository::save()} (a concurrent edit lost the
 * optimistic-lock race) is reported back as `false`, the same "partial
 * failure per row" shape the Queue's bulk actions need — never an
 * exception, and never a direct write from Admin (invariant I11,
 * `AdminBoundaryGuardTest`).
 */
final class AssignmentService {

	/**
	 * Fulfillment persistence.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Builds the service.
	 *
	 * @param FulfillmentRepository $fulfillments Fulfillment persistence.
	 */
	public function __construct( FulfillmentRepository $fulfillments ) {
		$this->fulfillments = $fulfillments;
	}

	/**
	 * Assigns a fulfillment to a user.
	 *
	 * @param int $fulfillment_id Fulfillment to assign.
	 * @param int $user_id        User to assign it to.
	 */
	public function assign( int $fulfillment_id, int $user_id ): bool {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return false;
		}

		$fulfillment->assign( 'user', $user_id );

		return $this->fulfillments->save( $fulfillment );
	}

	/**
	 * Clears a fulfillment's assignment.
	 *
	 * @param int $fulfillment_id Fulfillment to unassign.
	 */
	public function unassign( int $fulfillment_id ): bool {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return false;
		}

		$fulfillment->unassign();

		return $this->fulfillments->save( $fulfillment );
	}
}
