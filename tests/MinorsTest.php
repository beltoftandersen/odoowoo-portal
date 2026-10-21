<?php
/**
 * The final pass: small behaviours of the release.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Api\Map;
use OdooWooPortal\Api\Routes;
use OdooWooPortal\Cards\Renderer;
use OdooWooPortal\Context;
use OdooWooPortal\Plugin;
use OdooWooPortal\Prefixes;

/**
 * One test per item.
 */
final class MinorsTest extends RestTestCase {

	private function url( array $l, string $route, int $d = 7 ): string {
		return '/licenses/' . $l['id'] . '/dashboards/' . $d . '/' . $route;
	}

	public function test_prefixes_are_forgotten_only_after_woo_keys_writes_and_dashboard_create_and_delete(): void {
		$l   = $this->odoowoo_license();
		$uid = (int) $l['user_id'];
		$this->as_user( $uid );
		$this->django_map(
			array(
				'POST dashboards/7/trigger-category-sync/' => array( 202, array( 'task_id' => 'tk-1' ) ),
				'POST dashboards/7/tax-mappings/'          => array( 200, array( 'status' => 'success' ) ),
				'POST dashboards/7/odoo-settings/'         => array( 200, array( 'message' => 'ok' ) ),
				'PATCH dashboards/7/'                      => array( 200, array( 'id' => 7, 'name' => 'N' ) ),
				'POST dashboards/7/woo-keys/'              => array( 200, array( 'message' => 'ok' ) ),
				'POST dashboards/create/'                  => array( 201, array( 'id' => 8, 'name' => 'B' ) ),
				'DELETE dashboards/7/delete/'              => array( 204, '' ),
			)
		);
		$key  = 'odoowoo_portal_prefixes_' . $uid;
		$seed = function () use ( $key ) {
			set_transient( $key, array( 7 => 'AA' ), 60 );
		};

		$keeps = array(
			'sync trigger' => fn() => $this->rest( 'POST', $this->url( $l, 'trigger-category-sync' ), array() ),
			'mappings'     => fn() => $this->rest( 'PUT', $this->url( $l, 'tax-mappings' ), array( 'items' => array() ) ),
			'odoo save'    => fn() => $this->rest( 'PUT', $this->url( $l, 'odoo-settings' ), self::complete_odoo() ),
			'rename'       => fn() => $this->rest( 'PATCH', '/licenses/' . $l['id'] . '/dashboards/7', array( 'name' => 'N' ) ),
		);

		foreach ( $keeps as $name => $do ) {
			$seed();
			$res = $do();

			$this->assertSame( 200, $res->get_status(), $name );
			$this->assertSame( array( 7 => 'AA' ), get_transient( $key ), $name . ' keeps the prefix cache.' );
		}

		$forgets = array(
			'woo-keys save'    => fn() => $this->rest( 'PUT', $this->url( $l, 'woo-keys' ), self::complete_woo() ),
			'dashboard create' => fn() => $this->rest( 'POST', '/licenses/' . $l['id'] . '/dashboards', array( 'name' => 'B' ) ),
			'dashboard delete' => fn() => $this->rest( 'DELETE', '/licenses/' . $l['id'] . '/dashboards/7' ),
		);

		foreach ( $forgets as $name => $do ) {
			$seed();
			$res = $do();

			$this->assertSame( 200, $res->get_status(), $name );
			$this->assertFalse( get_transient( $key ), $name . ' forgets the prefix cache.' );
		}
	}

	public function test_uninstall_removes_support_meta_and_prefix_transients(): void {
		$user = self::factory()->user->create();
		update_user_meta( $user, 'odoowoo_support_until_12', time() + 100 );
		update_user_meta( $user, 'odoowoo_support_until_13', time() + 100 );
		update_user_meta( $user, 'unrelated_meta', 'stay' );
		set_transient( 'odoowoo_portal_prefixes_' . $user, array( 1 => 'AA' ), 60 );
		set_transient( 'unrelated_transient', 'stay', 60 );
		update_option( \OdooWooPortal\Settings::OPTION, array( 'delete_data' => true ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'odoowoo-portal/odoowoo-portal.php' );
		}

		include dirname( __DIR__ ) . '/uninstall.php';

		$this->assertSame( '', get_user_meta( $user, 'odoowoo_support_until_12', true ) );
		$this->assertSame( '', get_user_meta( $user, 'odoowoo_support_until_13', true ) );
		$this->assertSame( 'stay', get_user_meta( $user, 'unrelated_meta', true ) );
		$this->assertFalse( get_transient( 'odoowoo_portal_prefixes_' . $user ) );
		$this->assertSame( 'stay', get_transient( 'unrelated_transient' ) );
	}

	public function test_an_app_page_is_never_cached(): void {
		Plugin::marked_uncacheable( true );
		$license = $this->odoowoo_license();
		Renderer::reset();

		// A visitor who may not use the app: the page is not marked.
		wp_set_current_user( 0 );
		Renderer::render( 'dashboard-list' );
		$this->assertFalse( Plugin::marked_uncacheable() );

		// A licensed user: marked as soon as the app renders.
		$this->as_user( (int) $license['user_id'] );
		$_GET = array( 'license' => (string) $license['id'] );
		Renderer::render( 'dashboard-list' );
		$this->assertTrue( Plugin::marked_uncacheable( true ) );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	public function test_the_configured_pages_are_marked_before_output_for_a_viewer_of_the_app(): void {
		Plugin::marked_uncacheable( true );
		$license = $this->odoowoo_license();
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$other   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->settings( array( 'list_page_id' => $page ) );

		$this->go_to( get_permalink( $page ) );
		wp_set_current_user( 0 );
		Plugin::nocache_app_pages();
		$this->assertFalse( Plugin::marked_uncacheable(), 'Logged out.' );

		$this->as_user( (int) $license['user_id'] );
		$this->go_to( get_permalink( $other ) );
		Plugin::nocache_app_pages();
		$this->assertFalse( Plugin::marked_uncacheable(), 'Another page.' );

		$this->go_to( get_permalink( $page ) );
		Plugin::nocache_app_pages();
		$this->assertTrue( Plugin::marked_uncacheable( true ) );
	}

	public function test_license_summary_whitelists_the_dashboard_rows(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->django_map(
			array(
				'GET tenants/' . $this->tenant( $l ) . '/' => array( 200, array( 'limit' => 3, 'used' => 1 ) ),
				'GET dashboards/'                         => array(
					200,
					array(
						array( 'id' => 7, 'name' => 'Shop', 'enabled' => true, 'created_at' => '2026-10-21T10:00:00Z', 'medusa_customer_id' => 'cus_1', 'tenant' => 'x', 'odoo_url' => 'https://o.example', 'extra' => array( 'a' => 1 ) ),
					),
				),
			)
		);

		$summary = Routes::license_summary( Context::for_user( (int) $l['user_id'], (int) $l['id'] ) );

		$this->assertSame(
			array( array( 'id' => 7, 'name' => 'Shop', 'enabled' => true, 'created_at' => '2026-10-21T10:00:00Z' ) ),
			$summary['dashboards']
		);
	}

	public function test_unshaped_get_replies_pass_only_the_fields_django_defines(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		// Fields of sync_app/views.py:261-285 (woo-keys GET) and 563-598 (odoo-settings GET), plus extra keys that must not pass.
		$this->django_map(
			array(
				'GET dashboards/7/woo-keys/'      => array( 200, array( 'consumer_key' => 'ck', 'has_consumer_secret' => true, 'woocommerce_url' => 'https://s.example', 'prefix' => 'AA', 'enable_stock_update' => true, 'change_status_delivery' => true, 'has_webhook_secret' => true, 'require_webhook_signature' => false, 'used_prefixes' => array( 'AA' ), 'internal_note' => 'x', 'tenant' => 't' ) ),
				'GET dashboards/7/odoo-settings/' => array( 200, array( 'odoo_url' => 'https://o.example', 'has_odoo_password' => true, 'quantity_settings' => 'on_hand', 'internal_note' => 'x', 'dsn' => 'y' ) ),
				'GET dashboards/' => array( 200, array() ),
			)
		);

		$woo  = $this->rest( 'GET', $this->url( $l, 'woo-keys' ) )->get_data()['data'];
		$odoo = $this->rest( 'GET', $this->url( $l, 'odoo-settings' ) )->get_data()['data'];

		$this->assertArrayNotHasKey( 'internal_note', $woo );
		$this->assertArrayNotHasKey( 'tenant', $woo );
		$this->assertSame( 'AA', $woo['prefix'] );
		$this->assertTrue( $woo['has_webhook_secret'] );
		$this->assertFalse( $woo['require_webhook_signature'] );
		$this->assertArrayNotHasKey( 'internal_note', $odoo );
		$this->assertArrayNotHasKey( 'dsn', $odoo );
		$this->assertSame( 'https://o.example', $odoo['odoo_url'] );
		$this->assertTrue( $odoo['has_odoo_password'] );
	}

	public function test_text_domain_is_loaded_on_init(): void {
		$this->assertNotFalse( has_action( 'init', array( Plugin::class, 'load_textdomain' ) ) );
	}

	public function test_prefixes_in_use_is_gone_and_status_remains(): void {
		$this->assertFalse( method_exists( Prefixes::class, 'in_use' ) );
		$this->assertTrue( method_exists( Prefixes::class, 'status' ) );
	}

	public function test_woo_settings_say_partial_only_when_another_license_could_not_be_read(): void {
		$a = $this->odoowoo_license();
		$b = $this->odoowoo_license( array( 'user_id' => (int) $a['user_id'] ) );
		$this->as_user( (int) $a['user_id'] );
		$tenant_a = $this->tenant( $a );
		$b_up     = false;
		$this->fake_django(
			function ( $call ) use ( $tenant_a, &$b_up ) {
				$tenant = $call['headers']['X-Beltoft-Tenant'] ?? '';
				$path   = preg_replace( '#^/api/#', '', $call['path_and_query'] );

				if ( $tenant !== $tenant_a && ! $b_up ) {
					return array( 502, '<html>down</html>' );
				}

				if ( 'dashboards/' === $path ) {
					return array( 200, array( array( 'id' => $tenant === $tenant_a ? 7 : 9 ) ) );
				}

				return array( 200, array( 'prefix' => $tenant === $tenant_a ? 'AA' : 'BB', 'used_prefixes' => array( 'AA' ) ) );
			}
		);

		$data = $this->rest( 'GET', $this->url( $a, 'woo-keys' ) )->get_data()['data'];
		$this->assertTrue( $data['used_prefixes_partial'], 'The other license was not read.' );

		$b_up = true;
		Prefixes::forget( (int) $a['user_id'] );
		$data = $this->rest( 'GET', $this->url( $a, 'woo-keys' ) )->get_data()['data'];
		$this->assertFalse( $data['used_prefixes_partial'] );
		$this->assertSame( array( 'BB' ), $data['used_prefixes'] );
	}
}
