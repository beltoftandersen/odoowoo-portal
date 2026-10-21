<?php
/**
 * Card: WooCommerce settings (stock update, close orders, order prefix). The script fills in the
 * values and the prefix options.
 *
 * Variables: $ctx, $ids, $args, $entry.
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

$odoowoo_s   = static function ( string $key ) use ( $entry ): string {
	return esc_html( isset( $entry['strings'][ $key ] ) ? (string) $entry['strings'][ $key ] : '' );
};
$odoowoo_uid = wp_unique_id( 'odoowoo-woo-settings-' );

?>
<form class="odoowoo-form" novalidate>
	<div class="odoowoo-field odoowoo-field--check">
		<input type="checkbox" id="<?php echo esc_attr( $odoowoo_uid ); ?>-stock" name="enable_stock_update" />
		<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-stock"><?php echo $odoowoo_s( 'enableStock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the closure. ?></label>
	</div>
	<div class="odoowoo-field odoowoo-field--check">
		<input type="checkbox" id="<?php echo esc_attr( $odoowoo_uid ); ?>-close" name="change_status_delivery" />
		<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-close"><?php echo $odoowoo_s( 'closeOrders' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
	</div>
	<div class="odoowoo-field">
		<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-prefix"><?php echo $odoowoo_s( 'orderPrefix' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
		<select id="<?php echo esc_attr( $odoowoo_uid ); ?>-prefix" name="prefix" aria-describedby="<?php echo esc_attr( $odoowoo_uid ); ?>-prefix-help"></select>
		<p class="odoowoo-help odoowoo-prefix-note" role="status" aria-live="polite" hidden></p>
		<p class="odoowoo-help" id="<?php echo esc_attr( $odoowoo_uid ); ?>-prefix-help"><?php echo $odoowoo_s( 'prefixHelp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	</div>
	<div class="odoowoo-actions">
		<button type="submit" class="odoowoo-button"><?php echo $odoowoo_s( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
	</div>
	<div class="odoowoo-form-status" role="status" aria-live="polite"></div>
</form>
