<?php
/**
 * Documents history admin screen (M4-D).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Application\DocumentHistoryService;
use MPCF\Capabilities;
use MPCF\Documents\DocumentEventLabels;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\Page;

/**
 * Read-only document history with reprint and workspace links.
 * No edit/delete. Content opens via capability-checked REST stream.
 */
final class DocumentsPage implements Page {

	public const SLUG = 'mpcf-documents';

	/**
	 * @var AdminPageShell
	 */
	private AdminPageShell $shell;

	/**
	 * @var DocumentHistoryService
	 */
	private DocumentHistoryService $history;

	/**
	 * @param AdminPageShell         $shell   Shell.
	 * @param DocumentHistoryService $history History service.
	 */
	public function __construct( AdminPageShell $shell, DocumentHistoryService $history ) {
		$this->shell   = $shell;
		$this->history = $history;
	}

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Documents', 'mp-commerce-fulfillment' );
	}

	public function menu_title(): string {
		return __( 'Documents', 'mp-commerce-fulfillment' );
	}

	public function capability(): string {
		return Capabilities::RENDER_DOCUMENTS;
	}

	/**
	 * Renders the history screen.
	 */
	public function render(): void {
		$filters = array(
			'doc_type'  => isset( $_GET['doc_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['doc_type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
			'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date_from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date_to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
			'limit'     => 50,
			'offset'    => 0,
		);

		$result = $this->history->search( $filters );

		$this->shell->open_wrap();
		$this->shell->open();
		$this->shell->render_header( ShellHeader::view_model( self::SLUG ) );
		$this->shell->open_content( true );
		$this->shell->open_section_card( __( 'Document history', 'mp-commerce-fulfillment' ), __( 'Immutable outbound documents. Reprint streams the original stored artifact.', 'mp-commerce-fulfillment' ), 'dashicons-media-document' );

		echo '<form method="get" class="mpcf-documents-filters">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );

		echo '<p>';
		echo '<label>' . esc_html__( 'Type', 'mp-commerce-fulfillment' ) . ' ';
		echo '<select name="doc_type">';
		printf( '<option value="">%s</option>', esc_html__( 'All', 'mp-commerce-fulfillment' ) );
		printf( '<option value="picking_list"%s>%s</option>', selected( $filters['doc_type'], 'picking_list', false ), esc_html__( 'Picking list', 'mp-commerce-fulfillment' ) );
		printf( '<option value="packing_slip"%s>%s</option>', selected( $filters['doc_type'], 'packing_slip', false ), esc_html__( 'Packing slip', 'mp-commerce-fulfillment' ) );
		echo '</select></label> ';

		printf(
			'<label>%s <input type="search" name="s" value="%s" /></label> ',
			esc_html__( 'Order / fulfillment', 'mp-commerce-fulfillment' ),
			esc_attr( $filters['search'] )
		);
		printf(
			'<label>%s <input type="date" name="date_from" value="%s" /></label> ',
			esc_html__( 'From', 'mp-commerce-fulfillment' ),
			esc_attr( $filters['date_from'] )
		);
		printf(
			'<label>%s <input type="date" name="date_to" value="%s" /></label> ',
			esc_html__( 'To', 'mp-commerce-fulfillment' ),
			esc_attr( $filters['date_to'] )
		);
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Filter', 'mp-commerce-fulfillment' ) );
		echo '</p></form>';

		if ( array() === $result['items'] ) {
			echo '<p>' . esc_html__( 'No documents match these filters.', 'mp-commerce-fulfillment' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'ID', 'mp-commerce-fulfillment' ) . '</th>';
			echo '<th>' . esc_html__( 'Order', 'mp-commerce-fulfillment' ) . '</th>';
			echo '<th>' . esc_html__( 'Type', 'mp-commerce-fulfillment' ) . '</th>';
			echo '<th>' . esc_html__( 'Rendered', 'mp-commerce-fulfillment' ) . '</th>';
			echo '<th>' . esc_html__( 'By', 'mp-commerce-fulfillment' ) . '</th>';
			echo '<th>' . esc_html__( 'Template', 'mp-commerce-fulfillment' ) . '</th>';
			echo '<th>' . esc_html__( 'Stored', 'mp-commerce-fulfillment' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'mp-commerce-fulfillment' ) . '</th>';
			echo '</tr></thead><tbody>';

			$rest = esc_url_raw( rest_url( 'mpcf/v1/documents/' ) );

			foreach ( $result['items'] as $row ) {
				$id  = (int) $row['id'];
				$fid = (int) $row['fulfillment_id'];
				echo '<tr>';
				echo '<td>' . esc_html( (string) $id ) . '</td>';
				echo '<td>' . esc_html( (string) $row['order_number'] ) . ' <span class="description">#' . esc_html( (string) $fid ) . '</span></td>';
				echo '<td>' . esc_html( DocumentEventLabels::type_label( (string) $row['doc_type'] ) ) . '</td>';
				echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['rendered_by'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['template_version'] ) . '</td>';
				echo '<td>' . ( ! empty( $row['stored'] ) ? esc_html__( 'Yes', 'mp-commerce-fulfillment' ) : esc_html__( 'No', 'mp-commerce-fulfillment' ) ) . '</td>';
				echo '<td>';
				printf(
					'<a class="button button-small" href="%s">%s</a> ',
					esc_url( admin_url( 'admin.php?page=' . WorkspacePage::SLUG . '&fulfillment_id=' . $fid ) ),
					esc_html__( 'Workspace', 'mp-commerce-fulfillment' )
				);
				if ( ! empty( $row['stored'] ) ) {
					printf(
						'<button type="button" class="button button-small" data-mpcf-reprint-document="%d" data-mpcf-rest-base="%s">%s</button>',
						$id,
						esc_attr( $rest ),
						esc_html__( 'Reprint', 'mp-commerce-fulfillment' )
					);
				}
				echo '</td></tr>';
			}

			echo '</tbody></table>';
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: total matching documents */
						__( '%d document(s) matched.', 'mp-commerce-fulfillment' ),
						(int) $result['total']
					)
				)
			);
		}

		echo '<script>';
		echo <<<'JS'
document.addEventListener('click', function (event) {
  var button = event.target.closest('[data-mpcf-reprint-document]');
  if (!button) return;
  var id = button.getAttribute('data-mpcf-reprint-document');
  var base = button.getAttribute('data-mpcf-rest-base');
  var nonce = (window.wpApiSettings && window.wpApiSettings.nonce) || (window.mpcfWorkspace && window.mpcfWorkspace.nonce) || '';
  fetch(base + id + '/reprint', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
  }).then(function (r) { return r.json().then(function (data) { if (!r.ok) throw new Error(data.message || r.statusText); return data; }); })
    .then(function (data) {
      var iframe = document.createElement('iframe');
      iframe.setAttribute('aria-hidden', 'true');
      iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
      iframe.srcdoc = data.html;
      iframe.addEventListener('load', function () { iframe.contentWindow.focus(); iframe.contentWindow.print(); }, { once: true });
      document.body.appendChild(iframe);
    })
    .catch(function (err) { window.alert(err.message || 'Reprint failed'); });
});
JS;
		echo '</script>';

		$this->shell->close_section_card();
		$this->shell->close_content();
		$this->shell->close();
		$this->shell->close_wrap();
	}
}
