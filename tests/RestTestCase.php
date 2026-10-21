<?php
/**
 * REST helpers for the route tests.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use BeltoftServices\Resource;
use OdooWooPortal\Api\Routes;
use OdooWooPortal\Context;

/**
 * A REST server with the portal routes, and a fake Django that answers by "METHOD path".
 */
abstract class RestTestCase extends TestCase {

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		Context::reset();
		Routes::reset();
		$this->connection();
		ini_set( 'error_log', sys_get_temp_dir() . '/odoowoo-portal-tests.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * A complete woo-keys save (the route refuses a partial one), with overrides.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	public static function complete_woo( array $over = array() ): array {
		return $over + array(
			'consumer_key'           => 'ck',
			'woocommerce_url'        => 'https://s.example',
			'prefix'                 => 'A',
			'enable_stock_update'    => true,
			'change_status_delivery' => true,
		);
	}

	/**
	 * A complete odoo-settings save (the route refuses a partial one), with overrides.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	public static function complete_odoo( array $over = array() ): array {
		return $over + array(
			'odoo_url'                         => 'https://o.example',
			'odoo_db'                          => 'db',
			'odoo_user'                        => 'u',
			'enable_auto_payment'              => false,
			'enable_auto_payment_cn'           => false,
			'enable_set_invoice_status'        => false,
			'enable_reset_all_deliveries'      => false,
			'enable_set_deliveries_to_waiting' => false,
			'enable_auto_complete_deliveries'  => false,
			'odoo_order_pricelist_id'          => null,
			'odoo_order_pricelist_name'        => '',
			'capture_stage'                    => 'processing',
			'confirm_pending_orders'           => false,
			'price_includes_tax'               => false,
			'order_incl_tax'                   => true,
			'quantity_settings'                => 'on_hand',
			'enable_brand_sync'                => false,
			'enable_category_sync'             => true,
			'enable_product_name_sync'         => true,
			'enable_tag_sync'                  => true,
			'enable_image_sync'                => true,
			'enable_price_sync'                => false,
			'enable_barcode_sync'              => false,
		);
	}

	/**
	 * The tenant of a license.
	 *
	 * @param array<string, mixed> $license License.
	 */
	protected function tenant( array $license ): string {
		return (string) Resource::for_license( (int) $license['id'] )->get( 'uuid' );
	}

	/**
	 * Fake Django answering from a map "METHOD path" => [status, body]; unknown paths are 500.
	 *
	 * @param array<string, array{0: int, 1: mixed}> $map Answers.
	 */
	protected function django_map( array $map ): void {
		$this->fake_django(
			function ( $call ) use ( $map ) {
				$path = preg_replace( '#^/api/#', '', strtok( $call['path_and_query'], '?' ) );

				return $map[ $call['method'] . ' ' . $path ] ?? array( 500, 'unexpected ' . $call['method'] . ' ' . $path );
			}
		);
	}

	/**
	 * Sends a request through the REST server.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route under the namespace.
	 * @param array<string, mixed> $body   JSON body.
	 */
	protected function rest( string $method, string $route, ?array $body = null ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/' . Routes::NAMESPACE . $route );

		if ( null !== $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $req );
	}
}
