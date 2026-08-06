<?php
/**
 * Wave picking list template. Rendered by `Documents\HtmlRenderer` with a
 * `MPCF\Domain\Document\DocumentModel` bound to `$model`.
 *
 * @package MPCommerceFulfillment
 *
 * @var \MPCF\Domain\Document\DocumentModel $model
 */

declare( strict_types=1 );

if ( ! isset( $model ) || ! $model instanceof \MPCF\Domain\Document\DocumentModel ) {
	return;
}

$mpcf_wave_css_path = __DIR__ . '/wave-picking-list.css';
$mpcf_branding      = $model->branding();
$mpcf_address       = isset( $mpcf_branding['address_lines'] ) && is_array( $mpcf_branding['address_lines'] )
	? $mpcf_branding['address_lines']
	: array();
$mpcf_footer        = isset( $mpcf_branding['footer'] ) ? (string) $mpcf_branding['footer'] : '';
$mpcf_rendered_at   = $model->rendered_at();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php esc_html_e( 'Wave picking list', 'mp-commerce-fulfillment' ); ?> <?php echo esc_html( $model->order_number() ); ?></title>
<style>
<?php echo file_exists( $mpcf_wave_css_path ) ? file_get_contents( $mpcf_wave_css_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled local CSS. ?>
</style>
</head>
<body>
	<header class="mpcf-wave-header">
		<div class="mpcf-wave-brand">
			<div class="mpcf-wave-store"><?php echo esc_html( $model->store_name() ); ?></div>
			<?php if ( array() !== $mpcf_address ) : ?>
				<address class="mpcf-wave-address">
					<?php foreach ( $mpcf_address as $mpcf_line ) : ?>
						<?php echo esc_html( (string) $mpcf_line ); ?><br>
					<?php endforeach; ?>
				</address>
			<?php endif; ?>
		</div>
		<div>
			<div class="mpcf-wave-title"><?php esc_html_e( 'Wave picking list', 'mp-commerce-fulfillment' ); ?></div>
			<div class="mpcf-wave-order-number"><?php echo esc_html( $model->order_number() ); ?></div>
			<div class="mpcf-wave-meta">
				<?php
				printf(
					/* translators: 1: wave id, 2: wave state, 3: member summary */
					esc_html__( 'Wave #%1$d · %2$s · %3$s', 'mp-commerce-fulfillment' ),
					$model->fulfillment_id(),
					esc_html( $model->fulfillment_state() ),
					esc_html( $model->customer_name() )
				);
				?>
			</div>
			<?php if ( null !== $mpcf_rendered_at ) : ?>
				<div class="mpcf-wave-meta">
					<?php
					printf(
						/* translators: %s: render timestamp */
						esc_html__( 'Rendered %s', 'mp-commerce-fulfillment' ),
						esc_html( $mpcf_rendered_at->format( 'Y-m-d H:i' ) )
					);
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php if ( '' !== $model->barcode_payload() ) : ?>
			<div class="mpcf-wave-barcode">
				<?php echo \MPCF\Documents\Barcode\DocumentBarcodeMarkup::render_block( $model->barcode_payload() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted SVG markup. ?>
			</div>
		<?php endif; ?>
	</header>

	<?php if ( array() !== $model->ship_to_lines() ) : ?>
		<section class="mpcf-wave-members">
			<strong><?php esc_html_e( 'Members', 'mp-commerce-fulfillment' ); ?></strong>
			<?php echo esc_html( implode( ', ', $model->ship_to_lines() ) ); ?>
		</section>
	<?php endif; ?>

	<table class="mpcf-wave-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Location', 'mp-commerce-fulfillment' ); ?></th>
				<th><?php esc_html_e( 'SKU', 'mp-commerce-fulfillment' ); ?></th>
				<th><?php esc_html_e( 'Name', 'mp-commerce-fulfillment' ); ?></th>
				<th><?php esc_html_e( 'Qty', 'mp-commerce-fulfillment' ); ?></th>
				<th><?php esc_html_e( 'Orders', 'mp-commerce-fulfillment' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $model->items() as $mpcf_row ) : ?>
				<tr class="<?php echo ! empty( $mpcf_row['complete'] ) ? 'is-complete' : ''; ?>">
					<td><?php echo esc_html( (string) ( $mpcf_row['location_snapshot'] ?? '—' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $mpcf_row['sku'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $mpcf_row['name'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) (int) ( $mpcf_row['required_qty'] ?? 0 ) ); ?></td>
					<td>
						<?php
						$mpcf_alloc = isset( $mpcf_row['allocations'] ) && is_array( $mpcf_row['allocations'] ) ? $mpcf_row['allocations'] : array();
						$mpcf_bits  = array();
						foreach ( $mpcf_alloc as $mpcf_a ) {
							$mpcf_bits[] = (string) ( $mpcf_a['order_number'] ?? ( '#' . ( $mpcf_a['fulfillment_id'] ?? '' ) ) )
								. '×' . (int) ( $mpcf_a['outstanding'] ?? 0 );
						}
						echo esc_html( implode( ', ', $mpcf_bits ) );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( '' !== $mpcf_footer ) : ?>
		<footer class="mpcf-wave-footer"><?php echo esc_html( $mpcf_footer ); ?></footer>
	<?php endif; ?>
</body>
</html>
