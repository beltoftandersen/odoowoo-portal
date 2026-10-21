<?php
/**
 * Card: gift cards (booking of gift card sales and redemptions, and the expiry sweep). The script
 * fills in the values and the pickers; Django checks every id against the lists it stored.
 *
 * Variables: $ctx, $ids, $args, $entry.
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

$odoowoo_uid   = wp_unique_id( 'odoowoo-gift-' );
$odoowoo_write = $ctx->can_write();
$odoowoo_read  = $ctx->calls_allowed();

/**
 * One text input with its label, help text and a place for Django's message. The label and the
 * help are already translated and escaped by the caller.
 */
$odoowoo_text = static function ( string $name, string $label, string $help, string $placeholder ) use ( $odoowoo_uid ): void {
	$id = $odoowoo_uid . '-' . $name;
	?>
	<div class="odoowoo-field">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?></label>
		<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" autocomplete="off" placeholder="<?php echo esc_attr( $placeholder ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-help <?php echo esc_attr( $id ); ?>-error" />
		<p class="odoowoo-help" id="<?php echo esc_attr( $id ); ?>-help"><?php echo $help; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
		<p class="odoowoo-field-error" id="<?php echo esc_attr( $id ); ?>-error" data-error-for="<?php echo esc_attr( $name ); ?>" role="alert" hidden></p>
	</div>
	<?php
};

/**
 * One picker (options are filled by the script from the stored lists).
 */
$odoowoo_select = static function ( string $name, string $label, string $help ) use ( $odoowoo_uid ): void {
	$id = $odoowoo_uid . '-' . $name;
	?>
	<div class="odoowoo-field">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?></label>
		<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-help <?php echo esc_attr( $id ); ?>-error"></select>
		<p class="odoowoo-help" id="<?php echo esc_attr( $id ); ?>-help"><?php echo $help; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
		<p class="odoowoo-field-error" id="<?php echo esc_attr( $id ); ?>-error" data-error-for="<?php echo esc_attr( $name ); ?>" role="alert" hidden></p>
	</div>
	<?php
};

/**
 * One switch.
 */
$odoowoo_check = static function ( string $name, string $label ) use ( $odoowoo_uid ): void {
	$id = $odoowoo_uid . '-' . $name;
	?>
	<div class="odoowoo-field odoowoo-field--check">
		<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-error" />
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?></label>
		<p class="odoowoo-field-error" id="<?php echo esc_attr( $id ); ?>-error" data-error-for="<?php echo esc_attr( $name ); ?>" role="alert" hidden></p>
	</div>
	<?php
};

?>
<form class="odoowoo-form odoowoo-gift-cards" novalidate>
	<p class="odoowoo-help"><?php esc_html_e( 'A gift card is a multi-purpose voucher: it is sold without VAT and taxed when it is spent. With this on, a card sold in WooCommerce is invoiced at the exempt tax below and booked against the gift card liability account, and the value spent from a paid card is settled from the gift card journal instead of the shopper\'s payment method, so the goods still carry their own VAT.', 'odoowoo-portal' ); ?></p>

	<div class="odoowoo-actions">
		<button type="button" class="odoowoo-button odoowoo-button--secondary" data-action="load-lists"<?php echo ( $odoowoo_read && $odoowoo_write ) ? '' : ' disabled'; ?>><?php esc_html_e( 'Load taxes, journals and accounts', 'odoowoo-portal' ); ?></button>
	</div>

	<div class="odoowoo-note">
		<p><strong><?php esc_html_e( 'Before turning this on, three things have to exist in Odoo.', 'odoowoo-portal' ); ?></strong></p>
		<p><?php esc_html_e( 'A 0% sale tax with Moloni exemption reason M99, separate from the IVA0 used for intra-EU sales.', 'odoowoo-portal' ); ?></p>
		<p><?php esc_html_e( 'A liability account on the gift card product category, so selling a card is not booked as revenue twice.', 'odoowoo-portal' ); ?></p>
		<p><?php esc_html_e( 'A journal that settles against that same account, which is where redeemed card value is paid from.', 'odoowoo-portal' ); ?></p>
	</div>

	<?php $odoowoo_check( 'enabled', esc_html__( 'Book gift cards this way', 'odoowoo-portal' ) ); ?>

	<?php
	$odoowoo_select( 'sale_tax_id', esc_html__( 'Exempt tax for card sales', 'odoowoo-portal' ), esc_html__( 'Every sale tax is listed, not only the 0% ones, so a wrong choice is visible rather than hidden. Pick the M99 one.', 'odoowoo-portal' ) );
	$odoowoo_select( 'redeem_journal_id', esc_html__( 'Gift card journal', 'odoowoo-portal' ), esc_html__( 'Redeemed card value is paid from this journal, which must settle against the gift card liability account.', 'odoowoo-portal' ) );
	$odoowoo_text( 'sale_line_meta_key', esc_html__( 'Card amount line meta key', 'odoowoo-portal' ), esc_html__( 'The order line meta the gift card plugin writes the card value into. A line carrying it is a card sale.', 'odoowoo-portal' ), '_bgcw_amount' );
	$odoowoo_text( 'sale_product_codes', esc_html__( 'Gift card product codes', 'odoowoo-portal' ), esc_html__( 'Comma separated SKUs that are gift cards even when the line meta is missing. Leave empty to rely on the meta key alone.', 'odoowoo-portal' ), 'GC-100, GC-250' );
	$odoowoo_text( 'redeem_order_meta_key', esc_html__( 'Redemption order meta key', 'odoowoo-portal' ), esc_html__( 'The order meta listing which card paid how much of this order.', 'odoowoo-portal' ), '_bgcw_deducted_amounts' );
	$odoowoo_text( 'card_api_path', esc_html__( 'Card lookup API path', 'odoowoo-portal' ), esc_html__( 'The WordPress REST path the card code is appended to, used to ask whether the card was paid for.', 'odoowoo-portal' ), 'wc-bgcw/v1/gift-cards/code/' );
	$odoowoo_text( 'paid_flag_field', esc_html__( 'Paid flag field', 'odoowoo-portal' ), esc_html__( 'The field in that response saying the card was bought rather than given away. Only a paid card is settled from the journal.', 'odoowoo-portal' ), 'is_paid' );
	?>

	<fieldset class="odoowoo-gift-expiry">
		<legend><?php esc_html_e( 'Expired cards', 'odoowoo-portal' ); ?></legend>
		<p class="odoowoo-help"><?php esc_html_e( 'A card that expires without being spent leaves its value in the gift card liability account for good: money the shop has, booked as money it owes. With this on, a nightly sweep releases each expired card with one journal entry and records that it did, so no card is released twice.', 'odoowoo-portal' ); ?></p>

		<div class="odoowoo-note">
			<p><strong><?php esc_html_e( 'What each release posts.', 'odoowoo-portal' ); ?></strong></p>
			<p><?php esc_html_e( 'Debit: the gift card liability account, by the balance left on the card. This is the account the sale credited, coming back down.', 'odoowoo-portal' ); ?></p>
			<p><?php esc_html_e( 'Credit: the breakage income account, by the same amount. This is where money the shop was paid for goods nobody collected turns into income.', 'odoowoo-portal' ); ?></p>
			<p><?php esc_html_e( 'The two lines are equal, so the entry carries no VAT and shows on no tax report.', 'odoowoo-portal' ); ?></p>
			<p><?php esc_html_e( 'This cannot be turned on until Book gift cards this way is on, because the liability account it releases from holds nothing otherwise. Nothing is released until a daily task is also installed on the server.', 'odoowoo-portal' ); ?></p>
		</div>

		<?php
		$odoowoo_check( 'enable_expiry_sweep', esc_html__( 'Release the value of expired cards', 'odoowoo-portal' ) );
		$odoowoo_select( 'expiry_debit_account_id', esc_html__( 'Account to debit, the gift card liability', 'odoowoo-portal' ), esc_html__( 'The same account the gift card product category is booked to, so a release takes back exactly what the sale put there. This has to be the account the gift card journal above settles to, which is where a redemption already posts. Saving checks the journal in Odoo and refuses if the two disagree, because every other check here would pass while the money came out of the wrong account.', 'odoowoo-portal' ) );
		$odoowoo_select( 'expiry_credit_account_id', esc_html__( 'Account to credit, breakage income', 'odoowoo-portal' ), esc_html__( 'An income account of its own, so released card value can be told apart from sales at a glance.', 'odoowoo-portal' ) );
		$odoowoo_select( 'expiry_journal_id', esc_html__( 'Journal for releases', 'odoowoo-portal' ), esc_html__( 'Where the entry lands. Usually the miscellaneous operations journal, not the gift card journal above.', 'odoowoo-portal' ) );
		?>

		<div class="odoowoo-field">
			<label for="<?php echo esc_attr( $odoowoo_uid ); ?>-expiry_vat"><?php esc_html_e( 'VAT on a release', 'odoowoo-portal' ); ?></label>
			<select id="<?php echo esc_attr( $odoowoo_uid ); ?>-expiry_vat" name="expiry_vat" aria-describedby="<?php echo esc_attr( $odoowoo_uid ); ?>-expiry_vat-help" disabled>
				<option value="none" selected><?php esc_html_e( 'No VAT', 'odoowoo-portal' ); ?></option>
			</select>
			<p class="odoowoo-help" id="<?php echo esc_attr( $odoowoo_uid ); ?>-expiry_vat-help"><?php esc_html_e( 'Fixed for now. Splitting a release into net and VAT is not implemented, because the VAT would be posted without the tags Odoo tax reports and SAF-T are read from, and so would appear on neither.', 'odoowoo-portal' ); ?></p>
		</div>

		<?php
		$odoowoo_text( 'expiry_api_path', esc_html__( 'Card listing API path', 'odoowoo-portal' ), esc_html__( 'The WordPress REST path the sweep pages through to find expired cards.', 'odoowoo-portal' ), 'wc-bgcw/v1/gift-cards' );
		$odoowoo_text( 'expiry_status_values', esc_html__( 'Statuses that mean expired', 'odoowoo-portal' ), esc_html__( 'Comma separated, matched without regard to capitals. Which word a dead card carries is the plugin\'s business and can be more than one.', 'odoowoo-portal' ), 'expired' );
		$odoowoo_text( 'expiry_min_balance', esc_html__( 'Smallest balance to release', 'odoowoo-portal' ), esc_html__( 'Balances at or below this are left alone. A cent of dust on a dead card is not worth an entry with a name on it. Zero releases everything.', 'odoowoo-portal' ), '0.00' );
		?>
	</fieldset>

	<div class="odoowoo-actions">
		<button type="submit" class="odoowoo-button" disabled><?php esc_html_e( 'Save', 'odoowoo-portal' ); ?></button>
	</div>
	<div class="odoowoo-form-status" role="status" aria-live="polite"></div>
</form>
