<?php
/**
 * The six mapping editor cards: configs, markup and read-only state.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Api\Map;
use OdooWooPortal\Cards\Mappings;
use OdooWooPortal\Cards\Registry;
use OdooWooPortal\Cards\Renderer;
use OdooWooPortal\Context;
use OdooWooPortal\Support;

/**
 * Behaviour (rows, checks, loaders, save) is in tests/js/mapping-editor.test.mjs, against the
 * configs and replies produced here and by BrowserFixturesTest.
 */
final class CardMappingsTest extends RestTestCase {

	private const D = 7;

	/**
	 * Card => the route it edits and the keys of its columns (each from the Django view,
	 * sync_app/views.py of the Django branch: payment :2024, tax :2336, price :938, stock :1845,
	 * order route :1072, order pricelist :1142).
	 *
	 * @var array<string, array{0: string, 1: string[]}>
	 */
	private const CARDS = array(
		'payment-mappings'         => array( 'payment-method-mappings', array( 'woocommerce_payment_method', 'odoo_journal_id', 'payment_ref_meta_keys' ) ),
		'tax-mappings'             => array( 'tax-mappings', array( 'woocommerce_tax_class', 'odoo_customer_tax_id', 'odoo_supplier_tax_id' ) ),
		'price-mappings'           => array( 'price-mappings', array( 'source', 'odoo_pricelist_id', 'target_field', 'target_meta_key', 'tax_treatment', 'require_explicit_rule' ) ),
		'stock-mappings'           => array( 'stock-mappings', array( 'odoo_location_id', 'quantity_setting', 'target_field', 'target_meta_key' ) ),
		'order-route-mappings'     => array( 'order-route-mappings', array( 'meta_key', 'meta_value', 'odoo_route_id' ) ),
		'order-pricelist-mappings' => array( 'order-pricelist-mappings', array( 'meta_key', 'meta_value', 'odoo_pricelist_id' ) ),
	);

	/**
	 * Saved $_GET.
	 *
	 * @var array<string, mixed>
	 */
	private $get = array();

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

	/**
	 * Renders a card for a license state; owner unless $support.
	 *
	 * @param string $card    Card.
	 * @param string $state   Resource state.
	 * @param bool   $support Render as a support viewer.
	 */
	private function render( string $card, string $state = 'ready', bool $support = false ): string {
		$license = $this->odoowoo_license( array(), $state );
		Context::reset();

		if ( $support ) {
			$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
			get_userdata( $admin )->add_cap( 'manage_woocommerce' );
			$this->as_user( $admin );
			$query = array( 'license' => (string) $license['id'], 'odoowoo_support' => '1', '_wpnonce' => wp_create_nonce( 'odoowoo_support_' . $license['id'] ) );
			$this->assertTrue( Support::arrived( $query, $admin ) );
		} else {
			$this->as_user( (int) $license['user_id'] );
		}

		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => (string) self::D,
		);

		$html = Renderer::render( $card );
		$this->assertSame( array(), $this->django_calls(), 'Rendering makes no Django call.' );

		return $html;
	}

	/**
	 * The config printed in the card.
	 *
	 * @param string $html Card markup.
	 * @return array<string, mixed>
	 */
	private function printed_config( string $html ): array {
		$this->assertSame( 1, preg_match( '#data-config="([^"]*)"#', $html, $m ), 'The config is a data attribute.' );
		$config = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		$this->assertIsArray( $config );

		return $config;
	}

	/**
	 * The opening tag of a button, by its marker attribute.
	 *
	 * @param string $html   Markup.
	 * @param string $marker Attribute text.
	 */
	private function button( string $html, string $marker ): string {
		$this->assertSame( 1, preg_match( '#<button\b[^>]*' . preg_quote( $marker, '#' ) . '[^>]*>#', $html, $m ), $marker );

		return $m[0];
	}

	public function test_each_card_config_matches_its_map_route_and_columns(): void {
		foreach ( self::CARDS as $card => list( $route, $keys ) ) {
			$config = Mappings::config( $card );
			$row    = Map::ROUTES[ $route ] ?? null;

			$this->assertIsArray( $row, $card . ': the route is in the map.' );
			$this->assertSame( $route, $config['route'], $card );
			$this->assertArrayHasKey( 'PUT', $row['methods'], $card . ': the list is saved with a PUT.' );
			$this->assertSame( $keys, array_column( $config['columns'], 'key' ), $card . ': the columns, in order.' );

			$allowed = $row['item_fields'];

			foreach ( $config['columns'] as $column ) {
				$this->assertContains( $column['key'], $allowed, $card . ' ' . $column['key'] . ' is an item field.' );

				if ( isset( $column['nameField'] ) ) {
					$this->assertContains( $column['nameField'], $allowed, $card . ' ' . $column['nameField'] );
				}

				$this->assertContains( $column['type'], array( 'select', 'text', 'number', 'checkbox' ), $card );
				$this->assertNotSame( '', $column['label'], $card );

				if ( isset( $column['source'] ) ) {
					$this->assertArrayHasKey( $column['source']['route'], Map::ROUTES, $card . ' source ' . $column['source']['route'] );
				}

				if ( ! empty( $column['required'] ) ) {
					$this->assertNotSame( '', $column['missing'] ?? '', $card . ' ' . $column['key'] . ' says what is missing.' );
				}
			}

			foreach ( $config['loaders'] as $loader ) {
				$this->assertArrayHasKey( $loader['route'], Map::ROUTES, $card . ' loader ' . $loader['route'] );
				$this->assertArrayHasKey( $loader['method'], Map::ROUTES[ $loader['route'] ]['methods'], $card . ' loader method' );
				$this->assertContains( $loader['route'], Registry::cards()[ $card ]['keys'], $card . ': the card lists the loader route.' );
				$this->assertNotSame( '', $loader['empty'], $card . ': what an empty reply says.' );
				$this->assertSame( Mappings::writes( $loader['route'], $loader['method'] ), $loader['writes'], $card . ' ' . $loader['route'] . ': writes comes from the map.' );
			}

			foreach ( $config['saved'] as $saved ) {
				$this->assertArrayHasKey( $saved['route'], Map::ROUTES, $card . ' saved ' . $saved['route'] );
				$this->assertContains( $saved['route'], Registry::cards()[ $card ]['keys'], $card );
				$this->assertContains( $saved['idKey'], Map::ROUTES[ $saved['route'] ]['read_fields'], $card . ' ' . $saved['route'] . ' idKey' );
				$this->assertContains( $saved['nameKey'], Map::ROUTES[ $saved['route'] ]['read_fields'], $card . ' ' . $saved['route'] . ' nameKey' );
			}

			foreach ( $config['unique'] as $group ) {
				foreach ( $group as $key ) {
					$this->assertContains( $key, $keys, $card . ' unique ' . $key );
				}
			}

			$this->assertContains( $route, Registry::cards()[ $card ]['keys'], $card );
			$this->assertSame( 'assets/js/card-mappings.js', Registry::cards()[ $card ]['script'], $card );
			$this->assertContains( 'odoowoo-mapping-editor', Registry::cards()[ $card ]['requires'], $card );
		}
	}

	public function test_loader_writes_come_from_the_map_not_from_a_second_list(): void {
		// The three Django GETs that write rows, and the tax refresh, are the writing loaders.
		$this->assertTrue( Mappings::writes( 'woocommerce-payment-methods', 'GET' ) );
		$this->assertTrue( Mappings::writes( 'odoo-journals', 'GET' ) );
		$this->assertTrue( Mappings::writes( 'odoo-accounts', 'GET' ) );
		$this->assertTrue( Mappings::writes( 'fetch-and-store-odoo-taxes', 'POST' ) );

		foreach ( array( 'woo-tax-classes', 'odoo-taxes', 'odoo-pricelists', 'odoo-routes', 'odoo-stock-locations', 'saved-odoo-journals', 'stored-odoo-taxes' ) as $read ) {
			$this->assertFalse( Mappings::writes( $read, 'GET' ), $read );
		}
	}

	public function test_saved_names_are_in_the_initial_state(): void {
		$payment = Mappings::config( 'payment-mappings' );
		$by_key  = array_column( $payment['columns'], null, 'key' );

		$this->assertSame( 'woocommerce_payment_method_name', $by_key['woocommerce_payment_method']['nameField'] );
		$this->assertSame( 'odoo_journal_name', $by_key['odoo_journal_id']['nameField'] );
		$this->assertSame(
			array(
				array( 'route' => 'saved-woocommerce-payment-methods', 'idKey' => 'method_id', 'nameKey' => 'title', 'into' => 'woocommerce-payment-methods' ),
				array( 'route' => 'saved-odoo-journals', 'idKey' => 'journal_id', 'nameKey' => 'name', 'into' => 'odoo-journals' ),
			),
			$payment['saved']
		);

		$price = array_column( Mappings::config( 'price-mappings' )['columns'], null, 'key' );
		$this->assertSame( 'odoo_pricelist_name', $price['odoo_pricelist_id']['nameField'], 'Every price row keeps its pricelist name.' );

		$this->assertSame( 'odoo_location_name', array_column( Mappings::config( 'stock-mappings' )['columns'], null, 'key' )['odoo_location_id']['nameField'] );
		$this->assertSame( 'odoo_route_name', array_column( Mappings::config( 'order-route-mappings' )['columns'], null, 'key' )['odoo_route_id']['nameField'] );
		$this->assertSame( 'odoo_pricelist_name', array_column( Mappings::config( 'order-pricelist-mappings' )['columns'], null, 'key' )['odoo_pricelist_id']['nameField'] );

		// Tax rows hold ids only (Django models.py:247): the names come from the stored taxes.
		$tax = Mappings::config( 'tax-mappings' );

		foreach ( $tax['columns'] as $column ) {
			$this->assertArrayNotHasKey( 'nameField', $column, $column['key'] );
		}

		$by_key = array_column( $tax['columns'], null, 'key' );
		$this->assertSame( 'stored-odoo-taxes', $by_key['odoo_customer_tax_id']['source']['route'] );
		$this->assertSame( array( 'type_tax_use' => 'sale' ), $by_key['odoo_customer_tax_id']['source']['filter'] );
		$this->assertSame( array( 'type_tax_use' => 'purchase' ), $by_key['odoo_supplier_tax_id']['source']['filter'] );
		$this->assertSame( array( array( 'route' => 'stored-odoo-taxes', 'idKey' => 'id', 'nameKey' => 'name', 'into' => 'stored-odoo-taxes' ) ), $tax['saved'] );

		// What the page prints is the same config.
		$this->assertSame( $payment, $this->printed_config( $this->render( 'payment-mappings' ) ) );
	}

	public function test_tax_classes_have_no_unique_rule_and_pricelists_show_their_currency(): void {
		// Django has no unique constraint on the tax class (models.py:247).
		$this->assertSame( array(), Mappings::config( 'tax-mappings' )['unique'] );

		foreach ( array( 'price-mappings', 'order-pricelist-mappings' ) as $card ) {
			$column = array_column( Mappings::config( $card )['columns'], null, 'key' )['odoo_pricelist_id'];
			$this->assertSame( 'currency', $column['source']['suffixKey'], $card );
		}
	}

	public function test_conditional_columns_are_in_the_config(): void {
		$price = array_column( Mappings::config( 'price-mappings' )['columns'], null, 'key' );

		$this->assertSame( array( 'key' => 'source', 'in' => array( 'pricelist' ) ), $price['odoo_pricelist_id']['showWhen'] );
		$this->assertSame( array( 'key' => 'target_field', 'in' => array( 'meta' ) ), $price['target_meta_key']['showWhen'] );

		$stock = array_column( Mappings::config( 'stock-mappings' )['columns'], null, 'key' );
		$this->assertSame( array( 'key' => 'target_field', 'in' => array( 'meta' ) ), $stock['target_meta_key']['showWhen'] );
		$this->assertSame( 'available', $stock['quantity_setting']['default'] );
	}

	public function test_read_only_state_disables_writing_loaders_and_save(): void {
		// A paused owner: reads still work, nothing that writes does.
		$html = $this->render( 'payment-mappings', 'paused' );
		$this->assertStringContainsString( 'data-can-write="0"', $html );
		$this->assertStringContainsString( 'data-can-read="1"', $html );
		$this->assertStringContainsString( ' disabled', $this->button( $html, 'type="submit"' ), 'Save is off.' );
		$this->assertStringContainsString( ' disabled', $this->button( $html, 'data-route="woocommerce-payment-methods"' ), 'Woo payment methods write rows.' );
		$this->assertStringContainsString( ' disabled', $this->button( $html, 'data-route="odoo-journals"' ), 'Odoo journals write rows.' );

		$html = $this->render( 'tax-mappings', 'paused' );
		$this->assertStringContainsString( ' disabled', $this->button( $html, 'data-route="fetch-and-store-odoo-taxes"' ) );
		$this->assertStringNotContainsString( ' disabled', $this->button( $html, 'data-route="woo-tax-classes"' ), 'A pure read stays on.' );

		foreach ( array( 'price-mappings' => 'odoo-pricelists', 'stock-mappings' => 'odoo-stock-locations', 'order-route-mappings' => 'odoo-routes', 'order-pricelist-mappings' => 'odoo-pricelists' ) as $card => $route ) {
			$html = $this->render( $card, 'paused' );
			$this->assertStringNotContainsString( ' disabled', $this->button( $html, 'data-route="' . $route . '"' ), $card . ': a pure read stays on.' );
			$this->assertStringContainsString( ' disabled', $this->button( $html, 'type="submit"' ), $card );
		}

		// Support: read-only too, the same buttons.
		$html = $this->render( 'payment-mappings', 'ready', true );
		$this->assertStringContainsString( 'data-can-write="0"', $html );
		$this->assertStringContainsString( ' disabled', $this->button( $html, 'data-route="odoo-journals"' ) );
		$this->assertStringContainsString( ' disabled', $this->button( $html, 'type="submit"' ) );
		$html = $this->render( 'tax-mappings', 'ready', true );
		$this->assertStringNotContainsString( ' disabled', $this->button( $html, 'data-route="woo-tax-classes"' ) );

		// Getting ready: no Django call at all, so no loader.
		$html = $this->render( 'tax-mappings', 'provisioning' );
		$this->assertStringContainsString( 'data-can-read="0"', $html );
		$this->assertStringContainsString( ' disabled', $this->button( $html, 'data-route="woo-tax-classes"' ) );

		// The owner of a ready license: the writing loaders are on; Save waits for the list (script).
		$html = $this->render( 'payment-mappings' );
		$this->assertStringContainsString( 'data-can-write="1"', $html );
		$this->assertStringNotContainsString( ' disabled', $this->button( $html, 'data-route="odoo-journals"' ) );
		$this->assertStringNotContainsString( ' disabled', $this->button( $html, 'data-route="woocommerce-payment-methods"' ) );
	}

	public function test_markup_has_the_editor_pieces_and_scripts(): void {
		foreach ( array_keys( self::CARDS ) as $card ) {
			$html = $this->render( $card );

			$this->assertStringContainsString( 'data-odoowoo-card="' . $card . '"', $html );
			$this->assertStringContainsString( 'class="odoowoo-mapping"', $html, $card );
			$this->assertStringContainsString( 'data-action="add-row"', $html, $card );
			$this->assertMatchesRegularExpression( '#<button[^>]*type="submit"[^>]*>\s*Save\s*<#', $html, $card );
			$this->assertMatchesRegularExpression( '#role="status"#', $html, $card );
			$this->assertTrue( wp_script_is( 'odoowoo-card-' . $card, 'enqueued' ), $card );
			$this->assertContains( 'odoowoo-mapping-editor', $GLOBALS['wp_scripts']->registered[ 'odoowoo-card-' . $card ]->deps, $card );
			$this->assertTrue( wp_script_is( 'odoowoo-mapping-editor', 'enqueued' ), $card );
		}

		$html = $this->render( 'payment-mappings' );
		$this->assertStringContainsString( 'Load from Woo', $html );
		$this->assertStringContainsString( 'Load from Odoo', $html );
	}

	public function test_registry_strings_are_non_empty(): void {
		foreach ( array_keys( self::CARDS ) as $card ) {
			$strings = Registry::cards()[ $card ]['strings'];

			foreach ( array( 'addRow', 'removeRow', 'save', 'noRows', 'rowRepeats', 'rowError', 'loadedOk', 'loading' ) as $key ) {
				$this->assertArrayHasKey( $key, $strings, $card . ' ' . $key );
				$this->assertIsString( $strings[ $key ] );
				$this->assertNotSame( '', $strings[ $key ], $card . ' ' . $key );
			}
		}
	}
}
