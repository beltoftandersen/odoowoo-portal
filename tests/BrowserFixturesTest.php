<?php
/**
 * The browser replies the JS tests are written against, produced by the real routes.
 *
 * Every fixture is {status, body}: what the REST server really answers for a faked Django reply
 * (each cited from the Django view or the test that cites it). A test fails when a file in
 * tests/js/fixtures differs from what the routes answer now. To regenerate after a deliberate
 * route change:
 *
 *   ODOOWOO_REGEN_FIXTURES=1 /root/plugin-tests/run.sh <plugin dir> --filter BrowserFixturesTest
 *
 * then run the node tests and commit the changed files.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Tasks;

/**
 * Generates and checks tests/js/fixtures/*.json.
 */
final class BrowserFixturesTest extends RestTestCase {

	private const D = 7;

	/**
	 * Fixtures made so far: name => {status, body}.
	 *
	 * @var array<string, array{status: int, body: mixed}>
	 */
	private $made = array();

	private function url( array $l, string $route ): string {
		return '/licenses/' . $l['id'] . '/dashboards/' . self::D . '/' . $route;
	}

	/**
	 * Records a reply as a fixture.
	 *
	 * @param string            $name Name.
	 * @param \WP_REST_Response $res  Reply.
	 */
	private function keep( string $name, \WP_REST_Response $res ): void {
		$this->made[ $name ] = array(
			'status' => $res->get_status(),
			'body'   => json_decode( (string) wp_json_encode( $res->get_data() ), false ),
		);
	}

	/**
	 * A task reply through the status route.
	 *
	 * @param string               $name  Fixture name.
	 * @param array<string, mixed> $l     License.
	 * @param array<string, mixed> $reply Django reply.
	 * @param int                  $code  Django status.
	 */
	private function task( string $name, array $l, array $reply, int $code = 200 ): void {
		Tasks::remember( (int) $l['user_id'], (int) $l['id'], self::D, 'products', 'tk-1' );
		$this->fake_django( fn() => array( $code, $reply ) );
		$this->keep( $name, $this->rest( 'GET', $this->url( $l, 'task-status/tk-1' ) ) );
	}

	/**
	 * A fresh owner (each verify/loader call is rate limited per user), logged in.
	 *
	 * @return array<string, mixed> License.
	 */
	private function fresh(): array {
		$lic = $this->odoowoo_license();
		$this->as_user( (int) $lic['user_id'] );
		\OdooWooPortal\Context::reset();
		\OdooWooPortal\Api\Routes::reset();
		\OdooWooPortal\Prefixes::forget( (int) $lic['user_id'] );

		return $lic;
	}

	/**
	 * Task 10: the connection cards. Every Django body is copied from sync_app/views.py of the
	 * Django branch at 5ab091e (/root/projects/beltoft/odoowoo-tenants); middleware.py reshapes
	 * `{success:false, message}` bodies to `{code: invalid_request, success:false, message}`.
	 */
	private function connection_fixtures(): void {
		$u   = static fn( array $l, string $route ): string => '/licenses/' . $l['id'] . '/dashboards/' . self::D . '/' . $route;
		$rows = array( array( 'id' => self::D, 'name' => 'Shop', 'enabled' => true, 'created_at' => '2026-10-21T12:00:00Z' ), array( 'id' => 8, 'name' => 'Other', 'enabled' => true, 'created_at' => '2026-10-21T12:00:00Z' ) );

		// Woo keys GET, saved keys: views.py:272-283. The other dashboard (8) uses prefix AB; this one WS.
		$l = $this->fresh();
		$own = array( 'consumer_key' => 'ck_1', 'consumer_secret' => '', 'has_consumer_secret' => true, 'woocommerce_url' => 'https://shop.example', 'prefix' => 'WS', 'enable_stock_update' => true, 'change_status_delivery' => false, 'has_webhook_secret' => true, 'require_webhook_signature' => true, 'used_prefixes' => array( 'WS', 'AB' ) );
		$this->django_map(
			array(
				'GET dashboards/'              => array( 200, $rows ),
				'GET dashboards/7/woo-keys/'   => array( 200, $own ),
				'GET dashboards/8/woo-keys/'   => array( 200, array_merge( $own, array( 'prefix' => 'AB' ) ) ),
			)
		);
		$this->keep( 'woo_keys_saved', $this->rest( 'GET', $u( $l, 'woo-keys' ) ) );

		// Woo keys GET when the other dashboard's read failed (500, not in the map): the portal's list is partial; Django's own list (views.py:272-283) still names AB.
		$l = $this->fresh();
		$this->django_map(
			array(
				'GET dashboards/'            => array( 200, $rows ),
				'GET dashboards/7/woo-keys/' => array( 200, $own ),
			)
		);
		$this->keep( 'woo_keys_partial', $this->rest( 'GET', $u( $l, 'woo-keys' ) ) );

		// Woo keys GET, nothing saved yet: views.py:285-295 (no has_consumer_secret key at all).
		$l = $this->fresh();
		$new = array( 'consumer_key' => '', 'consumer_secret' => '', 'woocommerce_url' => '', 'prefix' => '', 'enable_stock_update' => true, 'change_status_delivery' => true, 'has_webhook_secret' => false, 'require_webhook_signature' => false, 'used_prefixes' => array() );
		$this->django_map(
			array(
				'GET dashboards/'            => array( 200, array( $rows[0] ) ),
				'GET dashboards/7/woo-keys/' => array( 200, $new ),
			)
		);
		$this->keep( 'woo_keys_new', $this->rest( 'GET', $u( $l, 'woo-keys' ) ) );

		$l = $this->fresh();
		$this->fake_django( fn() => array( 201, array( 'message' => 'WooCommerce keys created successfully.' ) ) ); // Views.py:365.
		$this->keep( 'woo_save_created', $this->rest( 'PUT', $u( $l, 'woo-keys' ), array( 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_dummy', 'woocommerce_url' => 'https://shop.example', 'prefix' => 'WS', 'enable_stock_update' => true, 'change_status_delivery' => true ) ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_value', 'message' => 'That order prefix is already used by another of your dashboards.', 'field' => 'prefix' ) ) ); // Views.py:358-361.
		$this->keep( 'woo_prefix_taken', $this->rest( 'PUT', $u( $l, 'woo-keys' ), array( 'consumer_key' => 'ck_1', 'woocommerce_url' => 'https://shop.example', 'prefix' => 'AB' ) ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_value', 'message' => 'The WooCommerce consumer secret is required for the first save.', 'field' => 'consumer_secret' ) ) ); // Views.py:336-339.
		$this->keep( 'woo_secret_required', $this->rest( 'PUT', $u( $l, 'woo-keys' ), array( 'consumer_key' => 'ck_1', 'woocommerce_url' => 'https://shop.example' ) ) );

		// Verify WooCommerce: success views.py:2186-2189; failures 2206-2209 (reshaped by middleware.py:165-166) and 2211-2216.
		$l = $this->fresh();
		$this->fake_django( fn() => array( 200, array( 'success' => true, 'message' => 'WooCommerce connection verified and credentials updated successfully.' ) ) );
		$this->keep( 'woo_verify_success', $this->rest( 'POST', $u( $l, 'verify-woocommerce' ), array( 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_dummy', 'woocommerce_url' => 'https://shop.example' ) ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_request', 'success' => false, 'message' => 'Failed to fetch products. Check your credentials.' ) ) );
		$this->keep( 'woo_verify_failed', $this->rest( 'POST', $u( $l, 'verify-woocommerce' ), array( 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_dummy', 'woocommerce_url' => 'https://shop.example' ) ) );
		$this->fake_django( fn() => array( 502, array( 'code' => 'woocommerce_unavailable', 'success' => false, 'message' => 'Could not verify the WooCommerce connection. Check your store URL and keys and try again.' ) ) );
		$this->keep( 'woo_verify_unavailable', $this->rest( 'POST', $u( $l, 'verify-woocommerce' ), array( 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_dummy', 'woocommerce_url' => 'https://shop.example' ) ) );

		// Transfer webhooks: views.py:533-540, 521-524, 399-400.
		$l = $this->fresh();
		$this->fake_django( fn() => array( 200, array( 'message' => 'Created WooCommerce webhooks successfully.', 'created' => array( 'order.created', 'order.updated' ), 'skipped' => array(), 'secret_rotated' => true ) ) );
		$this->keep( 'woo_webhooks_success', $this->rest( 'POST', $u( $l, 'inject-woo-webhooks' ) ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'webhooks_incomplete', 'message' => 'Some webhooks could not be configured.', 'created' => array(), 'skipped' => array(), 'failed' => array( array( 'topic' => 'order.created', 'error' => 'WooCommerce could not create this webhook.' ) ) ) ) );
		$this->keep( 'woo_webhooks_incomplete', $this->rest( 'POST', $u( $l, 'inject-woo-webhooks' ) ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'woocommerce_not_configured', 'message' => 'Save your WooCommerce connection settings first.' ) ) );
		$this->keep( 'woo_webhooks_not_configured', $this->rest( 'POST', $u( $l, 'inject-woo-webhooks' ) ) );

		// Odoo settings GET: saved views.py:563-598, nothing saved 600-626 (no has_odoo_password, plus a message).
		$l      = $this->fresh();
		$saved  = array( 'odoo_url' => 'https://odoo.example', 'odoo_db' => 'prod', 'odoo_user' => 'api@example.com', 'odoo_password' => '', 'has_odoo_password' => true, 'has_middleware_api_key' => true, 'enable_auto_payment' => true, 'enable_auto_payment_cn' => false, 'enable_set_invoice_status' => false, 'enable_reset_all_deliveries' => false, 'enable_set_deliveries_to_waiting' => false, 'enable_auto_complete_deliveries' => true, 'odoo_order_pricelist_id' => 3, 'odoo_order_pricelist_name' => 'Public', 'capture_stage' => 'processing', 'confirm_pending_orders' => false, 'price_includes_tax' => false, 'order_incl_tax' => true, 'quantity_settings' => 'on_hand', 'enable_brand_sync' => false, 'brand_sync_allowed' => false, 'enable_category_sync' => true, 'enable_product_name_sync' => true, 'enable_tag_sync' => true, 'enable_image_sync' => true, 'enable_price_sync' => false, 'enable_barcode_sync' => false, 'stock_sku_meta_keys' => '', 'stock_units_meta_keys' => '' );
		$this->fake_django( fn() => array( 200, $saved ) );
		$this->keep( 'odoo_settings_saved', $this->rest( 'GET', $u( $l, 'odoo-settings' ) ) );
		$blank = array_merge( $saved, array( 'odoo_url' => '', 'odoo_db' => '', 'odoo_user' => '', 'has_middleware_api_key' => false, 'enable_auto_payment' => false, 'enable_auto_complete_deliveries' => false, 'odoo_order_pricelist_id' => null, 'odoo_order_pricelist_name' => '', 'message' => 'No Odoo settings found. Please save them.' ) );
		unset( $blank['has_odoo_password'] );
		$this->fake_django( fn() => array( 200, $blank ) );
		$this->keep( 'odoo_settings_new', $this->rest( 'GET', $u( $l, 'odoo-settings' ) ) );

		$this->fake_django( fn() => array( 200, array( 'message' => 'Odoo settings saved.' ) ) ); // Views.py:774.
		$this->keep( 'odoo_save_success', $this->rest( 'PUT', $u( $l, 'odoo-settings' ), array( 'odoo_url' => 'https://odoo.example', 'odoo_db' => 'prod', 'odoo_user' => 'api@example.com', 'odoo_password' => 'pw_dummy' ) ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_value', 'message' => 'Password is required for initial setup.', 'field' => 'odoo_password' ) ) ); // Views.py:711.
		$this->keep( 'odoo_password_required', $this->rest( 'PUT', $u( $l, 'odoo-settings' ), array( 'odoo_url' => 'https://odoo.example', 'odoo_db' => 'prod', 'odoo_user' => 'api@example.com' ) ) );

		// Verify Odoo: views.py:2287-2291 (both messages), 2256-2259 (reshaped), 2295-2299.
		$l = $this->fresh();
		$this->fake_django( fn() => array( 200, array( 'success' => true, 'message' => 'Odoo connection verified successfully.', 'helper_installed' => true ) ) );
		$this->keep( 'odoo_verify_helper', $this->rest( 'POST', $u( $l, 'verify-odoo' ), array( 'odoo_url' => 'https://odoo.example', 'odoo_db' => 'prod', 'odoo_user' => 'api@example.com' ) ) );
		$this->fake_django( fn() => array( 200, array( 'success' => true, 'message' => 'Odoo connection verified, but the OdooWoo Helper module is not installed.', 'helper_installed' => false ) ) );
		$this->keep( 'odoo_verify_no_helper', $this->rest( 'POST', $u( $l, 'verify-odoo' ), array( 'odoo_url' => 'https://odoo.example', 'odoo_db' => 'prod', 'odoo_user' => 'api@example.com' ) ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_request', 'success' => false, 'message' => 'Authentication with Odoo failed. Check your credentials.' ) ) );
		$this->keep( 'odoo_verify_failed', $this->rest( 'POST', $u( $l, 'verify-odoo' ), array( 'odoo_url' => 'https://odoo.example', 'odoo_db' => 'prod', 'odoo_user' => 'api@example.com' ) ) );

		// Set up Odoo callbacks (generate-api-key): views.py:216, 217-222 (400 since c2ae25d), 225-229.
		$l = $this->fresh();
		$this->fake_django( fn() => array( 200, array( 'message' => 'API key generated and transferred to Odoo successfully.' ) ) );
		$this->keep( 'callback_success', $this->rest( 'POST', $u( $l, 'generate-api-key' ) ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'odoo_not_configured', 'message' => 'Save your Odoo connection settings first.' ) ) );
		$this->keep( 'callback_not_configured', $this->rest( 'POST', $u( $l, 'generate-api-key' ) ) );
		$this->fake_django( fn() => array( 500, array( 'code' => 'odoo_unavailable', 'message' => 'Could not send the API key to Odoo. Check the Odoo connection and try again.' ) ) );
		$this->keep( 'callback_unavailable', $this->rest( 'POST', $u( $l, 'generate-api-key' ) ) );
	}

	/**
	 * Task 11: the Odoo settings card. Django bodies copied from sync_app/views.py of the staging
	 * Django (c2ae25d, /root/projects/beltoft/odoowoo-staging).
	 */
	private function odoo_settings_fixtures(): void {
		$u = static fn( array $l, string $route ): string => '/licenses/' . $l['id'] . '/dashboards/' . self::D . '/' . $route;
		$l = $this->fresh();

		// GET with the plan: views.py:563-598 (brand_sync_allowed true, so enable_brand_sync is what is stored).
		$allowed = array( 'odoo_url' => 'https://odoo.example', 'odoo_db' => 'prod', 'odoo_user' => 'api@example.com', 'odoo_password' => '', 'has_odoo_password' => true, 'has_middleware_api_key' => true, 'enable_auto_payment' => true, 'enable_auto_payment_cn' => false, 'enable_set_invoice_status' => false, 'enable_reset_all_deliveries' => false, 'enable_set_deliveries_to_waiting' => false, 'enable_auto_complete_deliveries' => true, 'odoo_order_pricelist_id' => 3, 'odoo_order_pricelist_name' => 'Public', 'capture_stage' => 'pending', 'confirm_pending_orders' => true, 'price_includes_tax' => false, 'order_incl_tax' => true, 'quantity_settings' => 'on_hand', 'enable_brand_sync' => true, 'brand_sync_allowed' => true, 'enable_category_sync' => true, 'enable_product_name_sync' => true, 'enable_tag_sync' => true, 'enable_image_sync' => true, 'enable_price_sync' => false, 'enable_barcode_sync' => false, 'stock_sku_meta_keys' => '_bss_stock_sku', 'stock_units_meta_keys' => '' );
		$this->fake_django( fn() => array( 200, $allowed ) );
		$this->keep( 'odoo_settings_brand_allowed', $this->rest( 'GET', $u( $l, 'odoo-settings' ) ) );

		// The refusals of the POST: views.py:671 (BRANDS_NOT_IN_PLAN, api/triggers.py:35; Django 403 arrives as 409), 681-686, 693-698, 704.
		$l    = $this->fresh();
		$body = array_diff_key( $allowed, array( 'has_odoo_password' => 1, 'has_middleware_api_key' => 1, 'brand_sync_allowed' => 1, 'odoo_password' => 1 ) );
		$this->fake_django( fn() => array( 403, array( 'code' => 'plan_feature_unavailable', 'message' => 'Brand sync is not included in your plan.', 'field' => 'enable_brand_sync' ) ) );
		$this->keep( 'odoo_settings_brand_refused', $this->rest( 'PUT', $u( $l, 'odoo-settings' ), $body ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_value', 'message' => 'You cannot enable auto payments (invoices or credit notes) without any payment method mappings.', 'field' => 'enable_auto_payment' ) ) );
		$this->keep( 'odoo_settings_auto_payment_refused', $this->rest( 'PUT', $u( $l, 'odoo-settings' ), $body ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_value', 'message' => "You cannot enable 'Add tax on product price during sync' without any tax mappings.", 'field' => 'price_includes_tax' ) ) );
		$this->keep( 'odoo_settings_price_tax_refused', $this->rest( 'PUT', $u( $l, 'odoo-settings' ), $body ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_value', 'message' => 'Add at least one price mapping before enabling the price sync.', 'field' => 'enable_price_sync' ) ) );
		$this->keep( 'odoo_settings_price_sync_refused', $this->rest( 'PUT', $u( $l, 'odoo-settings' ), $body ) );

		// Pricelists: views.py:1068 {"pricelists": [...]} (shaped to items by Api\Map); not configured: views.py:110, 125.
		$l = $this->fresh();
		$this->fake_django( fn() => array( 200, array( 'pricelists' => array( array( 'id' => 5, 'name' => 'Public Pricelist (EUR)', 'currency' => 'EUR' ), array( 'id' => 6, 'name' => 'Retail DKK', 'currency' => 'DKK' ) ) ) ) );
		$this->keep( 'odoo_pricelists', $this->rest( 'GET', $u( $l, 'odoo-pricelists' ) ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'odoo_not_configured', 'message' => 'Save your Odoo connection settings first.' ) ) );
		$this->keep( 'odoo_pricelists_not_configured', $this->rest( 'GET', $u( $l, 'odoo-pricelists' ) ) );

		// The three mapping lists: payment views.py:2037-2048 (bare list), tax views.py:2351 (serializer rows), price views.py:954-966 ({mappings}).
		$l = $this->fresh();
		$this->fake_django( fn() => array( 200, array( array( 'id' => 1, 'woocommerce_payment_method' => 'bacs', 'woocommerce_payment_method_name' => 'Bank transfer', 'odoo_journal_id' => 8, 'odoo_journal_name' => 'Bank', 'payment_ref_meta_keys' => '' ) ) ) );
		$this->keep( 'payment_mappings_list', $this->rest( 'GET', $u( $l, 'payment-method-mappings' ) ) );
		$this->fake_django( fn() => array( 200, array() ) );
		$this->keep( 'payment_mappings_empty', $this->rest( 'GET', $u( $l, 'payment-method-mappings' ) ) );
		$this->fake_django( fn() => array( 200, array( array( 'id' => 1, 'woocommerce_tax_class' => 'standard', 'odoo_customer_tax_id' => 3, 'odoo_supplier_tax_id' => null ) ) ) );
		$this->keep( 'tax_mappings_list', $this->rest( 'GET', $u( $l, 'tax-mappings' ) ) );
		$this->fake_django( fn() => array( 200, array() ) );
		$this->keep( 'tax_mappings_empty', $this->rest( 'GET', $u( $l, 'tax-mappings' ) ) );
		$this->fake_django( fn() => array( 200, array( 'mappings' => array( array( 'id' => 1, 'source' => 'pricelist', 'odoo_pricelist_id' => 5, 'odoo_pricelist_name' => 'Public', 'target_field' => 'regular_price', 'target_meta_key' => '', 'tax_treatment' => 'as_is', 'require_explicit_rule' => false ) ) ) ) );
		$this->keep( 'price_mappings_list', $this->rest( 'GET', $u( $l, 'price-mappings' ) ) );
		$this->fake_django( fn() => array( 200, array( 'mappings' => array() ) ) );
		$this->keep( 'price_mappings_empty', $this->rest( 'GET', $u( $l, 'price-mappings' ) ) );
	}

	/**
	 * Task 12: the six mapping editors. The configs are what the cards print; every Django body
	 * is copied from sync_app/views.py of the Django branch at 5ab091e (line numbers below; the
	 * file is the same as at 4a755dd apart from two lines far from these).
	 */
	private function mapping_fixtures(): void {
		$u = static fn( array $l, string $route ): string => '/licenses/' . $l['id'] . '/dashboards/' . self::D . '/' . $route;

		foreach ( \OdooWooPortal\Cards\Mappings::CARDS as $card ) {
			$this->made[ 'mapping_config_' . str_replace( '-', '_', $card ) ] = array(
				'status' => 200,
				'body'   => json_decode( (string) wp_json_encode( \OdooWooPortal\Cards\Mappings::config( $card ) ), false ),
			);
		}

		$reads = array(
			// Payment: views.py:803-815 (bare list), 1992-1996, 2012-2016, 2037-2048.
			'woo_payment_methods'        => array( 'woocommerce-payment-methods', 200, array( array( 'id' => 'bacs', 'title' => 'Direct bank transfer' ), array( 'id' => 'cod', 'title' => 'Cash on delivery' ) ) ),
			'odoo_journals'              => array( 'odoo-journals', 200, array( array( 'id' => 8, 'name' => 'Bank' ), array( 'id' => 9, 'name' => 'Cash' ) ) ), // 849.
			'odoo_journals_empty'        => array( 'odoo-journals', 200, array() ),
			'saved_woo_payment_methods'  => array( 'saved-woocommerce-payment-methods', 200, array( array( 'method_id' => 'bacs', 'title' => 'Direct bank transfer' ) ) ),
			'saved_odoo_journals'        => array( 'saved-odoo-journals', 200, array( array( 'journal_id' => 8, 'name' => 'Bank' ) ) ),
			'woo_not_configured'         => array( 'woocommerce-payment-methods', 400, array( 'code' => 'woocommerce_not_configured', 'message' => 'Save your WooCommerce connection settings first.' ) ), // 111, 136-143.
			// Tax: 2422-2426 and 2457 (classes), 2587-2591 (stored), 2556-2566 (fetch and store), 2535 (204).
			'woo_tax_classes'            => array( 'woo-tax-classes', 200, array( array( 'label' => 'Standard rate', 'value' => 'standard' ), array( 'label' => 'Reduced rate', 'value' => 'reduced-rate' ), array( 'label' => 'Zero rate', 'value' => 'zero-rate' ) ) ),
			'stored_odoo_taxes'          => array( 'stored-odoo-taxes', 200, array( array( 'id' => 3, 'name' => '21% VAT sales', 'type_tax_use' => 'sale' ), array( 'id' => 4, 'name' => '21% VAT purchases', 'type_tax_use' => 'purchase' ), array( 'id' => 5, 'name' => '0% VAT sales', 'type_tax_use' => 'sale' ), array( 'id' => 6, 'name' => '0% VAT purchases', 'type_tax_use' => 'purchase' ) ) ),
			'odoo_taxes_fetched'         => array( 'fetch-and-store-odoo-taxes', 200, array( array( 'id' => 3, 'name' => '21% VAT sales', 'rate' => 21.0, 'type_tax_use' => 'sale' ), array( 'id' => 4, 'name' => '21% VAT purchases', 'rate' => 21.0, 'type_tax_use' => 'purchase' ) ) ),
			'odoo_taxes_fetched_empty'   => array( 'fetch-and-store-odoo-taxes', 204, array( 'message' => 'No tax records found in Odoo.' ) ),
			// Stock: 1861-1871 (rows), 1972-1976 (locations).
			'odoo_stock_locations'       => array( 'odoo-stock-locations', 200, array( 'locations' => array( array( 'id' => 12, 'name' => 'WH/Stock' ), array( 'id' => 15, 'name' => 'WH/Stock/Shelf 1' ) ) ) ),
			'stock_mappings_list'        => array( 'stock-mappings', 200, array( 'mappings' => array( array( 'id' => 1, 'odoo_location_id' => 12, 'odoo_location_name' => 'WH/Stock', 'quantity_setting' => 'available', 'target_field' => 'stock_quantity', 'target_meta_key' => '' ), array( 'id' => 2, 'odoo_location_id' => 15, 'odoo_location_name' => 'WH/Stock/Shelf 1', 'quantity_setting' => 'on_hand', 'target_field' => 'meta', 'target_meta_key' => 'shelf_stock' ) ) ) ),
			// Order routes and order pricelists: 1944-1948, 1088-1097, 1157-1166.
			'odoo_routes'                => array( 'odoo-routes', 200, array( 'routes' => array( array( 'id' => 2, 'name' => 'Dropship' ), array( 'id' => 3, 'name' => 'Buy' ) ) ) ),
			'odoo_routes_empty'          => array( 'odoo-routes', 200, array( 'routes' => array() ) ),
			'order_route_mappings_list'  => array( 'order-route-mappings', 200, array( 'mappings' => array( array( 'id' => 1, 'meta_key' => 'source', 'meta_value' => 'pos', 'odoo_route_id' => 2, 'odoo_route_name' => 'Dropship' ) ) ) ),
			'order_pricelist_mappings_list' => array( 'order-pricelist-mappings', 200, array( 'mappings' => array( array( 'id' => 1, 'meta_key' => 'customer_type', 'meta_value' => 'wholesale', 'odoo_pricelist_id' => 6, 'odoo_pricelist_name' => 'Retail DKK' ) ) ) ),
			// Price rows, one of each source: 954-966.
			'price_mappings_two_rows'    => array( 'price-mappings', 200, array( 'mappings' => array( array( 'id' => 1, 'source' => 'list_price', 'odoo_pricelist_id' => null, 'odoo_pricelist_name' => '', 'target_field' => 'regular_price', 'target_meta_key' => '', 'tax_treatment' => 'as_is', 'require_explicit_rule' => false ), array( 'id' => 2, 'source' => 'pricelist', 'odoo_pricelist_id' => 6, 'odoo_pricelist_name' => 'Retail DKK', 'target_field' => 'meta', 'target_meta_key' => '_wholesale_price', 'tax_treatment' => 'add_tax', 'require_explicit_rule' => true ) ) ) ),
		);

		foreach ( $reads as $name => list( $route, $status, $body ) ) {
			$l = $this->fresh();
			$this->fake_django( fn() => array( $status, $body ) );
			$method = 'fetch-and-store-odoo-taxes' === $route ? 'POST' : 'GET';
			$this->keep( $name, $this->rest( $method, $u( $l, $route ) ) );
		}

		$refusals = array(
			// Payment: 2085, 2064-2067. Tax: 2380. Price: 1006, 995-996. Stock: 1900. Route: 1119. Pricelist: 1188.
			'payment_save_duplicate'     => array( 'payment-method-mappings', array( array( 'woocommerce_payment_method' => 'bacs', 'odoo_journal_id' => 8 ), array( 'woocommerce_payment_method' => 'bacs', 'odoo_journal_id' => 9 ) ), 'invalid_value', "WooCommerce payment method 'bacs' is duplicated.", 'woocommerce_payment_method' ),
			'payment_save_no_journal'    => array( 'payment-method-mappings', array( array( 'woocommerce_payment_method' => 'bacs', 'odoo_journal_id' => 0 ) ), 'invalid_value', 'Choose an Odoo journal for every payment method.', 'odoo_journal_id' ),
			'tax_save_no_tax'            => array( 'tax-mappings', array( array( 'woocommerce_tax_class' => 'standard', 'odoo_customer_tax_id' => 3 ) ), 'invalid_value', 'Choose an Odoo tax for every tax class.', 'odoo_supplier_tax_id' ),
			'price_save_duplicate'       => array( 'price-mappings', array( array( 'source' => 'list_price', 'target_field' => 'regular_price' ), array( 'source' => 'list_price', 'target_field' => 'regular_price' ) ), 'invalid_value', "Two mappings both write 'regular_price'. Each target takes one.", 'target_field' ),
			'price_save_no_pricelist'    => array( 'price-mappings', array( array( 'source' => 'pricelist', 'target_field' => 'regular_price' ) ), 'invalid_value', 'A pricelist source needs an Odoo pricelist selected.', 'odoo_pricelist_id' ),
			'stock_save_duplicate'       => array( 'stock-mappings', array( array( 'odoo_location_id' => 12 ), array( 'odoo_location_id' => 15 ) ), 'invalid_value', "Two mappings both write 'stock_quantity'. Each target takes one.", 'target_field' ),
			'order_route_save_duplicate' => array( 'order-route-mappings', array( array( 'meta_key' => 'source', 'meta_value' => 'pos', 'odoo_route_id' => 2 ), array( 'meta_key' => 'source', 'meta_value' => 'pos', 'odoo_route_id' => 3 ) ), 'invalid_value', 'Two rules both match source=pos. Each pair takes one route.', 'meta_value' ),
			'order_pricelist_save_duplicate' => array( 'order-pricelist-mappings', array( array( 'meta_key' => 'k', 'meta_value' => 'v', 'odoo_pricelist_id' => 5 ), array( 'meta_key' => 'k', 'meta_value' => 'v', 'odoo_pricelist_id' => 6 ) ), 'invalid_value', 'Two rules both match k=v. Each pair takes one pricelist.', 'meta_value' ),
		);

		foreach ( $refusals as $name => list( $route, $items, $code, $message, $field ) ) {
			$l = $this->fresh();
			$this->fake_django( fn() => array( 400, array( 'code' => $code, 'message' => $message, 'field' => $field ) ) );
			$this->keep( $name, $this->rest( 'PUT', $u( $l, $route ), array( 'items' => $items ) ) );
		}

		$saves = array(
			// Payment 2106, tax 2408, price 1036, stock 1920, route 1138, pricelist 1207.
			'payment_save_success'        => array( 'payment-method-mappings', array( 'message' => 'Payment method mappings updated successfully.' ) ),
			'tax_save_success'            => array( 'tax-mappings', array( 'status' => 'success' ) ),
			'price_save_success'          => array( 'price-mappings', array( 'message' => 'Saved 2 price mapping(s).' ) ),
			'stock_save_success'          => array( 'stock-mappings', array( 'message' => 'Saved 1 stock mapping(s).' ) ),
			'order_route_save_success'    => array( 'order-route-mappings', array( 'message' => 'Saved 1 order route mapping(s).' ) ),
			'order_pricelist_save_success' => array( 'order-pricelist-mappings', array( 'message' => 'Saved 1 order pricelist mapping(s).' ) ),
		);

		foreach ( $saves as $name => list( $route, $body ) ) {
			$l = $this->fresh();
			$this->fake_django( fn() => array( 200, $body ) );
			$this->keep( $name, $this->rest( 'PUT', $u( $l, $route ), array( 'items' => array() ) ) );
		}
	}

	/**
	 * Task 13: manual syncs, the running-task memory, the sync log and the gift card settings.
	 * Every Django body is copied from the view named next to it (Django branch at 5ab091e,
	 * /root/projects/beltoft/odoowoo-tenants; sync_app/... paths).
	 */
	private function sync_fixtures(): void {
		$u = static fn( array $l, string $route ): string => '/licenses/' . $l['id'] . '/dashboards/' . self::D . '/' . $route;

		// A trigger answers 202 {message, task_id}: product/sync_products.py:105 (the other six are the same shape, sync_stock.py:60, sync_orders.py:84-87).
		$l = $this->fresh();
		$this->fake_django( fn() => array( 202, array( 'message' => 'Product sync started', 'task_id' => 'tk-1' ) ) );
		$this->keep( 'sync_started', $this->rest( 'POST', $u( $l, 'trigger-sync' ), array( 'product_ids' => array( 1, 2, 3 ) ) ) );

		// The guard's refusals: api/triggers.py:33-34 and 84 (dashboard paused), middleware.py:57-58 (tenant paused), triggers.py:35-37 and 86-87 (brands not in the plan).
		$l = $this->fresh();
		$this->fake_django( fn() => array( 409, array( 'code' => 'dashboard_paused', 'message' => 'This dashboard is paused. Enable it to run a sync.' ) ) );
		$this->keep( 'sync_dashboard_paused', $this->rest( 'POST', $u( $l, 'trigger-category-sync' ) ) );
		$l = $this->fresh();
		$this->fake_django( fn() => array( 409, array( 'code' => 'tenant_paused', 'message' => 'Your OdooWoo plan is paused. Renew it to make changes or run syncs.' ) ) );
		$this->keep( 'sync_tenant_paused', $this->rest( 'POST', $u( $l, 'trigger-category-sync' ) ) );
		$l = $this->fresh();
		$this->fake_django( fn() => array( 403, array( 'code' => 'plan_feature_unavailable', 'message' => 'Brand sync is not included in your plan.', 'field' => 'enable_brand_sync' ) ) );
		$this->keep( 'sync_brands_not_in_plan', $this->rest( 'POST', $u( $l, 'trigger-brand-sync' ) ) );

		// The portal's own refusals of an input (Map::sync_input): no Django call is made.
		$l = $this->fresh();
		$this->fake_django( fn() => array( 202, array( 'message' => 'Order sync started', 'task_id' => 'tk-2' ) ) );
		$this->keep( 'sync_orders_nothing_chosen', $this->rest( 'POST', $u( $l, 'sync-orders' ), array() ) );
		$this->keep( 'sync_orders_reversed', $this->rest( 'POST', $u( $l, 'sync-orders' ), array( 'from_date' => '2026-02-01', 'to_date' => '2026-01-01' ) ) );
		$this->keep( 'sync_orders_one_date', $this->rest( 'POST', $u( $l, 'sync-orders' ), array( 'from_date' => '2026-02-01' ) ) );
		$this->keep( 'sync_products_no_valid_id', $this->rest( 'POST', $u( $l, 'trigger-sync' ), array( 'product_ids' => 'x, -1' ) ) );
		$this->keep( 'sync_products_too_many', $this->rest( 'POST', $u( $l, 'trigger-sync' ), array( 'product_ids' => range( 1, 501 ) ) ) );
		$this->assertSame( array(), $this->django_calls(), 'A refused input sends nothing.' );

		// sync/active: nothing running, then a remembered task (Tasks::active).
		$l = $this->fresh();
		$this->keep( 'sync_active_none', $this->rest( 'GET', $u( $l, 'sync/active' ) ) );
		Tasks::remember( (int) $l['user_id'], (int) $l['id'], self::D, 'products', 'tk-1' );
		$res  = $this->rest( 'GET', $u( $l, 'sync/active' ) );
		$data = $res->get_data();
		$data['data']['task']['started'] = 1700000000; // The start time changes on every run.
		$res->set_data( $data );
		$this->keep( 'sync_active_task', $res );

		// A finished product sync with per-item results: product/views.py:31-60 (kept keys, ITEM_FAILED, reason), 71-141 (reply).
		$base = array( 'task_id' => 'tk-1', 'status' => 'SUCCESS', 'state' => 'done', 'message' => 'Task completed successfully.', 'progress' => array( 'current' => 3, 'total' => 3 ), 'current' => 3, 'total' => 3, 'skipped_products' => 1, 'synced_products' => 1 );
		$l    = $this->fresh();
		$this->task(
			'task_done_with_results',
			$l,
			array_merge(
				$base,
				array(
					'results' => array(
						array( 'product_name' => 'Blue mug', 'product_id' => 11, 'sku' => 'MUG-1', 'type' => 'simple', 'status' => 'success' ),
						array( 'product_name' => 'Red mug', 'product_id' => 12, 'sku' => 'MUG-2', 'type' => 'simple', 'status' => 'skipped', 'reason' => 'No SKU' ),
						array( 'product_name' => 'Green mug', 'product_id' => 13, 'sku' => 'MUG-3', 'type' => 'simple', 'status' => 'error', 'error' => 'Traceback (most recent call last): boom' ),
					),
				)
			)
		);

		// The sync log: product-less order rows as views.py:2307-2331 writes them (status text comes from Map::order_sync_rows).
		$l = $this->fresh();
		$rows = array(
			array( 'woocommerce_order_id' => 'WS_1051', 'odoo_order_id' => 'S00042', 'status' => 'success', 'synced_at' => '2026-10-21T10:15:00Z', 'log' => '' ),
			array( 'woocommerce_order_id' => 'WS_1050', 'odoo_order_id' => null, 'status' => 'error', 'synced_at' => '2026-10-21T09:40:00Z', 'log' => 'Order 1050 was not created in Odoo: its line names MUG-9 through line meta, but no product in Odoo carries that reference.' ), // orders/tasks.py:526-532, refuse_order 77-97.
			array( 'woocommerce_order_id' => 'WS_1049', 'odoo_order_id' => '17', 'status' => 'warning', 'synced_at' => '2026-10-21T09:10:00Z', 'log' => 'Order 17 was paid with 20.00 of gift card value, but no gift card journal is configured. Skipping auto payment so the invoice is not settled out of the wrong journal.' ), // orders/tasks.py:1143-1145 via _skip_auto_payment (1021-1036).
			array( 'woocommerce_order_id' => 'WS_1048', 'odoo_order_id' => '16', 'status' => 'failed', 'synced_at' => '2026-10-20T18:00:00Z', 'log' => "<Fault 1: 'Traceback (most recent call last):\\n  File \"odoo/http.py\", line 1\\nValueError'>" ), // SYNTHETIC text: the shape of an XML-RPC Fault (utils/odoo_helpers.py:138 logs one); no view writes this exact log, it exercises the ledger ruling on tracebacks.
			array( 'woocommerce_order_id' => 'WS_1047', 'odoo_order_id' => '15', 'status' => 'pending', 'synced_at' => '2026-10-20T17:00:00Z', 'log' => null ), // An unknown status text: OrderSync.status is any 20 characters (models.py:264); log is nullable (models.py:266).
		);
		$this->fake_django( fn() => array( 200, $rows ) );
		$this->keep( 'order_syncs_list', $this->rest( 'GET', $u( $l, 'order-syncs' ) ) );
		$this->fake_django( fn() => array( 200, array() ) );
		$this->keep( 'order_syncs_empty', $this->rest( 'GET', $u( $l, 'order-syncs' ) ) );
	}

	/**
	 * Task 13: gift card settings. views.py:1463-1841 (manage_gift_card_settings); defaults are the
	 * model's (models.py:470-570).
	 */
	private function gift_card_fixtures(): void {
		$u = static fn( array $l, string $route ): string => '/licenses/' . $l['id'] . '/dashboards/' . self::D . '/' . $route;

		$defaults = array(
			'enabled' => false, 'enable_expiry_sweep' => false,
			'sale_tax_id' => null, 'expiry_tax_id' => null, 'redeem_journal_id' => null, 'expiry_journal_id' => null,
			'expiry_debit_account_id' => null, 'expiry_credit_account_id' => null, 'expiry_vat_account_id' => null,
			'sale_line_meta_key' => '_bgcw_amount', 'sale_product_codes' => '', 'sale_tax_name' => '',
			'redeem_order_meta_key' => '_bgcw_deducted_amounts,_bgcw_pending_deductions', 'card_api_path' => 'wc-bgcw/v1/gift-cards/code/',
			'paid_flag_field' => 'is_paid', 'redeem_journal_name' => '', 'expiry_api_path' => 'wc-bgcw/v1/gift-cards',
			'expiry_status_values' => 'expired', 'expiry_journal_name' => '', 'expiry_tax_name' => '',
			'legacy_card_cutoff' => null, 'expiry_min_balance' => '0.00',
		); // The GET reply of an unsaved dashboard: views.py:1503-1523 with the model's defaults.

		$l = $this->fresh();
		$this->fake_django( fn() => array( 200, array( 'settings' => $defaults ) ) );
		$this->keep( 'gift_card_settings_new', $this->rest( 'GET', $u( $l, 'gift-card-settings' ) ) );

		$saved = array_merge( $defaults, array( 'enabled' => true, 'sale_tax_id' => 3, 'sale_tax_name' => '0% VAT sales', 'redeem_journal_id' => 8, 'redeem_journal_name' => 'Bank', 'legacy_card_cutoff' => '2026-01-31T00:00:00+00:00', 'expiry_min_balance' => '2.50' ) );
		$l     = $this->fresh();
		$this->fake_django( fn() => array( 200, array( 'settings' => $saved ) ) );
		$this->keep( 'gift_card_settings_saved', $this->rest( 'GET', $u( $l, 'gift-card-settings' ) ) );

		$l = $this->fresh();
		$this->fake_django( fn() => array( 200, array( 'message' => 'Gift card settings saved.' ) ) ); // Views.py:1841.
		$this->keep( 'gift_card_save_success', $this->rest( 'PUT', $u( $l, 'gift-card-settings' ), array( 'settings' => array( 'enabled' => false ) ) ) );

		$refusals = array(
			// Views.py:1582-1585 (gift_card_incomplete), 1453-1460 with the labels of 1246-1254 (unknown id), 1622-1629 (sweep before booking), 1347 with 1275-1278 (amount).
			'gift_card_incomplete'  => array( 'gift_card_incomplete', 'Choose the exempt tax for gift card sales before turning this on.', 'sale_tax_id' ),
			'gift_card_unknown_id'  => array( 'invalid_value', "The exempt tax for gift card sales is not one of this dashboard's stored Odoo taxes. Load the taxes and choose one of them.", 'sale_tax_id' ),
			'gift_card_sweep_needs_booking' => array( 'gift_card_incomplete', 'The expiry sweep cannot be turned on while gift card booking is off. The sweep releases value out of the gift card liability account, and nothing is in that account unless gift cards are being booked this way. Turn gift card booking on first.', 'enabled' ),
			'gift_card_amount_invalid' => array( 'invalid_value', 'The smallest balance the expiry sweep releases must be an amount, such as 2.50.', 'expiry_min_balance' ), 
		);

		foreach ( $refusals as $name => list( $code, $message, $field ) ) {
			$l = $this->fresh();
			$this->fake_django( fn() => array( 400, array( 'code' => $code, 'message' => $message, 'field' => $field ) ) );
			$this->keep( $name, $this->rest( 'PUT', $u( $l, 'gift-card-settings' ), array( 'settings' => array( 'enabled' => true ) ) ) );
		}

		// The two lists the loader button reads besides the stored taxes: views.py:897-905 (accounts, live) and 934-946 (stored).
		$reads = array(
			'odoo_accounts'       => array( 'odoo-accounts', array( array( 'account_id' => 41, 'code' => '2191', 'name' => 'Gift card liability' ), array( 'account_id' => 96, 'code' => '7881', 'name' => 'Breakage income' ) ) ),
			'saved_odoo_accounts' => array( 'saved-odoo-accounts', array( array( 'account_id' => 41, 'code' => '2191', 'name' => 'Gift card liability' ), array( 'account_id' => 96, 'code' => '7881', 'name' => 'Breakage income' ) ) ),
		);

		foreach ( $reads as $name => list( $route, $body ) ) {
			$l = $this->fresh();
			$this->fake_django( fn() => array( 200, $body ) );
			$this->keep( $name, $this->rest( 'GET', $u( $l, $route ) ) );
		}
	}

	public function test_browser_fixtures_match_the_real_routes(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );

		// A list: Django odoo-journals answers a bare list (sync_app/views.py:849).
		$this->fake_django( fn() => array( 200, array( array( 'id' => 1, 'name' => 'Bank' ), array( 'id' => 2, 'name' => 'Cash' ) ) ) );
		$this->keep( 'list_success', $this->rest( 'GET', $this->url( $l, 'odoo-journals' ) ) );

		// Settings: woo-keys GET (sync_app/views.py:261-285 fields; secrets are only has_* flags).
		$this->fake_django( fn() => array( 200, array( 'consumer_key' => 'ck_1', 'has_consumer_secret' => true, 'has_webhook_secret' => true, 'woocommerce_url' => 'https://shop.example', 'prefix' => 'WS', 'enable_stock_update' => true, 'change_status_delivery' => false, 'used_prefixes' => array( 'WS' ) ) ) );
		$this->keep( 'settings_success', $this->rest( 'GET', $this->url( $l, 'woo-keys' ) ) );

		// A save: Django answers a message (ConnectionRoutesTest, views.py woo-keys POST).
		$this->fake_django( fn() => array( 200, array( 'message' => 'WooCommerce keys updated successfully.' ) ) );
		$this->keep( 'save_success', $this->rest( 'PUT', $this->url( $l, 'woo-keys' ), array( 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_dummy', 'woocommerce_url' => 'https://shop.example', 'prefix' => 'WS' ) ) );

		// A Django field error (the {code, message, field} contract).
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_request', 'message' => 'Expected a \'mappings\' list.', 'field' => 'mappings' ) ) ); // Views.py:921, 1090.
		$this->keep( 'refusal_field', $this->rest( 'GET', $this->url( $l, 'odoo-journals' ) ) );

		// Not configured (views.py:110, 125).
		$this->fake_django( fn() => array( 400, array( 'code' => 'odoo_not_configured', 'message' => 'Save your Odoo connection settings first.' ) ) );
		$this->keep( 'not_configured', $this->rest( 'GET', $this->url( $l, 'odoo-journals' ) ) );

		// Django itself unusable: HTML from a proxy.
		$this->fake_django( fn() => array( 502, '<html>Bad gateway</html>' ) );
		$this->keep( 'django_garbage', $this->rest( 'GET', $this->url( $l, 'odoo-journals' ) ) );

		// Tasks (product/views.py:14 task_not_found; task shapes as in SyncRoutesTest).
		$base = array( 'task_id' => 'tk-1', 'status' => 'PROGRESS', 'state' => 'running', 'message' => 'Task is progress.', 'results' => array(), 'progress' => array( 'current' => 0, 'total' => 0 ), 'current' => 0, 'total' => 0 );
		$this->task( 'task_running_start', $l, $base );
		$this->task( 'task_running_1_of_10', $l, array_merge( $base, array( 'current' => 1, 'total' => 10 ) ) );
		$this->task( 'task_running_2_of_10', $l, array_merge( $base, array( 'current' => 2, 'total' => 10 ) ) );
		$this->task( 'task_running_30_of_120', $l, array_merge( $base, array( 'current' => 30, 'total' => 120 ) ) );
		$this->task( 'task_done', $l, array_merge( $base, array( 'status' => 'SUCCESS', 'state' => 'done', 'message' => 'Done.', 'current' => 5, 'total' => 5 ) ) );
		$this->task( 'task_skipped', $l, array_merge( $base, array( 'status' => 'SKIPPED', 'state' => 'skipped', 'message' => 'Sync categories is deactivated.' ) ) );
		$this->task( 'task_failed', $l, array_merge( $base, array( 'status' => 'FAILED', 'state' => 'failed', 'message' => 'The sync failed. See the sync log for details.' ) ) );
		$this->task( 'task_django_not_found', $l, array( 'code' => 'task_not_found', 'message' => 'This sync was not found. It may have finished more than a day ago.' ), 404 );
		$this->task( 'task_unavailable', $l, array( 'code' => 'task_status_unavailable', 'message' => 'The sync status could not be read. Try again in a moment.' ), 500 );

		$this->fake_django( fn() => array( 200, array() ) );
		$res = $this->rest( 'GET', $this->url( $l, 'task-status/never-started' ) );
		$this->keep( 'task_unknown', $res );

		// Django 401/403 arrive as 409 (never a session error): sync_app/middleware.py:13,50 and views.py:671
		// (BRANDS_NOT_IN_PLAN, tests/test_limits.py:33).
		$this->fake_django( fn() => array( 401, array( 'code' => 'unauthorized', 'message' => 'The request could not be authenticated.' ) ) );
		$this->keep( 'django_401', $this->rest( 'GET', $this->url( $l, 'odoo-journals' ) ) );
		$this->fake_django( fn() => array( 403, array( 'code' => 'plan_feature_unavailable', 'message' => 'Brand sync is not included in your plan.', 'field' => 'enable_brand_sync' ) ) );
		$this->keep( 'django_403', $this->rest( 'GET', $this->url( $l, 'odoo-journals' ) ) );

		// Dashboards (Task 9). Objects: serializers.py:9-35 {id, name, enabled, created_at}.
		$dash = array( 'id' => self::D, 'name' => 'Shop', 'enabled' => true, 'created_at' => '2026-10-21T12:00:00Z' );
		$this->fake_django( fn() => array( 200, $dash ) ); // Views.py DashboardDetailView.get.
		$this->keep( 'dashboard_detail_success', $this->rest( 'GET', '/licenses/' . $l['id'] . '/dashboards/' . self::D ) );
		$this->fake_django( fn() => array( 200, array_merge( $dash, array( 'name' => 'Outlet' ) ) ) ); // DashboardDetailView.patch.
		$this->keep( 'dashboard_rename_success', $this->rest( 'PATCH', '/licenses/' . $l['id'] . '/dashboards/' . self::D, array( 'name' => 'Outlet' ) ) );
		$this->fake_django( fn() => array( 200, array( 'id' => self::D, 'enabled' => false ) ) ); // A patch reply that carries no name.
		$this->keep( 'dashboard_rename_no_name', $this->rest( 'PATCH', '/licenses/' . $l['id'] . '/dashboards/' . self::D, array( 'name' => 'Outlet' ) ) );
		$this->fake_django( fn() => array( 404, array( 'code' => 'dashboard_not_found', 'message' => 'This dashboard was not found.' ) ) ); // Api/errors.py:46, middleware.py:78.
		$this->keep( 'dashboard_not_found', $this->rest( 'GET', '/licenses/' . $l['id'] . '/dashboards/' . self::D ) );
		$this->fake_django( fn() => array( 403, array( 'code' => 'dashboard_limit_reached', 'message' => 'You have reached your plan limit of 1 dashboard.', 'limit' => 1 ) ) ); // Views.py:160-166, 2619.
		$this->keep( 'dashboard_limit_reached', $this->rest( 'POST', '/licenses/' . $l['id'] . '/dashboards', array( 'name' => 'Again' ) ) );
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_value', 'message' => 'Give the dashboard a name.', 'field' => 'name' ) ) ); // Views.py:151-157, serializers.py:5.
		$this->keep( 'dashboard_name_invalid', $this->rest( 'POST', '/licenses/' . $l['id'] . '/dashboards', array( 'name' => 'x' ) ) );
		$this->fake_django( fn() => array( 409, array( 'code' => 'tenant_paused', 'message' => 'Your OdooWoo plan is paused. Renew it to make changes or run syncs.' ) ) ); // Middleware.py:57-58, 81.
		$this->keep( 'tenant_paused', $this->rest( 'PATCH', '/licenses/' . $l['id'] . '/dashboards/' . self::D, array( 'name' => 'Outlet' ) ) );

		$this->connection_fixtures();
		$this->odoo_settings_fixtures();
		$this->mapping_fixtures();
		$this->sync_fixtures();
		$this->gift_card_fixtures();

		// GET /licenses: the real summary of a ready, a paused and a getting-ready license (Routes::license_summary).
		foreach ( array( 'ready' => 'ready', 'paused' => 'paused', 'getting_ready' => 'provisioning' ) as $label => $resource_state ) {
			$lic = $this->odoowoo_license( array(), $resource_state );
			$this->as_user( (int) $lic['user_id'] );
			\OdooWooPortal\Context::reset();
			\OdooWooPortal\Api\Routes::reset();
			$tenant = $this->tenant( $lic );
			$this->django_map(
				array(
					'GET tenants/' . $tenant . '/' => array( 200, array( 'limit' => 3, 'used' => 1, 'attributes' => array() ) ), // Tenant detail, as in RoutesTest.
					'GET dashboards/'              => array( 200, array( $dash ) ),
				)
			);
			$res  = $this->rest( 'GET', '/licenses' );
			$data = $res->get_data();
			$this->assertSame( (int) $lic['id'], $data['data'][0]['id'] );
			$data['data'][0]['id'] = 1; // The license id changes on every run; the fixture pins it.
			$res->set_data( $data );
			$this->keep( 'licenses_' . $label, $res );
		}

		// Read-only: a paused owner tries to save.
		$p = $this->odoowoo_license( array(), 'paused' );
		$this->as_user( (int) $p['user_id'] );
		$this->keep( 'refusal_read_only', $this->rest( 'POST', '/licenses/' . $p['id'] . '/dashboards', array( 'name' => 'n' ) ) );

		// Session: logged out (the portal's own permission callback).
		wp_set_current_user( 0 );
		$this->keep( 'session_login', $this->rest( 'GET', '/licenses' ) );

		// Session: WordPress' own cookie check with a bad nonce (rest_cookie_check_errors, rest-api.php).
		$this->as_user( (int) $l['user_id'] );
		$_SERVER['HTTP_X_WP_NONCE'] = 'badbadbad';
		$_COOKIE[ LOGGED_IN_COOKIE ] = 'x';
		global $wp_rest_auth_cookie;
		$wp_rest_auth_cookie = true;
		$error               = rest_cookie_check_errors( null );
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_COOKIE[ LOGGED_IN_COOKIE ] );
		$wp_rest_auth_cookie = null;
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->keep( 'session_nonce', rest_convert_error_to_response( $error ) );

		$dir = __DIR__ . '/js/fixtures';

		foreach ( $this->made as $name => $fixture ) {
			$json = wp_json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
			$file = $dir . '/' . $name . '.json';

			if ( getenv( 'ODOOWOO_REGEN_FIXTURES' ) ) {
				file_put_contents( $file, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			$this->assertFileExists( $file, $name . ': run with ODOOWOO_REGEN_FIXTURES=1 to create it.' );
			$this->assertSame( $json, file_get_contents( $file ), $name . ' is stale: regenerate with ODOOWOO_REGEN_FIXTURES=1 (see this file\'s header).' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		// No file without a test: a leftover fixture is a stale one.
		$files = array_map( static fn( $f ) => basename( $f, '.json' ), glob( $dir . '/*.json' ) ?: array() );
		sort( $files );
		$names = array_keys( $this->made );
		sort( $names );
		$this->assertSame( $names, $files, 'Every fixture file is produced by this test.' );
	}
}
