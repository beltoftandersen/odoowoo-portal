<?php
/**
 * Card: Odoo connection. The script fills in the saved values and the callback key status; the
 * password and the key are never printed.
 *
 * Variables: $ctx, $ids, $args, $entry.
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

$odoowoo_s   = static function ( string $key ) use ( $entry ): string {
	return esc_html( isset( $entry['strings'][ $key ] ) ? (string) $entry['strings'][ $key ] : '' );
};
$odoowoo_uid = wp_unique_id( 'odoowoo-odoo-' );

?>
<form class="odoowoo-form" novalidate>
	<p class="odoowoo-ready"><strong><?php esc_html_e( 'Have ready:', 'odoowoo-portal' ); ?></strong> <?php esc_html_e( 'your Odoo address, the database name, and a user with its password or API key. The OdooWoo Helper module must be installed in Odoo.', 'odoowoo-portal' ); ?></p>
	<div class="odoowoo-field">
		<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-url"><?php echo $odoowoo_s( 'odooUrl' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the closure. ?></label>
		<input type="url" id="<?php echo esc_attr( $odoowoo_uid ); ?>-url" name="odoo_url" autocomplete="off" placeholder="https://" />
	</div>
	<div class="odoowoo-field">
		<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-db"><?php echo $odoowoo_s( 'database' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
		<input type="text" id="<?php echo esc_attr( $odoowoo_uid ); ?>-db" name="odoo_db" autocomplete="off" spellcheck="false" />
	</div>
	<div class="odoowoo-field">
		<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-user"><?php echo $odoowoo_s( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
		<input type="text" id="<?php echo esc_attr( $odoowoo_uid ); ?>-user" name="odoo_user" autocomplete="off" spellcheck="false" />
	</div>
	<div class="odoowoo-field">
		<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-password"><?php echo $odoowoo_s( 'password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
		<span class="odoowoo-secret">
			<input type="password" id="<?php echo esc_attr( $odoowoo_uid ); ?>-password" name="odoo_password" autocomplete="new-password" spellcheck="false" />
			<button type="button" class="odoowoo-button odoowoo-button--secondary odoowoo-reveal" aria-controls="<?php echo esc_attr( $odoowoo_uid ); ?>-password" aria-pressed="false"><?php echo $odoowoo_s( 'show' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		</span>
	</div>
	<p class="odoowoo-help" id="<?php echo esc_attr( $odoowoo_uid ); ?>-help"><?php echo $odoowoo_s( 'verifyHelp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	<p class="odoowoo-helper-status" role="status" aria-live="polite"></p>
	<div class="odoowoo-actions">
		<button type="button" class="odoowoo-button odoowoo-button--secondary" data-action="verify" aria-describedby="<?php echo esc_attr( $odoowoo_uid ); ?>-help"><?php echo $odoowoo_s( 'verify' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		<button type="submit" class="odoowoo-button"><?php echo $odoowoo_s( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
	</div>
	<div class="odoowoo-callbacks">
		<p class="odoowoo-callback-box" role="status" aria-live="polite"></p>
		<button type="button" class="odoowoo-button odoowoo-button--secondary" data-action="callbacks" aria-describedby="<?php echo esc_attr( $odoowoo_uid ); ?>-callback-help"><?php echo $odoowoo_s( 'setup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		<p class="odoowoo-help odoowoo-unsaved-note" role="status" aria-live="polite" hidden></p>
		<p class="odoowoo-help" id="<?php echo esc_attr( $odoowoo_uid ); ?>-callback-help"><?php echo $odoowoo_s( 'callbackHelp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	</div>
	<div class="odoowoo-form-status" role="status" aria-live="polite"></div>
</form>
