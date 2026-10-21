<?php
/**
 * Woo and Odoo connection and settings routes.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

/**
 * Each route: the Django method, the path and the exact signed body.
 */
final class ConnectionRoutesTest extends RestTestCase {

	private const D = 7;

	/**
	 * Route under the namespace for a license and dashboard.
	 *
	 * @param array<string, mixed> $l     License.
	 * @param string               $route Route key.
	 */
	private function url( array $l, string $route ): string {
		return '/licenses/' . $l['id'] . '/dashboards/' . self::D . '/' . $route;
	}

	/**
	 * Every key of an array at any depth.
	 *
	 * @param mixed $data Data.
	 * @return string[]
	 */
	private function keys_deep( $data ): array {
		$keys = array();

		foreach ( is_array( $data ) ? $data : array() as $k => $v ) {
			$keys[] = (string) $k;
			$keys   = array_merge( $keys, $this->keys_deep( $v ) );
		}

		return $keys;
	}

	public function test_woo_keys_url_is_trimmed_and_secret_is_never_echoed(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'message' => 'WooCommerce keys updated successfully.' ) ) );

		$this->rest( 'PUT', $this->url( $l, 'woo-keys' ), array( 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_dummy', 'woocommerce_url' => 'https://shop.example/', 'prefix' => 'WS' ) );
		$call = $this->django_calls()[0];

		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( array( 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_dummy', 'woocommerce_url' => 'https://shop.example', 'prefix' => 'WS' ), json_decode( $call['body'], true ) );

		$this->fake_django( fn() => array( 200, array( 'consumer_key' => 'ck_1', 'consumer_secret' => 'leak1', 'has_consumer_secret' => true, 'has_webhook_secret' => true, 'webhook_secret' => 'leak2', 'odoo_password' => 'leak3', 'middleware_api_key' => 'leak4', 'prefix' => 'WS', 'used_prefixes' => array( 'WS' ) ) ) );
		$res  = $this->rest( 'GET', $this->url( $l, 'woo-keys' ) );
		$data = $res->get_data()['data'];

		$this->assertSame( 'ck_1', $data['consumer_key'] );
		$this->assertTrue( $data['has_consumer_secret'] );
		$this->assertTrue( $data['has_webhook_secret'] );
		$json = wp_json_encode( $res->get_data() );

		foreach ( array( 'leak1', 'leak2', 'leak3', 'leak4' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $json );
		}
	}

	public function test_odoowoo_mistake_webhook_secret_never_in_reply(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'consumer_key' => 'ck', 'has_webhook_secret' => true, 'webhook_secret' => 'abc', 'nested' => array( 'webhook_secret' => 'abc' ) ) ) );

		$res = $this->rest( 'GET', $this->url( $l, 'woo-keys' ) );

		$this->assertNotContains( 'webhook_secret', $this->keys_deep( $res->get_data() ) );
		$this->assertStringNotContainsString( 'abc', wp_json_encode( $res->get_data() ) );
	}

	public function test_blank_secret_fields_keep_the_saved_value(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'message' => 'ok' ) ) );

		$this->rest( 'PUT', $this->url( $l, 'woo-keys' ), array( 'consumer_key' => 'ck', 'consumer_secret' => '  ', 'woocommerce_url' => 'https://s.example' ) );
		$this->rest( 'PUT', $this->url( $l, 'odoo-settings' ), array( 'odoo_url' => 'https://o.example', 'odoo_password' => '' ) );
		$calls = $this->django_calls();

		$this->assertArrayNotHasKey( 'consumer_secret', json_decode( $calls[0]['body'], true ) );
		$this->assertArrayNotHasKey( 'odoo_password', json_decode( $calls[1]['body'], true ) );
		$this->assertStringNotContainsString( '""', $calls[0]['body'] . $calls[1]['body'] );
	}

	public function test_first_save_requires_consumer_secret_message_passed_through(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_value', 'message' => 'The WooCommerce consumer secret is required for the first save.', 'field' => 'consumer_secret' ) ) );

		$res = $this->rest( 'PUT', $this->url( $l, 'woo-keys' ), array( 'consumer_key' => 'ck', 'woocommerce_url' => 'https://s.example', 'prefix' => 'ab', 'webhook_secret_override' => 'typed' ) );
		$body = json_decode( $this->django_calls()[0]['body'], true );

		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'The WooCommerce consumer secret is required for the first save.', $res->get_data()['message'] );
		$this->assertSame( 'consumer_secret', $res->get_data()['field'] );
		$this->assertArrayNotHasKey( 'webhook_secret_override', $body );
		$this->assertSame( 'ab', $body['prefix'] );
	}

	public function test_odoo_settings_get_reports_middleware_key_presence(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'odoo_url' => 'https://o.example', 'odoo_password' => '', 'has_odoo_password' => true, 'has_middleware_api_key' => true, 'middleware_api_key' => 'mwkey-dummy' ) ) );

		$res  = $this->rest( 'GET', $this->url( $l, 'odoo-settings' ) );
		$data = $res->get_data()['data'];

		$this->assertTrue( $data['has_middleware_api_key'] );
		$this->assertTrue( $data['has_odoo_password'] );
		$this->assertStringNotContainsString( 'mwkey-dummy', wp_json_encode( $res->get_data() ) );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function rows(): array {
		$woo  = array( 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'woocommerce_url' => 'https://s.example/' );
		$wooc = array( 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'woocommerce_url' => 'https://s.example' );
		$od   = array( 'odoo_url' => 'https://o.example', 'odoo_db' => 'db', 'odoo_user' => 'u', 'odoo_password' => 'p' );
		$set  = $od + array( 'enable_auto_payment' => true, 'odoo_order_pricelist_id' => 3, 'capture_stage' => 'processing', 'quantity_settings' => 'on_hand', 'stock_sku_meta_keys' => '_sku', 'webhook_secret_override' => 'x', 'tenant' => 'evil' );

		return array(
			'woo-keys get'         => array( 'GET', 'woo-keys', null, 'GET', 'woo-keys/', null ),
			'woo-keys put'         => array( 'PUT', 'woo-keys', $woo + array( 'prefix' => 'A', 'enable_stock_update' => false, 'change_status_delivery' => true, 'webhook_secret_override' => 'x' ), 'POST', 'woo-keys/', $wooc + array( 'prefix' => 'A', 'enable_stock_update' => false, 'change_status_delivery' => true ) ),
			'verify-woocommerce'   => array( 'POST', 'verify-woocommerce', $woo, 'POST', 'verify-woocommerce/', $wooc ),
			'inject-woo-webhooks'  => array( 'POST', 'inject-woo-webhooks', array( 'x' => 1 ), 'POST', 'inject-woo-webhooks/', array() ),
			'odoo-settings get'    => array( 'GET', 'odoo-settings', null, 'GET', 'odoo-settings/', null ),
			'odoo-settings put'    => array( 'PUT', 'odoo-settings', $set, 'POST', 'odoo-settings/', $od + array( 'enable_auto_payment' => true, 'odoo_order_pricelist_id' => 3, 'capture_stage' => 'processing', 'quantity_settings' => 'on_hand', 'stock_sku_meta_keys' => '_sku' ) ),
			'verify-odoo'          => array( 'POST', 'verify-odoo', $od, 'POST', 'verify-odoo/', $od ),
			'generate-api-key'     => array( 'POST', 'generate-api-key', array( 'x' => 1 ), 'POST', 'generate-api-key/', array() ),
			'odoo-pricelists'      => array( 'GET', 'odoo-pricelists', null, 'GET', 'odoo-pricelists/', null ),
			'odoo-routes'          => array( 'GET', 'odoo-routes', null, 'GET', 'odoo-routes/', null ),
			'odoo-stock-locations' => array( 'GET', 'odoo-stock-locations', null, 'GET', 'odoo-stock-locations/', null ),
		);
	}

	/**
	 * @dataProvider rows
	 *
	 * @param string                    $method   Browser method.
	 * @param string                    $route    Route.
	 * @param array<string, mixed>|null $body     Browser body.
	 * @param string                    $dj_meth  Django method.
	 * @param string                    $dj_path  Django path under dashboards/7/.
	 * @param array<string, mixed>|null $expected Expected Django body.
	 */
	public function test_each_route_sends_one_signed_call_to_the_mapped_path( string $method, string $route, ?array $body, string $dj_meth, string $dj_path, ?array $expected ): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'ok' => 1 ) ) );

		$res   = $this->rest( $method, $this->url( $l, $route ), $body );
		$calls = $this->django_calls();

		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 1, $calls );
		$this->assertSame( $dj_meth, $calls[0]['method'] );
		$this->assertSame( '/api/dashboards/' . self::D . '/' . $dj_path, $calls[0]['path_and_query'] );
		$sent = (string) $calls[0]['body'];

		if ( null === $expected ) {
			$this->assertSame( '', $sent );
		} else {
			$this->assertSame( $expected, (array) json_decode( $sent, true ) );
		}
		$this->assert_signed( $calls[0], $this->tenant( $l ) );
	}

	public function test_verify_and_callbacks_use_post_and_are_rate_limited(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'success' => true ) ) );
		add_filter( 'odoowoo_portal_rate_limit', fn( $n, $bucket ) => 'verify' === $bucket ? 2 : $n, 10, 2 );

		$this->assertSame( 200, $this->rest( 'POST', $this->url( $l, 'verify-woocommerce' ), array() )->get_status() );
		$this->assertSame( 200, $this->rest( 'POST', $this->url( $l, 'verify-odoo' ), array() )->get_status() );
		$this->assertSame( 429, $this->rest( 'POST', $this->url( $l, 'inject-woo-webhooks' ), array() )->get_status() );
		$this->assertSame( 429, $this->rest( 'POST', $this->url( $l, 'generate-api-key' ), array() )->get_status() );
		$this->assertCount( 2, $this->django_calls() );

		foreach ( array( 'GET', 'PUT', 'DELETE' ) as $m ) {
			$this->assertGreaterThanOrEqual( 400, $this->rest( $m, $this->url( $l, 'verify-odoo' ) )->get_status() );
		}
		$this->assertCount( 2, $this->django_calls() );
	}

	public function test_paused_tenant_may_verify_but_not_save_or_load(): void {
		$l = $this->odoowoo_license( array(), 'paused' );
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'success' => true ) ) );

		$this->assertSame( 200, $this->rest( 'POST', $this->url( $l, 'verify-odoo' ), array() )->get_status() );
		$this->assertSame( 403, $this->rest( 'PUT', $this->url( $l, 'woo-keys' ), array( 'consumer_key' => 'a' ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', $this->url( $l, 'generate-api-key' ), array() )->get_status() );
		$this->assertSame( 200, $this->rest( 'GET', $this->url( $l, 'odoo-settings' ) )->get_status() );
	}
}
