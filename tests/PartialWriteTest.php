<?php
/**
 * Writes that would wipe data in Django are refused before it is called.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Api\Map;

/**
 * Django replaces a whole list or object on a save and resets every field a save omits
 * (sync_app/views.py: woo-keys 297-299, odoo-settings 633-654, tax mappings 2383). A body
 * without its list or object, or without a field, is therefore refused with 400.
 */
final class PartialWriteTest extends RestTestCase {

	private function url( array $l, string $route ): string {
		return '/licenses/' . $l['id'] . '/dashboards/7/' . $route;
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function lists(): array {
		$rows = array();

		foreach ( array( 'payment-method-mappings', 'tax-mappings', 'price-mappings', 'stock-mappings', 'order-route-mappings', 'order-pricelist-mappings' ) as $k ) {
			$rows[ $k ] = array( $k, 'items' );
		}

		$rows['gift-card-settings'] = array( 'gift-card-settings', 'settings' );

		return $rows;
	}

	/**
	 * @dataProvider lists
	 *
	 * @param string $key   Row.
	 * @param string $field items or settings.
	 */
	public function test_a_save_without_its_list_or_object_is_refused( string $key, string $field ): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array() ) );

		$bad = array(
			'empty body'  => array(),
			'wrong type'  => array( $field => 'x' ),
			'other key'   => array( 'name' => 'x' ),
		);

		if ( 'items' === $field ) {
			$bad['object instead of list'] = array( 'items' => array( 'a' => array() ) );
			$bad['item not an object']     = array( 'items' => array( 'x' ) );
		}

		foreach ( $bad as $name => $body ) {
			$res = $this->rest( 'PUT', $this->url( $l, $key ), $body );

			$this->assertSame( 400, $res->get_status(), $key . ' ' . $name );
			$this->assertFalse( $res->get_data()['ok'], $key . ' ' . $name );
			$this->assertSame( 'invalid_value', $res->get_data()['code'], $key . ' ' . $name );
			$this->assertSame( $field, $res->get_data()['field'], $key . ' ' . $name );
		}

		$this->assertSame( array(), $this->django_calls(), 'Django is never called.' );

		// An empty list is a real choice ("no mappings") and passes.
		$res = $this->rest( 'PUT', $this->url( $l, $key ), array( $field => array() ) );
		$this->assertSame( 200, $res->get_status(), $key . ' empty list' );
		$this->assertCount( 1, $this->django_calls() );
	}

	private function woo_body(): array {
		return array(
			'consumer_key'           => 'ck_1',
			'consumer_secret'        => 'cs_dummy',
			'woocommerce_url'        => 'https://shop.example',
			'prefix'                 => 'WS',
			'enable_stock_update'    => true,
			'change_status_delivery' => false,
		);
	}

	private function odoo_body(): array {
		$body = array();

		foreach ( Map::ROUTES['odoo-settings']['required'] as $field ) {
			$body[ $field ] = 'v';
		}

		$body['odoo_order_pricelist_id'] = null;

		return $body;
	}

	public function test_woo_keys_put_needs_every_non_secret_field(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array() ) );

		foreach ( array( 'consumer_key', 'woocommerce_url', 'prefix', 'enable_stock_update', 'change_status_delivery' ) as $field ) {
			$body = $this->woo_body();
			unset( $body[ $field ] );
			$res = $this->rest( 'PUT', $this->url( $l, 'woo-keys' ), $body );

			$this->assertSame( 400, $res->get_status(), $field );
			$this->assertSame( 'invalid_value', $res->get_data()['code'], $field );
			$this->assertSame( $field, $res->get_data()['field'], $field );
		}

		$this->assertSame( array(), $this->django_calls() );

		// The secret may be left out (Django keeps it), and a complete body passes.
		$body = $this->woo_body();
		unset( $body['consumer_secret'] );
		$this->assertSame( 200, $this->rest( 'PUT', $this->url( $l, 'woo-keys' ), $body )->get_status() );
		$this->assertCount( 1, $this->django_calls() );
	}

	public function test_odoo_settings_put_needs_every_non_secret_field(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array() ) );

		$required = Map::ROUTES['odoo-settings']['required'];

		$this->assertNotContains( 'odoo_password', $required );
		$this->assertNotContains( 'stock_sku_meta_keys', $required, 'Django: absent means unchanged (views.py:753-759).' );
		$this->assertNotContains( 'stock_units_meta_keys', $required );
		$this->assertContains( 'odoo_url', $required );
		$this->assertContains( 'enable_barcode_sync', $required );
		$this->assertContains( 'quantity_settings', $required );

		foreach ( $required as $field ) {
			$body = $this->odoo_body();
			unset( $body[ $field ] );
			$res = $this->rest( 'PUT', $this->url( $l, 'odoo-settings' ), $body );

			$this->assertSame( 400, $res->get_status(), $field );
			$this->assertSame( 'invalid_value', $res->get_data()['code'], $field );
			$this->assertSame( $field, $res->get_data()['field'], $field );
		}

		$this->assertSame( array(), $this->django_calls() );

		// Complete without the password and the two optional keys, a null pricelist among them.
		$res = $this->rest( 'PUT', $this->url( $l, 'odoo-settings' ), $this->odoo_body() );
		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 1, $this->django_calls() );
	}

	public function test_verify_rows_are_not_whole_object_saves(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array() ) );

		// verify-* rows send only the fields they check: they are not refused.
		$res = $this->rest( 'POST', $this->url( $l, 'verify-odoo' ), array( 'odoo_url' => 'https://o.example' ) );
		$this->assertSame( 200, $res->get_status() );
	}
}
