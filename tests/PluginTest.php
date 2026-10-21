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

		// Start from a clean slate, so presence after a boot means that boot added it.
		remove_all_actions( 'admin_menu' );
		remove_all_actions( 'admin_init' );
		remove_all_actions( 'rest_api_init' );

		Plugin::boot();

		$this->assertFalse( Plugin::booted() );
		$this->assertFalse( has_action( 'admin_menu' ), 'No admin_menu callback without dependencies.' );
		$this->assertFalse( has_action( 'admin_init' ), 'No admin_init callback without dependencies.' );
		$this->assertFalse( has_action( 'rest_api_init' ), 'No rest_api_init callback without dependencies.' );
		$this->assertNotFalse( has_action( 'admin_notices', array( Plugin::class, 'notice_missing' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Plugin::notice_missing();
		$this->assertStringContainsString( 'beltoft-services', (string) ob_get_clean() );

		remove_all_filters( 'odoowoo_portal_dependencies' );

		Plugin::boot();

		$this->assertTrue( Plugin::booted() );
		$this->assertFalse( has_action( 'admin_notices', array( Plugin::class, 'notice_missing' ) ) );
		$this->assertSame( 10, has_action( 'admin_menu', array( \OdooWooPortal\Settings::class, 'menu' ) ), 'The parts are hooked once the dependencies are met.' );
		$this->assertSame( 10, has_action( 'admin_init', array( \OdooWooPortal\Settings::class, 'register_setting' ) ) );
	}

	public function test_default_dependencies_read_the_version_constants(): void {
		$dependencies = Plugin::dependencies();

		$this->assertTrue( $dependencies['beltoft-licenses']['present'] );
		$this->assertSame( BELTOFT_LICENSES_VERSION, $dependencies['beltoft-licenses']['version'] );
		$this->assertSame( '0.7.0', $dependencies['beltoft-licenses']['minimum'] );
		$this->assertTrue( $dependencies['beltoft-services']['present'] );
		$this->assertSame( BELTOFT_SERVICES_VERSION, $dependencies['beltoft-services']['version'] );
		$this->assertSame( '0.1.1', $dependencies['beltoft-services']['minimum'] );
		$this->assertTrue( $dependencies['woocommerce']['present'] );
	}

	/**
	 * The notice for one dependency, as an administrator sees it.
	 *
	 * @param array<string, mixed> $dependency Dependency override.
	 */
	private function notice_for( array $dependency ): string {
		add_filter(
			'odoowoo_portal_dependencies',
			static function ( $dependencies ) use ( $dependency ) {
				$dependencies['beltoft-services'] = $dependency;
				return $dependencies;
			}
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Plugin::notice_missing();

		return (string) ob_get_clean();
	}

	public function test_notice_names_the_minimum_and_says_update_when_too_old(): void {
		$old = $this->notice_for(
			array(
				'label'   => 'beltoft-services',
				'present' => true,
				'version' => '0.1.0',
				'minimum' => '0.1.1',
			)
		);

		$this->assertStringContainsString( 'Update beltoft-services to 0.1.1 or newer', $old );
		$this->assertStringContainsString( '0.1.0', $old, 'The installed version is named.' );

		remove_all_filters( 'odoowoo_portal_dependencies' );

		$absent = $this->notice_for(
			array(
				'label'   => 'beltoft-services',
				'present' => false,
				'version' => '',
				'minimum' => '0.1.1',
			)
		);

		$this->assertStringContainsString( 'Activate beltoft-services 0.1.1 or newer', $absent );
		$this->assertStringNotContainsString( 'Update', $absent );
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
