<?php
/**
 * Bricks element: Stock mappings.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Stock mappings card as a Bricks element.
 */
class Stock_Mappings extends Card_Element {

	public $name = 'odoowoo-stock-mappings';

	protected function card(): string {
		return 'stock-mappings';
	}

	protected function title(): string {
		return __( 'Stock mappings', 'odoowoo-portal' );
	}
}
