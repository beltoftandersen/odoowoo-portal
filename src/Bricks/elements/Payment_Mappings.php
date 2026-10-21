<?php
/**
 * Bricks element: Payment mappings.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Payment mappings card as a Bricks element.
 */
class Payment_Mappings extends Card_Element {

	public $name = 'odoowoo-payment-mappings';

	protected function card(): string {
		return 'payment-mappings';
	}

	protected function title(): string {
		return __( 'Payment mappings', 'odoowoo-portal' );
	}
}
