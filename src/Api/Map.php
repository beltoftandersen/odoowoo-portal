<?php
/**
 * The browser-to-Django route table.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Api;

defined( 'ABSPATH' ) || exit;

/**
 * One row per Django call the browser may cause. Django ids are the dashboard only; the
 * tenant is the signature, never part of a path or body.
 */
final class Map {

	/**
	 * Rate limit buckets: calls per minute and user.
	 */
	public const LIMITS = array(
		'verify' => 10,
		'sync'   => 10,
		'create' => 5,
	);

	/**
	 * Fixed dashboard routes (dealt with by their own REST routes, not the generic dispatcher).
	 */
	public const FIXED = array( 'dashboard-list', 'dashboard-create', 'dashboard-detail', 'dashboard-rename', 'dashboard-pause', 'dashboard-delete' );

	/**
	 * Rows: key => methods (browser method => [django method, django path, {d} is the dashboard id]),
	 * fields (body keys allowed), read_only_ok, limit (bucket or null).
	 */
	public const ROUTES = array(
		'dashboard-list'      => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/' ) ),
			'fields'       => array(),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'dashboard-create'    => array(
			'methods'      => array( 'POST' => array( 'POST', 'dashboards/create/' ) ),
			'fields'       => array( 'name' ),
			'read_only_ok' => false,
			'limit'        => 'create',
		),
		'dashboard-detail'    => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/' ) ),
			'fields'       => array(),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'dashboard-rename'    => array(
			'methods'      => array( 'PATCH' => array( 'PATCH', 'dashboards/{d}/' ) ),
			'fields'       => array( 'name' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'dashboard-pause'     => array(
			'methods'      => array( 'PATCH' => array( 'PATCH', 'dashboards/{d}/' ) ),
			'fields'       => array( 'enabled' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'dashboard-delete'    => array(
			'methods'      => array( 'DELETE' => array( 'DELETE', 'dashboards/{d}/delete/' ) ),
			'fields'       => array(),
			'read_only_ok' => true,
			'limit'        => null,
		),
		'woo-keys' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/woo-keys/' ),
				'PUT' => array( 'POST', 'dashboards/{d}/woo-keys/' ),
			),
			'fields'       => array( 'consumer_key', 'consumer_secret', 'woocommerce_url', 'prefix', 'enable_stock_update', 'change_status_delivery' ),
			'read_only_ok' => false,
			'limit'        => null,
			'clean'        => 'clean_woo_keys',
		),
		'verify-woocommerce' => array(
			'methods'      => array(
				'POST' => array( 'POST', 'dashboards/{d}/verify-woocommerce/' ),
			),
			'fields'       => array( 'consumer_key', 'consumer_secret', 'woocommerce_url' ),
			'read_only_ok' => true,
			'limit'        => 'verify',
			'clean'        => 'clean_woo_keys',
		),
		'inject-woo-webhooks' => array(
			'methods'      => array(
				'POST' => array( 'POST', 'dashboards/{d}/inject-woo-webhooks/' ),
			),
			'fields'       => array(),
			'read_only_ok' => false,
			'limit'        => 'verify',
		),
		'odoo-settings' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/odoo-settings/' ),
				'PUT' => array( 'POST', 'dashboards/{d}/odoo-settings/' ),
			),
			'fields'       => array( 'odoo_url', 'odoo_db', 'odoo_user', 'odoo_password', 'enable_auto_payment', 'enable_auto_payment_cn', 'enable_set_invoice_status', 'enable_reset_all_deliveries', 'enable_set_deliveries_to_waiting', 'enable_auto_complete_deliveries', 'odoo_order_pricelist_id', 'odoo_order_pricelist_name', 'capture_stage', 'confirm_pending_orders', 'price_includes_tax', 'order_incl_tax', 'quantity_settings', 'enable_brand_sync', 'enable_category_sync', 'enable_product_name_sync', 'enable_tag_sync', 'enable_image_sync', 'enable_price_sync', 'enable_barcode_sync', 'stock_sku_meta_keys', 'stock_units_meta_keys' ),
			'read_only_ok' => false,
			'limit'        => null,
			'clean'        => 'clean_odoo_settings',
		),
		'verify-odoo' => array(
			'methods'      => array(
				'POST' => array( 'POST', 'dashboards/{d}/verify-odoo/' ),
			),
			'fields'       => array( 'odoo_url', 'odoo_db', 'odoo_user', 'odoo_password' ),
			'read_only_ok' => true,
			'limit'        => 'verify',
			'clean'        => 'clean_odoo_settings',
		),
		'generate-api-key' => array(
			'methods'      => array(
				'POST' => array( 'POST', 'dashboards/{d}/generate-api-key/' ),
			),
			'fields'       => array(),
			'read_only_ok' => false,
			'limit'        => 'verify',
		),
		'odoo-pricelists' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/odoo-pricelists/' ),
			),
			'fields'       => array(),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'odoo-routes' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/odoo-routes/' ),
			),
			'fields'       => array(),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'odoo-stock-locations' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/odoo-stock-locations/' ),
			),
			'fields'       => array(),
			'read_only_ok' => false,
			'limit'        => null,
		),
	);

	/**
	 * Keys the generic dispatcher routes (everything but the fixed dashboard rows).
	 *
	 * @return string[]
	 */
	public static function generic_keys(): array {
		return array_values( array_diff( array_keys( self::ROUTES ), self::FIXED ) );
	}

	/**
	 * The Django method and path of a row for a browser method, or null.
	 *
	 * @param string $key       Row.
	 * @param string $method    Browser method.
	 * @param int    $dashboard Dashboard id.
	 * @return array{0: string, 1: string}|null
	 */
	public static function target( string $key, string $method, int $dashboard = 0 ): ?array {
		$row = self::ROUTES[ $key ]['methods'][ strtoupper( $method ) ] ?? null;

		if ( null === $row ) {
			return null;
		}

		return array( $row[0], str_replace( '{d}', (string) $dashboard, $row[1] ) );
	}

	/**
	 * Is the call a write? Derived: any Django method but GET.
	 *
	 * @param string $key    Row.
	 * @param string $method Browser method.
	 */
	public static function is_write( string $key, string $method ): bool {
		$target = self::target( $key, $method );

		return null === $target || 'GET' !== $target[0];
	}

	/**
	 * Keeps the allowed body keys; strings are trimmed.
	 *
	 * @param string       $key  Row.
	 * @param array<mixed> $body Raw body.
	 * @return array<string, mixed>
	 */
	public static function filter_body( string $key, array $body ): array {
		$out = array();

		foreach ( self::ROUTES[ $key ]['fields'] ?? array() as $field ) {
			if ( ! array_key_exists( $field, $body ) ) {
				continue;
			}

			$value = $body[ $field ];

			if ( 'enabled' === $field ) {
				$value = is_bool( $value ) ? $value : filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

				if ( null === $value ) {
					continue;
				}
			} elseif ( is_string( $value ) ) {
				$value = trim( $value );
			} elseif ( ! is_scalar( $value ) ) {
				continue;
			}

			$out[ $field ] = $value;
		}

		$clean = self::ROUTES[ $key ]['clean'] ?? null;

		return null === $clean ? $out : self::$clean( $out );
	}

	/**
	 * WooCommerce keys body: no trailing slash on the URL, a blank secret is left out (Django keeps
	 * the stored one), and the webhook secret override never passes.
	 *
	 * @param array<string, mixed> $body Body.
	 * @return array<string, mixed>
	 */
	public static function clean_woo_keys( array $body ): array {
		unset( $body['webhook_secret_override'] );

		if ( isset( $body['woocommerce_url'] ) && is_string( $body['woocommerce_url'] ) ) {
			$body['woocommerce_url'] = rtrim( $body['woocommerce_url'], '/' );
		}

		if ( isset( $body['consumer_secret'] ) && '' === $body['consumer_secret'] ) {
			unset( $body['consumer_secret'] );
		}

		return $body;
	}

	/**
	 * Odoo settings body: a blank password is left out (Django keeps the stored one).
	 *
	 * @param array<string, mixed> $body Body.
	 * @return array<string, mixed>
	 */
	public static function clean_odoo_settings( array $body ): array {
		if ( isset( $body['odoo_password'] ) && '' === $body['odoo_password'] ) {
			unset( $body['odoo_password'] );
		}

		return $body;
	}
}
