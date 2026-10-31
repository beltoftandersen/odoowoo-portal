<?php
/**
 * Wiring.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks every part of the plugin into WordPress, once its dependencies are present.
 */
final class Plugin {

	/**
	 * Whether the last boot registered the plugin's parts.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Whether this request was marked as not cacheable.
	 *
	 * @var bool
	 */
	private static $uncacheable = false;

	/**
	 * Runs on plugins_loaded, priority 25.
	 */
	public static function boot(): void {
		self::$booted = false;

		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

		if ( ! self::requirements_met() ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_missing' ) );
			return;
		}

		remove_action( 'admin_notices', array( __CLASS__, 'notice_missing' ) );

		Settings::register();

		Support::register();

		Api\Routes::register();

		Tasks::register();

		Account\OpenLink::register();
		Account\Area::register();

		Emails\ReadyDetails::register();

		Cards\Shortcode::register();
		add_action( 'wp_enqueue_scripts', array( Cards\Renderer::class, 'register_assets' ) );
		add_action( 'wp_footer', array( Cards\Renderer::class, 'attach_boot_data' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ) );
		add_action( 'template_redirect', array( __CLASS__, 'nocache_app_pages' ), 5 );

		Bricks\DynamicData::register();
		Bricks\Conditions::register();
		// After Bricks' own init-10 registration, which loads the element base class.
		add_action( 'init', array( Bricks\Elements::class, 'register' ), 11 );

		// Later tasks register their parts here.
		self::$booted = true;
	}

	/**
	 * Loads the translations shipped in /languages (the plugin is not on wordpress.org, so
	 * WordPress does not look there by itself).
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'odoowoo-portal', false, dirname( plugin_basename( ODOOWOO_PORTAL_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- not hosted on wordpress.org, so WordPress does not load /languages by itself.
	}

	/**
	 * Marks the page as not cacheable: it carries the REST nonce and the viewer's own data.
	 * Safe to call more than once, and after output has started (the headers are then skipped by
	 * WordPress itself, the constant still reaches page caches).
	 */
	public static function mark_uncacheable(): void {
		self::$uncacheable = true;

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant page caches look for.
		}

		nocache_headers();
	}

	/**
	 * Whether this request was marked as not cacheable (tests).
	 *
	 * @param bool $reset Forget the mark after reading it.
	 */
	public static function marked_uncacheable( bool $reset = false ): bool {
		$marked = self::$uncacheable;

		if ( $reset ) {
			self::$uncacheable = false;
		}

		return $marked;
	}

	/**
	 * Before any output: the list and dashboard pages are never cached for someone who may use the app.
	 */
	public static function nocache_app_pages(): void {
		$user_id = get_current_user_id();
		$pages   = array_filter( array( (int) Settings::get( 'list_page_id' ), (int) Settings::get( 'dashboard_page_id' ) ) );

		if ( $user_id <= 0 || array() === $pages || ! is_page( $pages ) ) {
			return;
		}

		if ( array() !== Context::licenses_for_user( $user_id ) || current_user_can( 'manage_woocommerce' ) ) {
			self::mark_uncacheable();
		}
	}

	/**
	 * A support user arriving through the "View as support" link gets the session; Support
	 * checks the flag, the per-license nonce and the capability.
	 */
	public static function template_redirect(): void {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified inside arrived().
		$query = wp_unslash( $_GET );

		if ( ! Support::arrived( $query, $user_id ) ) {
			return;
		}

		// A reload must not log another visit: carry on without the flag and the nonce.
		global $wp;

		unset( $query[ Support::FLAG ], $query['_wpnonce'] );

		$target = add_query_arg( urlencode_deep( $query ), home_url( isset( $wp->request ) ? (string) $wp->request : '' ) );

		if ( wp_safe_redirect( $target, 303 ) ) {
			exit;
		}
	}

	/**
	 * Whether the plugin's parts were registered by the last boot.
	 */
	public static function booted(): bool {
		return self::$booted;
	}

	/**
	 * Whether every dependency is present.
	 */
	public static function requirements_met(): bool {
		return array() === self::missing();
	}

	/**
	 * The dependencies: slug => [label, present, version constant value or '', minimum].
	 *
	 * @return array<string, array{label: string, present: bool, version: string, minimum: string}>
	 */
	public static function dependencies(): array {
		$dependencies = array(
			'woocommerce'      => array(
				'label'   => 'WooCommerce',
				'present' => class_exists( 'WooCommerce' ),
				'version' => '',
				'minimum' => '',
			),
			'beltoft-licenses' => array(
				'label'   => 'beltoft-licenses',
				'present' => defined( 'BELTOFT_LICENSES_VERSION' ),
				'version' => defined( 'BELTOFT_LICENSES_VERSION' ) ? (string) BELTOFT_LICENSES_VERSION : '',
				'minimum' => '0.7.0',
			),
			'beltoft-services' => array(
				'label'   => 'beltoft-services',
				'present' => defined( 'BELTOFT_SERVICES_VERSION' ),
				'version' => defined( 'BELTOFT_SERVICES_VERSION' ) ? (string) BELTOFT_SERVICES_VERSION : '',
				'minimum' => '0.1.1',
			),
		);

		/**
		 * Filters the dependencies, with their minimum versions.
		 *
		 * @param array $dependencies Slug => label, present, version, minimum.
		 */
		$filtered = apply_filters( 'odoowoo_portal_dependencies', $dependencies );

		return is_array( $filtered ) ? $filtered : $dependencies;
	}

	/**
	 * Names of the missing or too old dependencies, with the minimum version where one applies.
	 *
	 * @return string[]
	 */
	public static function missing(): array {
		$missing = array();

		foreach ( self::dependencies() as $slug => $dependency ) {
			$minimum = (string) ( $dependency['minimum'] ?? '' );
			$ok      = ! empty( $dependency['present'] )
				&& ( '' === $minimum || version_compare( (string) ( $dependency['version'] ?? '' ), $minimum, '>=' ) );

			if ( ! $ok ) {
				$missing[] = (string) ( $dependency['label'] ?? $slug );
			}
		}

		return $missing;
	}

	/**
	 * Tells admins what is missing.
	 */
	public static function notice_missing(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$lines = array();

		foreach ( self::dependencies() as $slug => $dependency ) {
			$label   = (string) ( $dependency['label'] ?? $slug );
			$minimum = (string) ( $dependency['minimum'] ?? '' );
			$found   = (string) ( $dependency['version'] ?? '' );

			if ( ! in_array( $label, self::missing(), true ) ) {
				continue;
			}

			if ( ! empty( $dependency['present'] ) ) {
				/* translators: 1: plugin name, 2: minimum version, 3: installed version. */
				$lines[] = sprintf( __( 'Update %1$s to %2$s or newer (installed: %3$s).', 'odoowoo-portal' ), $label, $minimum, '' === $found ? '?' : $found );
			} elseif ( '' !== $minimum ) {
				/* translators: 1: plugin name, 2: minimum version. */
				$lines[] = sprintf( __( 'Activate %1$s %2$s or newer.', 'odoowoo-portal' ), $label, $minimum );
			} else {
				/* translators: %s: plugin name. */
				$lines[] = sprintf( __( 'Activate %s.', 'odoowoo-portal' ), $label );
			}
		}

		printf(
			'<div class="notice notice-error"><p>%s %s</p></div>',
			esc_html__( 'OdooWoo Portal is inactive.', 'odoowoo-portal' ),
			esc_html( implode( ' ', $lines ) )
		);
	}
}
