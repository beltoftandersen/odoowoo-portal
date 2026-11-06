<?php
/**
 * The cards.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Cards;

defined( 'ABSPATH' ) || exit;

/**
 * name => {title, template, script, strings}. Tasks 9 to 13 fill `strings` (the card's JS strings, merged into
 * window.odoowooPortal.i18n); `template` and `script` are paths relative to the plugin's
 * templates/ and root folders (or an absolute template path and a script URL, for a card
 * added through the odoowoo_portal_cards filter).
 */
final class Registry {

	/**
	 * The cards, in the order the app lists them.
	 *
	 * @return array<string, array{title: string, template: string, script: string, strings: array<string, string>}>
	 */
	public static function cards(): array {
		$titles = array(
			'dashboard-list'           => __( 'Your dashboards', 'odoowoo-portal' ),
			'dashboard-header'         => __( 'Dashboard', 'odoowoo-portal' ),
			'woo-connection'           => __( 'WooCommerce connection', 'odoowoo-portal' ),
			'woo-settings'             => __( 'WooCommerce settings', 'odoowoo-portal' ),
			'odoo-connection'          => __( 'Odoo connection', 'odoowoo-portal' ),
			'dashboard-settings'       => __( 'Dashboard settings', 'odoowoo-portal' ),
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
				'strings'  => array(),
			);
		}

		$cards['dashboard-list']['strings']   = self::list_strings();
		$cards['dashboard-header']['strings'] = self::header_strings();
		$cards['woo-connection']['strings']   = self::woo_connection_strings();
		$cards['woo-settings']['strings']     = self::woo_settings_strings();
		$cards['odoo-connection']['strings']  = self::odoo_connection_strings();
		$cards['dashboard-settings']['strings'] = self::dashboard_settings_strings();
		$cards['odoo-settings']['strings']    = self::odoo_settings_strings();
		$cards['odoo-settings']['requires']   = array( 'odoowoo-deps' );

		$cards['gift-cards']['strings']       = self::gift_cards_strings();
		$cards['manual-sync']['strings']      = self::manual_sync_strings();
		$cards['sync-logs']['strings']        = self::sync_logs_strings();

		foreach ( Mappings::CARDS as $mapping_card ) {
			$cards[ $mapping_card ]['script']   = 'assets/js/card-mappings.js';
			$cards[ $mapping_card ]['requires'] = array( 'odoowoo-mapping-editor' );
			$cards[ $mapping_card ]['strings']  = Mappings::strings();
		}

		/**
		 * Filters the cards (name => title, template, script, strings).
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
	 * The dashboard settings card's strings.
	 *
	 * @return array<string, string>
	 */
	private static function dashboard_settings_strings(): array {
		return array(
			'enableGiftCards' => __( 'Enable gift card handling', 'odoowoo-portal' ),
			'giftCardsHelp'   => __( 'Needed for countries with multiple VAT rates, such as PT. Shows the Gift cards tab.', 'odoowoo-portal' ),
			'save'            => __( 'Save', 'odoowoo-portal' ),
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
			'saveFirst'      => __( 'Save your changes first.', 'odoowoo-portal' ),
			'transferHelp'   => __( 'Creates the order webhooks in your store. Save the keys first.', 'odoowoo-portal' ),
			'badgeSaved'     => __( 'Saved', 'odoowoo-portal' ),
			'badgeVerified'  => __( 'Verified', 'odoowoo-portal' ),
			'wooVerified'    => __( 'WooCommerce connection verified.', 'odoowoo-portal' ),
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
			'prefixesPartial' => __( 'Other licenses could not be checked. A prefix that is already taken will be refused when you save.', 'odoowoo-portal' ),
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
			'callbackConfirmBody'  => __( 'This replaces the current callback key. Changes you have not saved are not used.', 'odoowoo-portal' ),
			'saveFirst'            => __( 'Save your changes first.', 'odoowoo-portal' ),
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

	/**
	 * The Odoo settings card's strings: one label and one help text per setting (the help text of
	 * the old app where it had one), the dependency reasons and the picker's.
	 *
	 * @return array<string, string>
	 */
	private static function odoo_settings_strings(): array {
		return array(
			'label_enable_auto_payment' => __( 'Enable auto payments on invoices', 'odoowoo-portal' ),
			'label_enable_auto_payment_cn' => __( 'Enable auto payments on credit notes', 'odoowoo-portal' ),
			'label_enable_set_invoice_status' => __( 'Set invoice status back to to_invoice', 'odoowoo-portal' ),
			'label_enable_reset_all_deliveries' => __( 'Reset delivery back to draft', 'odoowoo-portal' ),
			'label_enable_set_deliveries_to_waiting' => __( 'Set deliveries to waiting', 'odoowoo-portal' ),
			'label_enable_auto_complete_deliveries' => __( 'Close deliveries when an order is paid', 'odoowoo-portal' ),
			'label_confirm_pending_orders' => __( 'Confirm pending orders automatically', 'odoowoo-portal' ),
			'label_price_includes_tax' => __( 'Add tax on product price during sync', 'odoowoo-portal' ),
			'label_order_incl_tax' => __( 'Sync orders with prices incl. tax', 'odoowoo-portal' ),
			'label_enable_brand_sync' => __( 'Sync brands to Odoo', 'odoowoo-portal' ),
			'label_enable_category_sync' => __( 'Sync categories to Odoo', 'odoowoo-portal' ),
			'label_enable_product_name_sync' => __( 'Sync product name to Odoo', 'odoowoo-portal' ),
			'label_enable_tag_sync' => __( 'Sync tags to Odoo', 'odoowoo-portal' ),
			'label_enable_image_sync' => __( 'Sync product images to Odoo', 'odoowoo-portal' ),
			'label_enable_price_sync' => __( 'Let Odoo control prices in WooCommerce', 'odoowoo-portal' ),
			'label_enable_barcode_sync' => __( 'Let Odoo control barcodes in WooCommerce', 'odoowoo-portal' ),
			'label_capture_stage' => __( 'Capture order at status', 'odoowoo-portal' ),
			'label_quantity_settings' => __( 'Quantity setting', 'odoowoo-portal' ),
			'label_odoo_order_pricelist_id' => __( 'Pricelist for orders from this store', 'odoowoo-portal' ),
			'label_stock_sku_meta_keys' => __( 'Line meta key naming the stock product', 'odoowoo-portal' ),
			'label_stock_units_meta_keys' => __( 'Line meta key naming the units per item', 'odoowoo-portal' ),
			'help_enable_auto_payment' => __( 'Registers a payment on the Odoo invoice of each paid order, using your payment method mappings. Needs payment method mappings.', 'odoowoo-portal' ),
			'help_enable_auto_payment_cn' => __( 'Registers a payment on the Odoo credit note of each refund, using your payment method mappings. Needs payment method mappings.', 'odoowoo-portal' ),
			'help_enable_set_invoice_status' => __( 'After issuing a refund/credit note, Odoo reopens the sale order\'s invoice state to \'To Invoice\', allowing a fresh invoice to be created.', 'odoowoo-portal' ),
			'help_enable_reset_all_deliveries' => __( 'After processing a refund/credit note, Odoo moves related delivery orders back to draft so warehouse staff can reprocess them.', 'odoowoo-portal' ),
			'help_enable_set_deliveries_to_waiting' => __( 'New Odoo orders from WooCommerce will immediately push their delivery orders to \'Waiting\' state during confirmation.', 'odoowoo-portal' ),
			'help_enable_auto_complete_deliveries' => __( 'For a site that hands goods over at the till. Once payment is registered, Odoo validates the order\'s deliveries. A delivery that cannot be fully reserved is left open for someone to handle, and the WooCommerce order is not marked complete.', 'odoowoo-portal' ),
			'help_confirm_pending_orders' => __( 'Applies when orders are captured at Pending. Confirms such orders in Odoo automatically.', 'odoowoo-portal' ),
			'help_price_includes_tax' => __( 'Enable if WooCommerce prices exclude tax but your Odoo tax settings are \'Included in Price\', so synced product prices match what customers pay. Needs tax mappings.', 'odoowoo-portal' ),
			'help_order_incl_tax' => __( 'Enable if your Odoo tax settings are set to \'Included in Price\'.', 'odoowoo-portal' ),
			'help_enable_brand_sync' => __( 'Sends product brands from WooCommerce to Odoo when products sync. Brand sync is part of some plans only.', 'odoowoo-portal' ),
			'help_enable_category_sync' => __( 'Sends product categories from WooCommerce to Odoo when products sync.', 'odoowoo-portal' ),
			'help_enable_product_name_sync' => __( 'Sends the product name from WooCommerce to Odoo when products sync.', 'odoowoo-portal' ),
			'help_enable_tag_sync' => __( 'Sends product tags from WooCommerce to Odoo when products sync.', 'odoowoo-portal' ),
			'help_enable_image_sync' => __( 'Sends product images from WooCommerce to Odoo when products sync.', 'odoowoo-portal' ),
			'help_enable_price_sync' => __( 'Odoo becomes the source of truth for price. WooCommerce prices are overwritten from the price mappings, and product syncs stop writing WooCommerce prices back into Odoo. Needs at least one price mapping.', 'odoowoo-portal' ),
			'help_enable_barcode_sync' => __( 'Odoo becomes the source of truth for barcodes. Use Sync barcodes to fill WooCommerce from Odoo. Product syncs stop writing barcodes back into Odoo, which also stops them clearing a barcode that only exists in Odoo.', 'odoowoo-portal' ),
			'help_capture_stage' => __( 'The WooCommerce order status at which an order is sent to Odoo.', 'odoowoo-portal' ),
			'help_quantity_settings' => __( 'Which Odoo quantity is used when stock is written to WooCommerce: on hand, forecast or available.', 'odoowoo-portal' ),
			'help_odoo_order_pricelist_id' => __( 'A pricelist belongs to the sale, not the customer. The same customer can buy retail on one store and wholesale on another, and Odoo can only hold one pricelist per customer. It does not change what is charged, since the prices come from WooCommerce.', 'odoowoo-portal' ),
			'help_stock_sku_meta_keys' => __( 'For a line that carries no SKU of its own, such as a subscription. Leave empty and such lines are skipped as before. Several keys can be given, separated by commas, and are tried in order.', 'odoowoo-portal' ),
			'help_stock_units_meta_keys' => __( 'How many of that product one ordered item consumes, so shared stock is reduced correctly. The money on the line is unchanged. Empty, or a value that cannot be read, books one per item.', 'odoowoo-portal' ),
			'needsPayment' => __( 'Needs payment method mappings.', 'odoowoo-portal' ),
			'needsTax' => __( 'Needs tax mappings.', 'odoowoo-portal' ),
			'needsPrice' => __( 'Needs a price mapping.', 'odoowoo-portal' ),
			'needsPlan' => __( 'Brand sync is not part of your plan.', 'odoowoo-portal' ),
			'optPending' => __( 'Pending', 'odoowoo-portal' ),
			'optOnHold' => __( 'On hold', 'odoowoo-portal' ),
			'optProcessing' => __( 'Processing', 'odoowoo-portal' ),
			'optCompleted' => __( 'Completed', 'odoowoo-portal' ),
			'optOnHand' => __( 'On hand', 'odoowoo-portal' ),
			'optForecast' => __( 'Forecast', 'odoowoo-portal' ),
			'optAvailable' => __( 'Available', 'odoowoo-portal' ),
			'letOdooDecide' => __( 'Let Odoo decide from the customer', 'odoowoo-portal' ),
			'loadPricelists' => __( 'Load pricelists', 'odoowoo-portal' ),
			'loadingPricelists' => __( 'Loading…', 'odoowoo-portal' ),
			'pricelistsLoaded' => __( 'Pricelists loaded.', 'odoowoo-portal' ),
			'stillLoading' => __( 'The settings are still loading. Try again in a moment.', 'odoowoo-portal' ),
			'saveOdooFirst' => __( 'Save your Odoo connection first.', 'odoowoo-portal' ),
			'save' => __( 'Save', 'odoowoo-portal' ),
			/* translators: %s: the name of a setting. */
			'helpFor' => __( 'Help: %s', 'odoowoo-portal' ),
		);
	}

	/**
	 * The gift cards card's strings (labels and help are in its template).
	 *
	 * @return array<string, string>
	 */
	private static function gift_cards_strings(): array {
		return array(
			'selectTax'       => __( 'Select a sale tax', 'odoowoo-portal' ),
			'selectJournal'   => __( 'Select a journal', 'odoowoo-portal' ),
			'selectAccount'   => __( 'Select an account', 'odoowoo-portal' ),
			'loadFirst'       => __( 'Load taxes, journals and accounts first', 'odoowoo-portal' ),
			/* translators: %s: the Odoo id of a tax. */
			'taxFallback'     => __( 'Tax %s', 'odoowoo-portal' ),
			/* translators: %s: the Odoo id of a journal. */
			'journalFallback' => __( 'Journal %s', 'odoowoo-portal' ),
			/* translators: %s: the Odoo id of an account. */
			'accountFallback' => __( 'Account %s', 'odoowoo-portal' ),
			'loadingLists'    => __( 'Loading…', 'odoowoo-portal' ),
			'listsLoaded'     => __( 'Taxes, journals and accounts loaded.', 'odoowoo-portal' ),
		);
	}

	/**
	 * The manual sync card's strings (labels and help are in its template). The input messages
	 * are the ones Api\Map gives for the same inputs, so a refusal reads the same either way.
	 *
	 * @return array<string, string>
	 */
	private static function manual_sync_strings(): array {
		return array(
			'syncing'         => __( 'Syncing…', 'odoowoo-portal' ),
			/* translators: %s: what is syncing, for example Products. */
			'running'         => __( '%s sync running…', 'odoowoo-portal' ),
			'kindBrands'      => __( 'Brands', 'odoowoo-portal' ),
			'kindCategories'  => __( 'Categories', 'odoowoo-portal' ),
			'kindProducts'    => __( 'Products', 'odoowoo-portal' ),
			'kindStock'       => __( 'Stock', 'odoowoo-portal' ),
			'kindPrices'      => __( 'Prices', 'odoowoo-portal' ),
			'kindBarcodes'    => __( 'Barcodes', 'odoowoo-portal' ),
			'kindOrders'      => __( 'Orders', 'odoowoo-portal' ),
			'brandsNotInPlan' => __( 'Brand sync is not part of your plan.', 'odoowoo-portal' ),
			'backToList'      => __( 'Back to your dashboards', 'odoowoo-portal' ),
			'noValidId'       => __( 'Enter at least one valid id, or leave the field empty.', 'odoowoo-portal' ),
			'tooManyIds'      => __( 'At most 500 ids at a time.', 'odoowoo-portal' ),
			'nothingChosen'   => __( 'Enter order ids, or choose a start and end date.', 'odoowoo-portal' ),
			'badDates'        => __( 'Enter a valid start and end date.', 'odoowoo-portal' ),
			'reversedDates'   => __( 'The start date is after the end date.', 'odoowoo-portal' ),
			'itemsHeading'    => __( 'Items that were not synced', 'odoowoo-portal' ),
			'resultLost'      => __( 'We could not follow this sync any more. See the sync log for what happened.', 'odoowoo-portal' ),
		);
	}

	/**
	 * The sync logs card's strings.
	 *
	 * @return array<string, string>
	 */
	private static function sync_logs_strings(): array {
		return array(
			'loading'       => __( 'Loading logs…', 'odoowoo-portal' ),
			'empty'         => __( 'No sync logs yet.', 'odoowoo-portal' ),
			'refresh'       => __( 'Refresh', 'odoowoo-portal' ),
			'refreshed'     => __( 'Refreshed', 'odoowoo-portal' ),
			/* translators: %s: the WooCommerce order reference. */
			'wooRef'        => __( 'WooCommerce %s', 'odoowoo-portal' ),
			/* translators: %s: the Odoo order reference. */
			'odooRef'       => __( 'Odoo %s', 'odoowoo-portal' ),
			'odooNone'      => __( 'Odoo N/A', 'odoowoo-portal' ),
			'logLabel'      => __( 'Log', 'odoowoo-portal' ),
			'itemFailed'    => __( 'This item was not synced.', 'odoowoo-portal' ),
			'unknownStatus' => __( 'Unknown', 'odoowoo-portal' ),
		);
	}
}
