<?php
/**
 * Card: manual sync (brands, categories, products, stock, prices, barcodes, orders). The script
 * resumes a running sync, starts new ones and shows the progress.
 *
 * Variables: $ctx, $ids, $args, $entry.
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

$odoowoo_off = $ctx->can_write() ? '' : ' disabled';
$odoowoo_uid = wp_unique_id( 'odoowoo-sync-' );

?>
<div class="odoowoo-sync" data-resume="sync/active">
	<section class="odoowoo-sync-section" data-section="brands">
		<h3 class="odoowoo-sync-section__title"><?php esc_html_e( 'Brands', 'odoowoo-portal' ); ?></h3>
		<p class="odoowoo-help" data-brand-notice hidden><?php esc_html_e( 'Brand sync is not part of your plan.', 'odoowoo-portal' ); ?></p>
		<div class="odoowoo-actions">
			<button type="button" class="odoowoo-button" data-kind="brands"<?php echo $odoowoo_off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute. ?>><?php esc_html_e( 'Sync brands', 'odoowoo-portal' ); ?></button>
		</div>
	</section>

	<section class="odoowoo-sync-section" data-section="categories">
		<h3 class="odoowoo-sync-section__title"><?php esc_html_e( 'Categories', 'odoowoo-portal' ); ?></h3>
		<div class="odoowoo-actions">
			<button type="button" class="odoowoo-button" data-kind="categories"<?php echo $odoowoo_off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Sync categories', 'odoowoo-portal' ); ?></button>
		</div>
	</section>

	<section class="odoowoo-sync-section" data-section="products">
		<h3 class="odoowoo-sync-section__title"><?php esc_html_e( 'Products, stock, prices and barcodes', 'odoowoo-portal' ); ?></h3>
		<div class="odoowoo-field">
			<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-products"><?php esc_html_e( 'Product ids, separated by commas', 'odoowoo-portal' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $odoowoo_uid ); ?>-products" name="product_ids" inputmode="numeric" autocomplete="off" placeholder="<?php echo esc_attr__( 'Example: 123, 456, 789', 'odoowoo-portal' ); ?>" aria-describedby="<?php echo esc_attr( $odoowoo_uid ); ?>-products-help"<?php echo $odoowoo_off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
			<p class="odoowoo-help" id="<?php echo esc_attr( $odoowoo_uid ); ?>-products-help"><?php esc_html_e( 'Leave empty to sync all products.', 'odoowoo-portal' ); ?></p>
		</div>
		<div class="odoowoo-actions">
			<button type="button" class="odoowoo-button" data-kind="products"<?php echo $odoowoo_off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Sync products', 'odoowoo-portal' ); ?></button>
			<button type="button" class="odoowoo-button" data-kind="stock"<?php echo $odoowoo_off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Sync stock', 'odoowoo-portal' ); ?></button>
			<button type="button" class="odoowoo-button" data-kind="prices"<?php echo $odoowoo_off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Sync prices', 'odoowoo-portal' ); ?></button>
			<button type="button" class="odoowoo-button" data-kind="barcodes"<?php echo $odoowoo_off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Sync barcodes', 'odoowoo-portal' ); ?></button>
		</div>
		<p class="odoowoo-sync-error odoowoo-field-error" data-error-for="product_ids" role="alert" hidden></p>
	</section>

	<section class="odoowoo-sync-section" data-section="orders">
		<h3 class="odoowoo-sync-section__title"><?php esc_html_e( 'Orders', 'odoowoo-portal' ); ?></h3>
		<div class="odoowoo-field">
			<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-orders"><?php esc_html_e( 'Order ids, separated by commas', 'odoowoo-portal' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $odoowoo_uid ); ?>-orders" name="order_ids" inputmode="numeric" autocomplete="off" placeholder="<?php echo esc_attr__( 'Example: 1051, 1052', 'odoowoo-portal' ); ?>" aria-describedby="<?php echo esc_attr( $odoowoo_uid ); ?>-orders-help"<?php echo $odoowoo_off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
			<p class="odoowoo-help" id="<?php echo esc_attr( $odoowoo_uid ); ?>-orders-help"><?php esc_html_e( 'Or choose a start and end date. Order ids win when both are given.', 'odoowoo-portal' ); ?></p>
		</div>
		<div class="odoowoo-sync-dates">
			<div class="odoowoo-field">
				<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-from"><?php esc_html_e( 'From', 'odoowoo-portal' ); ?></label>
				<input type="date" id="<?php echo esc_attr( $odoowoo_uid ); ?>-from" name="from"<?php echo $odoowoo_off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
			</div>
			<div class="odoowoo-field">
				<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-to"><?php esc_html_e( 'To', 'odoowoo-portal' ); ?></label>
				<input type="date" id="<?php echo esc_attr( $odoowoo_uid ); ?>-to" name="to"<?php echo $odoowoo_off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
			</div>
		</div>
		<div class="odoowoo-actions">
			<button type="button" class="odoowoo-button" data-kind="orders"<?php echo $odoowoo_off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Sync orders', 'odoowoo-portal' ); ?></button>
		</div>
		<p class="odoowoo-sync-error odoowoo-field-error" data-error-for="order_ids" role="alert" hidden></p>
		<p class="odoowoo-sync-error odoowoo-field-error" data-error-for="from_date" role="alert" hidden></p>
	</section>

	<p class="odoowoo-sync-message" role="status" aria-live="polite" hidden></p>
	<div class="odoowoo-sync-result" role="status" aria-live="polite"></div>
	<div class="odoowoo-sync-items"></div>
</div>
