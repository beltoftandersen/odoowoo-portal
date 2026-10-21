<?php
/**
 * Registry, renderer, shortcode and the template_redirect hook.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use BeltoftLicenses\Events;
use OdooWooPortal\Cards\Registry;
use OdooWooPortal\Cards\Renderer;
use OdooWooPortal\Context;
use OdooWooPortal\Support;
use OdooWooPortal\Tests\Support\CardProbe;

require_once __DIR__ . '/Support/CardProbe.php';

/**
 * The card frame: what a page gets, for whom, and which scripts come with it.
 */
final class CardsTest extends TestCase {

	/**
	 * Saved $_GET.
	 *
	 * @var array<string, mixed>
	 */
	private $get = array();

	public function set_up(): void {
		parent::set_up();
		$this->get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		Context::reset();
		Renderer::reset();
		$GLOBALS['wp_scripts'] = new \WP_Scripts();
		$GLOBALS['wp_styles']  = new \WP_Styles();
		wp_set_current_user( 0 );

		// The canonical redirect and friends are not what these tests are about.
		remove_action( 'template_redirect', 'redirect_canonical' );
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
		remove_action( 'template_redirect', 'wp_old_slug_redirect' );
	}

	public function tear_down(): void {
		$_GET = $this->get;
		Renderer::reset();
		Context::reset();
		remove_all_filters( 'odoowoo_portal_cards' );
		parent::tear_down();
	}

	/**
	 * What wp_footer does for the portal: its boot-data hook (the rest of wp_footer belongs to the theme).
	 */
	private function footer(): void {
		$this->assertSame( 1, has_action( 'wp_footer', array( Renderer::class, 'attach_boot_data' ) ), 'Hooked early on wp_footer.' );
		Renderer::attach_boot_data();
	}

	public function test_registry_has_the_fifteen_cards_with_names_keys_and_titles(): void {
		$names = array( 'dashboard-list', 'dashboard-header', 'woo-connection', 'woo-settings', 'odoo-connection', 'odoo-settings', 'payment-mappings', 'tax-mappings', 'price-mappings', 'stock-mappings', 'order-route-mappings', 'order-pricelist-mappings', 'gift-cards', 'manual-sync', 'sync-logs' );
		$cards = Registry::cards();

		$this->assertSame( $names, array_keys( $cards ) );

		foreach ( $cards as $name => $card ) {
			$this->assertSame( array( 'title', 'template', 'script', 'keys', 'strings' ), array_keys( $card ), $name );
			$this->assertIsString( $card['title'] );
			$this->assertNotSame( '', $card['title'], $name );
			$this->assertIsArray( $card['keys'], $name );
			$this->assertIsArray( $card['strings'], $name );
			$this->assertTrue( Registry::has( $name ) );
		}

		$this->assertFalse( Registry::has( 'zzz' ) );
		$this->assertFalse( Registry::has( '' ) );
	}

	public function test_scripts_load_only_where_the_card_is(): void {
		CardProbe::register();
		$license = $this->odoowoo_license();

		// A page without cards: registered, never enqueued.
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_script_is( 'odoowoo-core', 'registered' ) );
		$this->assertFalse( wp_script_is( 'odoowoo-core', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'odoowoo', 'enqueued' ) );

		// A card for a visitor who is logged out: nothing is enqueued either.
		Renderer::render( CardProbe::NAME );
		$this->assertFalse( wp_script_is( 'odoowoo-core', 'enqueued' ) );

		// A card for the licensed user: core, tasks, the card's own script and the style.
		$this->as_user( (int) $license['user_id'] );
		$_GET = array( 'license' => (string) $license['id'] );
		Renderer::render( CardProbe::NAME );
		$this->assertTrue( wp_script_is( 'odoowoo-core', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'odoowoo-tasks', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'odoowoo-card-' . CardProbe::NAME, 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'odoowoo', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'odoowoo-card-woo-connection', 'enqueued' ), 'Another card\'s script stays off the page.' );
	}

	public function test_shortcode_renders_same_markup_as_renderer(): void {
		CardProbe::register();
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$_GET = array( 'license' => (string) $license['id'] );

		$direct = Renderer::render( CardProbe::NAME );
		$code   = do_shortcode( '[odoowoo_card name="' . CardProbe::NAME . '"]' );

		$this->assertNotSame( '', $direct );
		$this->assertStringContainsString( 'Probe body', $direct );
		$this->assertSame( $direct, $code );
	}

	public function test_unknown_shortcode_name_renders_nothing(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );

		$this->assertSame( '', do_shortcode( '[odoowoo_card name="zzz"]' ) );
		$this->assertSame( '', do_shortcode( '[odoowoo_card]' ) );
		$this->assertSame( '', do_shortcode( '[odoowoo_card name="<script>"]' ) );
		$this->assertSame( '', Renderer::render( 'zzz' ) );
	}

	public function test_odoowoo_mistake_unknown_tab_falls_back_to_first(): void {
		CardProbe::register();
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );

		foreach ( array( 'zzz', '<script>alert(1)</script>', '' ) as $tab ) {
			$_GET = array(
				'license' => (string) $license['id'],
				'tab'     => $tab,
			);
			$this->assertSame( 'connections', Renderer::context_ids()['tab'], $tab );
			$html = Renderer::render( CardProbe::NAME );
			$this->assertStringNotContainsString( '<script>alert', $html );
			$this->assertStringContainsString( 'data-tab="connections"', $html );
		}

		$_GET = array(
			'license' => (string) $license['id'],
			'tab'     => 'mappings',
		);
		$this->assertSame( 'mappings', Renderer::context_ids()['tab'] );
		$this->assertStringContainsString( 'data-tab="mappings"', Renderer::render( CardProbe::NAME ) );
	}

	public function test_context_ids_are_absint_and_display_only(): void {
		$_GET = array(
			'license'   => '-7abc',
			'dashboard' => '12x',
			'tab'       => 'sync',
		);
		$this->assertSame(
			array(
				'license'   => 7,
				'dashboard' => 12,
				'tab'       => 'sync',
			),
			Renderer::context_ids()
		);

		$_GET = array(
			'license'   => array( '1' ),
			'dashboard' => array( '2' ),
			'tab'       => array( 'sync' ),
		);
		$this->assertSame(
			array(
				'license'   => 0,
				'dashboard' => 0,
				'tab'       => 'connections',
			),
			Renderer::context_ids()
		);
	}

	public function test_root_carries_ids_urls_and_rest_root_for_a_licensed_viewer(): void {
		CardProbe::register();
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => '9',
		);

		$html = Renderer::render( CardProbe::NAME );

		$this->assertStringContainsString( 'data-odoowoo-card="probe"', $html );
		$this->assertStringContainsString( 'data-license="' . $license['id'] . '"', $html );
		$this->assertStringContainsString( 'data-dashboard="9"', $html );
		$this->assertStringContainsString( 'data-connections-url="' . esc_attr( \OdooWooPortal\Urls::dashboard( (int) $license['id'], 9, 'connections' ) ) . '"', $html );
		$this->assertStringContainsString( 'data-rest="' . esc_attr( esc_url_raw( rest_url( 'odoowoo-portal/v1/' ) ) ) . '"', $html );
		$this->assertStringContainsString( 'Probe card', $html );
	}

	public function test_a_license_of_another_user_shows_not_found_and_no_rest_root(): void {
		CardProbe::register();
		$mine  = $this->odoowoo_license();
		$other = $this->odoowoo_license();
		$this->as_user( (int) $mine['user_id'] );
		$_GET = array( 'license' => (string) $other['id'] );

		$html = Renderer::render( CardProbe::NAME );

		$this->assertStringNotContainsString( 'Probe body', $html );
		$this->assertStringNotContainsString( 'data-rest', $html );
		$this->assertStringNotContainsString( 'data-license="' . $other['id'] . '"', $html );
		$this->assertStringContainsString( 'This license was not found.', $html );
	}

	public function test_no_license_renders_upsell_notice_without_data(): void {
		CardProbe::register();
		$user = self::factory()->user->create();
		$this->as_user( $user );

		$html = Renderer::render( CardProbe::NAME );

		$this->assertStringContainsString( 'You do not have an OdooWoo plan yet.', $html );
		$this->assertStringContainsString( esc_url( wc_get_page_permalink( 'shop' ) ), $html, 'No licensed product: the shop is the fallback link.' );
		$this->assertStringNotContainsString( 'Probe body', $html );
		$this->assertStringNotContainsString( 'data-rest', $html );
		$this->assertStringNotContainsString( 'data-license', $html );
		$this->assertStringNotContainsString( rest_url( 'odoowoo-portal/v1/' ), $html );
		$this->assertStringNotContainsString( wp_create_nonce( 'wp_rest' ), $html );
		$this->assertFalse( wp_script_is( 'odoowoo-core', 'enqueued' ) );
	}

	public function test_upsell_links_to_the_licensed_product_when_there_is_one(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'OdooWoo' );
		$product->set_status( 'publish' );
		$product->save();
		update_post_meta( $product->get_id(), '_beltoft_licenses_software_slug', 'odoowoo' );
		wp_cache_flush();
		$this->as_user( self::factory()->user->create() );

		$this->assertSame( (int) $product->get_id(), \BeltoftLicenses\Software::product_id( 'odoowoo' ) );
		$this->assertStringContainsString( esc_url( get_permalink( $product->get_id() ) ), Renderer::render( 'woo-connection' ) );
	}

	public function test_logged_out_renders_login_notice_without_nonce_or_rest_root(): void {
		CardProbe::register();
		$this->odoowoo_license();
		wp_set_current_user( 0 );

		$html = Renderer::render( CardProbe::NAME );

		$this->assertStringContainsString( 'Please log in to use OdooWoo.', $html );
		$this->assertStringContainsString( esc_url( wp_login_url( (string) get_permalink() ) ), $html );
		$this->assertStringNotContainsString( 'Probe body', $html );
		$this->assertStringNotContainsString( 'data-rest', $html );
		$this->assertStringNotContainsString( 'data-license', $html );
		$this->assertStringNotContainsString( 'rest-nonce', $html );
		$this->assertStringNotContainsString( 'odoowoo-portal/v1', $html );
		$this->assertStringNotContainsString( wp_create_nonce( 'wp_rest' ), $html );

		// The boot data never gets attached for them either.
		$this->footer();
		$this->assertFalse( wp_script_is( 'odoowoo-core', 'enqueued' ) );
		$this->assertFalse( (bool) wp_scripts()->get_data( 'odoowoo-core', 'before' ) );
	}

	public function test_boot_data_has_rest_root_nonce_and_nonce_url(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );

		$data = Renderer::boot_data();

		$this->assertSame( esc_url_raw( rest_url( 'odoowoo-portal/v1/' ) ), $data['rest'] );
		$this->assertNotFalse( wp_verify_nonce( $data['nonce'], 'wp_rest' ) );
		$this->assertStringEndsWith( 'action=rest-nonce', $data['nonceUrl'] );
		$this->assertStringStartsWith( esc_url_raw( admin_url( 'admin-ajax.php' ) ), $data['nonceUrl'] );
		$this->assertIsArray( $data['i18n'] );
		$this->assertSame( \OdooWooPortal\Django\Messages::generic(), $data['i18n']['generic'] );
		$this->assertSame( 'Please log in again. What you typed is still here.', $data['i18n']['session'] );
	}

	public function test_boot_data_is_printed_once_with_the_rendered_cards_strings(): void {
		CardProbe::register();
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$_GET = array( 'license' => (string) $license['id'] );

		Renderer::render( CardProbe::NAME );
		Renderer::render( CardProbe::NAME );
		$this->footer();
		$this->footer();

		$before = wp_scripts()->get_data( 'odoowoo-core', 'before' );
		$js     = is_array( $before ) ? implode( "\n", array_filter( $before ) ) : '';

		$this->assertSame( 1, substr_count( $js, 'window.odoowooPortal =' ) );
		$this->assertStringContainsString( 'Hello from the probe', $js );
		$this->assertStringContainsString( '"nonceUrl"', $js );
	}

	public function test_support_arrival_on_template_redirect_grants_session_and_logs_once(): void {
		$license = $this->odoowoo_license();
		$support = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->as_user( $support );

		parse_str( (string) wp_parse_url( Support::url( (int) $license['id'] ), PHP_URL_QUERY ), $query );
		$_GET = $query;

		$this->assertNotFalse( has_action( 'template_redirect', array( \OdooWooPortal\Plugin::class, 'template_redirect' ) ) );
		$this->assertFalse( Support::has_session( $support, (int) $license['id'] ) );
		do_action( 'template_redirect' );

		$this->assertTrue( Support::has_session( $support, (int) $license['id'] ) );
		$visits = array_filter( Events::for_license( (int) $license['id'] ), static fn( $e ) => 'support_view' === $e['type'] );
		$this->assertCount( 1, $visits );
	}

	public function test_template_redirect_without_the_flag_or_login_does_nothing(): void {
		$license = $this->odoowoo_license();
		$support = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->as_user( $support );
		$_GET = array( 'license' => (string) $license['id'] );
		do_action( 'template_redirect' );
		$this->assertFalse( Support::has_session( $support, (int) $license['id'] ) );

		parse_str( (string) wp_parse_url( Support::url( (int) $license['id'] ), PHP_URL_QUERY ), $query );
		$_GET = $query;
		wp_set_current_user( 0 );
		do_action( 'template_redirect' );
		$this->assertFalse( Support::has_session( $support, (int) $license['id'] ) );
		$this->assertSame( array(), array_filter( Events::for_license( (int) $license['id'] ), static fn( $e ) => 'support_view' === $e['type'] ) );
	}

	public function test_support_viewer_sees_banner(): void {
		CardProbe::register();
		$license = $this->odoowoo_license();
		$support = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->as_user( $support );
		Support::grant( $support, (int) $license['id'] );
		$_GET = array( 'license' => (string) $license['id'] );

		$html = Renderer::render( CardProbe::NAME );

		$this->assertSame( 'support', Context::for_user( $support, (int) $license['id'] )->mode() );
		$this->assertSame( 1, substr_count( $html, 'Viewing as support (read-only)' ), 'Once, not twice.' );
		$this->assertStringContainsString( 'Probe body', $html );
	}

	public function test_paused_license_shows_its_notice_next_to_the_card(): void {
		CardProbe::register();
		$license = $this->odoowoo_license( array(), 'paused' );
		$this->as_user( (int) $license['user_id'] );
		$_GET = array( 'license' => (string) $license['id'] );

		$html = Renderer::render( CardProbe::NAME );

		$this->assertStringContainsString( 'Your OdooWoo plan is paused.', $html );
		$this->assertStringContainsString( 'Probe body', $html );
	}
}
