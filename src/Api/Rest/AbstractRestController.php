<?php
/**
 * Shared helpers every concrete REST controller builds on.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use DateTimeImmutable;
use MPCF\Application\AvailableTransition;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use WP_Error;
use WP_REST_Response;

/**
 * Architecture Plan §IV.9: controllers are thin (permission_callback → DTO
 * → Application service → response), and every mutating response's error
 * shape is one of exactly five stable codes. This layer never touches the
 * database directly, never names a concrete repository class, and never
 * references the order platform by name — `RestBoundaryGuardTest` (F11)
 * enforces that the same way `AdminBoundaryGuardTest` already does for
 * `src/Admin/`.
 */
abstract class AbstractRestController implements RestController {

	/**
	 * The one namespace every M2 route registers under, frozen additive-
	 * only from the `v0.2.0` tag (Architecture Plan §4/§16.2).
	 */
	public const NAMESPACE_V1 = 'mpcf/v1';

	/**
	 * Builds a `permission_callback` closure that checks one capability,
	 * returning the stable `mpcf_forbidden` shape on failure instead of
	 * WordPress's generic `rest_forbidden`.
	 *
	 * @param string $capability Capability to check.
	 */
	protected function require_capability( string $capability ): callable {
		return static function () use ( $capability ) {
			return current_user_can( $capability ) ? true : self::forbidden_error( 'You are not allowed to do this.' );
		};
	}

	/**
	 * A successful response with a status code.
	 *
	 * @param array<string, mixed> $data   Response body.
	 * @param int                  $status HTTP status code.
	 */
	protected function respond( array $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->set_status( $status );

		return $response;
	}

	/**
	 * Maps an Application-layer failure code (from `*Outcome::failure_code()`)
	 * to one of the three failure shapes this table owns: `mpcf_not_found`
	 * (404), `mpcf_version_conflict` (409), or `mpcf_guard_rejected` (422) —
	 * every other business-rule rejection (a guard id, `not_deletable`,
	 * `unsafe_event_payload`, …) is a 422, since the request was
	 * syntactically valid but rejected by a rule.
	 *
	 * @param string $code    Machine-readable failure code.
	 * @param string $message Human-readable failure message.
	 */
	protected static function failure_error( string $code, string $message ): WP_Error {
		if ( in_array( $code, array( 'not_found', 'fulfillment_not_found' ), true ) ) {
			return new WP_Error( 'mpcf_not_found', $message, array( 'status' => 404 ) );
		}

		if ( 'version_conflict' === $code ) {
			return new WP_Error( 'mpcf_version_conflict', $message, array( 'status' => 409 ) );
		}

		return new WP_Error(
			'mpcf_guard_rejected',
			$message,
			array(
				'status' => 422,
				'guard'  => $code,
			)
		);
	}

	/**
	 * The `mpcf_forbidden` (403) shape.
	 *
	 * @param string $message Human-readable message.
	 */
	protected static function forbidden_error( string $message ): WP_Error {
		return new WP_Error( 'mpcf_forbidden', $message, array( 'status' => 403 ) );
	}

	/**
	 * The `mpcf_not_found` (404) shape.
	 *
	 * @param string $message Human-readable message.
	 */
	protected static function not_found_error( string $message ): WP_Error {
		return new WP_Error( 'mpcf_not_found', $message, array( 'status' => 404 ) );
	}

	/**
	 * The `mpcf_invalid_payload` (400) shape.
	 *
	 * @param string $message Human-readable message.
	 */
	protected static function invalid_payload_error( string $message ): WP_Error {
		return new WP_Error( 'mpcf_invalid_payload', $message, array( 'status' => 400 ) );
	}

	/**
	 * A fulfillment as the wire shape every route embedding one uses —
	 * {@see Fulfillment::to_array()} with its three `DateTimeImmutable`
	 * fields formatted as ISO 8601 strings, the one thing that shape is
	 * not yet safe to `json_encode()` directly.
	 *
	 * @param Fulfillment $fulfillment Fulfillment to serialize.
	 * @return array<string, mixed>
	 */
	protected static function fulfillment_resource( Fulfillment $fulfillment ): array {
		$data = $fulfillment->to_array();

		foreach ( array( 'created_at', 'state_entered_at', 'completed_at' ) as $key ) {
			if ( $data[ $key ] instanceof DateTimeImmutable ) {
				$data[ $key ] = $data[ $key ]->format( DATE_ATOM );
			}
		}

		return $data;
	}

	/**
	 * A line item as the wire shape every route embedding one uses.
	 *
	 * @param FulfillmentItem $item Item to serialize.
	 * @return array<string, mixed>
	 */
	protected static function item_resource( FulfillmentItem $item ): array {
		return $item->to_array();
	}

	/**
	 * A list of candidate transitions as the wire shape
	 * `GET .../transitions` and every mutation response embeds.
	 *
	 * @param array<int, AvailableTransition> $transitions Candidates to serialize.
	 * @return list<array<string, mixed>>
	 */
	protected static function transitions_resource( array $transitions ): array {
		return array_map( static fn( AvailableTransition $transition ): array => $transition->to_array(), $transitions );
	}
}
