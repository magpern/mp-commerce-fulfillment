<?php
/**
 * Read-only Analytics REST controller (`/mpcf/v1/analytics/...`).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use MPCF\Application\Analytics\AnalyticsCsvExporter;
use MPCF\Application\Analytics\AnalyticsRange;
use MPCF\Application\Analytics\AnalyticsService;
use MPCF\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Additive v1 only. No write routes.
 */
final class AnalyticsController extends AbstractRestController {

	/**
	 * Analytics façade.
	 *
	 * @var AnalyticsService
	 */
	private AnalyticsService $analytics;

	/**
	 * CSV exporter for `/export`.
	 *
	 * @var AnalyticsCsvExporter
	 */
	private AnalyticsCsvExporter $csv;

	/**
	 * Builds the controller.
	 *
	 * @param AnalyticsService     $analytics Analytics façade.
	 * @param AnalyticsCsvExporter $csv       CSV exporter for `/export`.
	 */
	public function __construct( AnalyticsService $analytics, AnalyticsCsvExporter $csv ) {
		$this->analytics = $analytics;
		$this->csv       = $csv;
	}

	/**
	 * Registers analytics read routes under the v1 namespace.
	 */
	public function register_routes(): void {
		$ns   = self::NAMESPACE_V1;
		$cap  = $this->require_capability( Capabilities::VIEW_ANALYTICS );
		$base = '/analytics';

		register_rest_route(
			$ns,
			$base . '/overview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'overview' ),
				'permission_callback' => $cap,
			)
		);
		register_rest_route(
			$ns,
			$base . '/timeline',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'timeline' ),
				'permission_callback' => $cap,
			)
		);
		register_rest_route(
			$ns,
			$base . '/queue-ageing',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'queue_ageing' ),
				'permission_callback' => $cap,
			)
		);
		register_rest_route(
			$ns,
			$base . '/waves',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'waves' ),
				'permission_callback' => $cap,
			)
		);
		register_rest_route(
			$ns,
			$base . '/diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'diagnostics' ),
				'permission_callback' => $cap,
			)
		);
		register_rest_route(
			$ns,
			$base . '/reports',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'reports' ),
				'permission_callback' => $cap,
			)
		);
		register_rest_route(
			$ns,
			$base . '/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $cap,
			)
		);
	}

	/**
	 * GET /analytics/overview.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function overview( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->respond( $this->analytics->overview() );
	}

	/**
	 * GET /analytics/timeline.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function timeline( WP_REST_Request $request ): WP_REST_Response {
		$date = $request->get_param( 'utc_date' );
		return $this->respond( $this->analytics->timeline( is_string( $date ) ? $date : null ) );
	}

	/**
	 * GET /analytics/queue-ageing.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function queue_ageing( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->respond( $this->analytics->queue_ageing() );
	}

	/**
	 * GET /analytics/waves.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function waves( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->respond( $this->analytics->waves_today() );
	}

	/**
	 * GET /analytics/diagnostics.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function diagnostics( WP_REST_Request $request ): WP_REST_Response {
		$limit = (int) ( $request->get_param( 'limit' ) ?? 25 );
		$data  = $this->analytics->diagnostics( max( 1, min( 100, $limit ) ) );
		$data  = $this->strip_operator_fields_if_needed( $data );
		return $this->respond( $data );
	}

	/**
	 * GET /analytics/reports.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reports( WP_REST_Request $request ) {
		try {
			$range = $this->range_from_request( $request );
		} catch ( \InvalidArgumentException $e ) {
			return self::failure_error( 'invalid_payload', $e->getMessage() );
		}

		return $this->respond( $this->analytics->report_dto( $range ) );
	}

	/**
	 * GET /analytics/export (CSV body).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export( WP_REST_Request $request ) {
		try {
			$range = $this->range_from_request( $request );
		} catch ( \InvalidArgumentException $e ) {
			return self::failure_error( 'invalid_payload', $e->getMessage() );
		}

		$type = sanitize_key( (string) ( $request->get_param( 'type' ) ?? AnalyticsCsvExporter::TYPE_THROUGHPUT ) );
		$dto  = $this->analytics->report_dto( $range );
		$csv  = $this->csv->export( $dto, $type );

		$response = new WP_REST_Response( $csv );
		$response->set_status( 200 );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="mpcf-analytics-' . $type . '.csv"' );

		return $response;
	}

	/**
	 * Builds an AnalyticsRange from request query args.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	private function range_from_request( WP_REST_Request $request ): AnalyticsRange {
		$now    = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$preset = sanitize_key( (string) ( $request->get_param( 'preset' ) ?? 'weekly' ) );

		switch ( $preset ) {
			case 'today':
				return AnalyticsRange::today( $now );
			case 'daily':
				return AnalyticsRange::last_n_closed_days( $now, 1 );
			case 'monthly':
				return AnalyticsRange::monthly( $now );
			case 'custom':
				$from = (string) $request->get_param( 'from' );
				$to   = (string) $request->get_param( 'to' );
				return AnalyticsRange::custom( $from, $to, $now );
			case 'weekly':
			default:
				return AnalyticsRange::weekly( $now );
		}
	}

	/**
	 * Removes operator-only fields when the caller lacks the capability.
	 *
	 * @param array<string, mixed> $data Diagnostics payload.
	 * @return array<string, mixed>
	 */
	private function strip_operator_fields_if_needed( array $data ): array {
		if ( current_user_can( Capabilities::VIEW_OPERATOR_STATS ) ) {
			return $data;
		}
		unset( $data['operators'] );
		return $data;
	}
}
