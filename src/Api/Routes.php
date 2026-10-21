<?php
/**
 * The browser's REST routes: guard, dashboards, proxy.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Api;

use BeltoftServices\RateLimit;
use OdooWooPortal\Context;
use OdooWooPortal\Django\Client;
use OdooWooPortal\Django\Messages;
use OdooWooPortal\Django\Reply;

defined( 'ABSPATH' ) || exit;

/**
 * Every route is logged-in only, names a license, and is checked against the user's
 * ownership before Django is called. The browser gets {ok, data} or {ok:false, code, message}.
 */
final class Routes {

	public const NAMESPACE = 'odoowoo-portal/v1';

	/**
	 * Summaries of this request, by "license:mode".
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $summaries = array();

	/**
	 * Hooks the routes.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * A parameter of the route's URL. Never get_param(): that lets a JSON or query value of the
	 * same name override the URL's.
	 *
	 * @param \WP_REST_Request $r    Request.
	 * @param string           $name Parameter.
	 */
	private static function url_param( \WP_REST_Request $r, string $name ): string {
		$params = $r->get_url_params();

		return isset( $params[ $name ] ) && is_scalar( $params[ $name ] ) ? (string) $params[ $name ] : '';
	}

	/**
	 * Forgets memoized summaries (tests, after writes).
	 */
	public static function reset(): void {
		self::$summaries = array();
	}

	/**
	 * Registers the REST routes.
	 */
	public static function routes(): void {
		$common = array( 'permission_callback' => array( __CLASS__, 'permission' ) );
		$lic    = array(
			'license' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
		$dash   = $lic + array(
			'dashboard' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/licenses',
			array(
				'methods'  => 'GET',
				'callback' => array( __CLASS__, 'licenses' ),
			) + $common
		);

		register_rest_route(
			self::NAMESPACE,
			'/licenses/(?P<license>\d+)/dashboards',
			array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, 'create' ),
				'args'     => $lic,
			) + $common
		);

		register_rest_route(
			self::NAMESPACE,
			'/licenses/(?P<license>\d+)/dashboards/(?P<dashboard>\d+)',
			array(
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'detail' ),
					'args'     => $dash,
				) + $common,
				array(
					'methods'  => 'PATCH',
					'callback' => array( __CLASS__, 'patch' ),
					'args'     => $dash,
				) + $common,
				array(
					'methods'  => 'DELETE',
					'callback' => array( __CLASS__, 'delete' ),
					'args'     => $dash,
				) + $common,
			)
		);

		$keys = Map::generic_keys();

		if ( array() !== $keys ) {
			register_rest_route(
				self::NAMESPACE,
				'/licenses/(?P<license>\d+)/dashboards/(?P<dashboard>\d+)/(?P<route>' . implode( '|', array_map( 'preg_quote', $keys ) ) . ')(?:/(?P<task>[A-Za-z0-9_-]+))?',
				array(
					'methods'  => \WP_REST_Server::ALLMETHODS,
					'callback' => array( __CLASS__, 'dispatch' ),
					'args'     => $dash,
				) + $common
			);
		}
	}

	/**
	 * Logged-in only; 401 otherwise. (The cookie nonce is checked by WordPress itself.)
	 *
	 * @return true|\WP_Error
	 */
	public static function permission() {
		if ( is_user_logged_in() ) {
			return true;
		}

		return new \WP_Error( 'odoowoo_portal_login', __( 'Please log in again.', 'odoowoo-portal' ), array( 'status' => 401 ) );
	}

	/**
	 * The guard. Returns the Context, or the refusal to send.
	 *
	 * @param \WP_REST_Request $r         Request.
	 * @param string           $route_key Map row.
	 * @return Context|\WP_REST_Response
	 */
	public static function guard( \WP_REST_Request $r, string $route_key ) {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return self::refusal( 401, 'odoowoo_portal_login', __( 'Please log in again.', 'odoowoo-portal' ) );
		}

		$license_id = (int) self::url_param( $r, 'license' );

		if ( $license_id <= 0 || ! isset( Map::ROUTES[ $route_key ] ) ) {
			return self::refusal( 400, 'odoowoo_portal_bad_request', __( 'This request is not valid.', 'odoowoo-portal' ) );
		}

		$url_params = $r->get_url_params();

		if ( isset( $url_params['dashboard'] ) && (int) $url_params['dashboard'] <= 0 ) {
			return self::refusal( 400, 'odoowoo_portal_bad_request', __( 'This request is not valid.', 'odoowoo-portal' ) );
		}

		if ( null === Map::target( $route_key, $r->get_method() ) ) {
			return self::refusal( 405, 'odoowoo_portal_bad_method', __( 'This request is not valid.', 'odoowoo-portal' ) );
		}

		$ctx = Context::for_user( $user_id, $license_id );

		if ( null === $ctx ) {
			return self::refusal( 403, 'odoowoo_portal_forbidden', __( 'You do not have access to this license.', 'odoowoo-portal' ) );
		}

		if ( ! $ctx->calls_allowed() ) {
			return self::refusal( 409, 'odoowoo_portal_getting_ready', $ctx->notice() );
		}

		$row = Map::ROUTES[ $route_key ];

		if ( Map::is_write( $route_key, $r->get_method() ) && ! $ctx->can_write() && ! ( $row['read_only_ok'] && 'owner' === $ctx->mode() ) ) {
			return self::refusal( 403, 'odoowoo_portal_read_only', $ctx->notice() );
		}

		$bucket = $row['limit'];

		if ( null !== $bucket ) {
			$limit = (int) apply_filters( 'odoowoo_portal_rate_limit', Map::LIMITS[ $bucket ] ?? 0, $bucket );

			if ( RateLimit::exceeded( $user_id, 'odoowoo-portal:' . $bucket, $limit ) ) {
				return self::refusal( 429, 'odoowoo_portal_rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'odoowoo-portal' ) );
			}
		}

		return $ctx;
	}

	/**
	 * A refusal in the browser shape.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private static function refusal( int $status, string $code, string $message ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'      => false,
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}

	/**
	 * A Django reply in the browser shape. Failed replies never carry Django's data.
	 *
	 * @param Reply $r Reply.
	 */
	public static function respond( Reply $r ): \WP_REST_Response {
		if ( $r->ok() ) {
			return new \WP_REST_Response(
				array(
					'ok'   => true,
					'data' => array() === $r->data() ? new \stdClass() : $r->data(),
				),
				200
			);
		}

		$error = Messages::from_reply( $r );

		if ( 'dashboard_not_found' === $error['code'] || ( 404 === $r->status() && ! $r->customer_facing() ) ) {
			return self::refusal( 404, 'dashboard_not_found', Messages::not_found() );
		}

		// 401 and 403 mean "session expired" to the browser: Django's never do.
		$status = $r->customer_facing() && $r->status() >= 400 && $r->status() < 600 && ! in_array( $r->status(), array( 401, 403 ), true ) ? $r->status() : 502;

		return new \WP_REST_Response( array( 'ok' => false ) + $error, $status );
	}

	/**
	 * The request body, filtered by a row's fields.
	 *
	 * @param \WP_REST_Request $r   Request.
	 * @param string           $key Row.
	 * @return array<string, mixed>
	 */
	private static function body( \WP_REST_Request $r, string $key ): array {
		$json = $r->get_json_params();

		return Map::filter_body( $key, is_array( $json ) ? $json : array() );
	}

	/**
	 * Guards, then calls Django for a row.
	 *
	 * @param \WP_REST_Request $r         Request.
	 * @param string           $key       Row.
	 * @param string           $suffix    Appended to the path (a task id), or ''.
	 * @param bool             $with_body Send the filtered body.
	 */
	private static function call( \WP_REST_Request $r, string $key, string $suffix = '', bool $with_body = true ): \WP_REST_Response {
		$ctx = self::guard( $r, $key );

		if ( $ctx instanceof \WP_REST_Response ) {
			return $ctx;
		}

		$target = Map::target( $key, $r->get_method(), (int) self::url_param( $r, 'dashboard' ) );

		if ( null === $target ) {
			return self::refusal( 405, 'odoowoo_portal_bad_request', __( 'This request is not valid.', 'odoowoo-portal' ) );
		}

		$body = null;

		if ( $with_body && 'GET' !== $target[0] && 'DELETE' !== $target[0] ) {
			$body = self::body( $r, $key );

			if ( 'PATCH' === $target[0] && array() === $body ) {
				return self::refusal( 400, 'odoowoo_portal_bad_request', __( 'There is nothing to change.', 'odoowoo-portal' ) );
			}
		}

		if ( '' !== $suffix && empty( Map::ROUTES[ $key ]['task'] ) ) {
			return self::refusal( 400, 'odoowoo_portal_bad_request', __( 'This request is not valid.', 'odoowoo-portal' ) );
		}

		$reply = Client::request( $ctx, $target[0], $target[1] . $suffix, $body );

		if ( 'GET' !== $target[0] ) {
			unset( self::$summaries[ self::summary_key( $ctx ) ] );
		}

		return self::respond( $reply );
	}

	/**
	 * GET /licenses.
	 */
	public static function licenses(): \WP_REST_Response {
		$out = array();

		foreach ( Context::licenses_for_user( get_current_user_id() ) as $license ) {
			$ctx = Context::for_user( get_current_user_id(), (int) $license['id'] );

			if ( $ctx && 'owner' === $ctx->mode() ) {
				$out[] = self::license_summary( $ctx );
			}
		}

		return new \WP_REST_Response(
			array(
				'ok'   => true,
				'data' => $out,
			),
			200
		);
	}

	/**
	 * POST /licenses/{license}/dashboards.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public static function create( \WP_REST_Request $r ): \WP_REST_Response {
		return self::call( $r, 'dashboard-create' );
	}

	/**
	 * GET a dashboard.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public static function detail( \WP_REST_Request $r ): \WP_REST_Response {
		return self::call( $r, 'dashboard-detail' );
	}

	/**
	 * PATCH: pause or enable when the body is about "enabled" alone, otherwise rename.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public static function patch( \WP_REST_Request $r ): \WP_REST_Response {
		$json = $r->get_json_params();
		$json = is_array( $json ) ? $json : array();

		return self::call( $r, array_key_exists( 'enabled', $json ) && ! array_key_exists( 'name', $json ) ? 'dashboard-pause' : 'dashboard-rename' );
	}

	/**
	 * DELETE a dashboard.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public static function delete( \WP_REST_Request $r ): \WP_REST_Response {
		$response = self::call( $r, 'dashboard-delete' );
		$data     = $response->get_data();

		if ( is_array( $data ) && ! empty( $data['ok'] ) ) {
			/**
			 * A dashboard was deleted in Django: forget what is remembered about it.
			 *
			 * @param int $license_id   License id.
			 * @param int $dashboard_id Dashboard id.
			 */
			do_action( 'odoowoo_portal_dashboard_deleted', (int) self::url_param( $r, 'license' ), (int) self::url_param( $r, 'dashboard' ) );
		}

		return $response;
	}

	/**
	 * The generic dispatcher: the row is the route name.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public static function dispatch( \WP_REST_Request $r ): \WP_REST_Response {
		$key  = (string) self::url_param( $r, 'route' );
		$task = (string) self::url_param( $r, 'task' );

		if ( ! in_array( $key, Map::generic_keys(), true ) ) {
			return self::refusal( 404, 'odoowoo_portal_bad_request', __( 'This request is not valid.', 'odoowoo-portal' ) );
		}

		return self::call( $r, $key, '' !== $task ? rawurlencode( $task ) . '/' : '' );
	}

	/**
	 * Memo key of a context.
	 *
	 * @param Context $ctx Context.
	 */
	private static function summary_key( Context $ctx ): string {
		return $ctx->license()['id'] . ':' . $ctx->mode();
	}

	/**
	 * The license entry for the app: usage and dashboards. One tenant read and one dashboards
	 * read per request, none while the license is not ready.
	 *
	 * @param Context $ctx Context.
	 * @return array<string, mixed>
	 */
	public static function license_summary( Context $ctx ): array {
		$key = self::summary_key( $ctx );

		if ( isset( self::$summaries[ $key ] ) ) {
			return self::$summaries[ $key ];
		}

		$license = $ctx->license();
		$product = wc_get_product( (int) ( $license['variation_id'] ?: $license['product_id'] ) );
		$entry   = array(
			'id'         => (int) $license['id'],
			'plan'       => $product ? $product->get_name() : '',
			'state'      => $ctx->state(),
			'mode'       => $ctx->mode(),
			'notice'     => $ctx->notice(),
			'limit'      => null,
			'used'       => null,
			'brand_sync' => false,
			'can_write'  => $ctx->can_write(),
			'dashboards' => array(),
		);

		if ( $ctx->calls_allowed() && in_array( $entry['state'], array( 'ready', 'paused' ), true ) ) {
			$tenant = Client::request( $ctx, 'GET', 'tenants/' . rawurlencode( $ctx->tenant() ) . '/' );

			if ( ! $tenant->ok() ) {
				$entry['error'] = Messages::from_reply( $tenant )['message'];
			} else {
				$t                   = $tenant->data();
				$entry['limit']      = isset( $t['limit'] ) && is_numeric( $t['limit'] ) ? (int) $t['limit'] : null;
				$entry['used']       = isset( $t['used'] ) && is_numeric( $t['used'] ) ? (int) $t['used'] : null;
				$entry['brand_sync'] = isset( $t['attributes']['allow_brand_sync'] ) && filter_var( $t['attributes']['allow_brand_sync'], FILTER_VALIDATE_BOOLEAN );
			}

			$list = Client::request( $ctx, 'GET', 'dashboards/' );

			if ( $list->ok() ) {
				$entry['dashboards'] = array_values( $list->data() );
			} else {
				$entry['error'] = Messages::from_reply( $list )['message'];
			}
		}

		self::$summaries[ $key ] = $entry;

		return $entry;
	}
}
