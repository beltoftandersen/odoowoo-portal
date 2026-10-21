<?php
/**
 * Bricks element: Order pricelist mappings.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Order pricelist mappings card as a Bricks element.
 */
class Order_Pricelist_Mappings extends Card_Element {

	public $name = 'odoowoo-order-pricelist-mappings';

	protected function card(): string {
		return 'order-pricelist-mappings';
	}

	protected function title(): string {
		return __( 'Order pricelist mappings', 'odoowoo-portal' );
	}
}
