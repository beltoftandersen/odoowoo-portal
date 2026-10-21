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
	 * Runs on plugins_loaded, priority 25.
	 */
	public static function boot(): void {
		self::$booted = false;

		if ( ! self::requirements_met() ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_missing' ) );
			return;
		}

		remove_action( 'admin_notices', array( __CLASS__, 'notice_missing' ) );

		Settings::register();

		Support::register();

		Api\Routes::register();

		Tasks::register();

		Cards\Shortcode::register();
		add_action( 'wp_enqueue_scripts', array( Cards\Renderer::class, 'register_assets' ) );
		add_action( 'wp_footer', array( Cards\Renderer::class, 'attach_boot_data' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ) );

		// Later tasks register their parts here.
		self::$booted = true;
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
		Support::arrived( wp_unslash( $_GET ), $user_id );
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
