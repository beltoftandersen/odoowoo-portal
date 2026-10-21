<?php
/**
 * Card: WooCommerce connection. The script fills in the saved values and the flags; the secret is
 * never printed.
 *
 * Variables: $ctx, $ids, $args, $entry.
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

$odoowoo_s   = static function ( string $key ) use ( $entry ): string {
	return esc_html( isset( $entry['strings'][ $key ] ) ? (string) $entry['strings'][ $key ] : '' );
};
$odoowoo_uid = wp_unique_id( 'odoowoo-woo-' );

?>
<form class="odoowoo-form" novalidate>
	<div class="odoowoo-field">
		<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-url"><?php echo $odoowoo_s( 'storeUrl' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the closure. ?></label>
		<input type="url" id="<?php echo esc_attr( $odoowoo_uid ); ?>-url" name="woocommerce_url" autocomplete="off" placeholder="https://" />
	</div>
	<div class="odoowoo-field">
		<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-key"><?php echo $odoowoo_s( 'consumerKey' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
		<input type="text" id="<?php echo esc_attr( $odoowoo_uid ); ?>-key" name="consumer_key" autocomplete="off" spellcheck="false" />
	</div>
	<div class="odoowoo-field">
		<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-secret"><?php echo $odoowoo_s( 'consumerSecret' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
		<span class="odoowoo-secret">
			<input type="password" id="<?php echo esc_attr( $odoowoo_uid ); ?>-secret" name="consumer_secret" autocomplete="new-password" spellcheck="false" />
			<button type="button" class="odoowoo-button odoowoo-button--secondary odoowoo-reveal" aria-controls="<?php echo esc_attr( $odoowoo_uid ); ?>-secret" aria-pressed="false"><?php echo $odoowoo_s( 'show' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		</span>
	</div>
	<p class="odoowoo-help" id="<?php echo esc_attr( $odoowoo_uid ); ?>-help"><?php echo $odoowoo_s( 'verifyHelp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	<p class="odoowoo-badges">
		<span class="odoowoo-badge" data-badge="saved" hidden></span>
		<span class="odoowoo-badge" data-badge="verified" hidden></span>
		<span class="odoowoo-badge" data-badge="webhooks" hidden></span>
	</p>
	<div class="odoowoo-actions">
		<button type="button" class="odoowoo-button odoowoo-button--secondary" data-action="verify" aria-describedby="<?php echo esc_attr( $odoowoo_uid ); ?>-help"><?php echo $odoowoo_s( 'verify' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		<button type="submit" class="odoowoo-button"><?php echo $odoowoo_s( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		<button type="button" class="odoowoo-button odoowoo-button--secondary" data-action="transfer" disabled aria-describedby="<?php echo esc_attr( $odoowoo_uid ); ?>-transfer-help"><?php echo $odoowoo_s( 'transfer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
	</div>
	<p class="odoowoo-help odoowoo-unsaved-note" role="status" aria-live="polite" hidden></p>
	<p class="odoowoo-help" id="<?php echo esc_attr( $odoowoo_uid ); ?>-transfer-help"><?php echo $odoowoo_s( 'transferHelp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	<div class="odoowoo-form-status" role="status" aria-live="polite"></div>
</form>
