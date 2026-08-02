<?php
/**
 * Packing slip template. Rendered by `Documents\HtmlRenderer` with a
 * `MPCF\Domain\Document\DocumentModel` bound to `$model` — this file
 * renders from that object's getters only, never a repository or a
 * second data source.
 *
 * @package MPCommerceFulfillment
 *
 * @var \MPCF\Domain\Document\DocumentModel $model
 */

declare( strict_types=1 );

if ( ! isset( $model ) || ! $model instanceof \MPCF\Domain\Document\DocumentModel ) {
	return;
}

$mpcf_slip_css_path = __DIR__ . '/packing-slip.css';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php esc_html_e( 'Packing slip', 'mp-commerce-fulfillment' ); ?> <?php echo esc_html( $model->order_number() ); ?></title>
<style>
<?php echo file_exists( $mpcf_slip_css_path ) ? file_get_contents( $mpcf_slip_css_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.EscapeOutput.OutputNotEscaped -- A bundled, plugin-authored local CSS file, not a remote URL or user input. ?>
</style>
</head>
<body>
	<header class="mpcf-slip-header">
		<div class="mpcf-slip-store"><?php echo esc_html( $model->store_name() ); ?></div>
		<div>
			<div class="mpcf-slip-title"><?php esc_html_e( 'Packing slip', 'mp-commerce-fulfillment' ); ?></div>
			<div class="mpcf-slip-order-number">
				<?php
				printf(
					/* translators: %s: order number */
					esc_html__( 'Order %s', 'mp-commerce-fulfillment' ),
					esc_html( $model->order_number() )
				);
				?>
			</div>
		</div>
	</header>

	<div class="mpcf-slip-addresses">
		<div class="mpcf-slip-ship-to">
			<h2><?php esc_html_e( 'Ship to', 'mp-commerce-fulfillment' ); ?></h2>
			<address>
				<?php foreach ( $model->ship_to_lines() as $mpcf_line ) : ?>
					<?php echo esc_html( $mpcf_line ); ?><br>
				<?php endforeach; ?>
			</address>
		</div>
	</div>

	<table class="mpcf-slip-items">
		<thead>
			<tr>
				<th><?php esc_html_e( 'SKU', 'mp-commerce-fulfillment' ); ?></th>
				<th><?php esc_html_e( 'Item', 'mp-commerce-fulfillment' ); ?></th>
				<th class="mpcf-slip-qty"><?php esc_html_e( 'Qty', 'mp-commerce-fulfillment' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $model->items() as $mpcf_item ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $mpcf_item['sku'] ); ?></td>
					<td><?php echo esc_html( (string) $mpcf_item['name'] ); ?></td>
					<td class="mpcf-slip-qty"><?php echo esc_html( (string) $mpcf_item['qty_ordered'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( array() !== $model->packages() ) : ?>
		<div class="mpcf-slip-packages">
			<h2><?php esc_html_e( 'Packages', 'mp-commerce-fulfillment' ); ?></h2>
			<ul>
				<?php foreach ( $model->packages() as $mpcf_package ) : ?>
					<li>
						<?php
						printf(
							/* translators: 1: package sequence number */
							esc_html__( 'Package %1$d', 'mp-commerce-fulfillment' ),
							(int) $mpcf_package['seq']
						);

						if ( null !== $mpcf_package['weight_grams'] ) {
							printf( ' — %d g', (int) $mpcf_package['weight_grams'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printf's own format string, no user input.
						}

						if ( null !== $mpcf_package['tracking_number'] ) {
							printf( ' — %s', esc_html( (string) $mpcf_package['tracking_number'] ) );
						}
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<div class="mpcf-slip-barcode">
		<div class="mpcf-slip-barcode-payload"><?php echo esc_html( $model->barcode_payload() ); ?></div>
	</div>
</body>
</html>
