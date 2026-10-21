<?php
/**
 * Card: sync logs (the order syncs of the dashboard, newest first). The script fills the list.
 *
 * Variables: $ctx, $ids, $args, $entry.
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

$odoowoo_read = $ctx->calls_allowed();

?>
<div class="odoowoo-logs">
	<div class="odoowoo-actions">
		<button type="button" class="odoowoo-button odoowoo-button--secondary" data-action="refresh"<?php echo $odoowoo_read ? '' : ' disabled'; ?>><?php esc_html_e( 'Refresh', 'odoowoo-portal' ); ?></button>
	</div>
	<p class="odoowoo-log-status" role="status" aria-live="polite"></p>
	<ul class="odoowoo-log-list"></ul>
</div>
