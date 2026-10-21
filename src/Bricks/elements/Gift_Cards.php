<?php
/**
 * Bricks element: Gift cards.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Gift cards card as a Bricks element.
 */
class Gift_Cards extends Card_Element {

	public $name = 'odoowoo-gift-cards';

	protected function card(): string {
		return 'gift-cards';
	}

	protected function title(): string {
		return __( 'Gift cards', 'odoowoo-portal' );
	}
}
