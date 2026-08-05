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
$mpcf_branding      = $model->branding();
$mpcf_address       = isset( $mpcf_branding['address_lines'] ) && is_array( $mpcf_branding['address_lines'] )
	? $mpcf_branding['address_lines']
	: array();
$mpcf_footer        = isset( $mpcf_branding['footer'] ) ? (string) $mpcf_branding['footer'] : '';
$mpcf_logo          = isset( $mpcf_branding['logo_data_uri'] ) ? (string) $mpcf_branding['logo_data_uri'] : '';
$mpcf_rendered_at   = $model->rendered_at();
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
		<div class="mpcf-slip-brand">
			<?php if ( '' !== $mpcf_logo && str_starts_with( $mpcf_logo, 'data:image/' ) ) : ?>
				<img class="mpcf-slip-logo" src="<?php echo esc_attr( $mpcf_logo ); ?>" alt="">
			<?php endif; ?>
			<div class="mpcf-slip-store"><?php echo esc_html( $model->store_name() ); ?></div>
			<?php if ( array() !== $mpcf_address ) : ?>
				<address class="mpcf-slip-address">
					<?php foreach ( $mpcf_address as $mpcf_line ) : ?>
						<?php echo esc_html( (string) $mpcf_line ); ?><br>
					<?php endforeach; ?>
				</address>
			<?php endif; ?>
		</div>
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
			<div class="mpcf-slip-meta">
				<?php
				printf(
					/* translators: 1: fulfillment id, 2: fulfillment state */
					esc_html__( 'Fulfillment #%1$d · %2$s', 'mp-commerce-fulfillment' ),
					$model->fulfillment_id(),
					esc_html( $model->fulfillment_state() )
				);
				?>
			</div>
			<?php if ( null !== $mpcf_rendered_at ) : ?>
				<div class="mpcf-slip-meta">
					<?php
					printf(
						/* translators: %s: render timestamp */
						esc_html__( 'Rendered %s', 'mp-commerce-fulfillment' ),
						esc_html( $mpcf_rendered_at->format( 'Y-m-d H:i' ) )
					);
					?>
				</div>
			<?php endif; ?>
			<div class="mpcf-slip-meta">
				<?php
				printf(
					/* translators: %s: template version */
					esc_html__( 'Template %s', 'mp-commerce-fulfillment' ),
					esc_html( $model->template_version() )
				);
				?>
			</div>
		</div>
	</header>

	<div class="mpcf-slip-addresses">
		<div class="mpcf-slip-ship-to">
			<h2><?php esc_html_e( 'Ship to', 'mp-commerce-fulfillment' ); ?></h2>
			<address>
				<strong><?php echo esc_html( $model->customer_name() ); ?></strong><br>
				<?php foreach ( $model->ship_to_lines() as $mpcf_line ) : ?>
					<?php echo esc_html( $mpcf_line ); ?><br>
				<?php endforeach; ?>
			</address>
		</div>
	</div>

	<?php if ( '' !== $model->customer_instructions() ) : ?>
		<div class="mpcf-slip-instructions">
			<h2><?php esc_html_e( 'Customer instructions', 'mp-commerce-fulfillment' ); ?></h2>
			<p><?php echo esc_html( $model->customer_instructions() ); ?></p>
		</div>
	<?php endif; ?>

	<table class="mpcf-slip-items">
		<thead>
			<tr>
				<th><?php esc_html_e( 'SKU', 'mp-commerce-fulfillment' ); ?></th>
				<th><?php esc_html_e( 'Item', 'mp-commerce-fulfillment' ); ?></th>
				<th class="mpcf-slip-qty"><?php esc_html_e( 'Ordered', 'mp-commerce-fulfillment' ); ?></th>
				<th class="mpcf-slip-qty"><?php esc_html_e( 'Packed', 'mp-commerce-fulfillment' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $model->items() as $mpcf_item ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $mpcf_item['sku'] ); ?></td>
					<td><?php echo esc_html( (string) $mpcf_item['name'] ); ?></td>
					<td class="mpcf-slip-qty"><?php echo esc_html( (string) $mpcf_item['qty_ordered'] ); ?></td>
					<td class="mpcf-slip-qty"><?php echo esc_html( (string) ( $mpcf_item['qty_packed'] ?? 0 ) ); ?></td>
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

	<?php if ( '' !== $mpcf_footer ) : ?>
		<footer class="mpcf-slip-footer"><?php echo esc_html( $mpcf_footer ); ?></footer>
	<?php endif; ?>
</body>
</html>
