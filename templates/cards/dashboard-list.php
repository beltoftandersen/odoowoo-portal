<?php
/**
 * Card: dashboard list. The script fills it in from GET /licenses (or the support summary).
 *
 * Variables: $ctx, $ids, $args.
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="odoowoo-list">
	<p class="odoowoo-list-status" role="status" aria-live="polite"><?php esc_html_e( 'Loading your dashboards…', 'odoowoo-portal' ); ?></p>
	<div class="odoowoo-list-body"></div>
	<p class="odoowoo-list-actions"><button type="button" class="odoowoo-button odoowoo-new" hidden><?php esc_html_e( 'New dashboard', 'odoowoo-portal' ); ?></button></p>
</div>
