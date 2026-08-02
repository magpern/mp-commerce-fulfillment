<?php
/**
 * REST controller for batch line-item quantity updates.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use MPCF\Application\PackingService;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `PUT /fulfillments/{id}/items` — the workspace's checklist burst-flush
 * endpoint (Architecture Plan §IV.9/§IV.10). Quantities are always
 * absolute, never deltas, so a retried or double-submitted batch is
 * idempotent by construction; that guarantee belongs to
 * {@see PackingService}, not this controller, which stays a thin
 * translation from the wire shape to that service's call shape.
 */
final class ItemsController extends AbstractRestController {

	/**
	 * Batch quantity mutations.
	 *
	 * @var PackingService
	 */
	private PackingService $packing;

	/**
	 * Fresh candidate-transition evaluation for the response envelope.
	 *
	 * @var WorkflowService
	 */
	private WorkflowService $workflow;

	/**
	 * Builds the controller.
	 *
	 * @param PackingService  $packing  Batch quantity mutations.
	 * @param WorkflowService $workflow Fresh candidate-transition evaluation for the response envelope.
	 */
	public function __construct( PackingService $packing, WorkflowService $workflow ) {
		$this->packing  = $packing;
		$this->workflow = $workflow;
	}

	/**
	 * Registers this controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/fulfillments/(?P<id>\d+)/items',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_items' ),
					'permission_callback' => $this->require_capability( Capabilities::PROCESS_FULFILLMENTS ),
					'args'                => array(
						'id'      => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
						),
						'version' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'lines'   => array(
							'type'              => 'array',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_array( $value ) && array() !== $value,
							'sanitize_callback' => array( self::class, 'sanitize_lines' ),
						),
					),
				),
			)
		);
	}

	/**
	 * `PUT /fulfillments/{id}/items`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function update_items( WP_REST_Request $request ) {
		$fulfillment_id = (int) $request->get_param( 'id' );
		$version        = (int) $request->get_param( 'version' );
		$lines          = (array) $request->get_param( 'lines' );

		$outcome = $this->packing->update_quantities( $fulfillment_id, $version, $lines, self::current_actor() );

		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		$candidates = $this->workflow->available_transitions( $fulfillment_id, 'current_user_can' );

		return $this->respond(
			array(
				'items'       => array_map( array( self::class, 'item_resource' ), $outcome->updated_items() ),
				'version'     => $outcome->version(),
				'transitions' => self::transitions_resource( $candidates ),
			)
		);
	}

	/**
	 * Coerces each submitted line to the `{item_id:int, qty_picked?:int,
	 * qty_packed?:int}` shape {@see PackingService::update_quantities()}
	 * expects — unknown keys are dropped, present keys are cast to `int`,
	 * absent optional keys stay absent (never coerced to `0`, which would
	 * silently zero a quantity the client never meant to touch).
	 *
	 * @param mixed $value Raw `lines` param.
	 * @return list<array{item_id:int, qty_picked?:int, qty_packed?:int}>
	 */
	public static function sanitize_lines( $value ): array {
		$lines = array();

		foreach ( (array) $value as $raw_line ) {
			if ( ! is_array( $raw_line ) || ! isset( $raw_line['item_id'] ) ) {
				continue;
			}

			$line = array( 'item_id' => (int) $raw_line['item_id'] );

			if ( array_key_exists( 'qty_picked', $raw_line ) ) {
				$line['qty_picked'] = (int) $raw_line['qty_picked'];
			}

			if ( array_key_exists( 'qty_packed', $raw_line ) ) {
				$line['qty_packed'] = (int) $raw_line['qty_packed'];
			}

			$lines[] = $line;
		}

		return $lines;
	}
}
