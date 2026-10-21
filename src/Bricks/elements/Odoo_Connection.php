<?php
/**
 * Bricks element: Odoo connection.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Odoo connection card as a Bricks element.
 */
class Odoo_Connection extends Card_Element {

	public $name = 'odoowoo-odoo-connection';

	protected function card(): string {
		return 'odoo-connection';
	}

	protected function title(): string {
		return __( 'Odoo connection', 'odoowoo-portal' );
	}
}
