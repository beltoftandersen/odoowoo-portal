<?php
/**
 * Bricks element: Dashboard settings.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Dashboard settings card as a Bricks element.
 */
class Dashboard_Settings extends Card_Element {

	public $name = 'odoowoo-dashboard-settings';

	protected function card(): string {
		return 'dashboard-settings';
	}

	protected function title(): string {
		return __( 'Dashboard settings', 'odoowoo-portal' );
	}
}
