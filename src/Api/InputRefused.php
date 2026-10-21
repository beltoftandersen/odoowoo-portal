<?php
/**
 * An input the browser sent that is refused before any Django call.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the message to show and the field it is about.
 */
final class InputRefused extends \InvalidArgumentException {

	private string $field;

	/**
	 * @param string $message Customer-ready message (already translated).
	 * @param string $field   Field name.
	 */
	public function __construct( string $message, string $field ) {
		parent::__construct( $message );
		$this->field = $field;
	}

	/**
	 * The field.
	 */
	public function field(): string {
		return $this->field;
	}
}
