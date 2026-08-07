<?php
/**
 * Analytics admin screen (Overview / Reports / Diagnostics).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Application\Analytics\AnalyticsCsvExporter;
use MPCF\Application\Analytics\AnalyticsRange;
use MPCF\Application\Analytics\AnalyticsService;
use MPCF\Capabilities;
use MPCF\Engine\Analytics\DurationCalculator;
use MPCF\Engine\Analytics\QueueAgeingBuckets;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\Page;

/**
 * Dedicated Analytics IA. Does not modify Mission Control.
 */
final class AnalyticsPage implements Page {

	public const SLUG = 'mpcf-analytics';

	/**
	 * Page-shell chrome renderer.
	 *
	 * @var AdminPageShell
	 */
	private AdminPageShell $shell;

	/**
	 * MPDS component renderer.
	 *
	 * @var ComponentRenderer
	 */
	private ComponentRenderer $renderer;

	/**
	 * Analytics façade.
	 *
	 * @var AnalyticsService
	 */
	private AnalyticsService $analytics;

	/**
	 * CSV exporter for report downloads.
	 *
	 * @var AnalyticsCsvExporter
	 */
	private AnalyticsCsvExporter $csv;

	/**
	 * Builds the page.
	 *
	 * @param AdminPageShell       $shell     Page-shell chrome renderer.
	 * @param ComponentRenderer    $renderer  MPDS component renderer.
	 * @param AnalyticsService     $analytics Analytics façade.
	 * @param AnalyticsCsvExporter $csv       CSV exporter for report downloads.
	 */
	public function __construct(
		AdminPageShell $shell,
		ComponentRenderer $renderer,
		AnalyticsService $analytics,
		AnalyticsCsvExporter $csv
	) {
		$this->shell     = $shell;
		$this->renderer  = $renderer;
		$this->analytics = $analytics;
		$this->csv       = $csv;
	}

	/**
	 * This page's slug.
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * The browser page title.
	 */
	public function title(): string {
		return __( 'Operational Analytics', 'mp-commerce-fulfillment' );
	}

	/**
	 * The submenu label.
	 */
	public function menu_title(): string {
		return __( 'Analytics', 'mp-commerce-fulfillment' );
	}

	/**
	 * The capability required to view this page.
	 */
	public function capability(): string {
		return Capabilities::VIEW_ANALYTICS;
	}

	/**
	 * Renders the Analytics admin screen.
	 */
	public function render(): void {
		$view = isset( $_GET['view'] ) ? sanitize_key( (string) wp_unslash( $_GET['view'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		if ( ! in_array( $view, array( 'overview', 'reports', 'diagnostics' ), true ) ) {
			$view = 'overview';
		}

		if ( 'reports' === $view && isset( $_GET['export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->stream_csv();
			return;
		}

		$this->shell->open_wrap();
		$this->shell->open();
		$this->shell->render_header( ShellHeader::view_model( self::SLUG ) );
		$this->shell->open_content( true );

		$this->render_subnav( $view );

		if ( 'reports' === $view ) {
			$this->render_reports();
		} elseif ( 'diagnostics' === $view ) {
			$this->render_diagnostics();
		} else {
			$this->render_overview();
		}

		$this->shell->close_content();
		$this->shell->close();
		$this->shell->close_wrap();
	}

	/**
	 * Renders Overview / Reports / Diagnostics tabs.
	 *
	 * @param string $view Active view key.
	 */
	private function render_subnav( string $view ): void {
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		$tabs = array(
			'overview'    => __( 'Overview', 'mp-commerce-fulfillment' ),
			'reports'     => __( 'Reports', 'mp-commerce-fulfillment' ),
			'diagnostics' => __( 'Diagnostics', 'mp-commerce-fulfillment' ),
		);
		echo '<p class="mpcf-analytics-tabs">';
		foreach ( $tabs as $key => $label ) {
			if ( $key === $view ) {
				echo '<strong>' . esc_html( $label ) . '</strong> ';
			} else {
				echo '<a href="' . esc_url( $base . '&view=' . $key ) . '">' . esc_html( $label ) . '</a> ';
			}
		}
		echo '</p>';
	}

	/**
	 * Renders the LIVE overview view.
	 */
	private function render_overview(): void {
		$data  = $this->analytics->overview();
		$cards = $data['cards'];

		$this->shell->open_section_card( __( 'Today (UTC live)', 'mp-commerce-fulfillment' ) );
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: UTC date Y-m-d */
				__( 'UTC day: %s — historical trends use nightly rollups; Mission Control is unchanged.', 'mp-commerce-fulfillment' ),
				(string) $data['utc_today']
			)
		) . '</p>';

		$stat_defs = array(
			__( 'Created today', 'mp-commerce-fulfillment' ) => (string) $cards['created_today'],
			__( 'Packed today', 'mp-commerce-fulfillment' ) => (string) $cards['packed_today'],
			__( 'Shipped today', 'mp-commerce-fulfillment' ) => (string) $cards['shipped_today'],
			__( 'Open queue', 'mp-commerce-fulfillment' ) => (string) $cards['open_queue'],
			__( 'Exceptions', 'mp-commerce-fulfillment' ) => (string) $cards['exceptions'],
			__( 'Waves completed', 'mp-commerce-fulfillment' ) => (string) $cards['waves_completed'],
		);
		$rate      = $cards['notification_failure_rate'];
		$stat_defs[ __( 'Notification failure rate', 'mp-commerce-fulfillment' ) ] = null === $rate
			? '—'
			: sprintf( '%.1f%%', 100.0 * (float) $rate );

		echo $this->renderer->statistics_grid_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $stat_defs as $label => $value ) {
			echo $this->renderer->statistics_card( $label, $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo $this->renderer->statistics_grid_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->shell->close_section_card();

		$this->render_timeline_table( $data['timeline'] );
		$this->render_ageing( $data['ageing'] );

		$waves = $data['today']['counters']['waves'];
		$this->shell->open_section_card( __( 'Waves today', 'mp-commerce-fulfillment' ) );
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: completed count 2: abandoned count */
				__( 'Completed: %1$d · Abandoned: %2$d', 'mp-commerce-fulfillment' ),
				(int) $waves['completed'],
				(int) $waves['abandoned']
			)
		) . '</p>';
		$this->shell->close_section_card();
	}

	/**
	 * Renders the Stage Timeline table.
	 *
	 * @param array<string, array<string, mixed>> $durations Hop duration stats.
	 */
	private function render_timeline_table( array $durations ): void {
		$this->shell->open_section_card( __( 'Stage Timeline', 'mp-commerce-fulfillment' ) );
		$labels = array(
			'queued_to_picking' => __( 'Queued → Picking', 'mp-commerce-fulfillment' ),
			'picking_to_picked' => __( 'Picking → Picked', 'mp-commerce-fulfillment' ),
			'picked_to_packing' => __( 'Picked → Packing', 'mp-commerce-fulfillment' ),
			'packing_to_packed' => __( 'Packing → Packed', 'mp-commerce-fulfillment' ),
			'packed_to_shipped' => __( 'Packed → Shipped', 'mp-commerce-fulfillment' ),
			'queued_to_shipped' => __( 'Queued → Shipped', 'mp-commerce-fulfillment' ),
		);
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Stage transition', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'Average (s)', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'p50 (s)', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'p90 (s)', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'Samples', 'mp-commerce-fulfillment' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( DurationCalculator::hop_keys() as $key ) {
			$stats = $durations[ $key ] ?? array();
			echo '<tr>';
			echo '<td>' . esc_html( $labels[ $key ] ?? $key ) . '</td>';
			echo '<td>' . esc_html( $this->fmt_num( $stats['avg'] ?? null ) ) . '</td>';
			echo '<td>' . esc_html( $this->fmt_num( $stats['p50'] ?? null ) ) . '</td>';
			echo '<td>' . esc_html( $this->fmt_num( $stats['p90'] ?? null ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $stats['count'] ?? 0 ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		$this->shell->close_section_card();
	}

	/**
	 * Renders queue-ageing buckets.
	 *
	 * @param array<string, mixed> $ageing Ageing summary from LIVE.
	 */
	private function render_ageing( array $ageing ): void {
		$this->shell->open_section_card( __( 'Queue ageing', 'mp-commerce-fulfillment' ) );
		$labels  = array(
			QueueAgeingBuckets::KEY_0_1H  => __( '0–1 h', 'mp-commerce-fulfillment' ),
			QueueAgeingBuckets::KEY_1_4H  => __( '1–4 h', 'mp-commerce-fulfillment' ),
			QueueAgeingBuckets::KEY_4_24H => __( '4–24 h', 'mp-commerce-fulfillment' ),
			QueueAgeingBuckets::KEY_1_3D  => __( '1–3 d', 'mp-commerce-fulfillment' ),
			QueueAgeingBuckets::KEY_GT_3D => __( '>3 d', 'mp-commerce-fulfillment' ),
		);
		$buckets = $ageing['buckets'] ?? array();
		echo '<ul>';
		foreach ( $labels as $key => $label ) {
			$count = (int) ( $buckets[ $key ] ?? 0 );
			$mark  = ( QueueAgeingBuckets::KEY_GT_3D === $key || QueueAgeingBuckets::KEY_1_3D === $key ) && $count > 0
				? ' ★'
				: '';
			echo '<li><strong>' . esc_html( $label ) . '</strong>: ' . esc_html( (string) $count ) . esc_html( $mark ) . '</li>';
		}
		echo '</ul>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: open count 2: exception count */
				__( 'Open: %1$d · Exceptions: %2$d', 'mp-commerce-fulfillment' ),
				(int) ( $ageing['open_count'] ?? 0 ),
				(int) ( $ageing['exception_count'] ?? 0 )
			)
		) . '</p>';
		$this->shell->close_section_card();
	}

	/**
	 * Renders the Reports view and CSV export link.
	 */
	private function render_reports(): void {
		$preset = isset( $_GET['preset'] ) ? sanitize_key( (string) wp_unslash( $_GET['preset'] ) ) : 'weekly'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$now    = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		try {
			if ( 'monthly' === $preset ) {
				$range = AnalyticsRange::monthly( $now );
			} elseif ( 'daily' === $preset ) {
				$range = AnalyticsRange::last_n_closed_days( $now, 1 );
			} elseif ( 'today' === $preset ) {
				$range = AnalyticsRange::today( $now );
			} else {
				$preset = 'weekly';
				$range  = AnalyticsRange::weekly( $now );
			}
		} catch ( \InvalidArgumentException $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
			return;
		}

		$report = $this->analytics->report_dto( $range );
		$this->shell->open_section_card( __( 'Reports', 'mp-commerce-fulfillment' ) );
		echo '<p>';
		foreach ( array( 'today', 'daily', 'weekly', 'monthly' ) as $p ) {
			if ( $p === $preset ) {
				echo '<strong>' . esc_html( $p ) . '</strong> ';
			} else {
				echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&view=reports&preset=' . $p ) ) . '">' . esc_html( $p ) . '</a> ';
			}
		}
		echo '</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&view=reports&preset=' . $preset . '&export=throughput' ) ) . '">' . esc_html__( 'Export throughput CSV (UTF-8)', 'mp-commerce-fulfillment' ) . '</a></p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'UTC date', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'Packed', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'Shipped', 'mp-commerce-fulfillment' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $report['days'] as $day ) {
			$c = $day['counters']['fulfillments'] ?? array();
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $day['utc_date'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $day['source'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $c['created'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $c['packed'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $c['shipped'] ?? 0 ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		$this->shell->close_section_card();
	}

	/**
	 * Renders the Diagnostics view.
	 */
	private function render_diagnostics(): void {
		$data = $this->analytics->diagnostics( 25 );
		$this->shell->open_section_card( __( 'Top failure reasons (today)', 'mp-commerce-fulfillment' ) );
		$this->render_reason_list( __( 'Rejections', 'mp-commerce-fulfillment' ), $data['top_rejection'] );
		$this->render_reason_list( __( 'Scan', 'mp-commerce-fulfillment' ), $data['top_scan'] );
		$this->render_reason_list( __( 'Notifications', 'mp-commerce-fulfillment' ), $data['top_notification'] );
		$this->shell->close_section_card();

		$this->shell->open_section_card( __( 'Slow fulfillments (≥4h in state)', 'mp-commerce-fulfillment' ) );
		$this->render_entity_table( $data['slow_fulfillments'], 'fulfillment' );
		$this->shell->close_section_card();

		$this->shell->open_section_card( __( 'Stalled / paused waves', 'mp-commerce-fulfillment' ) );
		$this->render_entity_table( $data['stalled_waves'], 'wave' );
		$this->shell->close_section_card();
	}

	/**
	 * Renders a titled top-reasons list.
	 *
	 * @param string                                  $title Section heading.
	 * @param list<array{reason: string, count: int}> $rows  Reason tallies.
	 */
	private function render_reason_list( string $title, array $rows ): void {
		echo '<h4>' . esc_html( $title ) . '</h4>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'None recorded today.', 'mp-commerce-fulfillment' ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $rows as $row ) {
			echo '<li>' . esc_html( $row['reason'] ) . ' — ' . esc_html( (string) $row['count'] ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Renders a diagnostic entity table.
	 *
	 * @param list<array<string, mixed>> $rows Entity rows.
	 * @param string                     $kind `fulfillment` or `wave`.
	 */
	private function render_entity_table( array $rows, string $kind ): void {
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No rows.', 'mp-commerce-fulfillment' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'Label', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'Age (s)', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'mp-commerce-fulfillment' ) . '</th>';
		echo '<th>' . esc_html__( 'Link', 'mp-commerce-fulfillment' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$id  = (int) ( $row['id'] ?? 0 );
			$url = 'wave' === $kind
				? admin_url( 'admin.php?page=mpcf-wave&wave_id=' . $id )
				: admin_url( 'admin.php?page=mpcf-workspace&fulfillment_id=' . $id );
			echo '<tr>';
			echo '<td>' . esc_html( (string) $id ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['label'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['state'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['age_seconds'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['reason'] ?? '' ) ) . '</td>';
			echo '<td><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'mp-commerce-fulfillment' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Streams a throughput CSV download and exits.
	 */
	private function stream_csv(): void {
		$preset = isset( $_GET['preset'] ) ? sanitize_key( (string) wp_unslash( $_GET['preset'] ) ) : 'weekly'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$now    = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$range  = 'monthly' === $preset ? AnalyticsRange::monthly( $now ) : AnalyticsRange::weekly( $now );
		$dto    = $this->analytics->report_dto( $range );
		$csv    = $this->csv->export( $dto, AnalyticsCsvExporter::TYPE_THROUGHPUT );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mpcf-analytics-throughput.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body.
		exit;
	}

	/**
	 * Formats a numeric cell for display.
	 *
	 * @param mixed $v Value to format.
	 */
	private function fmt_num( $v ): string {
		if ( null === $v ) {
			return '—';
		}
		return is_numeric( $v ) ? (string) round( (float) $v, 1 ) : '—';
	}
}
