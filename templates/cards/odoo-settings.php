<?php
/**
 * Card: Odoo settings. The script fills in the values, applies the dependencies (a setting that
 * needs a mapping or a plan is disabled with its reason) and saves the whole settings object.
 *
 * Variables: $ctx, $ids, $args, $entry.
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

$odoowoo_strings = isset( $entry['strings'] ) && is_array( $entry['strings'] ) ? $entry['strings'] : array();
$odoowoo_s       = static function ( string $key ) use ( $odoowoo_strings ): string {
	return isset( $odoowoo_strings[ $key ] ) ? (string) $odoowoo_strings[ $key ] : '';
};
$odoowoo_uid     = wp_unique_id( 'odoowoo-odoo-settings-' );

/**
 * The help button and its text, for one setting. The text is in the DOM, not in a title.
 *
 * @param string $name Setting name.
 */
$odoowoo_help = static function ( string $name ) use ( $odoowoo_s, $odoowoo_uid ): void {
	$label = $odoowoo_s( 'label_' . $name );
	$id    = $odoowoo_uid . '-' . $name . '-help';
	?>
	<span class="odoowoo-tipwrap" data-open="0">
		<button type="button" class="odoowoo-tip-button" data-help-for="<?php echo esc_attr( $name ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>" aria-expanded="false" aria-label="<?php echo esc_attr( sprintf( $odoowoo_s( 'helpFor' ), $label ) ); ?>"><span aria-hidden="true">?</span></button>
		<span class="odoowoo-tip" id="<?php echo esc_attr( $id ); ?>" role="tooltip"><?php echo esc_html( $odoowoo_s( 'help_' . $name ) ); ?></span>
	</span>
	<?php
};

/**
 * The place for a dependency reason and for Django's message about this setting.
 *
 * @param string $name Setting name.
 */
$odoowoo_notes = static function ( string $name ) use ( $odoowoo_uid ): void {
	?>
	<span class="odoowoo-reason" id="<?php echo esc_attr( $odoowoo_uid . '-' . $name . '-reason' ); ?>" data-reason-for="<?php echo esc_attr( $name ); ?>" hidden></span>
	<p class="odoowoo-field-error" id="<?php echo esc_attr( $odoowoo_uid . '-' . $name . '-error' ); ?>" data-error-for="<?php echo esc_attr( $name ); ?>" role="alert" hidden></p>
	<?php
};

/**
 * One checkbox row.
 *
 * @param string $name Setting name.
 */
$odoowoo_check = static function ( string $name ) use ( $odoowoo_s, $odoowoo_uid, $odoowoo_help, $odoowoo_notes ): void {
	$id = $odoowoo_uid . '-' . $name;
	?>
	<div class="odoowoo-field odoowoo-field--check" data-field-wrap="<?php echo esc_attr( $name ); ?>">
		<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" aria-describedby="<?php echo esc_attr( $id . '-reason ' . $id . '-error' ); ?>" />
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $odoowoo_s( 'label_' . $name ) ); ?></label>
		<?php $odoowoo_help( $name ); ?>
		<?php $odoowoo_notes( $name ); ?>
	</div>
	<?php
};

/**
 * One select or text row; $control prints the input itself.
 *
 * @param string   $name    Setting name.
 * @param callable $control Prints the control, given its id and aria-describedby.
 */
$odoowoo_row = static function ( string $name, callable $control ) use ( $odoowoo_s, $odoowoo_uid, $odoowoo_help, $odoowoo_notes ): void {
	$id = $odoowoo_uid . '-' . $name;
	?>
	<div class="odoowoo-field" data-field-wrap="<?php echo esc_attr( $name ); ?>">
		<span class="odoowoo-field__head">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $odoowoo_s( 'label_' . $name ) ); ?></label>
			<?php $odoowoo_help( $name ); ?>
		</span>
		<?php $control( $id, $id . '-reason ' . $id . '-error' ); ?>
		<?php $odoowoo_notes( $name ); ?>
	</div>
	<?php
};

?>
<form class="odoowoo-form odoowoo-form--settings" novalidate>
	<div class="odoowoo-group">
		<?php
		foreach ( array( 'enable_auto_payment', 'enable_auto_payment_cn', 'enable_set_invoice_status', 'enable_reset_all_deliveries', 'enable_set_deliveries_to_waiting', 'enable_auto_complete_deliveries', 'price_includes_tax', 'order_incl_tax' ) as $odoowoo_name ) {
			$odoowoo_check( $odoowoo_name );
		}
		?>
	</div>
	<div class="odoowoo-group">
		<?php
		foreach ( array( 'enable_brand_sync', 'enable_category_sync', 'enable_product_name_sync', 'enable_tag_sync', 'enable_image_sync', 'enable_price_sync', 'enable_barcode_sync' ) as $odoowoo_name ) {
			$odoowoo_check( $odoowoo_name );
		}
		?>
	</div>
	<?php
	$odoowoo_row(
		'capture_stage',
		static function ( string $id, string $described ) use ( $odoowoo_s ): void {
			?>
			<select id="<?php echo esc_attr( $id ); ?>" name="capture_stage" aria-describedby="<?php echo esc_attr( $described ); ?>">
				<option value="pending"><?php echo esc_html( $odoowoo_s( 'optPending' ) ); ?></option>
				<option value="on_hold"><?php echo esc_html( $odoowoo_s( 'optOnHold' ) ); ?></option>
				<option value="processing"><?php echo esc_html( $odoowoo_s( 'optProcessing' ) ); ?></option>
			</select>
			<?php
		}
	);
	$odoowoo_check( 'confirm_pending_orders' );
	$odoowoo_row(
		'quantity_settings',
		static function ( string $id, string $described ) use ( $odoowoo_s ): void {
			?>
			<select id="<?php echo esc_attr( $id ); ?>" name="quantity_settings" aria-describedby="<?php echo esc_attr( $described ); ?>">
				<option value="on_hand"><?php echo esc_html( $odoowoo_s( 'optOnHand' ) ); ?></option>
				<option value="forecast"><?php echo esc_html( $odoowoo_s( 'optForecast' ) ); ?></option>
				<option value="available"><?php echo esc_html( $odoowoo_s( 'optAvailable' ) ); ?></option>
			</select>
			<?php
		}
	);
	$odoowoo_row(
		'odoo_order_pricelist_id',
		static function ( string $id, string $described ) use ( $odoowoo_s ): void {
			?>
			<select id="<?php echo esc_attr( $id ); ?>" name="odoo_order_pricelist_id" aria-describedby="<?php echo esc_attr( $described ); ?>">
				<option value=""><?php echo esc_html( $odoowoo_s( 'letOdooDecide' ) ); ?></option>
			</select>
			<span><button type="button" class="odoowoo-button odoowoo-button--secondary" data-action="load-pricelists"><?php echo esc_html( $odoowoo_s( 'loadPricelists' ) ); ?></button></span>
			<?php
		}
	);
	foreach ( array( 'stock_sku_meta_keys', 'stock_units_meta_keys' ) as $odoowoo_name ) {
		$odoowoo_row(
			$odoowoo_name,
			static function ( string $id, string $described ) use ( $odoowoo_name ): void {
				?>
				<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $odoowoo_name ); ?>" maxlength="255" autocomplete="off" spellcheck="false" aria-describedby="<?php echo esc_attr( $described ); ?>" />
				<?php
			}
		);
	}
	?>
	<div class="odoowoo-actions">
		<button type="submit" class="odoowoo-button" disabled><?php echo esc_html( $odoowoo_s( 'save' ) ); ?></button>
	</div>
	<div class="odoowoo-form-status" role="status" aria-live="polite"></div>
</form>
