<?php
/**
 * Stage eligibility for document generation.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Document;

use MPCF\Domain\Fulfillment;

/**
 * Single stage-policy helper for all document types. Controllers and admin
 * pages must call this instead of duplicating eligibility rules.
 *
 * Exception states (`problem` / `waiting` / `backordered`) evaluate against
 * the fulfillment's `return_to_state`. `cancelled` is always denied.
 */
final class DocumentStagePolicy {

	/**
	 * Exception states that interrupt a working state.
	 *
	 * @var list<string>
	 */
	private const EXCEPTION_STATES = array( 'problem', 'waiting', 'backordered' );

	/**
	 * Whether the fulfillment may generate the given document type now.
	 *
	 * @param DocumentType $type        Document type definition.
	 * @param Fulfillment  $fulfillment Fulfillment under consideration.
	 */
	public static function allows( DocumentType $type, Fulfillment $fulfillment ): bool {
		return null === self::denial_code( $type, $fulfillment );
	}

	/**
	 * Machine-readable denial code, or null when allowed.
	 *
	 * @param DocumentType $type        Document type definition.
	 * @param Fulfillment  $fulfillment Fulfillment under consideration.
	 */
	public static function denial_code( DocumentType $type, Fulfillment $fulfillment ): ?string {
		$state = $fulfillment->state();

		if ( 'cancelled' === $state ) {
			return 'stage_not_allowed';
		}

		$effective = self::effective_state( $fulfillment );

		if ( null === $effective ) {
			return 'stage_not_allowed';
		}

		if ( ! in_array( $effective, $type->allowed_states(), true ) ) {
			return 'stage_not_allowed';
		}

		return null;
	}

	/**
	 * Operator-readable denial message suitable for REST/UI.
	 *
	 * @param DocumentType $type        Document type definition.
	 * @param Fulfillment  $fulfillment Fulfillment under consideration.
	 */
	public static function denial_message( DocumentType $type, Fulfillment $fulfillment ): string {
		$state = $fulfillment->state();

		if ( 'cancelled' === $state ) {
			return sprintf( 'Cannot print %s for a cancelled fulfillment.', $type->label() );
		}

		$effective = self::effective_state( $fulfillment );

		if ( null === $effective ) {
			return sprintf(
				'Cannot print %s while this fulfillment is in an exception state with no return state.',
				$type->label()
			);
		}

		return sprintf( '%s is not available in the "%s" stage.', $type->label(), $effective );
	}

	/**
	 * Working state used for eligibility: current state, or return_to when
	 * the fulfillment is in an exception state.
	 *
	 * @param Fulfillment $fulfillment Fulfillment under consideration.
	 */
	public static function effective_state( Fulfillment $fulfillment ): ?string {
		$state = $fulfillment->state();

		if ( 'cancelled' === $state ) {
			return null;
		}

		if ( in_array( $state, self::EXCEPTION_STATES, true ) ) {
			$return_to = $fulfillment->return_to_state();

			return ( null !== $return_to && '' !== $return_to ) ? $return_to : null;
		}

		return $state;
	}
}
