<?php
/**
 * Card: dashboard settings (the Gift cards tab). The script fills in the saved value.
 *
 * Variables: $ctx, $ids, $args, $entry.
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

$odoowoo_s   = static function ( string $key ) use ( $entry ): string {
	return esc_html( isset( $entry['strings'][ $key ] ) ? (string) $entry['strings'][ $key ] : '' );
};
$odoowoo_uid = wp_unique_id( 'odoowoo-dashboard-settings-' );

?>
<form class="odoowoo-form" novalidate>
	<div class="odoowoo-field odoowoo-field--check">
		<input type="checkbox" id="<?php echo esc_attr( $odoowoo_uid ); ?>-gift" name="show_gift_cards" aria-describedby="<?php echo esc_attr( $odoowoo_uid ); ?>-gift-help" />
		<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-gift"><?php echo $odoowoo_s( 'enableGiftCards' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the closure. ?></label>
		<p class="odoowoo-help" id="<?php echo esc_attr( $odoowoo_uid ); ?>-gift-help"><?php echo $odoowoo_s( 'giftCardsHelp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	</div>
	<div class="odoowoo-actions">
		<button type="submit" class="odoowoo-button"><?php echo $odoowoo_s( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
	</div>
	<div class="odoowoo-form-status" role="status" aria-live="polite"></div>
</form>
