<?php
/**
 * The six mapping editor cards: one editor, six configs.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Cards;

use OdooWooPortal\Api\Map;

defined( 'ABSPATH' ) || exit;

/**
 * Column lists and sources come from the Django views (sync_app/views.py of the Django branch:
 * payment :2024, tax :2336, price :938, stock :1845, order route :1072, order pricelist :1142);
 * the allowed keys are Map's `item_fields`. Django does the real validation: `required` and
 * `unique` here only spare the customer a round trip.
 */
final class Mappings {

	/**
	 * The cards of the editor.
	 */
	public const CARDS = array( 'payment-mappings', 'tax-mappings', 'price-mappings', 'stock-mappings', 'order-route-mappings', 'order-pricelist-mappings' );

	/**
	 * Is the card one of the six?
	 *
	 * @param string $card Card name.
	 */
	public static function is_card( string $card ): bool {
		return in_array( $card, self::CARDS, true );
	}

	/**
	 * Does calling this route change anything in Django? Derived from the map: a method that is
	 * not a GET, or a GET flagged `refreshes` (it rewrites the stored picker lists).
	 *
	 * @param string $route  Route key.
	 * @param string $method Browser method.
	 */
	public static function writes( string $route, string $method ): bool {
		return Map::is_write( $route, $method ) || true === ( Map::ROUTES[ $route ]['refreshes'] ?? false );
	}

	/**
	 * The routes a card calls, for the registry.
	 *
	 * @param string $card Card name.
	 * @return string[]
	 */
	public static function keys( string $card ): array {
		$config = self::config( $card );
		$keys   = array( $config['route'] );

		foreach ( array_merge( $config['loaders'], $config['saved'] ) as $entry ) {
			$keys[] = $entry['route'];
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * A select that shows what a list offers.
	 *
	 * @param string               $key     Row key.
	 * @param string               $label   Header.
	 * @param string               $missing What is missing when it is blank.
	 * @param array<string, mixed> $source  route, idKey, nameKey, filter.
	 * @param string|null          $name    Row key of the stored name, if any.
	 * @param bool                 $numeric Whether the value is an Odoo id.
	 * @param array<string, mixed> $extra   More column settings.
	 * @return array<string, mixed>
	 */
	private static function picker( string $key, string $label, string $missing, array $source, ?string $name, bool $numeric, array $extra = array() ): array {
		$column = array(
			'key'      => $key,
			'label'    => $label,
			'type'     => 'select',
			'numeric'  => $numeric,
			'source'   => $source,
			'required' => true,
			'missing'  => $missing,
		);

		if ( null !== $name ) {
			$column['nameField'] = $name;
		}

		return array_merge( $column, $extra );
	}

	/**
	 * A select with a fixed list of choices.
	 *
	 * @param string                                        $key     Row key.
	 * @param string                                        $label   Header.
	 * @param array<int, array{value: string, label: string}> $options Choices.
	 * @param string                                        $default Value of a new row.
	 * @param array<string, mixed>                          $extra   More column settings.
	 * @return array<string, mixed>
	 */
	private static function choice( string $key, string $label, array $options, string $default, array $extra = array() ): array {
		return array_merge(
			array(
				'key'      => $key,
				'label'    => $label,
				'type'     => 'select',
				'options'  => $options,
				'default'  => $default,
				'required' => true,
				'missing'  => __( 'choose a value.', 'odoowoo-portal' ),
			),
			$extra
		);
	}

	/**
	 * A loader button.
	 *
	 * @param string      $label  Button text.
	 * @param string      $route  Route key.
	 * @param string      $method Browser method.
	 * @param string      $empty  What an empty reply says.
	 * @param string|null $into   The list the reply feeds, when not the route's own.
	 * @return array<string, mixed>
	 */
	private static function loader( string $label, string $route, string $method, string $empty, ?string $into = null ): array {
		$loader = array(
			'label'  => $label,
			'route'  => $route,
			'method' => $method,
			'writes' => self::writes( $route, $method ),
			'empty'  => $empty,
		);

		if ( null !== $into ) {
			$loader['into'] = $into;
		}

		return $loader;
	}

	/**
	 * The text columns that name a Woo meta key and value.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function meta_pair(): array {
		return array(
			array(
				'key'      => 'meta_key',
				'label'    => __( 'Order meta key', 'odoowoo-portal' ),
				'type'     => 'text',
				'required' => true,
				'missing'  => __( 'enter the meta key.', 'odoowoo-portal' ),
			),
			array(
				'key'      => 'meta_value',
				'label'    => __( 'Order meta value', 'odoowoo-portal' ),
				'type'     => 'text',
				'required' => true,
				'missing'  => __( 'enter the meta value.', 'odoowoo-portal' ),
			),
		);
	}

	/**
	 * The config of a card; an unknown card gives an empty list.
	 *
	 * @param string $card Card name.
	 * @return array<string, mixed>
	 */
	public static function config( string $card ): array {
		$pricelists = array(
			'route'   => 'odoo-pricelists',
			'idKey'   => 'id',
			'nameKey' => 'name',
		);
		$meta_when  = array(
			'key' => 'target_field',
			'in'  => array( 'meta' ),
		);
		$meta_key   = static fn( string $missing ): array => array(
			'key'      => 'target_meta_key',
			'label'    => __( 'Meta key', 'odoowoo-portal' ),
			'type'     => 'text',
			'required' => true,
			'missing'  => $missing,
			'showWhen' => $meta_when,
		);
		$from_odoo  = __( 'Load from Odoo', 'odoowoo-portal' );

		switch ( $card ) {
			case 'payment-mappings':
				$config = array(
					'route'   => 'payment-method-mappings',
					'columns' => array(
						self::picker(
							'woocommerce_payment_method',
							__( 'WooCommerce payment method', 'odoowoo-portal' ),
							__( 'choose a payment method.', 'odoowoo-portal' ),
							array(
								'route'   => 'woocommerce-payment-methods',
								'idKey'   => 'id',
								'nameKey' => 'title',
							),
							'woocommerce_payment_method_name',
							false
						),
						self::picker(
							'odoo_journal_id',
							__( 'Odoo journal', 'odoowoo-portal' ),
							__( 'choose a journal.', 'odoowoo-portal' ),
							array(
								'route'   => 'odoo-journals',
								'idKey'   => 'id',
								'nameKey' => 'name',
							),
							'odoo_journal_name',
							true
						),
						array(
							'key'       => 'payment_ref_meta_keys',
							'label'     => __( 'Payment reference meta keys', 'odoowoo-portal' ),
							'hint'      => __( 'Order meta keys, separated by commas. At most 500 characters.', 'odoowoo-portal' ),
							'type'      => 'text',
							'maxLength' => 500,
							'required'  => false,
						),
					),
					'loaders' => array(
						self::loader( __( 'Load from Woo', 'odoowoo-portal' ), 'woocommerce-payment-methods', 'GET', __( 'WooCommerce returned no payment methods.', 'odoowoo-portal' ) ),
						self::loader( $from_odoo, 'odoo-journals', 'GET', __( 'Odoo returned no journals.', 'odoowoo-portal' ) ),
					),
					'saved'   => array(
						array(
							'route'   => 'saved-woocommerce-payment-methods',
							'idKey'   => 'method_id',
							'nameKey' => 'title',
							'into'    => 'woocommerce-payment-methods',
						),
						array(
							'route'   => 'saved-odoo-journals',
							'idKey'   => 'journal_id',
							'nameKey' => 'name',
							'into'    => 'odoo-journals',
						),
					),
					'unique'  => array( array( 'woocommerce_payment_method' ) ),
				);
				break;

			case 'tax-mappings':
				$taxes  = array(
					'route'   => 'stored-odoo-taxes',
					'idKey'   => 'id',
					'nameKey' => 'name',
				);
				$config = array(
					'route'   => 'tax-mappings',
					'columns' => array(
						self::picker(
							'woocommerce_tax_class',
							__( 'WooCommerce tax class', 'odoowoo-portal' ),
							__( 'choose a tax class.', 'odoowoo-portal' ),
							array(
								'route'   => 'woo-tax-classes',
								'idKey'   => 'value',
								'nameKey' => 'label',
							),
							null,
							false
						),
						self::picker(
							'odoo_customer_tax_id',
							__( 'Odoo customer tax', 'odoowoo-portal' ),
							__( 'choose a customer tax.', 'odoowoo-portal' ),
							array_merge( $taxes, array( 'filter' => array( 'type_tax_use' => 'sale' ) ) ),
							null,
							true
						),
						self::picker(
							'odoo_supplier_tax_id',
							__( 'Odoo supplier tax', 'odoowoo-portal' ),
							__( 'choose a supplier tax.', 'odoowoo-portal' ),
							array_merge( $taxes, array( 'filter' => array( 'type_tax_use' => 'purchase' ) ) ),
							null,
							true
						),
					),
					'loaders' => array(
						self::loader( __( 'Load from Woo', 'odoowoo-portal' ), 'woo-tax-classes', 'GET', __( 'WooCommerce returned no tax classes.', 'odoowoo-portal' ) ),
						self::loader( $from_odoo, 'fetch-and-store-odoo-taxes', 'POST', __( 'Odoo returned no taxes.', 'odoowoo-portal' ), 'stored-odoo-taxes' ),
					),
					'saved'   => array(
						array_merge( $taxes, array( 'into' => 'stored-odoo-taxes' ) ),
					),
					'unique'  => array( array( 'woocommerce_tax_class' ) ),
				);
				break;

			case 'price-mappings':
				$config = array(
					'route'   => 'price-mappings',
					'columns' => array(
						self::choice(
							'source',
							__( 'Price source', 'odoowoo-portal' ),
							array(
								array(
									'value' => 'list_price',
									'label' => __( 'Product sales price', 'odoowoo-portal' ),
								),
								array(
									'value' => 'pricelist',
									'label' => __( 'Pricelist', 'odoowoo-portal' ),
								),
							),
							'list_price'
						),
						self::picker(
							'odoo_pricelist_id',
							__( 'Odoo pricelist', 'odoowoo-portal' ),
							__( 'choose a pricelist.', 'odoowoo-portal' ),
							$pricelists,
							'odoo_pricelist_name',
							true,
							array(
								'showWhen' => array(
									'key' => 'source',
									'in'  => array( 'pricelist' ),
								),
							)
						),
						self::choice(
							'target_field',
							__( 'Write to', 'odoowoo-portal' ),
							array(
								array(
									'value' => 'regular_price',
									'label' => __( 'Regular price', 'odoowoo-portal' ),
								),
								array(
									'value' => 'sale_price',
									'label' => __( 'Sale price', 'odoowoo-portal' ),
								),
								array(
									'value' => 'meta',
									'label' => __( 'Product meta field', 'odoowoo-portal' ),
								),
							),
							'regular_price'
						),
						$meta_key( __( 'enter the meta key to write.', 'odoowoo-portal' ) ),
						self::choice(
							'tax_treatment',
							__( 'Tax', 'odoowoo-portal' ),
							array(
								array(
									'value' => 'as_is',
									'label' => __( 'Send as is', 'odoowoo-portal' ),
								),
								array(
									'value' => 'add_tax',
									'label' => __( 'Add the product tax', 'odoowoo-portal' ),
								),
								array(
									'value' => 'subtract_tax',
									'label' => __( 'Subtract the product tax', 'odoowoo-portal' ),
								),
							),
							'as_is'
						),
						array(
							'key'      => 'require_explicit_rule',
							'label'    => __( 'Only products with their own pricelist rule', 'odoowoo-portal' ),
							'hint'     => __( 'A pricelist with no rule for a product falls back to its sales price. Tick this to leave such products alone.', 'odoowoo-portal' ),
							'type'     => 'checkbox',
							'required' => false,
						),
					),
					'loaders' => array(
						self::loader( $from_odoo, 'odoo-pricelists', 'GET', __( 'Odoo returned no pricelists.', 'odoowoo-portal' ) ),
					),
					'saved'   => array(),
					'unique'  => array( array( 'target_field', 'target_meta_key' ) ),
				);
				break;

			case 'stock-mappings':
				$config = array(
					'route'   => 'stock-mappings',
					'columns' => array(
						self::picker(
							'odoo_location_id',
							__( 'Odoo location', 'odoowoo-portal' ),
							__( 'choose a location.', 'odoowoo-portal' ),
							array(
								'route'   => 'odoo-stock-locations',
								'idKey'   => 'id',
								'nameKey' => 'name',
							),
							'odoo_location_name',
							true
						),
						self::choice(
							'quantity_setting',
							__( 'Quantity', 'odoowoo-portal' ),
							array(
								array(
									'value' => 'on_hand',
									'label' => __( 'On hand', 'odoowoo-portal' ),
								),
								array(
									'value' => 'forecast',
									'label' => __( 'Forecast', 'odoowoo-portal' ),
								),
								array(
									'value' => 'available',
									'label' => __( 'Available', 'odoowoo-portal' ),
								),
							),
							'available'
						),
						self::choice(
							'target_field',
							__( 'Write to', 'odoowoo-portal' ),
							array(
								array(
									'value' => 'stock_quantity',
									'label' => __( 'Stock quantity', 'odoowoo-portal' ),
								),
								array(
									'value' => 'meta',
									'label' => __( 'Product meta field', 'odoowoo-portal' ),
								),
							),
							'stock_quantity'
						),
						$meta_key( __( 'enter the meta key to write.', 'odoowoo-portal' ) ),
					),
					'loaders' => array(
						self::loader( $from_odoo, 'odoo-stock-locations', 'GET', __( 'Odoo returned no stock locations.', 'odoowoo-portal' ) ),
					),
					'saved'   => array(),
					'unique'  => array( array( 'target_field', 'target_meta_key' ) ),
				);
				break;

			case 'order-route-mappings':
				$config = array(
					'route'   => 'order-route-mappings',
					'columns' => array_merge(
						self::meta_pair(),
						array(
							self::picker(
								'odoo_route_id',
								__( 'Odoo route', 'odoowoo-portal' ),
								__( 'choose a route.', 'odoowoo-portal' ),
								array(
									'route'   => 'odoo-routes',
									'idKey'   => 'id',
									'nameKey' => 'name',
								),
								'odoo_route_name',
								true
							),
						)
					),
					'loaders' => array(
						self::loader( $from_odoo, 'odoo-routes', 'GET', __( 'Odoo returned no routes.', 'odoowoo-portal' ) ),
					),
					'saved'   => array(),
					'unique'  => array( array( 'meta_key', 'meta_value' ) ),
				);
				break;

			case 'order-pricelist-mappings':
				$config = array(
					'route'   => 'order-pricelist-mappings',
					'columns' => array_merge(
						self::meta_pair(),
						array(
							self::picker( 'odoo_pricelist_id', __( 'Odoo pricelist', 'odoowoo-portal' ), __( 'choose a pricelist.', 'odoowoo-portal' ), $pricelists, 'odoo_pricelist_name', true ),
						)
					),
					'loaders' => array(
						self::loader( $from_odoo, 'odoo-pricelists', 'GET', __( 'Odoo returned no pricelists.', 'odoowoo-portal' ) ),
					),
					'saved'   => array(),
					'unique'  => array( array( 'meta_key', 'meta_value' ) ),
				);
				break;

			default:
				return array();
		}

		return $config;
	}

	/**
	 * The editor's strings (shared by the six cards). %s, %1$s and %2$s are filled in by the script.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'addRow'      => __( 'Add row', 'odoowoo-portal' ),
			'removeRow'   => __( 'Remove', 'odoowoo-portal' ),
			/* translators: %s: row number. */
			'removeRowN'  => __( 'Remove row %s', 'odoowoo-portal' ),
			'save'        => __( 'Save', 'odoowoo-portal' ),
			'noRows'      => __( 'No rows yet.', 'odoowoo-portal' ),
			/* translators: 1: row number, 2: the earlier row it repeats. */
			'rowRepeats'  => __( 'Row %1$s repeats row %2$s.', 'odoowoo-portal' ),
			/* translators: 1: row number, 2: what is missing. */
			'rowError'    => __( 'Row %1$s: %2$s', 'odoowoo-portal' ),
			/* translators: 1: column name, 2: row number. */
			'rowLabel'    => __( '%1$s, row %2$s', 'odoowoo-portal' ),
			'choose'      => __( 'Choose…', 'odoowoo-portal' ),
			'loading'     => __( 'Loading…', 'odoowoo-portal' ),
			'loadedOk'    => __( 'Loaded.', 'odoowoo-portal' ),
			'listLoading' => __( 'Loading the saved rows…', 'odoowoo-portal' ),
			'fixErrors'   => __( 'Fix the marked rows, then save again.', 'odoowoo-portal' ),
		);
	}
}
