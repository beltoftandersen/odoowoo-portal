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
			'shape'        => 'items',
			// Django reply: the list is under the 'pricelists' key (sync_app/views.py:1068 Response({"pricelists": [...]})).
			'unwrap'       => 'pricelists',
			'read_fields'  => array( 'id', 'name', 'currency' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'odoo-routes' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/odoo-routes/' ),
			),
			'fields'       => array(),
			'shape'        => 'items',
			// Django reply: the list is under the 'routes' key (sync_app/views.py:1948 Response({"routes": [...]})).
			'unwrap'       => 'routes',
			'read_fields'  => array( 'id', 'name' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'odoo-stock-locations' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/odoo-stock-locations/' ),
			),
			'fields'       => array(),
			'shape'        => 'items',
			// Django reply: the list is under the 'locations' key (sync_app/views.py:1976 Response({"locations": [...]})).
			'unwrap'       => 'locations',
			'read_fields'  => array( 'id', 'name' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'payment-method-mappings' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/payment-method-mappings/' ),
				'PUT' => array( 'POST', 'dashboards/{d}/payment-method-mappings/' ),
			),
			'fields'       => array( 'items' ),
			'item_fields'  => array( 'woocommerce_payment_method', 'woocommerce_payment_method_name', 'odoo_journal_id', 'odoo_journal_name', 'payment_ref_meta_keys' ),
			'shape'        => 'items',
			// Django reply: a bare list (sync_app/views.py:2048 Response(mappings_data), a bare list).
			'unwrap'       => null,
			'read_fields'  => array( 'id', 'woocommerce_payment_method', 'woocommerce_payment_method_name', 'odoo_journal_id', 'odoo_journal_name', 'payment_ref_meta_keys' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'woocommerce-payment-methods' => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/woocommerce-payment-methods/' ) ),
			'fields'       => array(),
			'shape'        => 'items',
			// Django reply: a bare list (sync_app/views.py:815 Response(enabled_methods), a bare list).
			'unwrap'       => null,
			'read_fields'  => array( 'id', 'title' ),
			// This GET writes Django rows (sync_app/views.py: payment methods :809, journals :841-843, accounts :885-887), so support may not call it.
			'refreshes'    => true,
			'read_only_ok' => false,
			'limit'        => null,
		),
		'saved-woocommerce-payment-methods' => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/saved-woocommerce-payment-methods/' ) ),
			'fields'       => array(),
			'shape'        => 'items',
			// Django reply: a bare list (sync_app/views.py:1996 Response(methods_data), a bare list).
			'unwrap'       => null,
			'read_fields'  => array( 'method_id', 'title' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'odoo-journals' => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/odoo-journals/' ) ),
			'fields'       => array(),
			'shape'        => 'items',
			// Django reply: a bare list (sync_app/views.py:849 Response(journals), a bare list).
			'unwrap'       => null,
			'read_fields'  => array( 'id', 'name' ),
			// This GET writes Django rows (sync_app/views.py: payment methods :809, journals :841-843, accounts :885-887), so support may not call it.
			'refreshes'    => true,
			'read_only_ok' => false,
			'limit'        => null,
		),
		'saved-odoo-journals' => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/saved-odoo-journals/' ) ),
			'fields'       => array(),
			'shape'        => 'items',
			// Django reply: a bare list (sync_app/views.py:2016 Response(journals_data), a bare list).
			'unwrap'       => null,
			'read_fields'  => array( 'journal_id', 'name' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'odoo-accounts' => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/odoo-accounts/' ) ),
			'fields'       => array(),
			'shape'        => 'items',
			// Django reply: a bare list (sync_app/views.py:897 Response([...account_id, code, name...]), a bare list).
			'unwrap'       => null,
			'read_fields'  => array( 'account_id', 'code', 'name' ),
			// This GET writes Django rows (sync_app/views.py: payment methods :809, journals :841-843, accounts :885-887), so support may not call it.
			'refreshes'    => true,
			'read_only_ok' => false,
			'limit'        => null,
		),
		'saved-odoo-accounts' => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/saved-odoo-accounts/' ) ),
			'fields'       => array(),
			'shape'        => 'items',
			// Django reply: a bare list (sync_app/views.py:934 Response(accounts_data), a bare list).
			'unwrap'       => null,
			'read_fields'  => array( 'account_id', 'code', 'name' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'tax-mappings' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/tax-mappings/' ),
				'PUT' => array( 'POST', 'dashboards/{d}/tax-mappings/' ),
			),
			'fields'       => array( 'items' ),
			'item_fields'  => array( 'woocommerce_tax_class', 'odoo_customer_tax_id', 'odoo_supplier_tax_id' ),
			'shape'        => 'items',
			// Django reply: a bare list (sync_app/views.py:2336 WooTaxMappingListCreateView.get Response(serializer.data), a bare list).
			'unwrap'       => null,
			'read_fields'  => array( 'id', 'woocommerce_tax_class', 'odoo_customer_tax_id', 'odoo_supplier_tax_id' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'woo-tax-classes' => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/woo-tax-classes/' ) ),
			'fields'       => array(),
			'shape'        => 'items',
			// Django reply: a bare list (sync_app/views.py:2411 WooTaxClassesView.get Response(classes), a bare list).
			'unwrap'       => null,
			'read_fields'  => array( 'label', 'value' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'odoo-taxes' => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/odoo-taxes/' ) ),
			'fields'       => array(),
			'shape'        => 'items',
			// Django reply: a bare list (sync_app/views.py:2476 OdooTaxesView.get Response(sorted_records), a bare list).
			'unwrap'       => null,
			'read_fields'  => array( 'id', 'name' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'stored-odoo-taxes' => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/stored-odoo-taxes/' ) ),
			'fields'       => array(),
			'shape'        => 'items',
			// Django reply: a bare list (sync_app/views.py:2573 ListOdooTaxesView.get Response(data), a bare list).
			'unwrap'       => null,
			'read_fields'  => array( 'id', 'name', 'type_tax_use' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'fetch-and-store-odoo-taxes' => array(
			'methods'      => array( 'POST' => array( 'POST', 'dashboards/{d}/fetch-and-store-odoo-taxes/' ) ),
			'fields'       => array(),
			'shape'        => 'items',
			// Django reply: a bare list (sync_app/views.py:2510 FetchAndStoreOdooTaxesView.post Response(serializer_data), a bare list (204 with a message when Odoo has no taxes)).
			'unwrap'       => null,
			'read_fields'  => array( 'id', 'name', 'rate', 'type_tax_use' ),
			'shape_for'    => array( 'POST' ),
			'read_only_ok' => false,
			'limit'        => 'verify',
		),
		'price-mappings' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/price-mappings/' ),
				'PUT' => array( 'POST', 'dashboards/{d}/price-mappings/' ),
			),
			'fields'       => array( 'items' ),
			'item_fields'  => array( 'source', 'odoo_pricelist_id', 'odoo_pricelist_name', 'target_field', 'target_meta_key', 'tax_treatment', 'require_explicit_rule' ),
			'shape'        => 'price_rows',
			// Django reply: the list is under the 'mappings' key (sync_app/views.py:954 Response({"mappings": [...]})).
			'unwrap'       => 'mappings',
			'read_fields'  => array( 'id', 'source', 'odoo_pricelist_id', 'odoo_pricelist_name', 'target_field', 'target_meta_key', 'tax_treatment', 'require_explicit_rule' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'stock-mappings' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/stock-mappings/' ),
				'PUT' => array( 'POST', 'dashboards/{d}/stock-mappings/' ),
			),
			'fields'       => array( 'items' ),
			'item_fields'  => array( 'odoo_location_id', 'odoo_location_name', 'quantity_setting', 'target_field', 'target_meta_key' ),
			'shape'        => 'items',
			// Django reply: the list is under the 'mappings' key (sync_app/views.py:1861 Response({"mappings": [...]})).
			'unwrap'       => 'mappings',
			'read_fields'  => array( 'id', 'odoo_location_id', 'odoo_location_name', 'quantity_setting', 'target_field', 'target_meta_key' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'order-route-mappings' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/order-route-mappings/' ),
				'PUT' => array( 'POST', 'dashboards/{d}/order-route-mappings/' ),
			),
			'fields'       => array( 'items' ),
			'item_fields'  => array( 'meta_key', 'meta_value', 'odoo_route_id', 'odoo_route_name' ),
			'shape'        => 'items',
			// Django reply: the list is under the 'mappings' key (sync_app/views.py:1088 Response({"mappings": [...]})).
			'unwrap'       => 'mappings',
			'read_fields'  => array( 'id', 'meta_key', 'meta_value', 'odoo_route_id', 'odoo_route_name' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'order-pricelist-mappings' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/order-pricelist-mappings/' ),
				'PUT' => array( 'POST', 'dashboards/{d}/order-pricelist-mappings/' ),
			),
			'fields'       => array( 'items' ),
			'item_fields'  => array( 'meta_key', 'meta_value', 'odoo_pricelist_id', 'odoo_pricelist_name' ),
			'shape'        => 'items',
			// Django reply: the list is under the 'mappings' key (sync_app/views.py:1157 Response({"mappings": [...]})).
			'unwrap'       => 'mappings',
			'read_fields'  => array( 'id', 'meta_key', 'meta_value', 'odoo_pricelist_id', 'odoo_pricelist_name' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'trigger-sync' => array(
			'methods'      => array( 'POST' => array( 'POST', 'dashboards/{d}/trigger-sync/' ) ),
			'fields'       => array(),
			'ids'          => array( 'product_ids' ),
			'kind'         => 'products',
			'read_only_ok' => false,
			'limit'        => 'sync',
		),
		'trigger-category-sync' => array(
			'methods'      => array( 'POST' => array( 'POST', 'dashboards/{d}/trigger-category-sync/' ) ),
			'fields'       => array(),
			'kind'         => 'categories',
			'read_only_ok' => false,
			'limit'        => 'sync',
		),
		'trigger-brand-sync' => array(
			'methods'      => array( 'POST' => array( 'POST', 'dashboards/{d}/trigger-brand-sync/' ) ),
			'fields'       => array(),
			'kind'         => 'brands',
			'read_only_ok' => false,
			'limit'        => 'sync',
		),
		'sync-stock' => array(
			'methods'      => array( 'POST' => array( 'POST', 'dashboards/{d}/sync-stock/' ) ),
			'fields'       => array(),
			'ids'          => array( 'product_ids' ),
			'kind'         => 'stock',
			'read_only_ok' => false,
			'limit'        => 'sync',
		),
		'sync-prices' => array(
			'methods'      => array( 'POST' => array( 'POST', 'dashboards/{d}/sync-prices/' ) ),
			'fields'       => array(),
			'ids'          => array( 'product_ids' ),
			'kind'         => 'prices',
			'read_only_ok' => false,
			'limit'        => 'sync',
		),
		'sync-barcodes' => array(
			'methods'      => array( 'POST' => array( 'POST', 'dashboards/{d}/sync-barcodes/' ) ),
			'fields'       => array(),
			'ids'          => array( 'product_ids' ),
			'kind'         => 'barcodes',
			'read_only_ok' => false,
			'limit'        => 'sync',
		),
		'sync-orders' => array(
			'methods'      => array( 'POST' => array( 'POST', 'dashboards/{d}/sync-orders/' ) ),
			'fields'       => array(),
			'ids'          => array( 'order_ids' ),
			'range'        => true,
			'kind'         => 'orders',
			'read_only_ok' => false,
			'limit'        => 'sync',
		),
		'task-status' => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/task-status/' ) ),
			'fields'       => array(),
			'task'         => true,
			'read_only_ok' => false,
			'limit'        => null,
		),
		'order-syncs' => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/order-syncs/' ) ),
			'fields'       => array(),
			'shape'        => 'order_syncs',
			'read_only_ok' => false,
			'limit'        => null,
		),
		'gift-card-settings' => array(
			'methods'      => array(
				'GET' => array( 'GET', 'dashboards/{d}/gift-card-settings/' ),
				'PUT' => array( 'POST', 'dashboards/{d}/gift-card-settings/' ),
			),
			'fields'       => array( 'settings' ),
			'setting_fields' => array( 'enabled', 'enable_expiry_sweep', 'sale_tax_id', 'expiry_tax_id', 'redeem_journal_id', 'expiry_journal_id', 'expiry_debit_account_id', 'expiry_credit_account_id', 'expiry_vat_account_id', 'sale_line_meta_key', 'sale_product_codes', 'sale_tax_name', 'redeem_order_meta_key', 'card_api_path', 'paid_flag_field', 'redeem_journal_name', 'expiry_api_path', 'expiry_status_values', 'expiry_journal_name', 'expiry_tax_name', 'legacy_card_cutoff', 'expiry_min_balance' ),
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
	 * Keeps the allowed body keys; strings are trimmed, except the two secrets (sent byte-exact).
	 *
	 * @param string       $key  Row.
	 * @param array<mixed> $body Raw body.
	 * @return array<string, mixed>
	 */
	public static function filter_body( string $key, array $body ): array {
		$out = self::sync_input( $key, $body );

		foreach ( self::ROUTES[ $key ]['fields'] ?? array() as $field ) {
			if ( ! array_key_exists( $field, $body ) ) {
				continue;
			}

			$value = $body[ $field ];

			if ( 'items' === $field || 'settings' === $field ) {
				$list = self::structured( $key, $field, $value );

				if ( null !== $list ) {
					$out['items' === $field ? 'mappings' : 'settings'] = $list;
				}

				continue;
			}

			if ( 'enabled' === $field ) {
				$value = is_bool( $value ) ? $value : filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

				if ( null === $value ) {
					continue;
				}
			} elseif ( is_string( $value ) ) {
				$value = in_array( $field, array( 'consumer_secret', 'odoo_password' ), true ) ? $value : trim( $value );
			} elseif ( ! is_scalar( $value ) ) {
				continue;
			}

			$out[ $field ] = $value;
		}

		$clean = self::ROUTES[ $key ]['clean'] ?? null;

		return null === $clean ? $out : self::$clean( $out );
	}

	/**
	 * Unique positive ids from an array or from text like "1, 2,,x 3". Never truncates: more than
	 * the cap is refused.
	 *
	 * @param mixed $input Browser value.
	 * @param int   $cap   Most ids allowed.
	 * @return array<int, int>
	 * @throws \LengthException When there are more than $cap ids.
	 */
	public static function clean_ids( $input, int $cap = 500 ): array {
		if ( is_string( $input ) ) {
			$input = preg_split( '/[\s,;]+/', $input, -1, PREG_SPLIT_NO_EMPTY );
		}

		$ids = array();

		foreach ( is_array( $input ) ? $input : array() as $item ) {
			if ( is_string( $item ) ) {
				$item = trim( $item );
				$item = '' !== $item && strlen( $item ) <= 15 && ctype_digit( $item ) ? (int) $item : 0;
			}

			if ( is_int( $item ) && $item > 0 ) {
				$ids[ $item ] = $item;
			}
		}

		if ( count( $ids ) > $cap ) {
			throw new \LengthException( esc_html__( 'At most 500 ids at a time.', 'odoowoo-portal' ) );
		}

		return array_values( $ids );
	}

	/**
	 * A date range: both days (Y-m-d) or neither.
	 *
	 * @param mixed $from Start.
	 * @param mixed $to   End.
	 * @return array{from_date: string, to_date: string}|null Null when both are empty.
	 * @throws \InvalidArgumentException When a date is missing, invalid, or the range is reversed.
	 */
	public static function clean_range( $from, $to ): ?array {
		$blank = static fn( $v ) => null === $v || '' === $v;

		if ( $blank( $from ) && $blank( $to ) ) {
			return null;
		}

		$a = is_string( $from ) ? self::day( trim( $from ) ) : null;
		$b = is_string( $to ) ? self::day( trim( $to ) ) : null;

		if ( null === $a || null === $b ) {
			throw new \InvalidArgumentException( esc_html__( 'Enter a valid start and end date.', 'odoowoo-portal' ) );
		}

		if ( $a > $b ) {
			throw new \InvalidArgumentException( esc_html__( 'The start date is after the end date.', 'odoowoo-portal' ) );
		}

		return array(
			'from_date' => $a,
			'to_date'   => $b,
		);
	}

	/**
	 * A real calendar day in Y-m-d, or null.
	 *
	 * @param string $value Text.
	 */
	private static function day( string $value ): ?string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );

		return $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
	}

	/**
	 * The id lists and date range of a sync row. Empty fields mean "everything".
	 *
	 * @param string       $key  Row.
	 * @param array<mixed> $body Raw body.
	 * @return array<string, mixed>
	 * @throws InputRefused When an input is not acceptable.
	 */
	private static function sync_input( string $key, array $body ): array {
		$row = self::ROUTES[ $key ];
		$out = array();

		foreach ( $row['ids'] ?? array() as $field ) {
			$raw = $body[ $field ] ?? null;

			if ( null === $raw || array() === $raw || ( is_string( $raw ) && '' === trim( $raw ) ) ) {
				continue;
			}

			try {
				$ids = self::clean_ids( $raw );
			} catch ( \LengthException $e ) {
				throw new InputRefused( $e->getMessage(), $field ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			if ( array() === $ids ) {
				throw new InputRefused( __( 'Enter at least one valid id, or leave the field empty.', 'odoowoo-portal' ), $field ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			$out[ $field ] = $ids;
		}

		if ( empty( $row['range'] ) || array() !== $out ) {
			return $out;
		}

		try {
			$range = self::clean_range( $body['from_date'] ?? null, $body['to_date'] ?? null );
		} catch ( \InvalidArgumentException $e ) {
			throw new InputRefused( $e->getMessage(), 'from_date' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( null === $range ) {
			throw new InputRefused( __( 'Enter order ids, or choose a start and end date.', 'odoowoo-portal' ), 'order_ids' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $range;
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

	/**
	 * A mapping list or settings object of the browser, cut to the row's allowed keys with plain
	 * values only. Whether the values are right is Django's call, never ours.
	 *
	 * @param string $key   Row.
	 * @param string $field items or settings.
	 * @param mixed  $value Browser value.
	 * @return array<mixed>|null Null when the shape is wrong (the key is then not sent).
	 */
	private static function structured( string $key, string $field, $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$row = self::ROUTES[ $key ];

		if ( 'settings' === $field ) {
			return self::pick( $value, $row['setting_fields'] ?? array() );
		}

		if ( ! array_is_list( $value ) ) {
			return null;
		}

		$items = array();

		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				return null;
			}

			$items[] = self::pick( $item, $row['item_fields'] ?? array() );
		}

		return $items;
	}

	/**
	 * Allowed keys with null, bool, number or string values, byte-exact.
	 *
	 * @param array<mixed>  $data    Data.
	 * @param array<string> $allowed Keys.
	 * @return array<string, mixed>
	 */
	private static function pick( array $data, array $allowed ): array {
		$out = array();

		foreach ( $allowed as $name ) {
			if ( ! array_key_exists( $name, $data ) ) {
				continue;
			}

			$v = $data[ $name ];

			if ( null === $v || is_bool( $v ) || is_int( $v ) || is_float( $v ) ) {
				$out[ $name ] = $v;
			} elseif ( is_string( $v ) ) {
				$out[ $name ] = $v;
			}
		}

		return $out;
	}

	/**
	 * Does a reply of this row and browser method get shaped?
	 *
	 * @param string $key    Row.
	 * @param string $method Browser method.
	 */
	public static function shapes( string $key, string $method ): bool {
		$row = self::ROUTES[ $key ] ?? array();

		return isset( $row['shape'] ) && in_array( strtoupper( $method ), $row['shape_for'] ?? array( 'GET' ), true );
	}

	/**
	 * The browser's data for a successful Django reply: lists become {items: [...]}, an empty or
	 * odd body becomes an empty list, never a warning. Rows without a shape pass unchanged.
	 *
	 * @param string       $key    Row.
	 * @param string       $method Browser method.
	 * @param array<mixed> $data   Django data (secrets already removed).
	 * @return array<mixed>|null Null when the body is not the list Django sends (a missing or odd
	 *                            list key, a bare object): unreadable, which is not "no rows".
	 */
	public static function shape( string $key, string $method, array $data ): ?array {
		$row   = self::ROUTES[ $key ] ?? array();
		$shape = $row['shape'] ?? null;

		if ( null === $shape || ! in_array( strtoupper( $method ), $row['shape_for'] ?? array( 'GET' ), true ) ) {
			return $data;
		}

		if ( 'order_syncs' === $shape ) {
			return array( 'items' => self::order_sync_rows( $data ) );
		}

		// The row says where Django put the list: under its unwrap key, or (null) as the bare body.
		$unwrap = $row['unwrap'] ?? null;

		if ( null !== $unwrap ) {
			if ( ! is_array( $data[ $unwrap ] ?? null ) || ! array_is_list( $data[ $unwrap ] ) ) {
				return null;
			}

			$data = $data[ $unwrap ];
		} elseif ( ! array_is_list( $data ) ) {
			return null;
		}

		$items = array();

		foreach ( is_array( $data ) ? $data : array() as $item ) {
			if ( is_array( $item ) ) {
				$items[] = self::pick_read( $item, $row['read_fields'] ?? array() );
			}
		}

		if ( 'price_rows' === $shape ) {
			$items = array_map( array( __CLASS__, 'price_row' ), $items );
		}

		return array( 'items' => $items );
	}

	/**
	 * Sync log rows as Django sent them (its order), the fields the app shows, plus a status text.
	 *
	 * @param array<mixed> $data Django list.
	 * @return array<int, array<string, mixed>>
	 */
	private static function order_sync_rows( array $data ): array {
		$rows = array();

		foreach ( array_is_list( $data ) ? $data : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$text = static fn( $name ) => isset( $row[ $name ] ) && is_scalar( $row[ $name ] ) ? (string) $row[ $name ] : '';

			$status = strtolower( $text( 'status' ) );

			if ( 'success' === $status ) {
				$status_text = __( 'Synced', 'odoowoo-portal' );
			} elseif ( in_array( $status, array( 'failed', 'error' ), true ) ) {
				$status_text = __( 'Failed', 'odoowoo-portal' );
			} elseif ( 'warning' === $status ) {
				$status_text = __( 'Warning', 'odoowoo-portal' );
			} else {
				$status_text = __( 'Unknown', 'odoowoo-portal' );
			}

			$rows[] = array(
				'woocommerce_order_id' => $text( 'woocommerce_order_id' ),
				'odoo_order_id'        => $text( 'odoo_order_id' ),
				'status'               => $text( 'status' ),
				'status_text'          => $status_text,
				'synced_at'            => $text( 'synced_at' ),
				'log'                  => $text( 'log' ),
			);
		}

		return $rows;
	}

	/**
	 * Only the fields a card needs: nothing internal (tenant, dashboard) reaches the browser.
	 *
	 * @param array<string, mixed> $item   Django row.
	 * @param array<string>        $fields Allowed keys.
	 * @return array<string, mixed>
	 */
	private static function pick_read( array $item, array $fields ): array {
		return array_intersect_key( $item, array_flip( $fields ) );
	}

	/**
	 * A price row always carries the saved pricelist id and name, whether or not the pricelist is
	 * in the list loaded from Odoo.
	 *
	 * @param array<string, mixed> $row Django row.
	 * @return array<string, mixed>
	 */
	public static function price_row( array $row ): array {
		$id   = $row['odoo_pricelist_id'] ?? null;
		$name = $row['odoo_pricelist_name'] ?? '';

		$row['odoo_pricelist_id']   = is_numeric( $id ) ? (int) $id : null;
		$row['odoo_pricelist_name'] = is_scalar( $name ) ? (string) $name : '';

		return $row;
	}
}
