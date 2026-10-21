<?php
/**
 * Card: dashboard header: back link, name, state and rename. The script fills in the name.
 *
 * Variables: $ctx, $ids, $args.
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

use OdooWooPortal\Urls;

?>
<div class="odoowoo-header">
	<p class="odoowoo-header__back"><a class="odoowoo-back" href="<?php echo esc_url( Urls::license_list( (int) $ctx->license()['id'] ) ); ?>"><?php esc_html_e( 'Your dashboards', 'odoowoo-portal' ); ?></a></p>
	<div class="odoowoo-header__title">
		<h1 class="odoowoo-dashboard-name" aria-live="polite"><?php esc_html_e( 'Loading…', 'odoowoo-portal' ); ?></h1>
		<span class="odoowoo-state"></span>
		<button type="button" class="odoowoo-button odoowoo-button--secondary odoowoo-rename" hidden><?php esc_html_e( 'Rename', 'odoowoo-portal' ); ?></button>
	</div>
	<div class="odoowoo-header__form"></div>
	<p class="odoowoo-header__status" role="status" aria-live="polite"></p>
</div>
