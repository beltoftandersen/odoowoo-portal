<?php
/**
 * Bricks element: Tax mappings.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Tax mappings card as a Bricks element.
 */
class Tax_Mappings extends Card_Element {

	public $name = 'odoowoo-tax-mappings';

	protected function card(): string {
		return 'tax-mappings';
	}

	protected function title(): string {
		return __( 'Tax mappings', 'odoowoo-portal' );
	}
}
