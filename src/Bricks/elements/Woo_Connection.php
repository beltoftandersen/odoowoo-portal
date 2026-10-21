<?php
/**
 * Bricks element: Woo connection.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Woo connection card as a Bricks element.
 */
class Woo_Connection extends Card_Element {

	public $name = 'odoowoo-woo-connection';

	protected function card(): string {
		return 'woo-connection';
	}

	protected function title(): string {
		return __( 'Woo connection', 'odoowoo-portal' );
	}
}
