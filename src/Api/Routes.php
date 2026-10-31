<?php
/**
 * The browser's REST routes: guard, dashboards, proxy.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Api;

use BeltoftServices\RateLimit;
use OdooWooPortal\Account\Area;
use OdooWooPortal\Account\Dashboards;
use OdooWooPortal\Context;
use OdooWooPortal\Django\Client;
use OdooWooPortal\Django\Messages;
use OdooWooPortal\Django\Reply;
use OdooWooPortal\Prefixes;
use OdooWooPortal\Tasks;
use OdooWooPortal\Urls;

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

		register_rest_route(
			self::NAMESPACE,
			'/licenses/(?P<license>\d+)/dashboards/(?P<dashboard>\d+)/sync/active',
			array(
				'methods'  => \WP_REST_Server::ALLMETHODS,
				'callback' => array( __CLASS__, 'sync_active' ),
				'args'     => $dash,
			) + $common
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
	 * @param callable|null    $validate  Runs after the access checks and before the rate limit: gets the Context, returns a refusal or null. Input is checked here so a refused input never uses a slot.
	 * @return Context|\WP_REST_Response
	 */
	public static function guard( \WP_REST_Request $r, string $route_key, ?callable $validate = null ) {
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

		if ( '' !== self::url_param( $r, 'task' ) && empty( Map::ROUTES[ $route_key ]['task'] ) ) {
			return self::refusal( 400, 'odoowoo_portal_bad_request', __( 'This request is not valid.', 'odoowoo-portal' ) );
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

		// A GET loader that writes Django rows (cache refresh): owner only, also while paused; never support.
		if ( ! empty( $row['refreshes'] ) && 'owner' !== $ctx->mode() ) {
			return self::refusal( 403, 'odoowoo_portal_read_only', $ctx->notice() );
		}

		if ( null !== $validate ) {
			$refusal = $validate( $ctx );

			if ( $refusal instanceof \WP_REST_Response ) {
				return $refusal;
			}
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
	 * @param Reply  $r      Reply.
	 * @param string $key    Map row, when its replies are shaped.
	 * @param string $method Browser method.
	 */
	public static function respond( Reply $r, string $key = '', string $method = '' ): \WP_REST_Response {
		if ( $r->ok() ) {
			if ( '' !== $key && Map::shapes( $key, $method ) ) {
				// A 204 means "nothing": Django may still send a {message} body with it (views.py:2556).
				$shaped = 204 === $r->status() ? array( 'items' => array() ) : Map::shape( $key, $method, $r->data() );

				if ( null === $shaped ) {
					return self::refusal( 502, 'server_error', Messages::generic() );
				}

				return new \WP_REST_Response(
					array(
						'ok'   => true,
						'data' => $shaped,
					),
					200
				);
			}

			return new \WP_REST_Response(
				array(
					'ok'   => true,
					'data' => array() === $r->data() && 204 === $r->status() ? new \stdClass() : ( '' === $key ? $r->data() : Map::read_only_fields( $key, $method, $r->data() ) ),
				),
				200
			);
		}

		$error = Messages::from_reply( $r );

		if ( 'dashboard_not_found' === $error['code'] || ( 404 === $r->status() && ! $r->customer_facing() ) ) {
			return self::refusal( 404, 'dashboard_not_found', Messages::not_found() );
		}

		// 401 and 403 mean "session expired" to the browser: Django's never do.
		$status = 502;

		if ( $r->customer_facing() && $r->status() >= 400 && $r->status() < 600 ) {
			$status = in_array( $r->status(), array( 401, 403 ), true ) ? 409 : $r->status();
		}

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
		$body   = null;
		$task   = self::url_param( $r, 'task' );
		$target = Map::target( $key, $r->get_method(), (int) self::url_param( $r, 'dashboard' ) );

		// Everything about the input is decided before the rate limiter is touched.
		$validate = static function ( Context $ctx ) use ( $r, $key, $with_body, $task, &$body, $target ) {
			if ( null === $target ) {
				return self::refusal( 405, 'odoowoo_portal_bad_request', __( 'This request is not valid.', 'odoowoo-portal' ) );
			}

			if ( 'task-status' === $key && ! Tasks::owns( get_current_user_id(), (int) $ctx->license()['id'], (int) self::url_param( $r, 'dashboard' ), $task ) ) {
				return self::refusal( 404, 'odoowoo_portal_task_unknown', __( 'This sync was not found. It may have finished more than a day ago.', 'odoowoo-portal' ) );
			}

			if ( $with_body && 'GET' !== $target[0] && 'DELETE' !== $target[0] ) {
				try {
					$body = self::body( $r, $key );
				} catch ( InputRefused $e ) {
					return new \WP_REST_Response(
						array(
							'ok'      => false,
							'code'    => 'invalid_value',
							'message' => $e->getMessage(),
							'field'   => $e->field(),
						),
						400
					);
				}

				if ( 'PATCH' === $target[0] && array() === $body ) {
					return self::refusal( 400, 'odoowoo_portal_bad_request', __( 'There is nothing to change.', 'odoowoo-portal' ) );
				}

				// Django replaces a whole list or object on a save: never send a partial one.
				$json    = $r->get_json_params();
				$missing = Map::incomplete( $key, $r->get_method(), is_array( $json ) ? $json : array(), $body );

				if ( null !== $missing ) {
					return new \WP_REST_Response(
						array(
							'ok'      => false,
							'code'    => 'invalid_value',
							'message' => __( 'This save is incomplete, so nothing was changed. Reload the page and try again.', 'odoowoo-portal' ),
							'field'   => $missing,
						),
						400
					);
				}
			}

			return null;
		};

		$ctx = self::guard( $r, $key, $validate );

		if ( $ctx instanceof \WP_REST_Response ) {
			return $ctx;
		}

		if ( '' !== $suffix && empty( Map::ROUTES[ $key ]['task'] ) ) {
			return self::refusal( 400, 'odoowoo_portal_bad_request', __( 'This request is not valid.', 'odoowoo-portal' ) );
		}

		$reply = Client::request( $ctx, $target[0], $target[1] . $suffix, $body );

		if ( 'GET' !== $target[0] ) {
			unset( self::$summaries[ self::summary_key( $ctx ) ] );

			// Only what changes a dashboard's prefix or the number of dashboards makes the cached map stale.
			if ( in_array( $key, array( 'woo-keys', 'dashboard-create', 'dashboard-delete' ), true ) ) {
				Prefixes::forget( get_current_user_id() );
			}
		}

		$dashboard = (int) self::url_param( $r, 'dashboard' );

		if ( isset( Map::ROUTES[ $key ]['kind'] ) ) {
			return self::started( $reply, $ctx, $dashboard, (string) Map::ROUTES[ $key ]['kind'], $key );
		}

		if ( 'task-status' === $key ) {
			return self::task_response( $reply, $ctx, $dashboard, $task );
		}

		$response = self::respond( $reply, $key, $r->get_method() );

		if ( 'woo-keys' === $key && 'GET' === $r->get_method() && $reply->ok() ) {
			$data = $response->get_data();

			$status = Prefixes::status( get_current_user_id(), $dashboard, (int) $ctx->license()['id'] );
			$own    = isset( $data['data']['prefix'] ) && is_string( $data['data']['prefix'] ) ? strtoupper( $data['data']['prefix'] ) : '';
			$used   = $status['used'];

			// Django's own list covers every dashboard of this tenant, also when the portal's read was partial.
			foreach ( isset( $data['data']['used_prefixes'] ) && is_array( $data['data']['used_prefixes'] ) ? $data['data']['used_prefixes'] : array() as $prefix ) {
				if ( is_string( $prefix ) && '' !== $prefix && strtoupper( $prefix ) !== $own ) {
					$used[] = $prefix;
				}
			}

			$used = array_values( array_unique( $used ) );

			sort( $used );

			$data['data']['used_prefixes']         = $used;
			$data['data']['used_prefixes_partial'] = $status['partial'];
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * The answer to a sync trigger: remembered only when Django accepted it (202) with a task id.
	 *
	 * @param Reply   $reply     Reply.
	 * @param Context $ctx       Context.
	 * @param int     $dashboard Dashboard.
	 * @param string  $kind      Sync kind.
	 * @param string  $key       Row.
	 */
	private static function started( Reply $reply, Context $ctx, int $dashboard, string $kind, string $key ): \WP_REST_Response {
		if ( ! $reply->ok() ) {
			return self::respond( $reply, $key, 'POST' );
		}

		$data    = $reply->data();
		$task_id = isset( $data['task_id'] ) && is_string( $data['task_id'] ) ? $data['task_id'] : '';

		if ( 202 !== $reply->status() || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $task_id ) ) {
			Messages::log( sprintf( 'sync %s answered %d without a usable task id', $key, $reply->status() ) );

			return new \WP_REST_Response( array( 'ok' => false ) + Messages::from_reply( Reply::unavailable( 'no task id' ) ), 502 );
		}

		Tasks::remember( get_current_user_id(), (int) $ctx->license()['id'], $dashboard, $kind, $task_id );

		return new \WP_REST_Response(
			array(
				'ok'   => true,
				'data' => array(
					'task_id' => $task_id,
					'kind'    => $kind,
				),
			),
			200
		);
	}

	/**
	 * The answer to a task status read. The task is forgotten once it is over (or Django has
	 * lost it); a failed read keeps it.
	 *
	 * @param Reply   $reply     Reply.
	 * @param Context $ctx       Context.
	 * @param int     $dashboard Dashboard.
	 * @param string  $task_id   Task.
	 */
	private static function task_response( Reply $reply, Context $ctx, int $dashboard, string $task_id ): \WP_REST_Response {
		$license = (int) $ctx->license()['id'];

		if ( ! $reply->ok() ) {
			if ( 404 === $reply->status() && 'task_not_found' === $reply->code() ) {
				Tasks::forget( get_current_user_id(), $license, $dashboard, $task_id );
			}

			return self::respond( $reply, 'task-status', 'GET' );
		}

		$view = self::task_view( $reply );

		if ( 'running' !== $view['state'] ) {
			Tasks::forget( get_current_user_id(), $license, $dashboard, $task_id );
		}

		return new \WP_REST_Response(
			array(
				'ok'   => true,
				'data' => $view,
			),
			200
		);
	}

	/**
	 * Django's task reply as the browser sees it. State is Django's; anything unknown is failed.
	 * Exception text is never passed on: failed items get a fixed message.
	 *
	 * @param Reply $r Reply.
	 * @return array{task_id: string, state: string, label: string, current: int, total: int, message: string, results: array<int, array<string, mixed>>}
	 */
	public static function task_view( Reply $r ): array {
		$d        = $r->data();
		$state    = isset( $d['state'] ) && is_string( $d['state'] ) ? $d['state'] : '';
		$known    = in_array( $state, array( 'running', 'done', 'skipped', 'failed' ), true );
		$progress = isset( $d['progress'] ) && is_array( $d['progress'] ) ? $d['progress'] : array();
		$number   = static function ( string $name ) use ( $d, $progress ): int {
			$value = isset( $d[ $name ] ) && is_numeric( $d[ $name ] ) ? $d[ $name ] : ( $progress[ $name ] ?? 0 );

			return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
		};

		if ( ! $known ) {
			$state = 'failed';
		}

		if ( 'running' === $state ) {
			$label = __( 'Running', 'odoowoo-portal' );
		} elseif ( 'done' === $state ) {
			$label = __( 'Completed', 'odoowoo-portal' );
		} elseif ( 'skipped' === $state ) {
			$label = __( 'Skipped', 'odoowoo-portal' );
		} else {
			$label = __( 'Failed', 'odoowoo-portal' );
		}

		$message = $known && isset( $d['message'] ) && is_string( $d['message'] ) ? $d['message'] : '';

		if ( ! $known ) {
			$message = __( 'The sync failed. See the sync log for details.', 'odoowoo-portal' );
		}

		return array(
			'task_id' => isset( $d['task_id'] ) && is_string( $d['task_id'] ) ? $d['task_id'] : '',
			'state'   => $state,
			'label'   => $label,
			'current' => $number( 'current' ),
			'total'   => $number( 'total' ),
			'message' => $message,
			'results' => self::task_results( $d['results'] ?? array() ),
		);
	}

	/**
	 * The per-item results: what identifies the item and its outcome. A failed item gets a fixed
	 * message, never Django's error text.
	 *
	 * @param mixed $results Django results.
	 * @return array<int, array<string, mixed>>
	 */
	private static function task_results( $results ): array {
		$kept = array( 'order', 'product_name', 'product_id', 'sku', 'type', 'status', 'reason' );
		$out  = array();

		foreach ( is_array( $results ) ? $results : array() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$row = array();

			foreach ( $entry as $name => $value ) {
				if ( in_array( $name, $kept, true ) && ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) ) {
					$row[ $name ] = $value;
				}
			}

			if ( 'error' === strtolower( (string) ( $entry['status'] ?? '' ) ) ) {
				unset( $row['reason'] );
				$row['message'] = __( 'This item was not synced. See the sync log for details.', 'odoowoo-portal' );
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * GET .../sync/active: the task this user started for the dashboard, from memory only.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public static function sync_active( \WP_REST_Request $r ): \WP_REST_Response {
		$ctx = self::guard( $r, 'task-status' );

		if ( $ctx instanceof \WP_REST_Response ) {
			return $ctx;
		}

		return new \WP_REST_Response(
			array(
				'ok'   => true,
				'data' => array( 'task' => Tasks::active( get_current_user_id(), (int) $ctx->license()['id'], (int) self::url_param( $r, 'dashboard' ) ) ),
			),
			200
		);
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
	 * PATCH: the gift card tab flag, pause or enable when the body is about "enabled" alone, otherwise rename.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public static function patch( \WP_REST_Request $r ): \WP_REST_Response {
		$json = $r->get_json_params();
		$json = is_array( $json ) ? $json : array();

		if ( array_key_exists( 'show_gift_cards', $json ) && ! array_key_exists( 'name', $json ) ) {
			return self::call( $r, 'dashboard-gift-tab' );
		}

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
	 * Django's dashboard rows cut to what the app shows (DashboardListView: id, name, enabled, created_at).
	 *
	 * @param array<mixed> $rows Django list.
	 * @return array<int, array<string, mixed>>
	 */
	private static function dashboard_rows( array $rows ): array {
		$out = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array_intersect_key( $row, array_flip( array( 'id', 'name', 'enabled', 'created_at' ) ) );
			}
		}

		return $out;
	}

	/**
	 * Adds each dashboard's address inside My Account when the app lives there (and remembers the slugs on the way).
	 * Nothing is added for a support viewer or on a site without the My Account area: the page then builds the address.
	 *
	 * @param Context                          $ctx  Context.
	 * @param array<int, array<string, mixed>> $rows Dashboard rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function with_addresses( Context $ctx, array $rows ): array {
		$license_id = (int) $ctx->license()['id'];

		if ( ! Area::enabled( $license_id ) ) {
			return $rows;
		}

		Dashboards::sync( $ctx, $rows );

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['url'] = Urls::dashboard( $license_id, (int) $row['id'], 'connections' );
		}

		return $rows;
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

		if ( Area::enabled( (int) $license['id'] ) ) {
			// Inside My Account the plan reads as the dashboard words it ("OdooWoo Pro, billed monthly").
			$entry['plan_label'] = Area::plan_label( (string) $entry['plan'] );
		}

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
				$entry['dashboards'] = self::dashboard_rows( $list->data() );
				$entry['dashboards'] = self::with_addresses( $ctx, $entry['dashboards'] );
			} else {
				$entry['error'] = Messages::from_reply( $list )['message'];
			}
		}

		self::$summaries[ $key ] = $entry;

		return $entry;
	}
}
