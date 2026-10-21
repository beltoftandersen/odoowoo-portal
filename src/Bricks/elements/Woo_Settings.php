<?php
/**
 * Bricks element: Woo settings.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Woo settings card as a Bricks element.
 */
class Woo_Settings extends Card_Element {

	public $name = 'odoowoo-woo-settings';

	protected function card(): string {
		return 'woo-settings';
	}

	protected function title(): string {
		return __( 'Woo settings', 'odoowoo-portal' );
	}
}
