<?php
/**
 * Renders a card inside its shell.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Cards;

use BeltoftLicenses\Software;
use OdooWooPortal\Api\Routes;
use OdooWooPortal\Context;
use OdooWooPortal\Settings;
use OdooWooPortal\Tabs;
use OdooWooPortal\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * Card markup, assets and the one boot-data script.
 *
 * Page cache rule: anonymous page output may be cached, so a visitor who is logged out, or has
 * no usable license, gets a notice only: no nonce, no REST root, no ids, no scripts.
 */
final class Renderer {

	/**
	 * Strings of the cards rendered on this page, merged into the boot data.
	 *
	 * @var array<string, string>
	 */
	private static $strings = array();

	/**
	 * Did a card render for a viewer who may use the app?
	 *
	 * @var bool
	 */
	private static $app_page = false;

	/**
	 * Was the boot data attached?
	 *
	 * @var bool
	 */
	private static $attached = false;

	/**
	 * Ids a page inside My Account gives its cards instead of the query string, or null.
	 *
	 * @var array{license: int, dashboard: int, tab: string}|null
	 */
	private static $forced = null;

	/**
	 * Names the license, dashboard and tab the cards that follow are for (the address says them, not ?license=). null goes
	 * back to the query string.
	 *
	 * @param array{license: int, dashboard: int, tab: string}|null $ids Ids.
	 */
	public static function use_ids( ?array $ids ): void {
		self::$forced = null === $ids ? null : array(
			'license'   => max( 0, (int) $ids['license'] ),
			'dashboard' => max( 0, (int) $ids['dashboard'] ),
			'tab'       => Tabs::valid( (string) $ids['tab'] ),
		);
	}

	/**
	 * Forgets per-request state (tests).
	 */
	public static function reset(): void {
		self::$forced   = null;
		self::$strings  = array();
		self::$app_page = false;
		self::$attached = false;
	}

	/**
	 * The ?ver of a script or style of the plugin: the plugin version and the file's modification
	 * time, so a deploy that changes the file changes the URL (static files are cached for years).
	 *
	 * @param string $relative Path inside the plugin.
	 */
	public static function asset_version( string $relative ): string {
		$mtime = @filemtime( ODOOWOO_PORTAL_PATH . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file only means no mtime.

		return ODOOWOO_PORTAL_VERSION . ( false === $mtime ? '' : '.' . $mtime );
	}

	/**
	 * Registers the shared script and style handles (nothing is enqueued here).
	 */
	public static function register_assets(): void {
		$url = plugin_dir_url( ODOOWOO_PORTAL_FILE );

		if ( ! wp_script_is( 'odoowoo-core', 'registered' ) ) {
			wp_register_script( 'odoowoo-core', $url . 'assets/js/core.js', array(), self::asset_version( 'assets/js/core.js' ), array( 'in_footer' => true ) );
		}

		if ( ! wp_script_is( 'odoowoo-tasks', 'registered' ) ) {
			wp_register_script( 'odoowoo-tasks', $url . 'assets/js/tasks.js', array( 'odoowoo-core' ), self::asset_version( 'assets/js/tasks.js' ), array( 'in_footer' => true ) );
		}

		if ( ! wp_script_is( 'odoowoo-deps', 'registered' ) ) {
			wp_register_script( 'odoowoo-deps', $url . 'assets/js/deps.js', array( 'odoowoo-core' ), self::asset_version( 'assets/js/deps.js' ), array( 'in_footer' => true ) );
		}

		if ( ! wp_script_is( 'odoowoo-mapping-editor', 'registered' ) ) {
			wp_register_script( 'odoowoo-mapping-editor', $url . 'assets/js/mapping-editor.js', array( 'odoowoo-core' ), self::asset_version( 'assets/js/mapping-editor.js' ), array( 'in_footer' => true ) );
		}

		if ( ! wp_style_is( 'odoowoo', 'registered' ) ) {
			wp_register_style( 'odoowoo', $url . 'assets/css/odoowoo.css', array(), self::asset_version( 'assets/css/odoowoo.css' ) );
		}
	}

	/**
	 * Registers tabs.js. It waits for Bricks' own script when Bricks is there, because Bricks
	 * initialises its Tabs element in its DOMContentLoaded handler. Decided when it is first
	 * needed, so Bricks has registered its handle by then.
	 */
	private static function register_tabs(): void {
		if ( wp_script_is( 'odoowoo-tabs', 'registered' ) ) {
			return;
		}

		wp_register_script(
			'odoowoo-tabs',
			plugin_dir_url( ODOOWOO_PORTAL_FILE ) . 'assets/js/tabs.js',
			wp_script_is( 'bricks-scripts', 'registered' ) ? array( 'bricks-scripts' ) : array(),
			self::asset_version( 'assets/js/tabs.js' ),
			array( 'in_footer' => true )
		);
	}

	/**
	 * ?license=, ?dashboard= and ?tab= for display only: nothing here is trusted. Access is
	 * decided by Context and, for every call, by the REST guard.
	 *
	 * @return array{license: int, dashboard: int, tab: string}
	 */
	public static function context_ids(): array {
		if ( null !== self::$forced ) {
			return self::$forced;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; access is decided by Context and the REST guard.
		$number = static function ( string $key ): int {
			return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
		};
		$tab    = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'license'   => $number( 'license' ),
			'dashboard' => $number( 'dashboard' ),
			'tab'       => Tabs::valid( $tab ),
		);
	}

	/**
	 * What the page's script needs. Only called for a viewer who may use the app.
	 *
	 * @return array{rest: string, nonce: string, nonceUrl: string, i18n: array<string, string>}
	 */
	public static function boot_data(): array {
		return array(
			'rest'     => esc_url_raw( rest_url( Routes::NAMESPACE . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'nonceUrl' => esc_url_raw( add_query_arg( 'action', 'rest-nonce', admin_url( 'admin-ajax.php' ) ) ),
			'i18n'     => array_merge( self::core_strings(), self::$strings ),
		);
	}

	/**
	 * The strings core.js and tasks.js use.
	 *
	 * @return array<string, string>
	 */
	public static function core_strings(): array {
		return array(
			'generic'         => \OdooWooPortal\Django\Messages::generic(),
			'session'         => __( 'Please log in again. What you typed is still here.', 'odoowoo-portal' ),
			'notLoaded'       => __( 'We could not read your settings, so nothing was saved. Reload the page and try again.', 'odoowoo-portal' ),
			'saving'          => __( 'Saving…', 'odoowoo-portal' ),
			'saved'           => __( 'Saved', 'odoowoo-portal' ),
			'secretSaved'     => __( 'Saved. Leave blank to keep it.', 'odoowoo-portal' ),
			'goToConnections' => __( 'Go to Connections', 'odoowoo-portal' ),
			'cancel'          => __( 'Cancel', 'odoowoo-portal' ),
			'taskRunning'     => __( 'Running', 'odoowoo-portal' ),
			'taskCompleted'   => __( 'Completed', 'odoowoo-portal' ),
			'taskCompletedIssues' => __( 'Completed, but some items were not synced', 'odoowoo-portal' ),
			'taskSkipped'     => __( 'Skipped', 'odoowoo-portal' ),
			'taskFailed'      => __( 'Failed', 'odoowoo-portal' ),
			'taskStarting'    => __( 'Starting…', 'odoowoo-portal' ),
			/* translators: 1: items done, 2: items in all. */
			'taskProgress'    => __( '%1$s of %2$s', 'odoowoo-portal' ),
		);
	}

	/**
	 * Prints window.odoowooPortal once, in front of core.js. Runs on wp_footer (priority 1,
	 * before the footer scripts print), so every card of the page has added its strings.
	 */
	public static function attach_boot_data(): void {
		if ( self::$attached || ! self::$app_page || ! wp_script_is( 'odoowoo-core', 'registered' ) ) {
			return;
		}

		self::$attached = true;

		wp_add_inline_script(
			'odoowoo-core',
			'window.odoowooPortal = ' . wp_json_encode( self::boot_data(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);
	}

	/**
	 * One card's markup. An unknown name gives ''. Everything is escaped here or by the card's
	 * own template (which receives $ctx, $ids and $args).
	 *
	 * @param string               $card Card name.
	 * @param array<string, mixed> $args Extra values for the template.
	 */
	public static function render( string $card, array $args = array() ): string {
		$cards = Registry::cards();

		if ( '' === $card || ! isset( $cards[ $card ] ) ) {
			return '';
		}

		$entry  = $cards[ $card ];
		$title  = isset( $entry['title'] ) ? (string) $entry['title'] : '';
		$ids    = self::context_ids();
		$attrs  = array( 'data-odoowoo-card' => $card );
		$shell  = array(
			'title'   => $title,
			'notices' => array(),
			'banner'  => '',
			'body'    => '',
			'attrs'   => array(),
		);
		$user   = get_current_user_id();

		if ( $user <= 0 ) {
			$shell['notices'][] = array(
				'text'      => __( 'Please log in to use OdooWoo.', 'odoowoo-portal' ),
				'link_url'  => wp_login_url( (string) get_permalink() ),
				'link_text' => __( 'Log in', 'odoowoo-portal' ),
			);

			return self::shell( $shell, $attrs );
		}

		$own        = Context::licenses_for_user( $user );
		$license_id = Context::page_license_id( $user, $ids['license'] );

		$ctx = $license_id > 0 ? Context::for_user( $user, $license_id ) : null;

		if ( null === $ctx ) {
			if ( array() === $own && 0 === $ids['license'] ) {
				$shell['notices'][] = array(
					'text'      => __( 'You do not have an OdooWoo plan yet.', 'odoowoo-portal' ),
					'link_url'  => self::product_url(),
					'link_text' => __( 'See the OdooWoo plans', 'odoowoo-portal' ),
				);
			} else {
				$shell['notices'][] = array(
					'text'      => __( 'This license was not found.', 'odoowoo-portal' ),
					'link_url'  => '',
					'link_text' => '',
				);
			}

			return self::shell( $shell, $attrs );
		}

		self::register_assets();
		self::register_tabs();
		self::$app_page = true;
		\OdooWooPortal\Plugin::mark_uncacheable();

		if ( isset( $entry['strings'] ) && is_array( $entry['strings'] ) ) {
			foreach ( $entry['strings'] as $key => $value ) {
				if ( is_string( $key ) && is_string( $value ) ) {
					self::$strings[ $key ] = $value;
				}
			}
		}

		self::enqueue( $card, $entry );

		$attrs += array(
			'data-license'         => (string) $license_id,
			'data-dashboard'       => (string) $ids['dashboard'],
			'data-tab'             => $ids['tab'],
			'data-connections-url' => Urls::dashboard( $license_id, $ids['dashboard'], 'connections' ),
			'data-rest'            => esc_url_raw( rest_url( Routes::NAMESPACE . '/' ) ),
		);

		$attrs += self::card_attrs( $card, $ctx );

		$banner = $ctx->banner();
		$notice = $ctx->notice();

		if ( '' !== $banner ) {
			$shell['banner'] = $banner;
		}

		if ( '' !== $notice && $notice !== $banner ) {
			$shell['notices'][] = array(
				'text'      => $notice,
				'link_url'  => '',
				'link_text' => '',
			);
		}

		$shell['body'] = self::template( $entry, $ctx, $ids, $args );

		return self::shell( $shell, $attrs );
	}

	/**
	 * Root attributes only some cards need. All values are escaped by the shell.
	 *
	 * dashboard-list: the dashboard page URL template (license and dashboard 0, filled in by the
	 * script) and, for a support viewer only, the license summary: GET /licenses lists only the
	 * viewer's own licenses, so support would see nothing. Ownership already holds (Context).
	 * dashboard-header: whether the viewer may rename. The connection and settings cards: whether the
	 * viewer may save and whether they may verify.
	 *
	 * @param string  $card Card name.
	 * @param Context $ctx  Context (a usable one).
	 * @return array<string, string>
	 */
	private static function card_attrs( string $card, Context $ctx ): array {
		if ( 'dashboard-list' === $card ) {
			$attrs = array( 'data-dashboard-url' => Urls::dashboard( 0, 0, 'connections' ) );
			$own   = \OdooWooPortal\Account\Area::dashboard_template( (int) $ctx->license()['id'] );

			if ( '' !== $own ) {
				// Inside My Account: this license's dashboards only, and addresses the script fills in with the dashboard's number.
				$attrs['data-dashboard-url'] = $own;
				$attrs['data-only-license']  = '1';
			}

			if ( 'support' === $ctx->mode() ) {
				$attrs['data-summary'] = (string) wp_json_encode( Routes::license_summary( $ctx ) );
			}

			return $attrs;
		}

		if ( 'dashboard-header' === $card ) {
			return array( 'data-can-write' => $ctx->can_write() ? '1' : '0' );
		}

		if ( in_array( $card, array( 'woo-connection', 'woo-settings', 'odoo-connection', 'odoo-settings', 'dashboard-settings' ), true ) ) {
			// The two verifies are the only writes a read-only owner may still make (Routes::guard).
			return array(
				'data-can-write'  => $ctx->can_write() ? '1' : '0',
				'data-can-verify' => 'owner' === $ctx->mode() && $ctx->calls_allowed() ? '1' : '0',
			);
		}

		if ( in_array( $card, array( 'gift-cards', 'manual-sync', 'sync-logs' ), true ) ) {
			// Syncs and the gift card loaders write; the log and the stored lists are reads.
			$attrs = array(
				'data-can-write' => $ctx->can_write() ? '1' : '0',
				'data-can-read'  => $ctx->calls_allowed() ? '1' : '0',
			);

			if ( 'manual-sync' === $card ) {
				// Where a paused dashboard's refusal sends the customer: this license's list.
				$attrs['data-list-url'] = Urls::license_list( (int) $ctx->license()['id'] );
			}

			return $attrs;
		}

		if ( Mappings::is_card( $card ) ) {
			// Reads need a ready or paused tenant; writing (Save and the loaders that rewrite rows) needs the owner of an active one.
			return array(
				'data-can-write' => $ctx->can_write() ? '1' : '0',
				'data-can-read'  => $ctx->calls_allowed() ? '1' : '0',
			);
		}

		return array();
	}

	/**
	 * Enqueues what a card needs: the shared script, the poller, the style, tabs.js and the
	 * card's own script (when it exists, or is a URL).
	 *
	 * @param string               $card  Card name.
	 * @param array<string, mixed> $entry Registry entry.
	 */
	private static function enqueue( string $card, array $entry ): void {
		wp_enqueue_style( 'odoowoo' );
		wp_enqueue_script( 'odoowoo-core' );
		wp_enqueue_script( 'odoowoo-tasks' );
		wp_enqueue_script( 'odoowoo-tabs' );

		$script = isset( $entry['script'] ) ? (string) $entry['script'] : '';

		if ( '' === $script ) {
			return;
		}

		if ( preg_match( '#^https?://#', $script ) ) {
			$src = $script;
			$ver = ODOOWOO_PORTAL_VERSION;
		} elseif ( is_readable( ODOOWOO_PORTAL_PATH . ltrim( $script, '/' ) ) ) {
			$src = plugin_dir_url( ODOOWOO_PORTAL_FILE ) . ltrim( $script, '/' );
			$ver = self::asset_version( ltrim( $script, '/' ) );
		} else {
			return;
		}

		$requires = array();

		foreach ( isset( $entry['requires'] ) && is_array( $entry['requires'] ) ? $entry['requires'] : array() as $handle ) {
			if ( is_string( $handle ) && wp_script_is( $handle, 'registered' ) ) {
				$requires[] = $handle;
				wp_enqueue_script( $handle );
			}
		}

		wp_enqueue_script( 'odoowoo-card-' . $card, $src, array_merge( array( 'odoowoo-core', 'odoowoo-tasks' ), $requires ), $ver, array( 'in_footer' => true ) );
	}

	/**
	 * The card's body from its template; '' when it has none yet.
	 *
	 * @param array<string, mixed>                          $entry Registry entry.
	 * @param Context                                       $ctx   Context.
	 * @param array{license: int, dashboard: int, tab: string} $ids   Ids from the URL.
	 * @param array<string, mixed>                          $args  Extra values.
	 */
	private static function template( array $entry, Context $ctx, array $ids, array $args ): string {
		$template = isset( $entry['template'] ) ? (string) $entry['template'] : '';

		if ( '' === $template ) {
			return '';
		}

		$path = '/' === $template[0] ? $template : ODOOWOO_PORTAL_PATH . 'templates/' . $template;

		if ( ! is_readable( $path ) ) {
			return '';
		}

		ob_start();
		include $path;

		return (string) ob_get_clean();
	}

	/**
	 * Wraps a body in the shell template.
	 *
	 * @param array<string, mixed> $shell Title, notices, banner, body.
	 * @param array<string, string> $attrs Root attributes (escaped on output).
	 */
	private static function shell( array $shell, array $attrs ): string {
		$shell['attrs'] = $attrs;

		ob_start();
		include ODOOWOO_PORTAL_PATH . 'templates/cards/_shell.php';

		return (string) ob_get_clean();
	}

	/**
	 * The licensed product's page, or the shop.
	 */
	private static function product_url(): string {
		$product_id = Software::product_id( (string) Settings::get( 'software_slug' ) );
		$url        = $product_id ? get_permalink( $product_id ) : false;

		return is_string( $url ) && '' !== $url ? $url : wc_get_page_permalink( 'shop' );
	}
}
