<?php
/**
 * Dependency gate.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Plugin;

/**
 * The plugin does nothing without its dependencies.
 */
final class PluginTest extends TestCase {

	private function rest_callbacks(): int {
		global $wp_filter;

		return isset( $wp_filter['rest_api_init'] ) ? count( $wp_filter['rest_api_init']->callbacks, COUNT_RECURSIVE ) : 0;
	}

	private function without( string $slug ): void {
		add_filter(
			'odoowoo_portal_dependencies',
			static function ( $dependencies ) use ( $slug ) {
				unset( $dependencies[ $slug ] );
				return $dependencies;
			}
		);
	}

	public function test_notice_and_no_hooks_without_dependencies(): void {
		$this->assertTrue( Plugin::requirements_met(), 'Everything is loaded in the test install.' );

		$this->without( 'beltoft-services' );
		add_filter(
			'odoowoo_portal_dependencies',
			static function ( $dependencies ) {
				$dependencies['beltoft-services'] = array(
					'label'   => 'beltoft-services',
					'present' => false,
					'version' => '',
					'minimum' => '0.1.1',
				);
				return $dependencies;
			}
		);

		$this->assertContains( 'beltoft-services', Plugin::missing() );

		$before = $this->rest_callbacks();
		Plugin::boot();

		$this->assertFalse( Plugin::booted() );
		$this->assertSame( $before, $this->rest_callbacks() );
		$this->assertNotFalse( has_action( 'admin_notices', array( Plugin::class, 'notice_missing' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Plugin::notice_missing();
		$this->assertStringContainsString( 'beltoft-services', (string) ob_get_clean() );

		remove_all_filters( 'odoowoo_portal_dependencies' );

		$before_ok = $this->rest_callbacks();
		Plugin::boot();

		$this->assertTrue( Plugin::booted() );
		$this->assertFalse( has_action( 'admin_notices', array( Plugin::class, 'notice_missing' ) ) );
		$this->assertGreaterThanOrEqual( $before_ok, $this->rest_callbacks() );
		$this->assertNotFalse( has_action( 'admin_menu', array( \OdooWooPortal\Settings::class, 'menu' ) ), 'The parts are hooked once the dependencies are met.' );
	}

	public function test_version_gate(): void {
		$set = static function ( string $licenses, string $services ) {
			remove_all_filters( 'odoowoo_portal_dependencies' );
			add_filter(
				'odoowoo_portal_dependencies',
				static function ( $dependencies ) use ( $licenses, $services ) {
					$dependencies['beltoft-licenses']['version'] = $licenses;
					$dependencies['beltoft-licenses']['minimum'] = '0.7.0';
					$dependencies['beltoft-services']['version'] = $services;
					$dependencies['beltoft-services']['minimum'] = '0.1.1';
					return $dependencies;
				}
			);
		};

		$set( '0.6.9', '0.1.1' );
		$this->assertSame( array( 'beltoft-licenses' ), Plugin::missing() );

		$set( '0.7.0', '0.1.0' );
		$this->assertSame( array( 'beltoft-services' ), Plugin::missing() );

		$set( '0.7.0', '0.1.1' );
		$this->assertSame( array(), Plugin::missing() );
		$this->assertTrue( Plugin::requirements_met() );
	}
}
