<?php
/**
 * Uninstall.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Settings;

/**
 * Data goes only when the setting says so.
 */
final class UninstallTest extends TestCase {

	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'odoowoo-portal/odoowoo-portal.php' );
		}

		include dirname( __DIR__ ) . '/uninstall.php';
	}

	public function test_data_kept_unless_ticked(): void {
		$user = self::factory()->user->create();
		update_user_meta( $user, 'odoowoo_portal_tasks', array( 'x' => 1 ) );
		update_option( Settings::OPTION, array( 'delete_data' => false, 'software_slug' => 'odoowoo' ) );

		$this->run_uninstall();

		$this->assertNotFalse( get_option( Settings::OPTION ) );
		$this->assertSame( array( 'x' => 1 ), get_user_meta( $user, 'odoowoo_portal_tasks', true ) );

		update_option( Settings::OPTION, array( 'delete_data' => true ) );

		$this->run_uninstall();

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertSame( '', get_user_meta( $user, 'odoowoo_portal_tasks', true ) );
	}
}
