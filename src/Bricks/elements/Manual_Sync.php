<?php
/**
 * Bricks element: Manual sync.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Manual sync card as a Bricks element.
 */
class Manual_Sync extends Card_Element {

	public $name = 'odoowoo-manual-sync';

	protected function card(): string {
		return 'manual-sync';
	}

	protected function title(): string {
		return __( 'Manual sync', 'odoowoo-portal' );
	}
}
