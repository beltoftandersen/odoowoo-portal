<?php
/**
 * Mapping and gift-card routes.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Api\Map;

/**
 * Methods and paths of the signed calls, bodies, Django refusals, and reply shaping.
 */
final class MappingRoutesTest extends RestTestCase {

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
	 * Every route with the browser method, the Django method and path.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function routes(): array {
		$rows = array();

		foreach ( array( 'payment-method-mappings', 'tax-mappings', 'price-mappings', 'stock-mappings', 'order-route-mappings', 'order-pricelist-mappings', 'gift-card-settings' ) as $k ) {
			$rows[ $k . ' read' ] = array( $k, 'GET', 'GET', 'dashboards/7/' . $k . '/' );
			$rows[ $k . ' save' ] = array( $k, 'PUT', 'POST', 'dashboards/7/' . $k . '/' );
		}

		foreach ( array( 'woocommerce-payment-methods', 'saved-woocommerce-payment-methods', 'odoo-journals', 'saved-odoo-journals', 'odoo-accounts', 'saved-odoo-accounts', 'woo-tax-classes', 'odoo-taxes', 'stored-odoo-taxes' ) as $k ) {
			$rows[ $k ] = array( $k, 'GET', 'GET', 'dashboards/7/' . $k . '/' );
		}

		$rows['fetch-and-store-odoo-taxes'] = array( 'fetch-and-store-odoo-taxes', 'POST', 'POST', 'dashboards/7/fetch-and-store-odoo-taxes/' );

		return $rows;
	}

	/**
	 * @dataProvider routes
	 *
	 * @param string $key    Route.
	 * @param string $method Browser method.
	 * @param string $django Django method.
	 * @param string $path   Django path.
	 */
	public function test_each_route_sends_one_signed_call_to_the_mapped_path( string $key, string $method, string $django, string $path ): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array() ) );

		$body = in_array( $method, array( 'PUT' ), true ) ? ( 'gift-card-settings' === $key ? array( 'settings' => array() ) : array( 'items' => array() ) ) : null;
		$res  = $this->rest( $method, $this->url( $l, $key ), $body );
		$call = $this->django_calls();

		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 1, $call );
		$this->assertSame( $django, $call[0]['method'] );
		$this->assertSame( '/api/' . $path, strtok( $call[0]['path_and_query'], '?' ) );
		$this->assert_signed( $call[0], $this->tenant( $l ) );
		$this->assertNotSame( 'PUT', $call[0]['method'], 'Django only ever gets GET or POST.' );
	}

	public function test_values_are_sent_byte_exact(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'message' => 'ok' ) ) );

		$this->rest( 'PUT', $this->url( $l, 'order-route-mappings' ), array( 'items' => array( array( 'meta_key' => 'k', 'meta_value' => ' v ' ) ) ) );

		$this->assertSame( ' v ', json_decode( $this->django_calls()[0]['body'], true )['mappings'][0]['meta_value'] );
	}

	public function test_save_replaces_the_list(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'message' => 'ok' ) ) );

		$this->rest(
			'PUT',
			$this->url( $l, 'payment-method-mappings' ),
			array(
				'items' => array(
					array( 'woocommerce_payment_method' => 'bacs', 'odoo_journal_id' => 5, 'odoo_journal_name' => 'Bank', 'id' => 99, 'evil' => 'x' ),
					array( 'woocommerce_payment_method' => 'cod', 'odoo_journal_id' => null ),
				),
			)
		);
		$call = $this->django_calls()[0];

		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame(
			array(
				'mappings' => array(
					array( 'woocommerce_payment_method' => 'bacs', 'odoo_journal_id' => 5, 'odoo_journal_name' => 'Bank' ),
					array( 'woocommerce_payment_method' => 'cod', 'odoo_journal_id' => null ),
				),
			),
			json_decode( $call['body'], true )
		);

		$this->fake_django( fn() => array( 200, array( 'message' => 'ok' ) ) );
		$this->rest( 'PUT', $this->url( $l, 'stock-mappings' ), array( 'items' => array() ) );
		$empty = $this->django_calls()[0];

		$this->assertSame( '{"mappings":[]}', $empty['body'], 'An empty list is allowed and sent.' );
	}

	public function test_gift_card_settings_are_sent_as_a_settings_object(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'message' => 'Gift card settings saved.' ) ) );

		$this->rest( 'PUT', $this->url( $l, 'gift-card-settings' ), array( 'settings' => array( 'enabled' => true, 'sale_tax_id' => 3, 'expiry_min_balance' => '1.50', 'nope' => 1 ) ) );

		$this->assertSame( array( 'settings' => array( 'enabled' => true, 'sale_tax_id' => 3, 'expiry_min_balance' => '1.50' ) ), json_decode( $this->django_calls()[0]['body'], true ) );
	}

	public function test_django_invalid_value_shown_as_is(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_value', 'message' => 'Row 2: choose a journal.', 'field' => 'journal_id' ) ) );

		$res  = $this->rest( 'PUT', $this->url( $l, 'payment-method-mappings' ), array( 'items' => array( array( 'woocommerce_payment_method' => 'bacs' ) ) ) );
		$data = $res->get_data();

		$this->assertSame( 400, $res->get_status() );
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'invalid_value', $data['code'] );
		$this->assertSame( 'Row 2: choose a journal.', $data['message'] );
		$this->assertSame( 'journal_id', $data['field'] );
	}

	public function test_gift_card_validation_message_shown_as_is(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_value', 'message' => 'The gift card journal is not one of your Odoo journals.', 'field' => 'redeem_journal_id' ) ) );

		$res  = $this->rest( 'PUT', $this->url( $l, 'gift-card-settings' ), array( 'settings' => array( 'redeem_journal_id' => 9 ) ) );
		$data = $res->get_data();

		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'The gift card journal is not one of your Odoo journals.', $data['message'] );
		$this->assertSame( 'redeem_journal_id', $data['field'] );
		$this->assertSame( 'invalid_value', $data['code'] );
	}

	public function test_paused_tenant_refuses_the_tax_loader_and_saves_but_not_reads(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn( $c ) => 'GET' === $c['method'] ? array( 200, array() ) : array( 409, array( 'code' => 'tenant_paused', 'message' => 'This account is paused.' ) ) );

		$this->assertSame( 200, $this->rest( 'GET', $this->url( $l, 'tax-mappings' ) )->get_status() );
		$res = $this->rest( 'POST', $this->url( $l, 'fetch-and-store-odoo-taxes' ) );
		$this->assertSame( 409, $res->get_status() );
		$this->assertFalse( $res->get_data()['ok'] );
		$this->assertSame( 'tenant_paused', $res->get_data()['code'] );
	}

	/**
	 * Replies that are empty or odd shape to an empty list, never a warning.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function empty_replies(): array {
		return array(
			'empty body'  => array( array() ),
			'null taxes'  => array( array( 'taxes' => null ) ),
			'null mappings' => array( array( 'mappings' => null ) ),
			'scalar rows' => array( array( 1, 'x', null ) ),
		);
	}

	/**
	 * @dataProvider empty_replies
	 *
	 * @param array<mixed> $data Django data.
	 */
	public function test_odoowoo_mistake_tax_refresh_empty_reply( array $data ): void {
		$this->assertSame( array( 'items' => array() ), Map::shape( 'odoo-taxes', 'GET', $data ) );
		$this->assertSame( array( 'items' => array() ), Map::shape( 'fetch-and-store-odoo-taxes', 'POST', $data ) );
		$this->assertSame( array( 'items' => array() ), Map::shape( 'stored-odoo-taxes', 'GET', $data ) );
	}

	public function test_lists_are_unwrapped_to_items_and_save_replies_untouched(): void {
		$rows = array( array( 'id' => 1, 'odoo_location_id' => 4 ) );

		$this->assertSame( array( 'items' => $rows ), Map::shape( 'stock-mappings', 'GET', array( 'mappings' => $rows ) ) );
		$this->assertSame( array( 'items' => array( array( 'id' => 1, 'odoo_journal_id' => 4 ) ) ), Map::shape( 'payment-method-mappings', 'GET', array( array( 'id' => 1, 'odoo_journal_id' => 4, 'medusa_customer_id' => 'c' ) ) ), 'Django answers this one with a bare list.' );
		$this->assertSame( array( 'message' => 'Saved 1 stock mapping(s).' ), Map::shape( 'stock-mappings', 'PUT', array( 'message' => 'Saved 1 stock mapping(s).' ) ) );
		$this->assertSame( array( 'settings' => array( 'enabled' => true ) ), Map::shape( 'gift-card-settings', 'GET', array( 'settings' => array( 'enabled' => true ) ) ) );
	}

	public function test_odoowoo_mistake_price_rows_keep_saved_pricelist(): void {
		$saved = array(
			array( 'id' => 1, 'source' => 'regular', 'odoo_pricelist_id' => 12, 'odoo_pricelist_name' => 'Retired list', 'target_field' => 'regular_price', 'target_meta_key' => '', 'tax_treatment' => 'as_is', 'require_explicit_rule' => false ),
			array( 'id' => 2, 'source' => 'sale', 'odoo_pricelist_id' => '3', 'target_field' => 'sale_price' ),
		);
		$out   = Map::shape( 'price-mappings', 'GET', array( 'mappings' => $saved ) )['items'];

		$this->assertSame( 12, $out[0]['odoo_pricelist_id'] );
		$this->assertSame( 'Retired list', $out[0]['odoo_pricelist_name'], 'A pricelist missing from the loaded list keeps its saved name.' );
		$this->assertSame( 3, $out[1]['odoo_pricelist_id'] );
		$this->assertSame( '', $out[1]['odoo_pricelist_name'] );
		$this->assertSame( 'regular_price', $out[0]['target_field'] );
	}

	public function test_price_save_sends_id_and_name(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'message' => 'ok' ) ) );

		$this->rest( 'PUT', $this->url( $l, 'price-mappings' ), array( 'items' => array( array( 'source' => 'regular', 'odoo_pricelist_id' => 12, 'odoo_pricelist_name' => 'Retail', 'target_field' => 'regular_price', 'require_explicit_rule' => true ) ) ) );
		$sent = json_decode( $this->django_calls()[0]['body'], true );

		$this->assertSame( 12, $sent['mappings'][0]['odoo_pricelist_id'] );
		$this->assertSame( 'Retail', $sent['mappings'][0]['odoo_pricelist_name'] );
		$this->assertTrue( $sent['mappings'][0]['require_explicit_rule'] );
	}

	public function test_no_row_uses_a_put_to_django_and_writes_are_derived(): void {
		foreach ( Map::ROUTES as $key => $row ) {
			foreach ( $row['methods'] as $pair ) {
				$this->assertContains( $pair[0], array( 'GET', 'POST', 'PATCH', 'DELETE' ), $key );
			}
		}

		$this->assertTrue( Map::is_write( 'fetch-and-store-odoo-taxes', 'POST' ) );
		$this->assertTrue( Map::is_write( 'gift-card-settings', 'PUT' ) );
		$this->assertFalse( Map::is_write( 'gift-card-settings', 'GET' ) );
	}

	/**
	 * Route-level shapes for each row family.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int, 3: array<mixed>, 4: array<mixed>}>
	 */
	public static function shaped(): array {
		$rows = array( array( 'id' => 1, 'meta_key' => 'k' ) );
		$pay  = array( array( 'id' => 1, 'woocommerce_payment_method' => 'bacs', 'odoo_journal_id' => 5 ) );

		return array(
			'bare list'        => array( 'payment-method-mappings', 'GET', 200, $pay, array( 'items' => $pay ) ),
			'mappings object'  => array( 'order-route-mappings', 'GET', 200, array( 'mappings' => $rows ), array( 'items' => $rows ) ),
			'tax mappings'     => array( 'tax-mappings', 'GET', 200, array( array( 'id' => 2, 'woocommerce_tax_class' => 'standard', 'odoo_customer_tax_id' => 3, 'odoo_supplier_tax_id' => 4 ) ), array( 'items' => array( array( 'id' => 2, 'woocommerce_tax_class' => 'standard', 'odoo_customer_tax_id' => 3, 'odoo_supplier_tax_id' => 4 ) ) ) ),
			'empty list'       => array( 'stock-mappings', 'GET', 200, array( 'mappings' => array() ), array( 'items' => array() ) ),
			'empty body'       => array( 'odoo-journals', 'GET', 200, array(), array( 'items' => array() ) ),
			'null taxes'       => array( 'odoo-taxes', 'GET', 200, array( 'taxes' => null ), array( 'items' => array() ) ),
			'loader no rows'   => array( 'fetch-and-store-odoo-taxes', 'POST', 204, array(), array( 'items' => array() ) ),
			'loader rows'      => array( 'fetch-and-store-odoo-taxes', 'POST', 200, array( array( 'id' => 3, 'name' => 'VAT 25', 'rate' => '25.00', 'type_tax_use' => 'sale' ) ), array( 'items' => array( array( 'id' => 3, 'name' => 'VAT 25', 'rate' => '25.00', 'type_tax_use' => 'sale' ) ) ) ),
			'prices'           => array( 'price-mappings', 'GET', 200, array( 'mappings' => array( array( 'id' => 1, 'source' => 'regular', 'odoo_pricelist_id' => 12, 'odoo_pricelist_name' => 'Retail', 'target_field' => 'regular_price', 'target_meta_key' => '', 'tax_treatment' => 'as_is', 'require_explicit_rule' => false ) ) ), array( 'items' => array( array( 'id' => 1, 'source' => 'regular', 'odoo_pricelist_id' => 12, 'odoo_pricelist_name' => 'Retail', 'target_field' => 'regular_price', 'target_meta_key' => '', 'tax_treatment' => 'as_is', 'require_explicit_rule' => false ) ) ) ),
			'gift card'        => array( 'gift-card-settings', 'GET', 200, array( 'settings' => array( 'enabled' => true ) ), array( 'settings' => array( 'enabled' => true ) ) ),
		);
	}

	/**
	 * @dataProvider shaped
	 *
	 * @param string       $key    Route.
	 * @param string       $method Browser method.
	 * @param int          $status Django status.
	 * @param array<mixed> $body   Django body.
	 * @param array<mixed> $expect Browser data.
	 */
	public function test_browser_gets_items_or_settings( string $key, string $method, int $status, array $body, array $expect ): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( $status, $body ) );

		$res = $this->rest( $method, $this->url( $l, $key ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( $expect, $res->get_data()['data'] );
	}

	public function test_save_reply_is_not_shaped(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'message' => 'Saved 0 stock mapping(s).' ) ) );

		$res = $this->rest( 'PUT', $this->url( $l, 'stock-mappings' ), array( 'items' => array() ) );

		$this->assertSame( array( 'message' => 'Saved 0 stock mapping(s).' ), $res->get_data()['data'] );
	}

	public function test_read_rows_carry_no_internal_fields(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( array( 'id' => 2, 'medusa_customer_id' => 'cust_1', 'dashboard' => 7, 'tenant' => 'u', 'woocommerce_tax_class' => 'standard', 'odoo_customer_tax_id' => 3, 'odoo_supplier_tax_id' => 4 ) ) ) );

		$res  = $this->rest( 'GET', $this->url( $l, 'tax-mappings' ) );
		$row  = $res->get_data()['data']['items'][0];
		$json = wp_json_encode( $res->get_data() );

		$this->assertSame( array( 'id' => 2, 'woocommerce_tax_class' => 'standard', 'odoo_customer_tax_id' => 3, 'odoo_supplier_tax_id' => 4 ), $row );
		$this->assertStringNotContainsString( 'medusa_customer_id', $json );
		$this->assertStringNotContainsString( 'cust_1', $json );
		$this->assertStringNotContainsString( '"dashboard"', $json );
	}

	public function test_tax_items_carry_ids_only(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'message' => 'ok' ) ) );

		$this->rest( 'PUT', $this->url( $l, 'tax-mappings' ), array( 'items' => array( array( 'woocommerce_tax_class' => 'standard', 'odoo_customer_tax_id' => 3, 'odoo_supplier_tax_id' => 4, 'odoo_customer_tax_name' => 'x' ) ) ) );

		$this->assertSame( array( 'mappings' => array( array( 'woocommerce_tax_class' => 'standard', 'odoo_customer_tax_id' => 3, 'odoo_supplier_tax_id' => 4 ) ) ), json_decode( $this->django_calls()[0]['body'], true ) );
	}

	/**
	 * Bodies whose items are not a list of rows are not forwarded as mappings.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function bad_items(): array {
		return array(
			'string'       => array( 'x' ),
			'object'       => array( array( 'a' => array( 'meta_key' => 'k' ) ) ),
			'scalar item'  => array( array( 'x' ) ),
			'nested scalar' => array( array( array( 'meta_key' => 'k' ), 5 ) ),
		);
	}

	/**
	 * @dataProvider bad_items
	 *
	 * @param mixed $items Items.
	 */
	public function test_items_that_are_not_a_list_of_rows_are_not_sent( $items ): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_request', 'message' => "Expected a 'mappings' list.", 'field' => 'mappings' ) ) );

		$res = $this->rest( 'PUT', $this->url( $l, 'order-route-mappings' ), array( 'items' => $items ) );

		$this->assertSame( '{}', $this->django_calls()[0]['body'], 'No mappings key: Django refuses it.' );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid_request', $res->get_data()['code'] );
	}

	public function test_django_error_on_a_get_loader_passes_through(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 400, array( 'code' => 'odoo_unavailable', 'message' => 'Could not read taxes from Odoo. Check the Odoo connection.' ) ) );

		$res = $this->rest( 'GET', $this->url( $l, 'odoo-taxes' ) );

		$this->assertSame( 400, $res->get_status() );
		$this->assertFalse( $res->get_data()['ok'] );
		$this->assertSame( 'odoo_unavailable', $res->get_data()['code'] );
		$this->assertSame( 'Could not read taxes from Odoo. Check the Odoo connection.', $res->get_data()['message'] );
		$this->assertArrayNotHasKey( 'data', $res->get_data() );
	}

	/**
	 * @dataProvider routes
	 *
	 * @param string $key    Route.
	 * @param string $method Browser method.
	 * @param string $django Django method.
	 */
	public function test_row_names_the_exact_django_method( string $key, string $method, string $django ): void {
		$this->assertSame( $django, Map::ROUTES[ $key ]['methods'][ $method ][0] );
		$this->assertSame( array( $method ), array_keys( array_filter( Map::ROUTES[ $key ]['methods'], fn( $m ) => $m[0] === $django && ( 'PUT' !== $method || 'POST' === $django ) ) ) );
	}
}
