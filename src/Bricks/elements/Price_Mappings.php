<?php
/**
 * Bricks element: Price mappings.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Price mappings card as a Bricks element.
 */
class Price_Mappings extends Card_Element {

	public $name = 'odoowoo-price-mappings';

	protected function card(): string {
		return 'price-mappings';
	}

	protected function title(): string {
		return __( 'Price mappings', 'odoowoo-portal' );
	}
}
