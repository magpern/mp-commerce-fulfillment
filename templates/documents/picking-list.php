<?php
/**
 * Picking list template. Rendered by `Documents\HtmlRenderer` with a
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

$mpcf_pick_css_path = __DIR__ . '/picking-list.css';
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
<title><?php esc_html_e( 'Picking list', 'mp-commerce-fulfillment' ); ?> <?php echo esc_html( $model->order_number() ); ?></title>
<style>
<?php echo file_exists( $mpcf_pick_css_path ) ? file_get_contents( $mpcf_pick_css_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled local CSS. ?>
</style>
</head>
<body>
	<header class="mpcf-pick-header">
		<div class="mpcf-pick-brand">
			<?php if ( '' !== $mpcf_logo && str_starts_with( $mpcf_logo, 'data:image/' ) ) : ?>
				<img class="mpcf-pick-logo" src="<?php echo esc_attr( $mpcf_logo ); ?>" alt="">
			<?php endif; ?>
			<div class="mpcf-pick-store"><?php echo esc_html( $model->store_name() ); ?></div>
			<?php if ( array() !== $mpcf_address ) : ?>
				<address class="mpcf-pick-address">
					<?php foreach ( $mpcf_address as $mpcf_line ) : ?>
						<?php echo esc_html( (string) $mpcf_line ); ?><br>
					<?php endforeach; ?>
				</address>
			<?php endif; ?>
		</div>
		<div>
			<div class="mpcf-pick-title"><?php esc_html_e( 'Picking list', 'mp-commerce-fulfillment' ); ?></div>
			<div class="mpcf-pick-order-number">
				<?php
				printf(
					/* translators: %s: order number */
					esc_html__( 'Order %s', 'mp-commerce-fulfillment' ),
					esc_html( $model->order_number() )
				);
				?>
			</div>
			<div class="mpcf-pick-meta">
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
				<div class="mpcf-pick-meta">
					<?php
					printf(
						/* translators: %s: render timestamp */
						esc_html__( 'Rendered %s', 'mp-commerce-fulfillment' ),
						esc_html( $mpcf_rendered_at->format( 'Y-m-d H:i' ) )
					);
					?>
				</div>
			<?php endif; ?>
			<div class="mpcf-pick-meta">
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

	<?php if ( '' !== $model->customer_instructions() ) : ?>
		<div class="mpcf-pick-instructions">
			<h2><?php esc_html_e( 'Customer instructions', 'mp-commerce-fulfillment' ); ?></h2>
			<p><?php echo esc_html( $model->customer_instructions() ); ?></p>
		</div>
	<?php endif; ?>

	<table class="mpcf-pick-items">
		<thead>
			<tr>
				<th><?php esc_html_e( 'SKU', 'mp-commerce-fulfillment' ); ?></th>
				<th><?php esc_html_e( 'Item', 'mp-commerce-fulfillment' ); ?></th>
				<th><?php esc_html_e( 'Location', 'mp-commerce-fulfillment' ); ?></th>
				<th class="mpcf-pick-qty"><?php esc_html_e( 'Ordered', 'mp-commerce-fulfillment' ); ?></th>
				<th class="mpcf-pick-qty"><?php esc_html_e( 'To pick', 'mp-commerce-fulfillment' ); ?></th>
				<th class="mpcf-pick-qty"><?php esc_html_e( 'Picked', 'mp-commerce-fulfillment' ); ?></th>
				<th class="mpcf-pick-qty"><?php esc_html_e( 'Remaining', 'mp-commerce-fulfillment' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $model->items() as $mpcf_item ) : ?>
				<tr>
					<td>
						<?php echo esc_html( (string) ( $mpcf_item['sku'] ?? '' ) ); ?>
						<?php if ( ! empty( $mpcf_item['barcode_payload'] ) ) : ?>
							<div class="mpcf-pick-item-barcode">
								<?php
								echo \MPCF\Documents\Barcode\DocumentBarcodeMarkup::render_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup helper escapes payload/text; SVG is deterministic.
									(string) $mpcf_item['barcode_payload'],
									(string) ( $mpcf_item['sku'] ?? '' )
								);
								?>
							</div>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( (string) ( $mpcf_item['name'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $mpcf_item['location_snapshot'] ?? '' ) ); ?></td>
					<td class="mpcf-pick-qty"><?php echo esc_html( (string) ( $mpcf_item['qty_ordered'] ?? 0 ) ); ?></td>
					<td class="mpcf-pick-qty"><?php echo esc_html( (string) ( $mpcf_item['qty_to_pick'] ?? 0 ) ); ?></td>
					<td class="mpcf-pick-qty"><?php echo esc_html( (string) ( $mpcf_item['qty_picked'] ?? 0 ) ); ?></td>
					<td class="mpcf-pick-qty"><?php echo esc_html( (string) ( $mpcf_item['qty_remaining'] ?? 0 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<div class="mpcf-pick-barcode">
		<?php
		echo \MPCF\Documents\Barcode\DocumentBarcodeMarkup::render_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup helper escapes payload/text; SVG is deterministic.
			$model->barcode_payload(),
			$model->order_number()
		);
		?>
	</div>

	<?php if ( '' !== $mpcf_footer ) : ?>
		<footer class="mpcf-pick-footer"><?php echo esc_html( $mpcf_footer ); ?></footer>
	<?php endif; ?>
</body>
</html>
