<?php
/**
 * A stand-in for the two Bricks classes the plugin's elements touch. Loaded by BricksTest only.
 *
 * Signatures and property names are the installed theme's (Bricks 2.4.3), not invented:
 *  - \Bricks\Element:   includes/elements/base.php (constructor :72, get_label :208, set_controls :267,
 *                       set_css_selector :196, set_attribute :2356, render_attributes :2676,
 *                       enqueue_scripts :2856, render :2863, load :139).
 *  - \Bricks\Elements:  includes/elements.php (register_element :253, public static $elements :6).
 * Only what the plugin calls is implemented; the behaviour is the simplest that the real methods
 * have for these calls.
 *
 * @package OdooWooPortal
 */

namespace Bricks;

if ( ! class_exists( __NAMESPACE__ . '\Element', false ) ) {

	/**
	 * Base class of every Bricks element.
	 */
	abstract class Element {

		public $element;
		public $id;
		public $name;
		public $label;
		public $description;
		public $keywords;
		public $category;
		public $icon;
		public $css_selector;
		public $control_groups = array();
		public $controls       = array();
		public $settings       = array();
		public $attributes     = array();
		public $is_frontend    = true;

		/**
		 * B includes/elements/base.php:72.
		 *
		 * @param array|null $element Element data.
		 */
		public function __construct( $element = null ) {
			$this->element     = $element;
			$this->label       = $this->get_label();
			$this->is_frontend = isset( $element['is_frontend'] ) ? $element['is_frontend'] : true;
			$this->id          = ! empty( $element['id'] ) ? (string) $element['id'] : 'abc123';
			$this->settings    = ! empty( $element['settings'] ) ? $element['settings'] : array();
		}

		/**
		 * B base.php:139: the controls are built here, not in the constructor.
		 */
		public function load() {
			$this->control_groups = array();
			$this->set_control_groups();
			$this->controls = array();
			$this->set_controls();

			if ( ! empty( $this->css_selector ) ) {
				$this->set_css_selector( $this->css_selector );
			}
		}

		/**
		 * B base.php:208.
		 */
		public function get_label() {
			return str_replace( '-', ' ', (string) $this->name );
		}

		public function set_control_groups() {}

		public function set_controls() {}

		/**
		 * B base.php:196: a control's css rule without a selector gets the element's.
		 *
		 * @param string $custom_css_selector Selector.
		 */
		public function set_css_selector( $custom_css_selector ) {
			foreach ( $this->controls as $key => $value ) {
				if ( isset( $this->controls[ $key ]['css'] ) && is_array( $this->controls[ $key ]['css'] ) ) {
					foreach ( $this->controls[ $key ]['css'] as $index => $rule ) {
						if ( ! isset( $this->controls[ $key ]['css'][ $index ]['selector'] ) ) {
							$this->controls[ $key ]['css'][ $index ]['selector'] = $custom_css_selector;
						}
					}
				}
			}
		}

		/**
		 * B base.php:2356.
		 *
		 * @param string $key       Attribute group ('_root').
		 * @param string $attribute Attribute name.
		 * @param mixed  $value     Value or values.
		 */
		public function set_attribute( $key, $attribute, $value = null ) {
			foreach ( (array) $value as $val ) {
				$this->attributes[ $key ][ $attribute ][] = $val;
			}
		}

		/**
		 * B base.php:2676 (the real one also adds the id and Bricks' own classes).
		 *
		 * @param string $key                   Attribute group.
		 * @param bool   $add_custom_attributes Unused here.
		 * @param bool   $as_array              Unused here.
		 */
		public function render_attributes( $key, $add_custom_attributes = false, $as_array = false ) {
			$out = array( 'id="brxe-' . esc_attr( $this->id ) . '"' );

			foreach ( $this->attributes[ $key ] ?? array() as $attribute => $values ) {
				$out[] = $attribute . '="' . esc_attr( implode( ' ', $values ) ) . '"';
			}

			return implode( ' ', $out );
		}

		public function enqueue_scripts() {}

		public function render() {}

		/**
		 * B base.php:2932 (frontend.php:777 captures the output with ob_start).
		 */
		public function init() {
			$this->enqueue_scripts();
			$this->render();
		}
	}
}

if ( ! class_exists( __NAMESPACE__ . '\Elements', false ) ) {

	/**
	 * The element registry.
	 */
	class Elements {

		/**
		 * B elements.php:6.
		 *
		 * @var array
		 */
		public static $elements = array();

		/**
		 * Every call's arguments, so a test can check that only the file was passed (stub only).
		 *
		 * @var array
		 */
		public static $calls = array();

		/**
		 * File => class it declared (stub only).
		 *
		 * @var array
		 */
		private static $loaded = array();

		/**
		 * B elements.php:253: loads the file, takes the last declared class, reads name and label.
		 *
		 * @param string $file               Element file.
		 * @param string $element_name       Element name.
		 * @param string $element_class_name Class name.
		 */
		public static function register_element( $file, $element_name = '', $element_class_name = '' ) {
			self::$calls[] = func_get_args();

			if ( ! is_readable( $file ) ) {
				return;
			}

			// Stub only: the real theme registers once per request. A test registers again, and a file
			// that is already loaded declares no new class, so remember which class each file gave.
			if ( isset( self::$loaded[ $file ] ) ) {
				$element_class_name = self::$loaded[ $file ];
			} else {
				require_once $file;
			}

			if ( empty( $element_class_name ) || ! class_exists( $element_class_name ) ) {
				$get_declared_classes = get_declared_classes();
				$element_class_name   = end( $get_declared_classes );
			}

			self::$loaded[ $file ] = $element_class_name;

			$element_label = '';

			if ( empty( $element_name ) ) {
				$element_instance = new $element_class_name();
				$element_name     = $element_instance->name;
				$element_label    = $element_instance->label;
			}

			self::$elements[ $element_name ] = array(
				'class' => $element_class_name,
				'name'  => $element_name,
				'label' => $element_label,
			);
		}
	}
}
