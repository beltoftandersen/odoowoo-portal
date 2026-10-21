<?php
/**
 * Bricks element: Odoo settings.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Odoo settings card as a Bricks element.
 */
class Odoo_Settings extends Card_Element {

	public $name = 'odoowoo-odoo-settings';

	protected function card(): string {
		return 'odoo-settings';
	}

	protected function title(): string {
		return __( 'Odoo settings', 'odoowoo-portal' );
	}
}
