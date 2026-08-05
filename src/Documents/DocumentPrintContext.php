<?php
/**
 * Workspace/REST print-action descriptors derived from stage policy.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Documents;

use MPCF\Domain\Document\DocumentStagePolicy;
use MPCF\Domain\Document\DocumentType;
use MPCF\Domain\Fulfillment;

/**
 * Single place Workspace and shortcuts consult for which document is
 * primary and which actions are eligible. Eligibility itself remains
 * owned by {@see DocumentStagePolicy} — this class does not duplicate
 * state matrices.
 */
final class DocumentPrintContext {

	/**
	 * Context-default document type for Shift+P, or null when none.
	 *
	 * Picking-stage effective states → picking_list; packing-or-later →
	 * packing_slip. Cancelled / unknown → null.
	 *
	 * @param Fulfillment $fulfillment Fulfillment in the workspace.
	 */
	public static function primary_doc_type( Fulfillment $fulfillment ): ?string {
		$effective = DocumentStagePolicy::effective_state( $fulfillment );

		if ( null === $effective ) {
			return null;
		}

		if ( in_array( $effective, DocumentTypeRegistry::PICKING_LIST_STATES, true ) ) {
			return 'picking_list';
		}

		if ( in_array( $effective, DocumentTypeRegistry::PACKING_SLIP_STATES, true ) ) {
			return 'packing_slip';
		}

		return null;
	}

	/**
	 * Bounded action list for both M4 document types.
	 *
	 * @param Fulfillment               $fulfillment Fulfillment in the workspace.
	 * @param DocumentTypeRegistry|null $registry    Optional registry (tests).
	 * @return list<array{id: string, label: string, allowed: bool, message: string, primary: bool}>
	 */
	public static function actions( Fulfillment $fulfillment, ?DocumentTypeRegistry $registry = null ): array {
		$registry = $registry ?? new DocumentTypeRegistry();
		$primary  = self::primary_doc_type( $fulfillment );
		$actions  = array();

		foreach ( array( 'picking_list', 'packing_slip' ) as $id ) {
			$type = $registry->get( $id );

			if ( null === $type || ! $type instanceof DocumentType ) {
				continue;
			}

			$allowed = DocumentStagePolicy::allows( $type, $fulfillment );

			$actions[] = array(
				'id'      => $id,
				'label'   => $type->label(),
				'allowed' => $allowed,
				'message' => $allowed ? '' : DocumentStagePolicy::denial_message( $type, $fulfillment ),
				'primary' => $primary === $id,
			);
		}

		return $actions;
	}
}
