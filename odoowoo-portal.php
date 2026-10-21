<?php
/**
 * Plugin Name:          OdooWoo Portal
 * Plugin URI:           https://beltoft.net/
 * Description:          The OdooWoo customer app: dashboards, connections, mappings and syncs for licensed OdooWoo customers, built on Beltoft Licenses and Beltoft Services.
 * Version:              0.0.1
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce, beltoft-licenses, beltoft-services
 * Author:               beltoft.net
 * Author URI:           https://beltoft.net/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          odoowoo-portal
 * Domain Path:          /languages
 * WC requires at least: 9.0
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

define( 'ODOOWOO_PORTAL_FILE', __FILE__ );
define( 'ODOOWOO_PORTAL_PATH', plugin_dir_path( __FILE__ ) );
define( 'ODOOWOO_PORTAL_VERSION', '0.0.1' );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'OdooWooPortal\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = ODOOWOO_PORTAL_PATH . 'src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ODOOWOO_PORTAL_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ODOOWOO_PORTAL_FILE, true );
		}
	}
);

// After beltoft-licenses (10) and beltoft-services (20).
add_action( 'plugins_loaded', array( \OdooWooPortal\Plugin::class, 'boot' ), 25 );
