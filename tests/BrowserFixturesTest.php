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
