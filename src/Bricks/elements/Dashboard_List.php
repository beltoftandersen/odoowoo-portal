<?php
/**
 * Bricks element: Dashboard list.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Dashboard list card as a Bricks element.
 */
class Dashboard_List extends Card_Element {

	public $name = 'odoowoo-dashboard-list';

	protected function card(): string {
		return 'dashboard-list';
	}

	protected function title(): string {
		return __( 'Dashboard list', 'odoowoo-portal' );
	}
}
