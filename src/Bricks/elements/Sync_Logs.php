<?php
/**
 * Bricks element: Sync logs.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks\elements;

use OdooWooPortal\Bricks\Card_Element;

defined( 'ABSPATH' ) || exit;

/**
 * The Sync logs card as a Bricks element.
 */
class Sync_Logs extends Card_Element {

	public $name = 'odoowoo-sync-logs';

	protected function card(): string {
		return 'sync-logs';
	}

	protected function title(): string {
		return __( 'Sync logs', 'odoowoo-portal' );
	}
}
