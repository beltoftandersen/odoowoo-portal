<?php
/**
 * The dashboard list and dashboard header cards: config attributes, strings, the support summary.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Api\Routes;
use OdooWooPortal\Cards\Registry;
use OdooWooPortal\Cards\Renderer;
use OdooWooPortal\Context;
use OdooWooPortal\Support;
use OdooWooPortal\Urls;

/**
 * Markup and attributes only; the behaviour is in tests/js/dashboards.test.mjs.
 */
final class CardListTest extends RestTestCase {

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
	 * A support user with an open session on a license.
	 *
	 * @param array<string, mixed> $license License.
	 */
	private function support_for( array $license ): int {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( 'manage_woocommerce' );
		Support::grant( $admin, (int) $license['id'] );
		Context::reset();

		return $admin;
	}

	/**
	 * Fake Django for one license: tenant read (P license_summary) and dashboards (D DashboardListView).
	 *
	 * @param array<string, mixed> $license License.
	 */
	private function fake_summary( array $license ): void {
		$this->django_map(
			array(
				'GET tenants/' . $this->tenant( $license ) . '/' => array(
					200,
					array(
						'limit'      => 3,
						'used'       => 1,
						'attributes' => array(),
					),
				),
				'GET dashboards/' => array( 200, array( array( 'id' => 5, 'name' => 'Shop <b>', 'enabled' => true, 'created_at' => '2026-10-21T12:00:00Z' ) ) ),
			)
		);
	}

	public function test_list_card_template_has_config_attributes(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$_GET = array( 'license' => (string) $license['id'] );

		$html = Renderer::render( 'dashboard-list' );

		$this->assertStringContainsString( 'data-odoowoo-card="dashboard-list"', $html );
		$this->assertStringContainsString( 'data-dashboard-url="' . esc_attr( Urls::dashboard( 0, 0, 'connections' ) ) . '"', $html );
		$this->assertStringContainsString( 'data-rest="' . esc_attr( esc_url_raw( rest_url( 'odoowoo-portal/v1/' ) ) ) . '"', $html );
		$this->assertStringContainsString( 'data-license="' . $license['id'] . '"', $html );
		$this->assertStringContainsString( 'class="odoowoo-new"', str_replace( 'odoowoo-button ', '', $html ) );
		$this->assertStringNotContainsString( 'data-summary', $html, 'An owner reads the list over REST.' );
		$this->assertTrue( wp_script_is( 'odoowoo-card-dashboard-list', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'odoowoo-card-dashboard-header', 'enqueued' ) );
		$this->assertSame( array(), $this->django_calls(), 'Rendering the card makes no Django call for an owner.' );
	}

	public function test_header_card_template_has_h1_rename_and_back_link(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => '9',
		);

		$html = Renderer::render( 'dashboard-header' );

		$this->assertStringContainsString( 'data-odoowoo-card="dashboard-header"', $html );
		$this->assertStringContainsString( 'data-dashboard="9"', $html );
		$this->assertStringContainsString( 'data-can-write="1"', $html );
		$this->assertMatchesRegularExpression( '#<h1 class="odoowoo-dashboard-name"[^>]*>#', $html );
		$this->assertStringContainsString( 'odoowoo-rename', $html );
		$this->assertStringContainsString( 'odoowoo-state', $html );
		$this->assertMatchesRegularExpression( '#<a [^>]*class="odoowoo-back"[^>]*>#', $html );
		$this->assertStringContainsString( 'href="' . esc_url( Urls::license_list( (int) $license['id'] ) ) . '"', $html );
		$this->assertTrue( wp_script_is( 'odoowoo-card-dashboard-header', 'enqueued' ) );
		$this->assertSame( array(), $this->django_calls() );
	}

	public function test_header_card_for_support_is_not_writable(): void {
		$license = $this->odoowoo_license();
		$admin   = $this->support_for( $license );
		$this->as_user( $admin );
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => '9',
		);

		$this->assertStringContainsString( 'data-can-write="0"', Renderer::render( 'dashboard-header' ) );
	}

	public function test_delete_dialog_text_is_in_the_strings(): void {
		$cards = Registry::cards();

		$this->assertSame( 'Delete %s? This removes its settings and logs. Stores already connected stop syncing.', $cards['dashboard-list']['strings']['deleteBody'] );
		$this->assertSame( array( 'dashboard-list', 'dashboard-create', 'dashboard-pause', 'dashboard-delete' ), $cards['dashboard-list']['keys'] );
		$this->assertSame( array( 'dashboard-detail', 'dashboard-rename' ), $cards['dashboard-header']['keys'] );

		foreach ( array( 'newDashboard', 'open', 'pause', 'enable', 'delete', 'active', 'paused', 'emptyList', 'noPlan', 'limitReached', 'limitReachedOne', 'count', 'countOne', 'countOf', 'countOfOne', 'nameRequired' ) as $key ) {
			$this->assertArrayHasKey( $key, $cards['dashboard-list']['strings'], $key );
			$this->assertNotSame( '', $cards['dashboard-list']['strings'][ $key ], $key );
		}

		foreach ( array( 'rename', 'save', 'nameLabel', 'nameRequired', 'active', 'paused' ) as $key ) {
			$this->assertArrayHasKey( $key, $cards['dashboard-header']['strings'], $key );
		}

		// The strings reach the page's boot data (and only for a card that is on it).
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$_GET = array( 'license' => (string) $license['id'] );
		Renderer::render( 'dashboard-list' );
		$boot = Renderer::boot_data();
		$this->assertSame( $cards['dashboard-list']['strings']['deleteBody'], $boot['i18n']['deleteBody'] );
		$this->assertArrayNotHasKey( 'rename', $boot['i18n'] );
	}

	public function test_support_summary_is_embedded_only_for_support_and_never_for_a_stranger(): void {
		$license = $this->odoowoo_license();
		$this->fake_summary( $license );

		// Support: embedded, JSON, escaped, with the tenant read and the dashboards read made once.
		$admin = $this->support_for( $license );
		$this->as_user( $admin );
		$_GET = array( 'license' => (string) $license['id'] );

		$html = Renderer::render( 'dashboard-list' );

		$this->assertMatchesRegularExpression( '/ data-summary="([^"]*)"/', $html );
		preg_match( '/ data-summary="([^"]*)"/', $html, $m );
		$summary = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		$this->assertIsArray( $summary );
		$this->assertSame( (int) $license['id'], $summary['id'] );
		$this->assertSame( 'support', $summary['mode'] );
		$this->assertFalse( $summary['can_write'] );
		$this->assertSame( 3, $summary['limit'] );
		$this->assertSame( 'Shop <b>', $summary['dashboards'][0]['name'] );
		$this->assertStringNotContainsString( 'Shop <b>', $html, 'Escaped inside the attribute.' );
		$this->assertCount( 2, $this->django_calls(), 'One tenant read and one dashboards read.' );

		Renderer::render( 'dashboard-list' );
		$this->assertCount( 2, $this->django_calls(), 'Memoized.' );

		// No secret, no tenant id in what is embedded.
		$this->assertStringNotContainsString( $this->tenant( $license ), $html );

		// A stranger with a license id in the URL: not found, nothing embedded, no Django call.
		Routes::reset();
		Renderer::reset();
		Context::reset();
		$this->fake_summary( $license );
		$stranger = self::factory()->user->create();
		$this->as_user( $stranger );
		$out = Renderer::render( 'dashboard-list' );
		$this->assertStringNotContainsString( 'data-summary', $out );
		$this->assertStringContainsString( 'This license was not found.', $out );
		$this->assertSame( array(), $this->django_calls() );

		// Logged out: notice only.
		$this->as_user( 0 );
		$out = Renderer::render( 'dashboard-list' );
		$this->assertStringNotContainsString( 'data-summary', $out );
		$this->assertSame( array(), $this->django_calls() );

		// An admin with the capability but no arrival gets nothing either.
		$cap_only = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $cap_only )->add_cap( 'manage_woocommerce' );
		$this->as_user( $cap_only );
		Context::reset();
		$out = Renderer::render( 'dashboard-list' );
		$this->assertStringNotContainsString( 'data-summary', $out );
		$this->assertSame( array(), $this->django_calls() );
	}

	public function test_support_summary_not_embedded_for_other_cards_or_owner_view(): void {
		$license = $this->odoowoo_license();
		$this->fake_summary( $license );
		$admin = $this->support_for( $license );
		$this->as_user( $admin );
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => '9',
		);

		$this->assertStringNotContainsString( 'data-summary', Renderer::render( 'dashboard-header' ) );
		$this->assertSame( array(), $this->django_calls() );
	}
}
