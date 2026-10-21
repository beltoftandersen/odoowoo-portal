<?php
/**
 * Bricks element: Dashboard header.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Dashboard header card as a Bricks element.
 */
class Dashboard_Header extends Card_Element {

	public $name = 'odoowoo-dashboard-header';

	protected function card(): string {
		return 'dashboard-header';
	}

	protected function title(): string {
		return __( 'Dashboard header', 'odoowoo-portal' );
	}
}
