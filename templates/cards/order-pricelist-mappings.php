<?php
/**
 * Card: order-pricelist mappings. The shared editor does the work; this card only names its config.
 *
 * Variables: $ctx, $ids, $args, $entry.
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

$odoowoo_card = 'order-pricelist-mappings';

include __DIR__ . '/_mapping-editor.php';
?>
<h3><?php esc_html_e( 'Order currencies (optional)', 'odoowoo-portal' ); ?></h3>
<p><?php esc_html_e( 'Overrides the rules above. Leave empty to keep current behaviour; otherwise map every order currency to its matching pricelist.', 'odoowoo-portal' ); ?></p>
<?php
$odoowoo_card = 'order-currency-mappings';
include __DIR__ . '/_mapping-editor.php';
