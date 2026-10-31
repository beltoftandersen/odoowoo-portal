<?php
/**
 * The OdooWoo app inside My Account: addresses, slugs, the page body, redirects, assets.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Apps;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Resource;
use OdooWooPortal\Account\Area;
use OdooWooPortal\Account\Dashboards;
use OdooWooPortal\Account\OpenLink;
use OdooWooPortal\Api\Routes;
use OdooWooPortal\Cards\Renderer;
use OdooWooPortal\Context;
use OdooWooPortal\Support;
use OdooWooPortal\Tabs;
use OdooWooPortal\Urls;

/**
 * Pages below /my-account/apps/<service>/. Django is always the fake.
 */
final class AccountAreaTest extends RestTestCase {

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
		Renderer::use_ids( null );
		$GLOBALS['wp_scripts'] = new \WP_Scripts();
		$GLOBALS['wp_styles']  = new \WP_Styles();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$_GET = $this->get;
		unset( $GLOBALS['wp']->query_vars['apps'] );
		Renderer::reset();
		Renderer::use_ids( null );
		Context::reset();
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * An OdooWoo license that the Apps area of beltoft-services lists: its product uses the http driver.
	 *
	 * @param array<string, mixed> $data License columns.
	 * @return array<string, mixed>
	 */
	private function app_license( array $data = array() ): array {
		$product = new \WC_Product_Simple();
		$product->set_name( 'OdooWoo' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'odoowoo' );
		$product->update_meta_data( \BeltoftServices\Product::META_DRIVER, 'http' );
		$product->save();

		Context::reset();

		return $this->odoowoo_license( $data + array( 'product_id' => $product->get_id() ) );
	}

	/**
	 * Fake Django: this tenant's dashboards.
	 *
	 * @param array<string, mixed>             $license    License.
	 * @param array<int, array<string, mixed>> $dashboards Rows (id, name).
	 */
	private function django_with( array $license, array $dashboards ): void {
		$rows = array_map(
			static fn( array $d ): array => $d + array( 'enabled' => true, 'created_at' => '2026-10-21T12:00:00Z' ),
			$dashboards
		);

		$this->django_map(
			array(
				'GET tenants/' . $this->tenant( $license ) . '/' => array( 200, array( 'limit' => 5, 'used' => count( $rows ), 'attributes' => array() ) ),
				'GET dashboards/' => array( 200, $rows ),
			)
		);
	}

	/**
	 * The area's page for a path, as beltoft-services hands it to the listeners.
	 *
	 * @param array<string, mixed> $license License.
	 * @param array<int, string>   $path    Parts after the service.
	 * @return array<string, mixed>
	 */
	private function page( array $license, array $path = array() ): array {
		$area = ServiceArea::get( Apps::class );

		return array(
			'license'  => $license,
			'tab'      => '',
			'resource' => Resource::for_license( (int) $license['id'] ),
			'area'     => $area,
			'path'     => $path,
			'base'     => $area->service_url( $license ),
		);
	}

	/**
	 * What the body hook prints for a path.
	 *
	 * @param array<string, mixed> $license License.
	 * @param array<int, string>   $path    Parts after the service.
	 */
	private function body( array $license, array $path = array() ): string {
		Renderer::reset();
		ob_start();
		$handled = apply_filters( 'beltoft_services_account_page_body', false, $this->page( $license, $path ) );
		$html    = (string) ob_get_clean();
		$this->assertTrue( $handled, 'The portal drew the page.' );

		return $html;
	}

	// -- Addresses ---------------------------------------------------------------------------

	public function test_the_app_lives_in_my_account_only_for_the_owner_of_a_license_the_apps_area_lists(): void {
		$license = $this->app_license();
		$plain   = $this->odoowoo_license();
		Context::reset();

		$this->as_user( (int) $license['user_id'] );
		$this->assertTrue( Area::enabled( (int) $license['id'] ) );
		$this->assertFalse( Area::enabled( (int) $plain['id'] ), 'Not a license of the Apps area (no http product): the old pages stay.' );
		$this->assertFalse( Area::enabled( 0 ) );

		$this->as_user( self::factory()->user->create() );
		$this->assertFalse( Area::enabled( (int) $license['id'] ), 'Someone else.' );

		wp_set_current_user( 0 );
		$this->assertFalse( Area::enabled( (int) $license['id'] ), 'Logged out.' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( 'manage_woocommerce' );
		Support::grant( $admin, (int) $license['id'] );
		Context::reset();
		$this->as_user( $admin );
		$this->assertFalse( Area::enabled( (int) $license['id'] ), 'A support viewer keeps the old pages.' );
	}

	public function test_urls_point_into_my_account_and_fall_back_to_the_old_pages(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'odoowoo' ) );
		$this->settings( array( 'list_page_id' => $page, 'dashboard_page_id' => $page ) );

		$license = $this->app_license();
		$id      = (int) $license['id'];
		$plain   = $this->odoowoo_license();

		// Nobody logged in: the old builder.
		$this->assertSame( get_permalink( $page ) . '?license=' . $id, Urls::license_list( $id ) );

		$this->as_user( (int) $license['user_id'] );
		$base = ServiceArea::url_for_license( $license );
		$this->assertStringEndsWith( '/apps/odoowoo/', $base );
		$this->assertSame( $base, Urls::license_list( $id ) );
		$this->assertSame( $base . '9/', Urls::dashboard( $id, 9 ), 'No slug yet: the number, which the area sends on.' );
		$this->assertSame( $base . '9/mappings/', Urls::dashboard( $id, 9, 'mappings' ) );
		$this->assertSame( $base . '9/', Urls::dashboard( $id, 9, 'nonsense' ), 'An unknown tab is the first.' );
		$this->assertSame( $base . '{dashboard}/', Area::dashboard_template( $id ) );

		// Dashboard 0 is the template of the old page.
		$this->assertStringContainsString( 'dashboard=0', Urls::dashboard( $id, 0 ) );

		// A license the area does not list.
		$this->assertSame( get_permalink( $page ) . '?license=' . $plain['id'], Urls::license_list( (int) $plain['id'] ) );
	}

	// -- Slugs -------------------------------------------------------------------------------

	public function test_slugs_come_from_the_name_are_unique_in_the_tenant_and_never_change(): void {
		$license = $this->app_license();
		$this->as_user( (int) $license['user_id'] );
		$ctx = Context::for_user( (int) $license['user_id'], (int) $license['id'] );

		$map = Dashboards::sync(
			$ctx,
			array(
				array( 'id' => 12, 'name' => 'My Shop' ),
				array( 'id' => 11, 'name' => 'My shop' ),
				array( 'id' => 13, 'name' => 'Ünïcode & <b>Store</b>' ),
				array( 'id' => 14, 'name' => '2024' ),
				array( 'id' => 15, 'name' => '   ' ),
				array( 'id' => 16, 'name' => 'My_Shop' ),
			)
		);

		$this->assertSame( 'my-shop', $map[11], 'The oldest keeps the plain one.' );
		$this->assertSame( 'my-shop-2', $map[12] );
		$this->assertSame( 'my-shop-3', $map[16] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $map[13] );
		$this->assertArrayNotHasKey( 14, $map, 'Digits only would read as an id.' );
		$this->assertArrayNotHasKey( 15, $map, 'No usable name: the number.' );
		$this->assertSame( $map, Dashboards::stored( (int) $license['id'] ) );
		$this->assertSame( 11, Dashboards::id_for( (int) $license['id'], 'my-shop' ) );
		$this->assertSame( 11, Dashboards::id_for( (int) $license['id'], 'MY-SHOP' ) );
		$this->assertSame( 0, Dashboards::id_for( (int) $license['id'], 'nope' ) );

		// A rename keeps the address; a new dashboard with the taken name gets the next number.
		$again = Dashboards::sync( $ctx, array( array( 'id' => 11, 'name' => 'Renamed' ), array( 'id' => 17, 'name' => 'My shop' ), array( 'id' => 12, 'name' => 'x' ), array( 'id' => 16, 'name' => 'x' ), array( 'id' => 13, 'name' => 'x' ) ) );
		$this->assertSame( 'my-shop', $again[11] );
		$this->assertSame( 'my-shop-4', $again[17] );
		$this->assertArrayNotHasKey( 14, $again );

		// Gone from Django: the slug is forgotten, and a deletion through the portal frees it at once.
		$left = Dashboards::sync( $ctx, array( array( 'id' => 11, 'name' => 'Renamed' ) ) );
		$this->assertSame( array( 11 => 'my-shop' ), $left );
		do_action( 'odoowoo_portal_dashboard_deleted', (int) $license['id'], 11 );
		$this->assertSame( array(), Dashboards::stored( (int) $license['id'] ) );
	}

	public function test_a_support_viewer_never_writes_slugs(): void {
		$license = $this->app_license();
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( 'manage_woocommerce' );
		Support::grant( $admin, (int) $license['id'] );
		Context::reset();
		$ctx = Context::for_user( $admin, (int) $license['id'] );

		$this->assertSame( 'support', $ctx->mode() );
		$this->assertSame( array(), Dashboards::sync( $ctx, array( array( 'id' => 1, 'name' => 'Shop' ) ) ) );
		$this->assertSame( array(), Dashboards::stored( (int) $license['id'] ) );
	}

	public function test_the_summary_gives_each_dashboard_its_address_to_the_owner_only(): void {
		$license = $this->app_license();
		$this->django_with( $license, array( array( 'id' => 5, 'name' => 'Shop' ) ) );
		$this->as_user( (int) $license['user_id'] );
		Routes::reset();

		$rows = Routes::license_summary( Context::for_user( (int) $license['user_id'], (int) $license['id'] ) )['dashboards'];

		$this->assertSame( ServiceArea::url_for_license( $license, array( 'shop' ) ), $rows[0]['url'] );
		$this->assertSame( array( 5 => 'shop' ), Dashboards::stored( (int) $license['id'] ) );
	}

	public function test_the_summary_for_a_site_without_the_area_has_no_addresses(): void {
		$license = $this->odoowoo_license();
		$this->django_with( $license, array( array( 'id' => 5, 'name' => 'Shop' ) ) );
		$this->as_user( (int) $license['user_id'] );
		Routes::reset();

		$rows = Routes::license_summary( Context::for_user( (int) $license['user_id'], (int) $license['id'] ) )['dashboards'];

		$this->assertArrayNotHasKey( 'url', $rows[0] );
		$this->assertArrayNotHasKey( 'slug', $rows[0] );
	}

	// -- The page body -----------------------------------------------------------------------

	public function test_the_service_page_is_the_dashboard_list_for_this_license(): void {
		$license = $this->app_license();
		$this->django_with( $license, array() );
		$this->as_user( (int) $license['user_id'] );

		$html = $this->body( $license );

		$this->assertStringContainsString( 'data-odoowoo-card="dashboard-list"', $html );
		$this->assertStringContainsString( 'data-license="' . $license['id'] . '"', $html );
		$this->assertStringContainsString( 'data-only-license="1"', $html );
		$this->assertStringContainsString( 'data-dashboard-url="' . esc_attr( Area::dashboard_template( (int) $license['id'] ) ) . '"', $html );
		$this->assertTrue( wp_script_is( 'odoowoo-card-dashboard-list', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'odoowoo', 'enqueued' ) );
		$this->assertSame( array(), $this->django_calls(), 'The list loads over REST.' );
	}

	public function test_a_dashboard_page_has_the_header_the_tabs_and_the_cards_of_its_tab(): void {
		$license = $this->app_license();
		$this->django_with( $license, array( array( 'id' => 5, 'name' => 'Shop' ), array( 'id' => 6, 'name' => 'Outlet' ) ) );
		$this->as_user( (int) $license['user_id'] );
		$base = ServiceArea::url_for_license( $license );

		$html = $this->body( $license, array( 'outlet' ) );

		$this->assertStringContainsString( 'data-odoowoo-card="dashboard-header"', $html );
		$this->assertStringContainsString( 'data-dashboard="6"', $html, 'The slug stands for the number the cards and the REST routes use.' );
		$this->assertStringContainsString( 'data-license="' . $license['id'] . '"', $html );
		$this->assertStringContainsString( 'data-odoowoo-card="woo-connection"', $html );
		$this->assertStringContainsString( 'data-odoowoo-card="odoo-connection"', $html );
		$this->assertStringNotContainsString( 'data-odoowoo-card="gift-cards"', $html );
		$this->assertStringContainsString( '<nav class="beltoft-services-tabs"', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		$this->assertStringContainsString( 'href="' . esc_url( $base . 'outlet/' ) . '" aria-current="page">Connections', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $base . 'outlet/mappings/' ) . '">Mappings', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $base ) . '"', $html, 'The header links back to the list.' );
		$this->assertTrue( wp_script_is( 'odoowoo-card-woo-connection', 'enqueued' ) );

		// Other tabs.
		$mappings = $this->body( $license, array( 'outlet', 'mappings' ) );
		foreach ( \OdooWooPortal\Cards\Mappings::CARDS as $card ) {
			$this->assertStringContainsString( 'data-odoowoo-card="' . $card . '"', $mappings, $card );
		}
		$this->assertStringContainsString( 'data-tab="mappings"', $mappings );
		$this->assertStringContainsString( 'href="' . esc_url( $base . 'outlet/mappings/' ) . '" aria-current="page">Mappings', $mappings );
		$this->assertStringContainsString( 'data-odoowoo-card="gift-cards"', $this->body( $license, array( 'outlet', 'gift-cards' ) ) );
		$this->assertStringContainsString( 'data-odoowoo-card="manual-sync"', $this->body( $license, array( 'outlet', 'sync' ) ) );
		$this->assertStringContainsString( 'data-odoowoo-card="sync-logs"', $this->body( $license, array( 'outlet', 'logs' ) ) );

		// An unknown tab is the first; a number is a dashboard too.
		$this->assertStringContainsString( 'data-odoowoo-card="woo-connection"', $this->body( $license, array( 'outlet', 'zzz' ) ) );
		$this->assertStringContainsString( 'data-dashboard="5"', $this->body( $license, array( '5' ) ) );

		// What the page asked for does not leak into the next request.
		$_GET = array();
		$this->assertSame( 0, Renderer::context_ids()['dashboard'] );
	}

	public function test_the_tab_cards_can_be_changed_with_a_filter(): void {
		add_filter( 'odoowoo_portal_account_tab_cards', static fn( $cards ) => array( 'connections' => array( 'woo-connection', 7 ) ) + $cards );

		$this->assertSame( array( 'woo-connection' ), Tabs::cards( 'connections' ) );
		$this->assertSame( array( 'gift-cards' ), Tabs::cards( 'gift-cards' ) );
		$this->assertSame( array( 'woo-connection' ), Tabs::cards( 'zzz' ), 'An unknown tab is the first.' );

		remove_all_filters( 'odoowoo_portal_account_tab_cards' );
	}

	public function test_an_unknown_dashboard_and_another_customers_dashboard_get_the_same_not_found(): void {
		$mine   = $this->app_license();
		$theirs = $this->app_license();

		// Mine has dashboard 5; the other tenant's dashboard is 99, named like mine would not be.
		$this->django_with( $mine, array( array( 'id' => 5, 'name' => 'Shop' ) ) );
		$this->as_user( (int) $mine['user_id'] );

		wp_set_current_user( (int) $theirs['user_id'] );
		Context::reset();
		$other_ctx = Context::for_user( (int) $theirs['user_id'], (int) $theirs['id'] );
		Dashboards::sync( $other_ctx, array( array( 'id' => 99, 'name' => 'Secret store' ) ) );
		$this->as_user( (int) $mine['user_id'] );
		Context::reset();
		Routes::reset();

		$unknown = $this->body( $mine, array( 'no-such-store' ) );
		$foreign_slug = $this->body( $mine, array( 'secret-store' ) );
		$foreign_id   = $this->body( $mine, array( '99' ) );

		$this->assertSame( $unknown, $foreign_slug );
		$this->assertSame( $unknown, $foreign_id );
		$this->assertStringContainsString( 'That dashboard was not found', $unknown );
		$this->assertStringContainsString( 'data-odoowoo-card="dashboard-list"', $unknown );
		$this->assertStringNotContainsString( 'data-odoowoo-card="dashboard-header"', $unknown );
		$this->assertStringNotContainsString( 'Secret', $unknown );
	}

	public function test_the_body_leaves_another_app_and_another_customers_page_alone(): void {
		$license = $this->app_license();
		$this->django_with( $license, array() );

		// Not the viewer's license.
		$this->as_user( self::factory()->user->create() );
		Context::reset();
		ob_start();
		$handled = apply_filters( 'beltoft_services_account_page_body', false, $this->page( $license ) );
		$this->assertSame( '', ob_get_clean() );
		$this->assertFalse( $handled );

		// Another app of the Apps area.
		$this->as_user( (int) $license['user_id'] );
		Context::reset();
		$other = $license;
		$other['product_slug'] = 'something-else';
		ob_start();
		$handled = apply_filters( 'beltoft_services_account_page_body', false, $this->page( $other ) );
		$this->assertSame( '', ob_get_clean() );
		$this->assertFalse( $handled );

		// Already drawn by another listener.
		$this->assertTrue( apply_filters( 'beltoft_services_account_page_body', true, $this->page( $license ) ) );
	}

	public function test_the_whole_apps_page_of_beltoft_services_shows_the_dashboards_for_the_owner(): void {
		$license = $this->app_license();
		Resource::for_license( (int) $license['id'] )->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$this->django_with( $license, array( array( 'id' => 5, 'name' => 'Shop' ) ) );
		$this->as_user( (int) $license['user_id'] );
		Context::reset();

		$area = ServiceArea::get( Apps::class );
		$view = View::license( $license );
		$GLOBALS['wp']->query_vars['apps'] = \BeltoftServices\Account\Slugs::value( $license ) . '/shop/logs';

		ob_start();
		$area->render( array( $view ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'beltoft-services-area-page--apps', $html );
		$this->assertStringContainsString( 'data-odoowoo-card="dashboard-header"', $html );
		$this->assertStringContainsString( 'data-odoowoo-card="sync-logs"', $html );
		$this->assertStringContainsString( 'data-dashboard="5"', $html );
		$this->assertStringNotContainsString( 'beltoft-services-http__facts', $html, 'The driver part gave way.' );

		$GLOBALS['wp']->query_vars['apps'] = \BeltoftServices\Account\Slugs::value( $license );
		ob_start();
		$area->render( array( $view ) );
		$this->assertStringContainsString( 'data-odoowoo-card="dashboard-list"', (string) ob_get_clean() );
	}

	// -- Open button -------------------------------------------------------------------------

	public function test_the_open_button_of_the_apps_list_goes_into_the_dashboard(): void {
		$license = $this->app_license();
		$this->as_user( (int) $license['user_id'] );
		Context::reset();
		$resource = Resource::for_license( (int) $license['id'] );

		$this->assertSame( ServiceArea::url_for_license( $license ), Area::app_url( '', $resource, $license ) );
		$this->assertSame( 'https://x.example/', Area::app_url( 'https://x.example/', $resource, array( 'id' => (int) $license['id'], 'product_slug' => 'other' ) ), 'Another app keeps its own.' );

		$this->as_user( self::factory()->user->create() );
		Context::reset();
		$this->assertSame( '', Area::app_url( '', $resource, $license ), 'Nothing for someone else.' );
	}

	public function test_the_license_block_link_goes_to_the_service_page_even_without_a_list_page(): void {
		$license = $this->app_license();
		$this->as_user( (int) $license['user_id'] );
		Context::reset();

		ob_start();
		OpenLink::render( $license );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'href="' . esc_url( ServiceArea::url_for_license( $license ) ) . '"', $html );
		$this->assertStringContainsString( '>Open OdooWoo<', $html );

		// A site without the area and without a list page: nothing.
		$plain = $this->odoowoo_license( array( 'user_id' => (int) $license['user_id'] ) );
		ob_start();
		OpenLink::render( $plain );
		$this->assertSame( '', ob_get_clean() );
	}

	// -- Redirects below the service ---------------------------------------------------------

	public function test_a_number_in_the_address_goes_to_the_slug_for_the_owner(): void {
		$license = $this->app_license();
		$this->django_with( $license, array( array( 'id' => 5, 'name' => 'Shop' ), array( 'id' => 6, 'name' => '' ) ) );
		$this->as_user( (int) $license['user_id'] );
		$base = ServiceArea::url_for_license( $license );
		Routes::reset();

		$this->assertSame( $base . 'shop/', apply_filters( 'beltoft_services_account_page_redirect', '', $this->page( $license, array( '5' ) ) ) );
		$this->assertSame( $base . 'shop/mappings/', apply_filters( 'beltoft_services_account_page_redirect', '', $this->page( $license, array( '5', 'mappings' ) ) ) );
		$this->assertSame( $base . 'shop/', apply_filters( 'beltoft_services_account_page_redirect', '', $this->page( $license, array( '5', 'zzz' ) ) ), 'An unknown tab is dropped.' );
		$this->assertSame( '', apply_filters( 'beltoft_services_account_page_redirect', '', $this->page( $license, array( 'shop' ) ) ), 'A slug stays.' );
		$this->assertSame( '', apply_filters( 'beltoft_services_account_page_redirect', '', $this->page( $license, array( '99' ) ) ), 'An unknown number stays (and is not found).' );
		$this->assertSame( '', apply_filters( 'beltoft_services_account_page_redirect', '', $this->page( $license, array( '6' ) ) ), 'A dashboard without a usable name keeps its number.' );
		$this->assertSame( '', apply_filters( 'beltoft_services_account_page_redirect', '', $this->page( $license ) ) );
	}

	public function test_nothing_is_redirected_for_another_customer_or_another_app(): void {
		$license = $this->app_license();
		$this->django_with( $license, array( array( 'id' => 5, 'name' => 'Shop' ) ) );

		$this->as_user( self::factory()->user->create() );
		Context::reset();
		$this->assertSame( '', apply_filters( 'beltoft_services_account_page_redirect', '', $this->page( $license, array( '5' ) ) ) );
		$this->assertSame( array(), $this->django_calls(), 'Not even a Django call.' );

		$this->as_user( (int) $license['user_id'] );
		Context::reset();
		$other                 = $license;
		$other['product_slug'] = 'something-else';
		$this->assertSame( '', apply_filters( 'beltoft_services_account_page_redirect', '', $this->page( $other, array( '5' ) ) ) );
	}

	// -- The old pages -----------------------------------------------------------------------

	private function old_pages(): array {
		$list = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'odoowoo' ) );
		$dash = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'odoowoo-dashboard' ) );
		$this->settings( array( 'list_page_id' => $list, 'dashboard_page_id' => $dash ) );

		return array( $list, $dash );
	}

	public function test_the_old_pages_go_to_the_new_addresses_for_the_owner(): void {
		list( $list, $dash ) = $this->old_pages();
		$license             = $this->app_license();
		$this->django_with( $license, array( array( 'id' => 5, 'name' => 'Shop' ) ) );
		$this->as_user( (int) $license['user_id'] );
		Context::reset();
		Routes::reset();
		$base = ServiceArea::url_for_license( $license );
		$id   = (int) $license['id'];

		$this->go_to( get_permalink( $list ) . '?license=' . $id );
		$this->assertSame( $base, Area::old_page_target() );

		$this->go_to( get_permalink( $list ) );
		$this->assertSame( $base, Area::old_page_target(), 'No license in the address: the owner\'s newest.' );

		$this->go_to( get_permalink( $dash ) . '?license=' . $id . '&dashboard=5&tab=mappings' );
		$this->assertSame( $base . 'shop/mappings/', Area::old_page_target() );

		$this->go_to( get_permalink( $dash ) . '?license=' . $id . '&dashboard=5&tab=connections' );
		$this->assertSame( $base . 'shop/', Area::old_page_target() );

		$this->go_to( get_permalink( $dash ) . '?license=' . $id . '&dashboard=77' );
		$this->assertSame( $base . '77/', Area::old_page_target(), 'An unknown dashboard: the number, which the area answers with not found.' );

		$this->go_to( get_permalink( $dash ) . '?license=' . $id );
		$this->assertSame( $base, Area::old_page_target(), 'The dashboard page without a dashboard: the list.' );
	}

	public function test_the_old_pages_stay_for_everyone_else(): void {
		list( $list, $dash ) = $this->old_pages();
		$license             = $this->app_license();
		$id                  = (int) $license['id'];
		$other_page          = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'about' ) );

		// A visitor.
		$this->go_to( get_permalink( $list ) . '?license=' . $id );
		$this->assertSame( '', Area::old_page_target() );

		// Another customer asking for this license.
		$this->as_user( self::factory()->user->create() );
		Context::reset();
		$this->go_to( get_permalink( $dash ) . '?license=' . $id . '&dashboard=5' );
		$this->assertSame( '', Area::old_page_target() );

		// Support.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( 'manage_woocommerce' );
		Support::grant( $admin, $id );
		Context::reset();
		$this->as_user( $admin );
		$this->go_to( get_permalink( $list ) . '?license=' . $id );
		$this->assertSame( '', Area::old_page_target() );

		// Another page, even for the owner.
		$this->as_user( (int) $license['user_id'] );
		Context::reset();
		$this->go_to( get_permalink( $other_page ) . '?license=' . $id );
		$this->assertSame( '', Area::old_page_target() );

		// A site whose area does not list the license: nothing moves.
		$plain = $this->odoowoo_license();
		$this->as_user( (int) $plain['user_id'] );
		Context::reset();
		$this->go_to( get_permalink( $list ) . '?license=' . $plain['id'] );
		$this->assertSame( '', Area::old_page_target() );
	}

	// -- Assets ------------------------------------------------------------------------------

	public function test_the_stylesheet_loads_on_my_account_for_a_customer_with_odoowoo_only(): void {
		$none = self::factory()->user->create();
		$this->as_user( $none );
		Context::reset();
		Area::assets();
		$this->assertFalse( wp_style_is( 'odoowoo', 'enqueued' ) );

		$license = $this->app_license();
		$this->as_user( (int) $license['user_id'] );
		Context::reset();
		do_action( 'beltoft_services_account_assets', 'beltoft-services-account', 'apps' );
		$this->assertTrue( wp_style_is( 'odoowoo', 'enqueued' ) );
	}

	public function test_the_renderer_takes_ids_from_the_page_and_not_from_the_query_when_told_to(): void {
		$_GET = array( 'license' => '5', 'dashboard' => '6', 'tab' => 'logs' );
		$this->assertSame( array( 'license' => 5, 'dashboard' => 6, 'tab' => 'logs' ), Renderer::context_ids() );

		Renderer::use_ids( array( 'license' => 7, 'dashboard' => 8, 'tab' => 'bogus' ) );
		$this->assertSame( array( 'license' => 7, 'dashboard' => 8, 'tab' => 'connections' ), Renderer::context_ids() );

		Renderer::use_ids( null );
		$this->assertSame( 6, Renderer::context_ids()['dashboard'] );
	}

	public function test_new_code_has_no_border_radius_and_no_em_dash(): void {
		$files = array( 'src/Account/Area.php', 'src/Account/Dashboards.php', 'src/Tabs.php', 'src/Urls.php' );

		foreach ( $files as $file ) {
			$text = (string) file_get_contents( ODOOWOO_PORTAL_PATH . $file );
			$this->assertStringNotContainsString( 'border-radius', $text, $file );
			$this->assertStringNotContainsString( "\u{2014}", $text, $file );
		}
	}

	public function test_the_plan_reads_the_way_the_dashboard_words_it(): void {
		$this->assertSame( 'OdooWoo Pro, billed monthly', Area::plan_label( 'OdooWoo - Pro, Monthly' ) );
		$this->assertSame( 'OdooWoo Pro, billed yearly', Area::plan_label( 'OdooWoo - Pro, Annual' ) );
		$this->assertSame( 'OdooWoo Starter, billed quarterly', Area::plan_label( 'OdooWoo - Starter, quarterly' ) );
		$this->assertSame( 'OdooWoo', Area::plan_label( 'OdooWoo' ) );
		$this->assertSame( 'OdooWoo Pro', Area::plan_label( 'OdooWoo - Pro' ) );
		$this->assertSame( 'Monthly', Area::plan_label( 'Monthly' ), 'A lone word is a name, not a period.' );
		$this->assertSame( 'OdooWoo Pro, Lifetime', Area::plan_label( 'OdooWoo - Pro, Lifetime' ) );
	}

	public function test_the_summary_carries_the_plan_label_only_inside_my_account(): void {
		$license = $this->app_license();
		$this->django_with( $license, array() );
		$this->as_user( (int) $license['user_id'] );
		Routes::reset();
		$entry = Routes::license_summary( Context::for_user( (int) $license['user_id'], (int) $license['id'] ) );

		$this->assertArrayHasKey( 'plan_label', $entry );
		$this->assertSame( Area::plan_label( $entry['plan'] ), $entry['plan_label'] );
		$this->assertSame( 'OdooWoo', $entry['plan'], 'The stored name is untouched.' );

		$plain = $this->odoowoo_license();
		$this->django_with( $plain, array() );
		$this->as_user( (int) $plain['user_id'] );
		Routes::reset();
		$this->assertArrayNotHasKey( 'plan_label', Routes::license_summary( Context::for_user( (int) $plain['user_id'], (int) $plain['id'] ) ) );
	}

	public function test_the_account_look_is_scoped_to_the_account_area_and_square(): void {
		$css   = (string) file_get_contents( ODOOWOO_PORTAL_PATH . 'assets/css/odoowoo.css' );
		$block = substr( $css, (int) strpos( $css, "/*\n * Inside My Account (the dashboard" ) );

		$this->assertNotSame( '', $block );
		$this->assertStringNotContainsString( 'border-radius', $block );
		$this->assertStringNotContainsString( "\u{2014}", $block );

		// Every selector of the block starts in the account area (or a dialog opened from it).
		$rules = (string) preg_replace( '/@media[^{]*\{/', '', (string) preg_replace( '#/\*.*?\*/#s', '', $block ) );
		preg_match_all( '/(?:^|\})\s*([^{}@][^{}]*)\{/m', $rules, $m );
		foreach ( $m[1] as $list ) {
			foreach ( explode( ',', $list ) as $selector ) {
				$selector = trim( $selector );
				$this->assertMatchesRegularExpression( '/^(\.beltoft-services-area-page|body:has\(\.beltoft-services-area-page\))/', $selector, $selector );
			}
		}

		$this->assertStringContainsString( 'font-size: 20px', $block );
		$this->assertStringContainsString( ':has(.show-password-input) .odoowoo-reveal', $block );
	}
}
