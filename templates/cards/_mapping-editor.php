<?php
/**
 * The shared markup of the six mapping editors. The rows are drawn by mapping-editor.js from the
 * config printed in data-config; the saved list is read from Django when the card opens.
 *
 * Variables: $ctx, $odoowoo_card (the card name).
 *
 * @package OdooWooPortal
 */

use OdooWooPortal\Cards\Mappings;

defined( 'ABSPATH' ) || exit;

$odoowoo_config = Mappings::config( (string) $odoowoo_card );
$odoowoo_read   = $ctx->calls_allowed();
$odoowoo_write  = $ctx->can_write();

?>
<div class="odoowoo-mapping" data-config="<?php echo esc_attr( (string) wp_json_encode( $odoowoo_config ) ); ?>">
	<?php if ( array() !== $odoowoo_config['loaders'] ) : ?>
		<p class="odoowoo-mapping__loaders">
			<?php foreach ( $odoowoo_config['loaders'] as $odoowoo_i => $odoowoo_loader ) : ?>
				<button type="button" class="odoowoo-button odoowoo-button--secondary" data-loader="<?php echo esc_attr( (string) $odoowoo_i ); ?>" data-route="<?php echo esc_attr( $odoowoo_loader['route'] ); ?>"<?php echo ( ! $odoowoo_read || ( $odoowoo_loader['writes'] && ! $odoowoo_write ) ) ? ' disabled' : ''; ?>><?php echo esc_html( $odoowoo_loader['label'] ); ?></button>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>
	<form class="odoowoo-form odoowoo-form--mapping" novalidate>
		<div class="odoowoo-mapping__rows"></div>
		<div class="odoowoo-actions">
			<button type="button" class="odoowoo-button odoowoo-button--secondary" data-action="add-row"<?php echo $odoowoo_write ? '' : ' disabled'; ?>><?php echo esc_html( Mappings::strings()['addRow'] ); ?></button>
			<button type="submit" class="odoowoo-button" disabled><?php echo esc_html( Mappings::strings()['save'] ); ?></button>
		</div>
		<div class="odoowoo-form-status" role="status" aria-live="polite"></div>
	</form>
</div>
