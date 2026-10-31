<?php
/**
 * The OdooWoo app inside the My Account dashboard of beltoft-services.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Account;

use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Resource;
use OdooWooPortal\Cards\Renderer;
use OdooWooPortal\Context;
use OdooWooPortal\Settings;
use OdooWooPortal\Tabs;
use OdooWooPortal\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * beltoft-services draws the customer's apps in My Account (/my-account/apps/<service>/) and lets another plugin draw the
 * page of an app and the pages below it. This is that plugin for an OdooWoo license:
 *
 *  - `/my-account/apps/<service>/`: the dashboard list card;
 *  - `/my-account/apps/<service>/<dashboard>/` and `/<dashboard>/<tab>/`: the header card, the tabs and the cards of the tab.
 *
 * It only reuses the cards (Cards\Renderer) and their REST routes: nothing is decided here that Context, the routes and
 * Django do not decide too. A request for the old list or dashboard page goes to the new address (301) for the license's
 * owner. Everything stays off, and Urls keeps the old addresses, on a site whose beltoft-services has no such area.
 */
final class Area {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_account_page_body', array( __CLASS__, 'body' ), 10, 2 );
		add_filter( 'beltoft_services_account_page_redirect', array( __CLASS__, 'redirect' ), 10, 2 );
		add_filter( 'beltoft_services_account_app_url', array( __CLASS__, 'app_url' ), 10, 3 );
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_old_pages' ), 6 );
		Dashboards::register();
	}

	/**
	 * Does beltoft-services draw apps inside My Account (the hook it offers is there)?
	 */
	public static function available(): bool {
		return class_exists( ServiceArea::class ) && method_exists( ServiceArea::class, 'url_for_license' );
	}

	/**
	 * Does this license's app live inside My Account for the current user: the area exists, and the viewer owns an OdooWoo
	 * license that the Apps area lists? A support viewer, or anyone else, keeps the old pages.
	 *
	 * @param int $license_id License id.
	 */
	public static function enabled( int $license_id ): bool {
		return null !== self::owner_context( $license_id );
	}

	/**
	 * The current user's owner context for a license when the app lives inside My Account for it, else null.
	 *
	 * @param int $license_id License id.
	 */
	private static function owner_context( int $license_id ): ?Context {
		if ( $license_id <= 0 || ! self::available() || ! is_user_logged_in() ) {
			return null;
		}

		$ctx = Context::for_user( get_current_user_id(), $license_id );

		if ( null === $ctx || 'owner' !== $ctx->mode() || '' === ServiceArea::url_for_license( $ctx->license() ) ) {
			return null;
		}

		return $ctx;
	}

	/**
	 * The address of an app's page (the dashboard list), or '' when the app does not live in My Account for the viewer.
	 *
	 * @param int $license_id License id.
	 */
	public static function service_url( int $license_id ): string {
		$ctx = self::owner_context( $license_id );

		return $ctx ? ServiceArea::url_for_license( $ctx->license() ) : '';
	}

	/**
	 * The address of one dashboard's page, on a tab (the first tab has the short address), or '' (see service_url()). A
	 * dashboard without a remembered slug is addressed by its number, which the area sends on to the slug.
	 *
	 * @param int    $license_id   License id.
	 * @param int    $dashboard_id Dashboard id.
	 * @param string $tab          Tab.
	 */
	public static function dashboard_url( int $license_id, int $dashboard_id, string $tab ): string {
		$ctx = self::owner_context( $license_id );

		if ( ! $ctx || $dashboard_id <= 0 ) {
			return '';
		}

		$slug = Dashboards::slug( $license_id, $dashboard_id );
		$tab  = Tabs::valid( $tab );
		$path = array( '' !== $slug ? $slug : (string) $dashboard_id );

		if ( 'connections' !== $tab ) {
			$path[] = $tab;
		}

		return ServiceArea::url_for_license( $ctx->license(), $path );
	}

	/**
	 * The template the list card's script fills in: the dashboard page's address with {dashboard} for the dashboard's number
	 * (the area sends a number on to the slug). '' when the app does not live in My Account.
	 *
	 * @param int $license_id License id.
	 */
	public static function dashboard_template( int $license_id ): string {
		$base = self::service_url( $license_id );

		return '' === $base ? '' : trailingslashit( $base ) . '{dashboard}/';
	}

	/**
	 * Listener of beltoft_services_account_app_url: the Open button of an OdooWoo app goes to its page in My Account.
	 *
	 * @param mixed $url      Address so far.
	 * @param mixed $resource Resource.
	 * @param mixed $license  License row.
	 * @return mixed
	 */
	public static function app_url( $url, $resource = null, $license = array() ) {
		unset( $resource );

		if ( ! is_array( $license ) || empty( $license['id'] ) || ! self::is_odoowoo( $license ) ) {
			return $url;
		}

		$own = self::service_url( (int) $license['id'] );

		return '' !== $own ? $own : $url;
	}

	/**
	 * Is the license an OdooWoo one (the software slug of the settings)?
	 *
	 * @param array<string, mixed> $license License row.
	 */
	private static function is_odoowoo( array $license ): bool {
		return (string) ( $license['product_slug'] ?? '' ) === (string) Settings::get( 'software_slug' );
	}

	/**
	 * Listener of beltoft_services_account_assets: the app's stylesheet on My Account pages of a customer who has OdooWoo. The
	 * scripts follow with the cards that need them.
	 */
	public static function assets(): void {
		if ( array() === Context::licenses_for_user( get_current_user_id() ) ) {
			return;
		}

		Renderer::register_assets();
		wp_enqueue_style( 'odoowoo' );
	}

	/**
	 * Listener of beltoft_services_account_page_body: prints the list or a dashboard in place of the HTTP driver's part of the
	 * app's page. Another app's page is left alone.
	 *
	 * @param mixed $handled Whether another listener printed the page.
	 * @param mixed $page    The page: license, resource, path, base.
	 * @return mixed
	 */
	public static function body( $handled, $page = array() ) {
		if ( $handled || ! is_array( $page ) || ! is_array( $page['license'] ?? null ) || ! ( $page['resource'] ?? null ) instanceof Resource || ! self::is_odoowoo( $page['license'] ) ) {
			return $handled;
		}

		$license_id = (int) $page['license']['id'];
		$ctx        = self::owner_context( $license_id );

		if ( null === $ctx ) {
			return $handled;
		}

		$path = isset( $page['path'] ) && is_array( $page['path'] ) ? array_values( $page['path'] ) : array();

		try {
			if ( array() === $path ) {
				self::print_list( $license_id );

				return true;
			}

			$dashboard = Dashboards::resolve( $ctx, (string) $path[0] );

			if ( $dashboard <= 0 ) {
				echo '<p class="beltoft-services-notice" role="status">' . esc_html( self::not_found_text() ) . '</p>';
				self::print_list( $license_id );

				return true;
			}

			self::print_dashboard( $license_id, $dashboard, Tabs::valid( (string) ( $path[1] ?? '' ) ) );
		} finally {
			Renderer::use_ids( null );
		}

		return true;
	}

	/**
	 * What a dashboard that is not the viewer's, or not there, is told: the same words either way.
	 */
	public static function not_found_text(): string {
		return __( 'That dashboard was not found. Here are your dashboards.', 'odoowoo-portal' );
	}

	/**
	 * Prints the dashboard list card.
	 *
	 * @param int $license_id License id.
	 */
	private static function print_list( int $license_id ): void {
		Renderer::use_ids( array( 'license' => $license_id, 'dashboard' => 0, 'tab' => 'connections' ) );
		echo Renderer::render( 'dashboard-list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the card's shell and template.
	}

	/**
	 * Prints one dashboard: its header, the tabs and the cards of the tab.
	 *
	 * @param int    $license_id License id.
	 * @param int    $dashboard  Dashboard id.
	 * @param string $tab        Tab.
	 */
	private static function print_dashboard( int $license_id, int $dashboard, string $tab ): void {
		Renderer::use_ids( array( 'license' => $license_id, 'dashboard' => $dashboard, 'tab' => $tab ) );

		echo '<div class="odoowoo-account-dashboard" data-odoowoo-tab="' . esc_attr( $tab ) . '">';
		echo Renderer::render( 'dashboard-header' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the card's shell and template.

		$nav = array();

		foreach ( Tabs::titles() as $slug => $title ) {
			$nav[ $slug ] = array(
				'title' => $title,
				'url'   => Urls::dashboard( $license_id, $dashboard, $slug ),
			);
		}

		ServiceArea::tab_nav( $nav, $tab, __( 'Dashboard sections', 'odoowoo-portal' ) );

		foreach ( Tabs::cards( $tab ) as $card ) {
			echo Renderer::render( $card ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the card's shell and template.
		}

		echo '</div>';
	}

	/**
	 * Listener of beltoft_services_account_page_redirect: a dashboard's number in the address goes to its slug (301). A slug, an
	 * unknown number and a dashboard without a name stay.
	 *
	 * @param mixed $target Address so far.
	 * @param mixed $page   The page: license, path.
	 * @return mixed
	 */
	public static function redirect( $target, $page = array() ) {
		if ( ! is_array( $page ) || ! is_array( $page['license'] ?? null ) || ! self::is_odoowoo( $page['license'] ) || empty( $page['path'] ) || ! is_array( $page['path'] ) ) {
			return $target;
		}

		$license_id = (int) $page['license']['id'];
		$ctx        = self::owner_context( $license_id );
		$first      = (string) $page['path'][0];

		if ( null === $ctx || ! ctype_digit( $first ) ) {
			return $target;
		}

		$id = Dashboards::resolve( $ctx, $first );

		if ( $id <= 0 || '' === Dashboards::slug( $license_id, $id ) ) {
			return $target;
		}

		return self::dashboard_url( $license_id, $id, Tabs::valid( (string) ( $page['path'][1] ?? '' ) ) );
	}

	/**
	 * Early on a request for the old list or dashboard page (they stay where they are): the license's owner goes to the new
	 * address, 301.
	 */
	public static function redirect_old_pages(): void {
		$target = self::old_page_target();

		if ( '' !== $target && wp_safe_redirect( $target, 301 ) ) {
			exit;
		}
	}

	/**
	 * The new address for a request of the old list or dashboard page, or '' (another page, a visitor who is not the owner of
	 * the license, a license that is not the viewer's, an app that does not live in My Account).
	 */
	public static function old_page_target(): string {
		$user = get_current_user_id();
		$page = is_page() ? (int) get_queried_object_id() : 0;
		$list = (int) Settings::get( 'list_page_id' );
		$dash = (int) Settings::get( 'dashboard_page_id' );

		if ( $user <= 0 || $page <= 0 || ! in_array( $page, array( $list, $dash ), true ) ) {
			return '';
		}

		$ids        = Renderer::context_ids();
		$license_id = Context::page_license_id( $user, $ids['license'] );
		$ctx        = self::owner_context( $license_id );

		if ( null === $ctx ) {
			return '';
		}

		if ( $page === $dash && $ids['dashboard'] > 0 ) {
			// Reading the dashboards remembers their slugs, so the address is the readable one at once.
			Dashboards::listing( $ctx );

			return Urls::dashboard( $license_id, $ids['dashboard'], $ids['tab'] );
		}

		return Urls::license_list( $license_id );
	}
}
