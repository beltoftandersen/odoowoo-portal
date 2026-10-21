<?php
/**
 * Bricks elements, dynamic data tags and the condition.
 *
 * The stub copies the installed theme's signatures (see tests/Support/BricksStub.php); the tests
 * run without Bricks, so every hook below is called through apply_filters() the way the theme
 * does (B = wp-content/themes/bricks/includes/...).
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use BeltoftLicenses\Licenses;
use OdooWooPortal\Api\Routes;
use OdooWooPortal\Bricks\Elements;
use OdooWooPortal\Cards\Registry;
use OdooWooPortal\Cards\Renderer;
use OdooWooPortal\Context;
use OdooWooPortal\Plugin;
use OdooWooPortal\Tabs;

require_once __DIR__ . '/Support/BricksStub.php';

/**
 * The Bricks integration.
 */
final class BricksTest extends RestTestCase {

	/**
	 * Saved $_GET.
	 *
	 * @var array<string, mixed>
	 */
	private $get = array();

	/**
	 * Card name => element name and label (spec section 4 wording).
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const ELEMENTS = array(
		'dashboard-list'           => array( 'odoowoo-dashboard-list', 'OdooWoo: Dashboard list' ),
		'dashboard-header'         => array( 'odoowoo-dashboard-header', 'OdooWoo: Dashboard header' ),
		'woo-connection'           => array( 'odoowoo-woo-connection', 'OdooWoo: Woo connection' ),
		'woo-settings'             => array( 'odoowoo-woo-settings', 'OdooWoo: Woo settings' ),
		'odoo-connection'          => array( 'odoowoo-odoo-connection', 'OdooWoo: Odoo connection' ),
		'odoo-settings'            => array( 'odoowoo-odoo-settings', 'OdooWoo: Odoo settings' ),
		'payment-mappings'         => array( 'odoowoo-payment-mappings', 'OdooWoo: Payment mappings' ),
		'tax-mappings'             => array( 'odoowoo-tax-mappings', 'OdooWoo: Tax mappings' ),
		'price-mappings'           => array( 'odoowoo-price-mappings', 'OdooWoo: Price mappings' ),
		'stock-mappings'           => array( 'odoowoo-stock-mappings', 'OdooWoo: Stock mappings' ),
		'order-route-mappings'     => array( 'odoowoo-order-route-mappings', 'OdooWoo: Order route mappings' ),
		'order-pricelist-mappings' => array( 'odoowoo-order-pricelist-mappings', 'OdooWoo: Order pricelist mappings' ),
		'gift-cards'               => array( 'odoowoo-gift-cards', 'OdooWoo: Gift cards' ),
		'manual-sync'              => array( 'odoowoo-manual-sync', 'OdooWoo: Manual sync' ),
		'sync-logs'                => array( 'odoowoo-sync-logs', 'OdooWoo: Sync logs' ),
	);

	public function set_up(): void {
		parent::set_up();
		$this->get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		Renderer::reset();
		Routes::reset();
		Context::reset();
		\Bricks\Elements::$elements = array();
		\Bricks\Elements::$calls    = array();
		$GLOBALS['wp_scripts']      = new \WP_Scripts();
		$GLOBALS['wp_styles']       = new \WP_Styles();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$_GET = $this->get;
		Renderer::reset();
		Routes::reset();
		Context::reset();
		parent::tear_down();
	}

	/**
	 * An element of a card, built the way Bricks builds it for a frontend render.
	 *
	 * @param string $card     Card name.
	 * @param bool   $frontend Frontend (true) or builder preview (false).
	 * @return \Bricks\Element
	 */
	private function element( string $card, bool $frontend = true ) {
		Elements::register();
		$class   = \Bricks\Elements::$elements[ self::ELEMENTS[ $card ][0] ]['class'];
		$element = new $class(
			array(
				'id'          => 'x1y2z3',
				'is_frontend' => $frontend,
			)
		);
		$element->load();

		return $element;
	}

	/**
	 * What the element prints (frontend.php:777 captures it with ob_start).
	 *
	 * @param \Bricks\Element $element Element.
	 */
	private function printed( $element ): string {
		ob_start();
		$element->init();

		return (string) ob_get_clean();
	}

	/**
	 * Fake Django: a tenant with a limit and used count, and two dashboards.
	 *
	 * @param array<string, mixed> $license License.
	 * @param int|null             $limit   Plan limit.
	 */
	private function fake_plan( array $license, ?int $limit = 3 ): void {
		$this->django_map(
			array(
				'GET tenants/' . $this->tenant( $license ) . '/' => array(
					200,
					array(
						'limit'      => $limit,
						'used'       => 1,
						'attributes' => array(),
					),
				),
				// D DashboardListView: id, name, enabled.
				'GET dashboards/' => array(
					200,
					array(
						array( 'id' => 5, 'name' => 'Shop <b>', 'enabled' => true ),
						array( 'id' => 6, 'name' => 'Old shop', 'enabled' => false ),
					),
				),
			)
		);
	}

	/**
	 * Renders a tag through the theme's single-tag filter (providers.php:912), braced as the theme
	 * hands an unknown tag on (providers.php:772) or bare.
	 *
	 * @param string $tag     Tag.
	 * @param bool   $braced  Braced form.
	 * @param string $context Context.
	 * @return mixed
	 */
	private function tag( string $tag, bool $braced = true, string $context = 'text' ) {
		return apply_filters( 'bricks/dynamic_data/render_tag', $braced ? '{' . $tag . '}' : $tag, null, $context );
	}

	public function test_fifteen_elements_registered_with_spec_names_and_category(): void {
		Elements::register();

		$this->assertSame( array_column( array_values( self::ELEMENTS ), 0 ), array_keys( \Bricks\Elements::$elements ) );

		foreach ( self::ELEMENTS as $card => $expected ) {
			$registered = \Bricks\Elements::$elements[ $expected[0] ];

			$this->assertSame( $expected[0], $registered['name'] );
			$this->assertSame( $expected[1], $registered['label'] );

			$element = new $registered['class']();
			$this->assertSame( 'odoowoo', $element->category, $card );
			$this->assertSame( 'ti-layout-tab', $element->icon, $card );
			$this->assertSame( '.odoowoo-card', $element->css_selector, $card );
		}

		// The panel group title comes from the builder strings (builder.php:1905).
		$this->assertSame( 'OdooWoo', apply_filters( 'bricks/builder/i18n', array() )['odoowoo'] );
	}

	public function test_registration_passes_a_file_only_and_runs_on_init_11(): void {
		Plugin::boot();
		$this->assertSame( 11, has_action( 'init', array( Elements::class, 'register' ) ) );

		Elements::register();

		$this->assertCount( 15, \Bricks\Elements::$calls );

		foreach ( \Bricks\Elements::$calls as $args ) {
			$this->assertCount( 1, $args, 'Only the file.' );
			$this->assertStringStartsWith( ODOOWOO_PORTAL_PATH . 'src/Bricks/elements/', $args[0] );
			$this->assertFileExists( $args[0] );
		}
	}

	public function test_every_style_control_maps_to_an_odoowoo_css_variable(): void {
		$variables = array();
		$bound     = array(
			'typography' => 'font',
			'border'     => 'border',
			'spacing'    => 'padding',
		);

		foreach ( array_keys( self::ELEMENTS ) as $card ) {
			$element = $this->element( $card );

			$this->assertNotSame( array(), $element->controls, $card );

			foreach ( $element->controls as $key => $control ) {
				$this->assertSame( 'style', $control['tab'], "$card $key" );
				$this->assertCount( 1, $control['css'], "$card $key" );

				$rule = $control['css'][0];

				// Defaults live on .odoowoo-card itself, so the rule must reach that element, not the wrapper.
				$this->assertSame( '.odoowoo-card', $rule['selector'], "$card $key" );

				if ( isset( $bound[ $control['type'] ] ) ) {
					$this->assertSame( $bound[ $control['type'] ], $rule['property'], "$card $key" );
					continue;
				}

				$this->assertContains( $control['type'], array( 'color', 'number', 'text' ), "$card $key" );
				$this->assertMatchesRegularExpression( '/^--odoowoo-[a-z]+$/', $rule['property'], "$card $key" );

				if ( 'number' === $control['type'] ) {
					$this->assertTrue( $control['units'], "$card $key" );
				}

				$variables[ $rule['property'] ] = true;
			}

			$this->assertSame( 'OdooWoo card', $element->control_groups['odoowoo']['title'], $card );
		}

		$this->assertSame(
			array( '--odoowoo-accent', '--odoowoo-bg', '--odoowoo-border', '--odoowoo-danger', '--odoowoo-font', '--odoowoo-gap', '--odoowoo-muted', '--odoowoo-radius', '--odoowoo-text' ),
			$this->sorted( array_keys( $variables ) )
		);
	}

	/**
	 * Sorted copy.
	 *
	 * @param string[] $values Values.
	 * @return string[]
	 */
	private function sorted( array $values ): array {
		sort( $values );

		return $values;
	}

	public function test_variables_are_the_ones_the_stylesheet_uses(): void {
		$css = (string) file_get_contents( ODOOWOO_PORTAL_PATH . 'assets/css/odoowoo.css' );
		preg_match_all( '/var\((--odoowoo-[a-z]+)/', $css, $used );
		preg_match_all( '/^\s*(--odoowoo-[a-z]+):/m', $css, $declared );

		$controls = array();

		foreach ( $this->element( 'dashboard-list' )->controls as $control ) {
			if ( 0 === strpos( $control['css'][0]['property'], '--odoowoo-' ) ) {
				$controls[] = $control['css'][0]['property'];
			}
		}

		$this->assertSame( $this->sorted( array_unique( $used[1] ) ), $this->sorted( $controls ), 'Each variable the stylesheet reads has a control.' );
		$this->assertSame( $this->sorted( array_unique( $declared[1] ) ), $this->sorted( $controls ), 'Each has a default in the stylesheet.' );
	}

	public function test_element_render_equals_shortcode_render(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$this->fake_plan( $license );
		$_GET = array( 'license' => (string) $license['id'] );

		foreach ( array_keys( self::ELEMENTS ) as $card ) {
			Renderer::reset();
			// The templates number their ids with wp_unique_id(), which counts up through the request.
			$same  = static fn( string $html ): string => (string) preg_replace( '/(odoowoo-[a-z-]+?)-\d+(?=[-"])/', '$1-N', $html );
			$inner = do_shortcode( '[odoowoo_card name="' . $card . '"]' );

			$this->assertStringContainsString( 'data-odoowoo-card="' . $card . '"', $inner, $card );

			Renderer::reset();
			$html = $this->printed( $this->element( $card ) );

			$this->assertSame( $same( '<div id="brxe-x1y2z3" class="odoowoo-element">' . $inner . '</div>' ), $same( $html ), $card );
		}
	}

	public function test_builder_preview_makes_no_rest_calls_and_has_no_secrets(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$this->fake_plan( $license );
		$_GET = array( 'license' => (string) $license['id'] );

		foreach ( array_keys( self::ELEMENTS ) as $card ) {
			Renderer::reset();
			$html = $this->printed( $this->element( $card, false ) );

			$this->assertStringStartsWith( '<div id="brxe-x1y2z3" class="odoowoo-element"><section class="odoowoo-card" data-odoowoo-card="' . $card . '">', $html, $card );
			$this->assertStringContainsString( esc_html( Registry::cards()[ $card ]['title'] ), $html, $card );
			$this->assertStringNotContainsString( '<script', $html, $card );
			$this->assertStringNotContainsString( 'data-rest', $html, $card );
			$this->assertStringNotContainsString( 'data-license', $html, $card );
			$this->assertStringNotContainsString( (string) $license['license_key'], $html, $card );
			$this->assertStringNotContainsString( $this->tenant( $license ), $html, $card );
			$this->assertStringNotContainsString( 'wp-json', $html, $card );
		}

		$this->assertSame( array(), $this->django_calls(), 'No Django call from the builder.' );
		$this->assertFalse( wp_script_is( 'odoowoo-core', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'odoowoo-card-dashboard-list', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'odoowoo', 'enqueued' ), 'The style is always enqueued.' );

		Renderer::attach_boot_data();
		$this->assertFalse( wp_script_is( 'odoowoo-core', 'enqueued' ) );
		$this->assertStringNotContainsString( 'odoowooPortal', (string) wp_scripts()->get_data( 'odoowoo-core', 'before' ) ?: '' );
	}

	public function test_frontend_render_enqueues_the_style_and_the_cards_script(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$_GET = array( 'license' => (string) $license['id'] );

		$this->printed( $this->element( 'dashboard-list' ) );

		$this->assertTrue( wp_style_is( 'odoowoo', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'odoowoo-card-dashboard-list', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'odoowoo-card-sync-logs', 'enqueued' ) );
	}

	public function test_no_bricks_means_no_registration_and_no_fatal(): void {
		add_filter( 'odoowoo_portal_bricks_available', '__return_false' );

		$this->assertFalse( Elements::available() );

		Elements::register();

		$this->assertSame( array(), \Bricks\Elements::$elements );
		$this->assertSame( array(), \Bricks\Elements::$calls );
		$this->assertArrayNotHasKey( 'odoowoo', apply_filters( 'bricks/builder/i18n', array() ) );

		remove_filter( 'odoowoo_portal_bricks_available', '__return_false' );
		$this->assertTrue( Elements::available() );
	}

	public function test_tags_render_for_owner_and_empty_for_visitor_and_other_license(): void {
		$license = $this->odoowoo_license();
		$other   = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$this->fake_plan( $license );
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => '5',
			'tab'       => 'no-such-tab',
		);

		foreach ( array( true, false ) as $braced ) {
			$this->assertSame( 'Shop &lt;b&gt;', $this->tag( 'odoowoo_dashboard_name', $braced ) );
			$this->assertSame( 'Active', $this->tag( 'odoowoo_dashboard_state', $braced ) );
			$this->assertSame( '3', $this->tag( 'odoowoo_plan_dashboards', $braced ) );
			$this->assertSame( '1', $this->tag( 'odoowoo_plan_used', $braced ) );
			$this->assertSame( '1', $this->tag( 'odoowoo_has_plan', $braced ) );
		}

		// A tab the app does not know falls back to the first one, which is what the state path relies on.
		$this->assertSame( 'connections', Tabs::valid( 'no-such-tab' ) );

		// Link and image contexts get nothing.
		$this->assertSame( '', $this->tag( 'odoowoo_dashboard_name', true, 'link' ) );
		$this->assertSame( '', $this->tag( 'odoowoo_dashboard_name', true, 'image' ) );

		// Tags inside content, both content filters.
		$content = 'Hi {odoowoo_dashboard_name}, {post_title}: {odoowoo_plan_used}/{odoowoo_plan_dashboards} {odoowoo_dashboard_state}';
		$want    = 'Hi Shop &lt;b&gt;, {post_title}: 1/3 Active';

		$this->assertSame( $want, apply_filters( 'bricks/dynamic_data/render_content', $content, null, 'text' ) );
		$this->assertSame( $want, apply_filters( 'bricks/frontend/render_data', $content, null, 'content' ) );

		// The second dashboard is paused.
		$_GET['dashboard'] = '6';
		$this->assertSame( 'Paused', $this->tag( 'odoowoo_dashboard_state' ) );

		// A dashboard that is not in this license's list.
		$_GET['dashboard'] = '99';
		$this->assertSame( '', $this->tag( 'odoowoo_dashboard_name' ) );
		$this->assertSame( '', $this->tag( 'odoowoo_dashboard_state' ) );
		$this->assertSame( 'Hi , x', apply_filters( 'bricks/dynamic_data/render_content', 'Hi {odoowoo_dashboard_name}, x', null, 'text' ) );

		// Someone else's license in the URL.
		$_GET = array(
			'license'   => (string) $other['id'],
			'dashboard' => '5',
		);
		Context::reset();
		Routes::reset();
		$this->assertSame( '', $this->tag( 'odoowoo_dashboard_name' ) );
		$this->assertSame( '', $this->tag( 'odoowoo_plan_used' ) );
		$this->assertSame( '', $this->tag( 'odoowoo_plan_dashboards' ) );

		// A visitor.
		wp_set_current_user( 0 );
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => '5',
		);
		Context::reset();
		Routes::reset();

		foreach ( array( 'odoowoo_dashboard_name', 'odoowoo_dashboard_state', 'odoowoo_plan_dashboards', 'odoowoo_plan_used', 'odoowoo_has_plan' ) as $name ) {
			$this->assertSame( '', $this->tag( $name ), $name );
		}

		$this->assertSame( 'Hi , x', apply_filters( 'bricks/dynamic_data/render_content', 'Hi {odoowoo_dashboard_name}, x', null, 'text' ) );
	}

	public function test_the_page_license_is_the_one_in_the_url_else_the_first(): void {
		$user = self::factory()->user->create();
		$one  = $this->odoowoo_license( array( 'user_id' => $user ) );
		$two  = $this->odoowoo_license( array( 'user_id' => $user ) );

		$this->assertSame( (int) $two['id'], Context::page_license_id( $user, 0 ), 'Newest first, as licenses_for_user lists them.' );
		$this->assertSame( (int) $one['id'], Context::page_license_id( $user, (int) $one['id'] ) );
		$this->assertSame( 999, Context::page_license_id( $user, 999 ), 'A requested id is kept, and Context::for_user then refuses it.' );
		$this->assertSame( 0, Context::page_license_id( self::factory()->user->create(), 0 ) );
		$this->assertSame( 0, Context::page_license_id( 0, 0 ) );
	}

	public function test_tags_use_the_first_license_when_the_url_names_none(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$this->fake_plan( $license );
		$_GET = array();

		$this->assertSame( '3', $this->tag( 'odoowoo_plan_dashboards' ) );
		$this->assertSame( '1', $this->tag( 'odoowoo_plan_used' ) );
		$this->assertSame( '', $this->tag( 'odoowoo_dashboard_name' ), 'No dashboard in the URL, no name.' );
	}

	public function test_plan_tags_are_empty_with_two_licenses_and_no_license_in_the_url(): void {
		$user = self::factory()->user->create();
		$one  = $this->odoowoo_license( array( 'user_id' => $user ) );
		$two  = $this->odoowoo_license( array( 'user_id' => $user ) );
		$this->as_user( $user );
		$this->fake_plan( $two );
		$_GET = array();

		// The list shows both licenses, so one license's numbers in the heading would mislead.
		$this->assertSame( '', $this->tag( 'odoowoo_plan_used' ) );
		$this->assertSame( '', $this->tag( 'odoowoo_plan_dashboards' ) );
		$this->assertSame( '1', $this->tag( 'odoowoo_has_plan' ) );

		// Naming the license brings them back.
		$_GET = array( 'license' => (string) $two['id'] );
		Context::reset();
		Routes::reset();
		$this->assertSame( '3', $this->tag( 'odoowoo_plan_dashboards' ) );
		$this->assertSame( '1', $this->tag( 'odoowoo_plan_used' ) );
		$this->assertNotSame( (int) $one['id'], (int) $two['id'] );
	}

	public function test_tags_leave_other_tags_and_values_alone(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$_GET = array( 'license' => (string) $license['id'] );

		$this->assertSame( array( 'a' => 1 ), apply_filters( 'bricks/dynamic_data/render_tag', array( 'a' => 1 ), null, 'text' ) );
		$this->assertNull( apply_filters( 'bricks/dynamic_data/render_tag', null, null, 'text' ) );
		$this->assertSame( 7, apply_filters( 'bricks/dynamic_data/render_tag', 7, null, 'text' ) );
		$this->assertSame( '{post_title}', apply_filters( 'bricks/dynamic_data/render_tag', '{post_title}', null, 'text' ) );
		$this->assertSame( 'post_title', apply_filters( 'bricks/dynamic_data/render_tag', 'post_title', null, 'image' ) );
		$this->assertSame( '{odoowoo_unknown}', apply_filters( 'bricks/dynamic_data/render_tag', '{odoowoo_unknown}', null, 'text' ) );
		$this->assertSame( 'text {post_title} {odoowoo_unknown}', apply_filters( 'bricks/dynamic_data/render_content', 'text {post_title} {odoowoo_unknown}', null, 'text' ) );
		$this->assertSame( array( 'x' ), apply_filters( 'bricks/frontend/render_data', array( 'x' ), null, 'content' ) );
		$this->assertSame( array(), $this->django_calls(), 'Other tags cost nothing.' );
	}

	public function test_tag_list_has_the_five_tags_in_the_odoowoo_group(): void {
		$list = apply_filters( 'bricks/dynamic_tags_list', array( array( 'name' => '{post_title}', 'label' => 'Post title', 'group' => 'post' ) ) );

		$this->assertSame( '{post_title}', $list[0]['name'], 'Existing entries stay.' );
		$this->assertSame(
			array( '{odoowoo_dashboard_name}', '{odoowoo_dashboard_state}', '{odoowoo_plan_dashboards}', '{odoowoo_plan_used}', '{odoowoo_has_plan}' ),
			array_column( array_slice( $list, 1 ), 'name' )
		);

		foreach ( array_slice( $list, 1 ) as $entry ) {
			$this->assertSame( 'OdooWoo', $entry['group'] );
			$this->assertNotSame( '', $entry['label'] );
		}
	}

	public function test_tags_cost_one_django_read_per_request(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$this->fake_plan( $license );
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => '5',
		);

		$this->tag( 'odoowoo_dashboard_name' );
		$this->tag( 'odoowoo_plan_used' );
		$this->tag( 'odoowoo_plan_dashboards' );

		$calls = array_map( static fn( $call ) => $call['method'] . ' ' . strtok( $call['path_and_query'], '?' ), $this->django_calls() );

		$this->assertCount( 2, $calls );
		$this->assertContains( 'GET /api/tenants/' . $this->tenant( $license ) . '/', $calls );
		$this->assertContains( 'GET /api/dashboards/', $calls );

		$this->tag( 'odoowoo_dashboard_state' );
		apply_filters( 'bricks/dynamic_data/render_content', '{odoowoo_dashboard_name}', null, 'text' );

		$this->assertCount( 2, $this->django_calls(), 'A second round adds none.' );
	}

	public function test_a_failed_read_gives_empty_tags(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$this->fake_django( fn() => array( 500, 'boom' ) );
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => '5',
		);

		$this->assertSame( '', $this->tag( 'odoowoo_dashboard_name' ) );
		$this->assertSame( '', $this->tag( 'odoowoo_dashboard_state' ) );
		$this->assertSame( '', $this->tag( 'odoowoo_plan_used' ) );
		$this->assertSame( '', $this->tag( 'odoowoo_plan_dashboards' ) );
		$this->assertSame( '1', $this->tag( 'odoowoo_has_plan' ), 'The plan itself is WordPress state, not a read.' );
	}

	public function test_odoowoo_mistake_dashboard_name_shown_via_tag(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$this->fake_plan( $license );
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => '5',
		);

		$name = $this->tag( 'odoowoo_dashboard_name' );

		$this->assertSame( 'Shop &lt;b&gt;', $name, 'The name, escaped, not the id.' );
		$this->assertNotSame( '5', $name );
		$this->assertSame( 'Shop &lt;b&gt;', apply_filters( 'bricks/dynamic_data/render_content', '{odoowoo_dashboard_name}', null, 'text' ) );
	}

	public function test_a_dashboard_name_cannot_smuggle_in_another_tag(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$this->django_map(
			array(
				'GET tenants/' . $this->tenant( $license ) . '/' => array( 200, array( 'limit' => 3, 'used' => 1, 'attributes' => array() ) ),
				'GET dashboards/' => array( 200, array( array( 'id' => 5, 'name' => '{echo:phpinfo} {post_title}', 'enabled' => true ) ) ),
			)
		);
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => '5',
		);

		$expected = '&#123;echo:phpinfo&#125; &#123;post_title&#125;';

		$this->assertSame( $expected, $this->tag( 'odoowoo_dashboard_name' ) );
		$this->assertSame( $expected, apply_filters( 'bricks/dynamic_data/render_content', '{odoowoo_dashboard_name}', null, 'text' ) );
	}

	public function test_limit_zero_tag_says_unlimited(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$this->fake_plan( $license, 0 );
		$_GET = array( 'license' => (string) $license['id'] );

		$this->assertSame( 'unlimited', $this->tag( 'odoowoo_plan_dashboards' ) );
		$this->assertSame( '1', $this->tag( 'odoowoo_plan_used' ) );
	}

	/**
	 * A user with one license in the given state (or of another product).
	 *
	 * @param array<string, mixed> $data Extra license columns.
	 * @param string               $status Status to set, '' to leave it.
	 */
	private function user_with( array $data = array(), string $status = '' ): int {
		$license = $this->odoowoo_license( $data );

		if ( '' !== $status ) {
			Licenses::update( (int) $license['id'], array( 'status' => $status ) );
		}

		Context::reset();

		return (int) $license['user_id'];
	}

	/**
	 * What the condition filter says for the current user.
	 *
	 * @param string $compare '==' or '!='.
	 * @param mixed  $value   Condition value.
	 * @param bool   $before  Value so far.
	 */
	private function condition( string $compare, $value, bool $before = false ): bool {
		return apply_filters(
			'bricks/conditions/result',
			$before,
			'odoowoo_has_plan',
			array(
				'key'     => 'odoowoo_has_plan',
				'compare' => $compare,
				'value'   => $value,
			)
		);
	}

	public function test_condition_true_for_any_usable_odoowoo_license(): void {
		$cases = array(
			'active'        => array( $this->user_with(), true ),
			'suspended'     => array( $this->user_with( array(), 'suspended' ), true ),
			'expired'       => array( $this->user_with( array(), 'expired' ), true ),
			'revoked'       => array( $this->user_with( array(), 'revoked' ), false ),
			'other product' => array( $this->user_with( array( 'product_slug' => 'some-plugin' ) ), false ),
			'logged out'    => array( 0, false ),
		);

		foreach ( $cases as $label => $case ) {
			wp_set_current_user( $case[0] );

			// "Has a plan" (value 1).
			$this->assertSame( $case[1], $this->condition( '==', '1' ), "$label == 1" );
			$this->assertSame( $case[1], $this->condition( '==', 1 ), "$label == 1 (int)" );
			// "is not" a plan holder.
			$this->assertSame( ! $case[1], $this->condition( '!=', '1' ), "$label != 1" );
			// "Has no plan" (value 0).
			$this->assertSame( ! $case[1], $this->condition( '==', '0' ), "$label == 0" );
			$this->assertSame( $case[1], $this->condition( '!=', '0' ), "$label != 0" );
			// The value so far is replaced, not combined: the theme knows nothing of this key.
			$this->assertSame( $case[1], $this->condition( '==', '1', ! $case[1] ), "$label ignores the value so far" );
		}
	}

	public function test_other_condition_keys_pass_through_the_result_filter(): void {
		$user = $this->user_with();
		wp_set_current_user( $user );

		foreach ( array( true, false ) as $before ) {
			$this->assertSame( $before, apply_filters( 'bricks/conditions/result', $before, 'user_logged_in', array( 'key' => 'user_logged_in', 'compare' => '==', 'value' => '0' ) ) );
			$this->assertSame( $before, apply_filters( 'bricks/conditions/result', $before, 'odoowoo_has_planx', array( 'key' => 'odoowoo_has_planx', 'compare' => '==', 'value' => '1' ) ) );
		}
	}

	public function test_condition_is_in_the_picker_and_has_a_group(): void {
		$groups = apply_filters( 'bricks/conditions/groups', array( array( 'name' => 'other', 'label' => 'Other' ) ) );

		$this->assertSame( 'other', $groups[0]['name'] );
		$this->assertContains( 'odoowoo', array_column( $groups, 'name' ) );

		$options = apply_filters( 'bricks/conditions/options', array( array( 'key' => 'user_logged_in' ) ) );
		$entries = array_values( array_filter( $options, static fn( $o ) => 'odoowoo_has_plan' === ( $o['key'] ?? '' ) ) );

		$this->assertSame( 'user_logged_in', $options[0]['key'], 'Existing options stay.' );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'odoowoo', $entries[0]['group'] );
		$this->assertSame( 'Has an OdooWoo plan', $entries[0]['label'] );
		$this->assertSame( 'select', $entries[0]['compare']['type'] );
		$this->assertSame( array( '==', '!=' ), array_keys( $entries[0]['compare']['options'] ) );
		$this->assertSame( 'select', $entries[0]['value']['type'] );
		$this->assertSame( array( 1, 0 ), array_keys( $entries[0]['value']['options'] ) );
		$this->assertSame( 'Has a plan', $entries[0]['value']['options'][1] );
		$this->assertSame( 'Has no plan', $entries[0]['value']['options'][0] );
	}

	public function test_condition_key_is_allowed_by_the_mcp_writer_filter(): void {
		// B includes/abilities/element-conditions.php:572.
		$keys = apply_filters( 'bricks/abilities/element_conditions/allowed_keys', array( 'user_logged_in', 'user_id' ) );

		$this->assertSame( array( 'user_logged_in', 'user_id', 'odoowoo_has_plan' ), $keys );
		$this->assertSame( $keys, apply_filters( 'bricks/abilities/element_conditions/allowed_keys', $keys ), 'Not added twice.' );
	}
}
