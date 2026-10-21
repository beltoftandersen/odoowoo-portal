<?php
/**
 * The Odoo settings card: markup, strings, and the routes it saves through.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Api\Map;
use OdooWooPortal\Cards\Registry;
use OdooWooPortal\Cards\Renderer;

/**
 * Behaviour (dependencies, save body, picker) is in tests/js/deps.test.mjs.
 */
final class CardOdooSettingsTest extends RestTestCase {

	private const D = 7;

	/**
	 * Saved $_GET.
	 *
	 * @var array<string, mixed>
	 */
	private $get = array();

	/**
	 * The 16 checkboxes, the 2 selects, the pricelist and the 2 meta keys (a snapshot, see the
	 * snapshot test).
	 *
	 * @var string[]
	 */
	private const SETTINGS = array(
		'enable_auto_payment', 'enable_auto_payment_cn', 'enable_set_invoice_status', 'enable_reset_all_deliveries', 'enable_set_deliveries_to_waiting', 'enable_auto_complete_deliveries', 'confirm_pending_orders', 'price_includes_tax', 'order_incl_tax',
		'enable_brand_sync', 'enable_category_sync', 'enable_product_name_sync', 'enable_tag_sync', 'enable_image_sync', 'enable_price_sync', 'enable_barcode_sync',
		'capture_stage', 'quantity_settings', 'odoo_order_pricelist_id', 'stock_sku_meta_keys', 'stock_units_meta_keys',
	);

	public function set_up(): void {
		parent::set_up();
		$this->get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		Renderer::reset();
		$GLOBALS['wp_scripts'] = new \WP_Scripts();
		$GLOBALS['wp_styles']  = new \WP_Styles();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$_GET = $this->get;
		Renderer::reset();
		parent::tear_down();
	}

	private function render_as_owner(): string {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => (string) self::D,
		);

		$html = Renderer::render( 'odoo-settings' );
		$this->assertSame( array(), $this->django_calls(), 'Rendering makes no Django call.' );

		return $html;
	}

	private function url( array $l, string $route ): string {
		return '/licenses/' . $l['id'] . '/dashboards/' . self::D . '/' . $route;
	}

	public function test_every_setting_has_label_and_keyboard_tooltip(): void {
		$html = $this->render_as_owner();

		$this->assertStringContainsString( 'data-odoowoo-card="odoo-settings"', $html );

		foreach ( self::SETTINGS as $name ) {
			$this->assertMatchesRegularExpression( '#<(input|select)\b[^>]*\bname="' . $name . '"[^>]*>#', $html, $name );
			preg_match( '#<(?:input|select)\b[^>]*\bname="' . $name . '"[^>]*>#', $html, $field );
			$this->assertMatchesRegularExpression( '#\bid="([^"]+)"#', $field[0], $name );
			preg_match( '#\bid="([^"]+)"#', $field[0], $id );
			$this->assertMatchesRegularExpression( '#<label[^>]*\bfor="' . preg_quote( $id[1], '#' ) . '"[^>]*>\s*\S[^<]*</label>#', $html, 'A label for ' . $name );

			// A help button, reachable by keyboard, pointing at text that is in the DOM.
			$this->assertMatchesRegularExpression( '#<button\b[^>]*type="button"[^>]*data-help-for="' . $name . '"[^>]*>#', $html, 'Help button for ' . $name );
			preg_match( '#<button\b[^>]*data-help-for="' . $name . '"[^>]*>#', $html, $button );
			$this->assertDoesNotMatchRegularExpression( '#\btabindex="-1"#', $button[0], $name );
			$this->assertMatchesRegularExpression( '#aria-describedby="([^"]+)"#', $button[0], $name );
			preg_match( '#aria-describedby="([^"]+)"#', $button[0], $target );
			$this->assertMatchesRegularExpression( '#<[a-z]+[^>]*\bid="' . preg_quote( $target[1], '#' ) . '"[^>]*>\s*\S[^<]*</#', $html, 'Tooltip text for ' . $name );
			$this->assertDoesNotMatchRegularExpression( '#<button[^>]*\btitle=#', $button[0], 'Not title only: ' . $name );

			// Somewhere for Django's message next to the input.
			$this->assertMatchesRegularExpression( '#data-error-for="' . $name . '"#', $html, 'Error place for ' . $name );
		}

		$this->assertSame( 16, preg_match_all( '#<input[^>]*type="checkbox"#', $html ) );
		$this->assertMatchesRegularExpression( '#<select[^>]*name="capture_stage"[^>]*>.*value="pending".*value="on_hold".*value="processing"#s', $html );
		$this->assertMatchesRegularExpression( '#<select[^>]*name="quantity_settings"[^>]*>.*value="on_hand".*value="forecast".*value="available"#s', $html );
		$this->assertStringContainsString( 'Let Odoo decide from the customer', $html );
		$this->assertMatchesRegularExpression( '#<button[^>]*data-action="load-pricelists"#', $html );
		$this->assertMatchesRegularExpression( '#<button[^>]*type="submit"[^>]*>\s*Save\s*<#', $html );

		foreach ( array( 'Enable auto payments on invoices', 'Enable auto payments on credit notes', 'Set invoice status back to to_invoice', 'Reset delivery back to draft', 'Set deliveries to waiting', 'Close deliveries when an order is paid', 'Add tax on product price during sync', 'Sync orders with prices incl. tax', 'Sync brands to Odoo', 'Sync categories to Odoo', 'Sync product name to Odoo', 'Sync tags to Odoo', 'Sync product images to Odoo', 'Let Odoo control prices in WooCommerce', 'Let Odoo control barcodes in WooCommerce', 'Capture order at status', 'Quantity setting', 'Pricelist for orders from this store', 'Confirm pending orders automatically', 'Line meta key naming the stock product', 'Line meta key naming the units per item' ) as $label ) {
			$this->assertMatchesRegularExpression( '#<label[^>]*>\s*' . preg_quote( $label, '#' ) . '\s*</label>#', $html, $label );
		}

		$this->assertTrue( wp_script_is( 'odoowoo-card-odoo-settings', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'odoowoo-deps', 'enqueued' ), 'deps.js is loaded with this card.' );
		$deps = $GLOBALS['wp_scripts']->registered['odoowoo-card-odoo-settings']->deps;
		$this->assertContains( 'odoowoo-deps', $deps );
	}

	public function test_dependency_strings_are_the_exact_ones(): void {
		$s = Registry::cards()['odoo-settings']['strings'];

		$this->assertSame( 'Needs payment method mappings.', $s['needsPayment'] );
		$this->assertSame( 'Needs tax mappings.', $s['needsTax'] );
		$this->assertSame( 'Needs a price mapping.', $s['needsPrice'] );
		$this->assertSame( 'Brand sync is not part of your plan.', $s['needsPlan'] );
		$this->assertSame( array( 'odoo-settings', 'odoo-pricelists', 'payment-method-mappings', 'tax-mappings', 'price-mappings' ), Registry::cards()['odoo-settings']['keys'] );
	}

	public function test_save_sends_only_known_fields_and_django_error_shown_next_to_field(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_value', 'message' => 'Add at least one price mapping before enabling the price sync.', 'field' => 'enable_price_sync' ) ) ); // Views.py:704 (staging Django c2ae25d).

		$body = array(
			'odoo_url'                 => 'https://o.example',
			'odoo_db'                  => 'db',
			'odoo_user'                => 'u',
			'odoo_order_pricelist_id'  => null,
			'enable_price_sync'        => true,
			'enable_brand_sync'        => false,
			'capture_stage'            => 'processing',
			'quantity_settings'        => 'on_hand',
			'stock_sku_meta_keys'      => '',
			'brand_sync_allowed'       => true,
			'has_odoo_password'        => true,
			'tenant'                   => 'evil',
			'dashboard'                => 99,
		);
		$res  = $this->rest( 'PUT', $this->url( $l, 'odoo-settings' ), $body );
		$sent = json_decode( $this->django_calls()[0]['body'], true );
		$call = $this->django_calls()[0];

		$this->assertSame( 'POST', $call['method'] );
		$this->assertStringEndsWith( '/dashboards/' . self::D . '/odoo-settings/', $call['path_and_query'] );
		$this->assertSame( array_keys( $sent ), array_values( array_intersect( array_keys( $sent ), Map::ROUTES['odoo-settings']['fields'] ) ), 'Only known fields are forwarded.' );
		$this->assertArrayNotHasKey( 'brand_sync_allowed', $sent );
		$this->assertArrayNotHasKey( 'tenant', $sent );
		$this->assertArrayNotHasKey( 'dashboard', $sent );
		$this->assertTrue( $sent['enable_price_sync'], 'A real boolean.' );
		$this->assertFalse( $sent['enable_brand_sync'] );
		// Map::filter_body drops null (not a scalar). Django reads an absent id as None
		// (`request.data.get('odoo_order_pricelist_id') or None`, views.py:638), the same as null.
		$this->assertArrayNotHasKey( 'odoo_order_pricelist_id', $sent );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'enable_price_sync', $res->get_data()['field'] );
		$this->assertSame( 'Add at least one price mapping before enabling the price sync.', $res->get_data()['message'] );
	}

	public function test_brand_sync_toggle_is_not_the_security_boundary(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 403, array( 'code' => 'plan_feature_unavailable', 'message' => 'Brand sync is not included in your plan.', 'field' => 'enable_brand_sync' ) ) ); // Views.py:671, api/triggers.py:35.

		$res = $this->rest( 'PUT', $this->url( $l, 'odoo-settings' ), array( 'odoo_url' => 'https://o.example', 'odoo_db' => 'db', 'odoo_user' => 'u', 'enable_brand_sync' => true ) );
		$calls = $this->django_calls();

		$this->assertCount( 1, $calls, 'The portal forwards it; Django decides.' );
		$this->assertSame( 'POST', $calls[0]['method'] );
		$this->assertTrue( json_decode( $calls[0]['body'], true )['enable_brand_sync'] );
		$this->assertSame( 409, $res->get_status(), 'Django 403 arrives as 409.' );
		$this->assertSame( 'plan_feature_unavailable', $res->get_data()['code'] );
		$this->assertSame( 'Brand sync is not included in your plan.', $res->get_data()['message'] );
		$this->assertSame( 'enable_brand_sync', $res->get_data()['field'] );
	}

	/**
	 * Snapshot of the field list Django's manage_odoo_settings reads. Source: Django branch at
	 * staging commit c2ae25d (/root/projects/beltoft/odoowoo-staging), sync_app/views.py lines
	 * 629-654 (request.data.get(...) for each name) and 744-760 (the two meta keys); the 4a755dd
	 * commit of the Django plan had the same names. The real check is Task 18, which walks Save
	 * against the staging Django.
	 */
	public function test_odoo_settings_field_list_is_a_snapshot(): void {
		$names = array( 'odoo_url', 'odoo_db', 'odoo_user', 'odoo_password', 'enable_auto_payment', 'enable_auto_payment_cn', 'enable_set_invoice_status', 'enable_reset_all_deliveries', 'enable_set_deliveries_to_waiting', 'enable_auto_complete_deliveries', 'odoo_order_pricelist_id', 'odoo_order_pricelist_name', 'capture_stage', 'confirm_pending_orders', 'price_includes_tax', 'order_incl_tax', 'quantity_settings', 'enable_brand_sync', 'enable_category_sync', 'enable_product_name_sync', 'enable_tag_sync', 'enable_image_sync', 'enable_price_sync', 'enable_barcode_sync', 'stock_sku_meta_keys', 'stock_units_meta_keys' );

		$this->assertCount( 26, $names );
		$this->assertSame( $names, Map::ROUTES['odoo-settings']['fields'] );

		// The card's own inputs are all among them, and the JS store sends the same list.
		foreach ( self::SETTINGS as $name ) {
			$this->assertContains( $name, $names, $name );
		}

		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/core.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		preg_match( "#odooSettingsStore = createStore\( \{\s*fields: \[([^\]]+)\]#", $js, $m );
		preg_match_all( "#'([a-z_]+)'#", $m[1], $store );
		$this->assertSame( $names, $store[1], 'core.js store field list equals the snapshot.' );
	}

	public function test_price_control_and_pricelist_picker_load_from_odoo_route(): void {
		// Rendering never calls Django: the picker loads on demand (render_as_owner checks it).
		$html = $this->render_as_owner();
		$this->assertMatchesRegularExpression( '#<select[^>]*name="odoo_order_pricelist_id"#', $html );
		$this->assertMatchesRegularExpression( '#<button[^>]*data-action="load-pricelists"#', $html );

		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array( 'pricelists' => array( array( 'id' => 5, 'name' => 'Public Pricelist (EUR)', 'currency' => 'EUR' ) ) ) ) ); // Views.py:1068.

		$res = $this->rest( 'GET', $this->url( $l, 'odoo-pricelists' ) );
		$calls = $this->django_calls();

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( array( 'id' => 5, 'name' => 'Public Pricelist (EUR)', 'currency' => 'EUR' ) ), $res->get_data()['data']['items'] );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'GET', $calls[0]['method'] );
		$this->assertStringEndsWith( '/dashboards/' . self::D . '/odoo-pricelists/', $calls[0]['path_and_query'] );
	}

	public function test_every_odoo_settings_string_is_a_non_empty_string(): void {
		$strings = Registry::cards()['odoo-settings']['strings'];
		$this->assertNotEmpty( $strings );

		foreach ( $strings as $key => $value ) {
			$this->assertIsString( $value, $key );
			$this->assertNotSame( '', $value, $key );
		}
	}
}
