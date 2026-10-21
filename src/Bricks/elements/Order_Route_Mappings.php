<?php
/**
 * Bricks element: Order route mappings.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Order route mappings card as a Bricks element.
 */
class Order_Route_Mappings extends Card_Element {

	public $name = 'odoowoo-order-route-mappings';

	protected function card(): string {
		return 'order-route-mappings';
	}

	protected function title(): string {
		return __( 'Order route mappings', 'odoowoo-portal' );
	}
}
