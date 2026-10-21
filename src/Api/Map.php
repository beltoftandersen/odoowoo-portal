<?php
/**
 * The browser-to-Django route table.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Api;

defined( 'ABSPATH' ) || exit;

/**
 * One row per Django call the browser may cause. Django ids are the dashboard only; the
 * tenant is the signature, never part of a path or body.
 */
final class Map {

	/**
	 * Rate limit buckets: calls per minute and user.
	 */
	public const LIMITS = array(
		'verify' => 10,
		'sync'   => 10,
		'create' => 5,
	);

	/**
	 * Fixed dashboard routes (dealt with by their own REST routes, not the generic dispatcher).
	 */
	public const FIXED = array( 'dashboard-list', 'dashboard-create', 'dashboard-detail', 'dashboard-rename', 'dashboard-pause', 'dashboard-delete' );

	/**
	 * Rows: key => methods (browser method => [django method, django path, {d} is the dashboard id]),
	 * fields (body keys allowed), read_only_ok, limit (bucket or null).
	 */
	public const ROUTES = array(
		'dashboard-list'      => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/' ) ),
			'fields'       => array(),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'dashboard-create'    => array(
			'methods'      => array( 'POST' => array( 'POST', 'dashboards/create/' ) ),
			'fields'       => array( 'name' ),
			'read_only_ok' => false,
			'limit'        => 'create',
		),
		'dashboard-detail'    => array(
			'methods'      => array( 'GET' => array( 'GET', 'dashboards/{d}/' ) ),
			'fields'       => array(),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'dashboard-rename'    => array(
			'methods'      => array( 'PATCH' => array( 'PATCH', 'dashboards/{d}/' ) ),
			'fields'       => array( 'name' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'dashboard-pause'     => array(
			'methods'      => array( 'PATCH' => array( 'PATCH', 'dashboards/{d}/' ) ),
			'fields'       => array( 'enabled' ),
			'read_only_ok' => false,
			'limit'        => null,
		),
		'dashboard-delete'    => array(
			'methods'      => array( 'DELETE' => array( 'DELETE', 'dashboards/{d}/delete/' ) ),
			'fields'       => array(),
			'read_only_ok' => true,
			'limit'        => null,
		),
		'verify-woocommerce'  => array(
			'methods'      => array( 'POST' => array( 'POST', 'dashboards/{d}/verify-woocommerce/' ) ),
			'fields'       => array( 'consumer_key', 'consumer_secret', 'woocommerce_url' ),
			'read_only_ok' => true,
			'limit'        => 'verify',
		),
		'verify-odoo'         => array(
			'methods'      => array( 'POST' => array( 'POST', 'dashboards/{d}/verify-odoo/' ) ),
			'fields'       => array( 'odoo_url', 'odoo_db', 'odoo_user', 'odoo_password' ),
			'read_only_ok' => true,
			'limit'        => 'verify',
		),
	);

	/**
	 * Keys the generic dispatcher routes (everything but the fixed dashboard rows).
	 *
	 * @return string[]
	 */
	public static function generic_keys(): array {
		return array_values( array_diff( array_keys( self::ROUTES ), self::FIXED ) );
	}

	/**
	 * The Django method and path of a row for a browser method, or null.
	 *
	 * @param string $key       Row.
	 * @param string $method    Browser method.
	 * @param int    $dashboard Dashboard id.
	 * @return array{0: string, 1: string}|null
	 */
	public static function target( string $key, string $method, int $dashboard = 0 ): ?array {
		$row = self::ROUTES[ $key ]['methods'][ strtoupper( $method ) ] ?? null;

		if ( null === $row ) {
			return null;
		}

		return array( $row[0], str_replace( '{d}', (string) $dashboard, $row[1] ) );
	}

	/**
	 * Is the call a write? Derived: any Django method but GET.
	 *
	 * @param string $key    Row.
	 * @param string $method Browser method.
	 */
	public static function is_write( string $key, string $method ): bool {
		$target = self::target( $key, $method );

		return null === $target || 'GET' !== $target[0];
	}

	/**
	 * Keeps the allowed body keys; strings are trimmed.
	 *
	 * @param string       $key  Row.
	 * @param array<mixed> $body Raw body.
	 * @return array<string, mixed>
	 */
	public static function filter_body( string $key, array $body ): array {
		$out = array();

		foreach ( self::ROUTES[ $key ]['fields'] ?? array() as $field ) {
			if ( ! array_key_exists( $field, $body ) ) {
				continue;
			}

			$value = $body[ $field ];

			if ( 'enabled' === $field ) {
				$value = is_bool( $value ) ? $value : filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

				if ( null === $value ) {
					continue;
				}
			} elseif ( is_string( $value ) ) {
				$value = trim( $value );
			} elseif ( ! is_scalar( $value ) ) {
				continue;
			}

			$out[ $field ] = $value;
		}

		return $out;
	}
}
