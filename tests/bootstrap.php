<?php
/**
 * PHPUnit bootstrap.
 *
 * Runs against the WordPress test suite's own throwaway install (WP_TESTS_DIR). WooCommerce,
 * WooCommerce Subscriptions, beltoft-licenses and beltoft-services are loaded by absolute
 * path (BELTOFT_LICENSES_DIR and BELTOFT_SERVICES_DIR override the sibling folders); the
 * runner provides phpunit and the test library:
 *
 *   /root/plugin-tests/run.sh <path-to-this-plugin>
 *
 * @package OdooWooPortal
 */

// Never runnable over HTTP: it requires files by path and boots a test install.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$odoowoo_portal_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/tmp/wordpress-tests-lib';
$odoowoo_portal_site      = getenv( 'SITE_PLUGINS' );
$odoowoo_portal_licenses  = getenv( 'BELTOFT_LICENSES_DIR' ) ? getenv( 'BELTOFT_LICENSES_DIR' ) : dirname( __DIR__, 2 ) . '/beltoft-licenses';
$odoowoo_portal_services  = getenv( 'BELTOFT_SERVICES_DIR' ) ? getenv( 'BELTOFT_SERVICES_DIR' ) : dirname( __DIR__, 2 ) . '/beltoft-services';

require_once $odoowoo_portal_tests_dir . '/includes/functions.php';

foreach ( array( 'woocommerce/woocommerce.php', 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) as $odoowoo_portal_required ) {
	if ( ! $odoowoo_portal_site || ! file_exists( $odoowoo_portal_site . '/' . $odoowoo_portal_required ) ) {
		fwrite( STDERR, "Test bootstrap: SITE_PLUGINS must point at a plugins folder containing {$odoowoo_portal_required}.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		exit( 1 );
	}
}

foreach ( array(
	'beltoft-licenses' => $odoowoo_portal_licenses,
	'beltoft-services' => $odoowoo_portal_services,
) as $odoowoo_portal_name => $odoowoo_portal_dir ) {
	if ( ! file_exists( $odoowoo_portal_dir . '/' . $odoowoo_portal_name . '.php' ) ) {
		fwrite( STDERR, "Test bootstrap: {$odoowoo_portal_name} not found at {$odoowoo_portal_dir} (set the BELTOFT_*_DIR variable).\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		exit( 1 );
	}
}

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $odoowoo_portal_site, $odoowoo_portal_licenses, $odoowoo_portal_services ) {
		require $odoowoo_portal_site . '/woocommerce/woocommerce.php';
		require $odoowoo_portal_licenses . '/beltoft-licenses.php';
		require $odoowoo_portal_services . '/beltoft-services.php';
		require dirname( __DIR__ ) . '/odoowoo-portal.php';
	}
);

// WooCommerce Subscriptions must load after WooCommerce has set up Action Scheduler.
tests_add_filter(
	'plugins_loaded',
	static function () use ( $odoowoo_portal_site ) {
		require $odoowoo_portal_site . '/woocommerce-subscriptions/woocommerce-subscriptions.php';
	},
	1
);

// After Action Scheduler's data store is ready (init priority 1) and WooCommerce has
// registered its post types (init 5).
tests_add_filter(
	'init',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
		}

		\BeltoftLicenses\Install::create_tables();
		\BeltoftLicenses\Install::ensure_secret();
	},
	20
);

require $odoowoo_portal_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/Support/FakeHttpDriver.php';
require __DIR__ . '/Support/FakeDjango.php';
require __DIR__ . '/TestCase.php';
