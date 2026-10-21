<?php
/**
 * The cards.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Cards;

defined( 'ABSPATH' ) || exit;

/**
 * name => {title, template, script, keys, strings}. Tasks 9 to 13 fill `keys` (the browser
 * route keys of Api\Map the card calls) and `strings` (the card's JS strings, merged into
 * window.odoowooPortal.i18n); `template` and `script` are paths relative to the plugin's
 * templates/ and root folders (or an absolute template path and a script URL, for a card
 * added through the odoowoo_portal_cards filter).
 */
final class Registry {

	/**
	 * The cards, in the order the app lists them.
	 *
	 * @return array<string, array{title: string, template: string, script: string, keys: string[], strings: array<string, string>}>
	 */
	public static function cards(): array {
		$titles = array(
			'dashboard-list'           => __( 'Your dashboards', 'odoowoo-portal' ),
			'dashboard-header'         => __( 'Dashboard', 'odoowoo-portal' ),
			'woo-connection'           => __( 'WooCommerce connection', 'odoowoo-portal' ),
			'woo-settings'             => __( 'WooCommerce settings', 'odoowoo-portal' ),
			'odoo-connection'          => __( 'Odoo connection', 'odoowoo-portal' ),
			'odoo-settings'            => __( 'Odoo settings', 'odoowoo-portal' ),
			'payment-mappings'         => __( 'Payment method mappings', 'odoowoo-portal' ),
			'tax-mappings'             => __( 'Tax mappings', 'odoowoo-portal' ),
			'price-mappings'           => __( 'Price mappings', 'odoowoo-portal' ),
			'stock-mappings'           => __( 'Stock mappings', 'odoowoo-portal' ),
			'order-route-mappings'     => __( 'Order route mappings', 'odoowoo-portal' ),
			'order-pricelist-mappings' => __( 'Order pricelist mappings', 'odoowoo-portal' ),
			'gift-cards'               => __( 'Gift cards', 'odoowoo-portal' ),
			'manual-sync'              => __( 'Manual sync', 'odoowoo-portal' ),
			'sync-logs'                => __( 'Sync logs', 'odoowoo-portal' ),
		);

		$cards = array();

		foreach ( $titles as $name => $title ) {
			$cards[ $name ] = array(
				'title'    => $title,
				'template' => 'cards/' . $name . '.php',
				'script'   => 'assets/js/card-' . $name . '.js',
				'keys'     => array(),
				'strings'  => array(),
			);
		}

		$cards['dashboard-list']['keys']      = array( 'dashboard-list', 'dashboard-create', 'dashboard-pause', 'dashboard-delete' );
		$cards['dashboard-list']['strings']   = self::list_strings();
		$cards['dashboard-header']['keys']    = array( 'dashboard-detail', 'dashboard-rename' );
		$cards['dashboard-header']['strings'] = self::header_strings();
		$cards['woo-connection']['keys']      = array( 'woo-keys', 'verify-woocommerce', 'inject-woo-webhooks' );
		$cards['woo-connection']['strings']   = self::woo_connection_strings();
		$cards['woo-settings']['keys']        = array( 'woo-keys' );
		$cards['woo-settings']['strings']     = self::woo_settings_strings();
		$cards['odoo-connection']['keys']     = array( 'odoo-settings', 'verify-odoo', 'generate-api-key' );
		$cards['odoo-connection']['strings']  = self::odoo_connection_strings();

		/**
		 * Filters the cards (name => title, template, script, keys, strings).
		 *
		 * @param array $cards Cards.
		 */
		$filtered = apply_filters( 'odoowoo_portal_cards', $cards );

		return is_array( $filtered ) ? array_filter( $filtered, 'is_array' ) : $cards;
	}

	/**
	 * Is there a card with this name?
	 *
	 * @param string $name Card name.
	 */
	public static function has( string $name ): bool {
		return '' !== $name && array_key_exists( $name, self::cards() );
	}

	/**
	 * The dashboard list card's strings. %s and %1$s are filled in by the script.
	 *
	 * @return array<string, string>
	 */
	private static function list_strings(): array {
		return array(
			'loading'         => __( 'Loading your dashboards…', 'odoowoo-portal' ),
			'newDashboard'    => __( 'New dashboard', 'odoowoo-portal' ),
			'newTitle'        => __( 'New dashboard', 'odoowoo-portal' ),
			'nameLabel'       => __( 'Dashboard name', 'odoowoo-portal' ),
			'planLabel'       => __( 'Plan', 'odoowoo-portal' ),
			'create'          => __( 'Create', 'odoowoo-portal' ),
			'creating'        => __( 'Creating…', 'odoowoo-portal' ),
			'nameRequired'    => __( 'Give the dashboard a name.', 'odoowoo-portal' ),
			'open'            => __( 'Open', 'odoowoo-portal' ),
			'pause'           => __( 'Pause', 'odoowoo-portal' ),
			'enable'          => __( 'Enable', 'odoowoo-portal' ),
			'delete'          => __( 'Delete', 'odoowoo-portal' ),
			/* translators: %s: dashboard name. */
			'openNamed'       => __( 'Open %s', 'odoowoo-portal' ),
			/* translators: %s: dashboard name. */
			'pauseNamed'      => __( 'Pause %s', 'odoowoo-portal' ),
			/* translators: %s: dashboard name. */
			'enableNamed'     => __( 'Enable %s', 'odoowoo-portal' ),
			/* translators: %s: dashboard name. */
			'deleteNamed'     => __( 'Delete %s', 'odoowoo-portal' ),
			'active'          => __( 'Active', 'odoowoo-portal' ),
			'paused'          => __( 'Paused', 'odoowoo-portal' ),
			/* translators: %s: date. */
			'created'         => __( 'Created %s', 'odoowoo-portal' ),
			'deleteTitle'     => __( 'Delete dashboard', 'odoowoo-portal' ),
			/* translators: %s: dashboard name. */
			'deleteBody'      => __( 'Delete %s? This removes its settings and logs. Stores already connected stop syncing.', 'odoowoo-portal' ),
			'emptyList'       => __( 'You have no dashboards yet. Create one to connect a store.', 'odoowoo-portal' ),
			'noPlan'          => __( 'You do not have an OdooWoo plan yet.', 'odoowoo-portal' ),
			/* translators: %s: number of dashboards. */
			'count'           => __( '%s dashboards', 'odoowoo-portal' ),
			'countOne'        => __( '1 dashboard', 'odoowoo-portal' ),
			/* translators: 1: dashboards used, 2: dashboards the plan allows. */
			'countOf'         => __( '%1$s of %2$s dashboards', 'odoowoo-portal' ),
			/* translators: %s: dashboards used. */
			'countOfOne'      => __( '%s of 1 dashboard', 'odoowoo-portal' ),
			/* translators: %s: number of dashboards. */
			'limitReached'    => __( 'You have reached your plan limit of %s dashboards.', 'odoowoo-portal' ),
			'limitReachedOne' => __( 'You have reached your plan limit of 1 dashboard.', 'odoowoo-portal' ),
		);
	}

	/**
	 * The dashboard header card's strings (Cancel comes with the core strings).
	 *
	 * @return array<string, string>
	 */
	private static function header_strings(): array {
		return array(
			'loading'      => __( 'Loading…', 'odoowoo-portal' ),
			'rename'       => __( 'Rename', 'odoowoo-portal' ),
			'save'         => __( 'Save', 'odoowoo-portal' ),
			'nameLabel'    => __( 'Dashboard name', 'odoowoo-portal' ),
			'nameRequired' => __( 'Give the dashboard a name.', 'odoowoo-portal' ),
			'active'       => __( 'Active', 'odoowoo-portal' ),
			'paused'       => __( 'Paused', 'odoowoo-portal' ),
			'notFound'     => \OdooWooPortal\Django\Messages::not_found(),
			'headerError'  => __( 'Dashboard', 'odoowoo-portal' ),
			'retry'        => __( 'Try again', 'odoowoo-portal' ),
		);
	}

	/**
	 * The WooCommerce connection card's strings (labels from the old app's settings form).
	 *
	 * @return array<string, string>
	 */
	private static function woo_connection_strings(): array {
		return array(
			'storeUrl'       => __( 'Store URL', 'odoowoo-portal' ),
			'consumerKey'    => __( 'Consumer Key', 'odoowoo-portal' ),
			'consumerSecret' => __( 'Consumer Secret', 'odoowoo-portal' ),
			'show'           => __( 'Show', 'odoowoo-portal' ),
			'hide'           => __( 'Hide', 'odoowoo-portal' ),
			'verify'         => __( 'Verify connection', 'odoowoo-portal' ),
			'verifying'      => __( 'Verifying…', 'odoowoo-portal' ),
			'verifyHelp'     => __( 'Verify also saves the URL and keys.', 'odoowoo-portal' ),
			'save'           => __( 'Save', 'odoowoo-portal' ),
			'transfer'       => __( 'Transfer webhooks', 'odoowoo-portal' ),
			'transferring'   => __( 'Transferring…', 'odoowoo-portal' ),
			'transferHelp'   => __( 'Creates the order webhooks in your store. Save the keys first.', 'odoowoo-portal' ),
			'badgeSaved'     => __( 'Saved', 'odoowoo-portal' ),
			'badgeVerified'  => __( 'Verified', 'odoowoo-portal' ),
			'badgeWebhooks'  => __( 'Webhooks transferred', 'odoowoo-portal' ),
			'keyRequired'    => __( 'Enter the consumer key.', 'odoowoo-portal' ),
			'urlRequired'    => __( 'Enter the store URL.', 'odoowoo-portal' ),
			'secretRequired' => __( 'Enter the consumer secret.', 'odoowoo-portal' ),
			'verifiedOk'     => __( 'Connection verified.', 'odoowoo-portal' ),
		);
	}

	/**
	 * The WooCommerce settings card's strings.
	 *
	 * @return array<string, string>
	 */
	private static function woo_settings_strings(): array {
		return array(
			'enableStock'  => __( 'Update stock when orders sync', 'odoowoo-portal' ),
			'closeOrders'  => __( 'Close Woo orders when Odoo delivery completes', 'odoowoo-portal' ),
			'orderPrefix'  => __( 'Order prefix', 'odoowoo-portal' ),
			'prefixHelp'   => __( 'Two letters that start the order numbers of this dashboard. Each dashboard needs its own.', 'odoowoo-portal' ),
			'selectPrefix' => __( 'Select an order prefix', 'odoowoo-portal' ),
			/* translators: %s: the two-letter prefix. */
			'prefixTaken'  => __( '%s (already used by another of your dashboards)', 'odoowoo-portal' ),
			'save'         => __( 'Save', 'odoowoo-portal' ),
			'saveWooFirst' => __( 'Save your WooCommerce connection first.', 'odoowoo-portal' ),
		);
	}

	/**
	 * The Odoo connection card's strings.
	 *
	 * @return array<string, string>
	 */
	private static function odoo_connection_strings(): array {
		return array(
			'odooUrl'              => __( 'Odoo URL', 'odoowoo-portal' ),
			'database'             => __( 'Database', 'odoowoo-portal' ),
			'user'                 => __( 'User', 'odoowoo-portal' ),
			'password'             => __( 'Password / API Token', 'odoowoo-portal' ),
			'show'                 => __( 'Show', 'odoowoo-portal' ),
			'hide'                 => __( 'Hide', 'odoowoo-portal' ),
			'verify'               => __( 'Verify connection', 'odoowoo-portal' ),
			'verifying'            => __( 'Verifying…', 'odoowoo-portal' ),
			'verifyHelp'           => __( 'Verify checks the login and whether the OdooWoo Helper module is installed. It does not save.', 'odoowoo-portal' ),
			'save'                 => __( 'Save', 'odoowoo-portal' ),
			'setup'                => __( 'Set up Odoo callbacks', 'odoowoo-portal' ),
			'settingUp'            => __( 'Setting up…', 'odoowoo-portal' ),
			'callbackHelp'         => __( 'Lets Odoo tell OdooWoo about changes. Save the connection first.', 'odoowoo-portal' ),
			'callbackSet'          => __( 'Callback key is set', 'odoowoo-portal' ),
			'callbackNone'         => __( 'No callback key yet', 'odoowoo-portal' ),
			'callbackConfirmTitle' => __( 'Set up Odoo callbacks', 'odoowoo-portal' ),
			'callbackConfirmBody'  => __( 'This replaces the current callback key.', 'odoowoo-portal' ),
			'callbackConfirm'      => __( 'Replace key', 'odoowoo-portal' ),
			'callbackDone'         => __( 'Odoo callbacks are set up.', 'odoowoo-portal' ),
			'urlRequired'          => __( 'Enter the Odoo URL.', 'odoowoo-portal' ),
			'dbRequired'           => __( 'Enter the database.', 'odoowoo-portal' ),
			'userRequired'         => __( 'Enter the user.', 'odoowoo-portal' ),
			'passwordRequired'     => __( 'Enter the password or API token.', 'odoowoo-portal' ),
			'verifiedOk'           => __( 'Connection verified.', 'odoowoo-portal' ),
			'helperInstalled'      => __( 'Odoo connection verified. The OdooWoo Helper module is installed.', 'odoowoo-portal' ),
			'helperMissing'        => __( 'Odoo connection verified, but the OdooWoo Helper module is not installed.', 'odoowoo-portal' ),
		);
	}
}
