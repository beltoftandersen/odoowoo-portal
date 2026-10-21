<?php
/**
 * The gift cards, manual sync and sync log cards: markup, strings, and the signed calls their
 * scripts make through the real routes. Behaviour (bodies, resume, rows) is in tests/js.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Cards\Registry;
use OdooWooPortal\Cards\Renderer;
use OdooWooPortal\Context;
use OdooWooPortal\Support;
use OdooWooPortal\Tasks;

/**
 * Task 13.
 */
final class CardSyncTest extends RestTestCase {

	private const D = 7;

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
	 * Renders a card for a license state; owner unless $support.
	 *
	 * @param string $card    Card.
	 * @param string $state   Resource state.
	 * @param bool   $support Render as a support viewer.
	 */
	private function render( string $card, string $state = 'ready', bool $support = false ): string {
		$license = $this->odoowoo_license( array(), $state );
		Context::reset();

		if ( $support ) {
			$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
			get_userdata( $admin )->add_cap( 'manage_woocommerce' );
			$this->as_user( $admin );
			$query = array( 'license' => (string) $license['id'], 'odoowoo_support' => '1', '_wpnonce' => wp_create_nonce( 'odoowoo_support_' . $license['id'] ) );
			$this->assertTrue( Support::arrived( $query, $admin ) );
		} else {
			$this->as_user( (int) $license['user_id'] );
		}

		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => (string) self::D,
		);

		$html = Renderer::render( $card );
		$this->assertSame( array(), $this->django_calls(), 'Rendering makes no Django call.' );

		return $html;
	}

	/**
	 * The opening tag of the one element matching a marker.
	 *
	 * @param string $html   Markup.
	 * @param string $marker Attribute text.
	 * @param string $tag    Tag name.
	 */
	private function tag( string $html, string $marker, string $tag = 'button' ): string {
		$this->assertSame( 1, preg_match_all( '#<' . $tag . '\b[^>]*' . preg_quote( $marker, '#' ) . '[^>]*>#', $html, $m ), $marker );

		return $m[0][0];
	}

	private function url( array $l, string $route ): string {
		return '/licenses/' . $l['id'] . '/dashboards/' . self::D . '/' . $route;
	}

	private function owner(): array {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );

		return $l;
	}

	public function test_odoowoo_mistake_running_task_resumed_after_reload(): void {
		$html = $this->render( 'manual-sync' );

		$this->assertMatchesRegularExpression( '#<div[^>]*class="odoowoo-sync"[^>]*data-resume="sync/active"#', $html, 'The card says which route it resumes from.' );

		// The route exists and answers from memory only: nothing running, then the remembered task.
		$l = $this->owner();
		Tasks::forget( (int) $l['user_id'], (int) $l['id'], self::D, 'tk-1' );
		$res = $this->rest( 'GET', $this->url( $l, 'sync/active' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertNull( $res->get_data()['data']['task'] );

		Tasks::remember( (int) $l['user_id'], (int) $l['id'], self::D, 'products', 'tk-1' );
		$res = $this->rest( 'GET', $this->url( $l, 'sync/active' ) );
		$this->assertSame( 'tk-1', $res->get_data()['data']['task']['task_id'] );
		$this->assertSame( 'products', $res->get_data()['data']['task']['kind'] );
		$this->assertSame( array(), $this->django_calls(), 'sync/active never asks Django.' );

		// The script asks sync/active before anything else it sends.
		$js = (string) file_get_contents( ODOOWOO_PORTAL_PATH . 'assets/js/card-manual-sync.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( "'sync/active'", $js );
		$this->assertLessThan( strpos( $js, 'task-status' ), strpos( $js, "'sync/active'" ), 'The active read comes before any status read.' );
	}

	public function test_manual_sync_sections_and_plan_gating(): void {
		$html = $this->render( 'manual-sync' );

		$this->assertStringContainsString( 'data-odoowoo-card="manual-sync"', $html );

		foreach ( array( 'brands', 'categories', 'products', 'orders' ) as $section ) {
			$this->tag( $html, 'data-section="' . $section . '"', 'section' );
		}

		foreach ( array( 'brands', 'categories', 'products', 'stock', 'prices', 'barcodes', 'orders' ) as $kind ) {
			$button = $this->tag( $html, 'data-kind="' . $kind . '"' );
			$this->assertStringContainsString( 'type="button"', $button, $kind );
			$this->assertStringNotContainsString( ' disabled', $button, $kind . ': an owner of a ready license may sync.' );
		}

		foreach ( array( 'Sync brands', 'Sync categories', 'Sync products', 'Sync stock', 'Sync prices', 'Sync barcodes', 'Sync orders' ) as $label ) {
			$this->assertMatchesRegularExpression( '#<button[^>]*data-kind[^>]*>\s*' . preg_quote( $label, '#' ) . '\s*</button>#', $html, $label );
		}

		// The brand section has its notice, hidden until the script knows the plan lacks brand sync.
		$this->assertMatchesRegularExpression( '#<p[^>]*data-brand-notice[^>]*\bhidden\b[^>]*>\s*Brand sync is not part of your plan\.\s*</p>#', $html );
		$this->assertSame( 'Brand sync is not part of your plan.', Registry::cards()['manual-sync']['strings']['brandsNotInPlan'] );

		// The way back to the list for a paused dashboard is a same-site URL on the card.
		$this->assertMatchesRegularExpression( '#data-list-url="[^"]+"#', $html );
		$this->assertStringContainsString( 'data-can-write="1"', $html );
		$this->assertTrue( wp_script_is( 'odoowoo-card-manual-sync', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'odoowoo-tasks', 'enqueued' ), 'The poller is loaded.' );
	}

	public function test_manual_sync_is_off_when_the_viewer_may_not_write(): void {
		foreach ( array( 'paused owner' => array( 'paused', false ), 'support' => array( 'ready', true ), 'getting ready' => array( 'provisioning', false ) ) as $label => list( $state, $support ) ) {
			Renderer::reset();
			$html = $this->render( 'manual-sync', $state, $support );

			$this->assertStringContainsString( 'data-can-write="0"', $html, $label );

			foreach ( array( 'brands', 'categories', 'products', 'stock', 'prices', 'barcodes', 'orders' ) as $kind ) {
				$this->assertStringContainsString( ' disabled', $this->tag( $html, 'data-kind="' . $kind . '"' ), $label . ' ' . $kind );
			}
		}

		// The notice the shell shows says why.
		Renderer::reset();
		$this->assertStringContainsString( 'odoowoo-notice', $this->render( 'manual-sync', 'paused' ) );
	}

	public function test_id_inputs_are_labelled_with_format_hint(): void {
		$html = $this->render( 'manual-sync' );

		$input = $this->tag( $html, 'name="product_ids"', 'input' );
		$this->assertStringContainsString( 'placeholder="Example: 123, 456, 789"', $input );
		$this->assertMatchesRegularExpression( '#\bid="([^"]+)"#', $input );
		preg_match( '#\bid="([^"]+)"#', $input, $id );
		$this->assertMatchesRegularExpression( '#<label[^>]*\bfor="' . preg_quote( $id[1], '#' ) . '"[^>]*>\s*Product ids, separated by commas\s*</label>#', $html );
		$this->assertStringContainsString( 'Leave empty to sync all products.', $html );

		$input = $this->tag( $html, 'name="order_ids"', 'input' );
		preg_match( '#\bid="([^"]+)"#', $input, $id );
		$this->assertMatchesRegularExpression( '#<label[^>]*\bfor="' . preg_quote( $id[1], '#' ) . '"[^>]*>\s*Order ids, separated by commas\s*</label>#', $html );
		$this->assertStringContainsString( 'placeholder="Example: 1051, 1052"', $input );

		foreach ( array( 'from' => 'From', 'to' => 'To' ) as $name => $label ) {
			$date = $this->tag( $html, 'name="' . $name . '"', 'input' );
			$this->assertStringContainsString( 'type="date"', $date );
			preg_match( '#\bid="([^"]+)"#', $date, $id );
			$this->assertMatchesRegularExpression( '#<label[^>]*\bfor="' . preg_quote( $id[1], '#' ) . '"[^>]*>\s*' . $label . '\s*</label>#', $html, $label );
		}

		$this->assertStringContainsString( 'Or choose a start and end date. Order ids win when both are given.', $html );
	}

	public function test_result_line_has_text_for_every_state(): void {
		$html = $this->render( 'manual-sync' );

		// One polite live region carries the progress and the result.
		$this->assertMatchesRegularExpression( '#<div[^>]*class="odoowoo-sync-result"[^>]*aria-live="polite"#', $html );

		// Every refusal place has an id (the input points at it) and the dates' one says which input it is for.
		foreach ( array( 'product_ids', 'order_ids', 'from_date' ) as $field ) {
			$this->assertMatchesRegularExpression( '#<p[^>]*\bid="[^"]+"[^>]*data-error-for="' . $field . '"#', $html, $field );
		}

		$this->assertMatchesRegularExpression( '#data-error-for="from_date"[^>]*data-input="from"#', $html );
		$this->assertMatchesRegularExpression( '#<div[^>]*class="odoowoo-sync-result"[^>]*role="status"#', $html );

		$core = Renderer::core_strings();
		$this->assertSame( 'Running', $core['taskRunning'] );
		$this->assertSame( 'Completed', $core['taskCompleted'] );
		$this->assertSame( 'Skipped', $core['taskSkipped'] );
		$this->assertSame( 'Failed', $core['taskFailed'] );

		$strings = Registry::cards()['manual-sync']['strings'];

		foreach ( array( 'kindProducts', 'kindOrders', 'running', 'syncing', 'brandsNotInPlan', 'backToList', 'noValidId', 'tooManyIds', 'nothingChosen', 'badDates', 'reversedDates', 'itemsHeading', 'resultLost' ) as $key ) {
			$this->assertArrayHasKey( $key, $strings, $key );
			$this->assertNotSame( '', $strings[ $key ], $key );
		}
	}

	public function test_sync_logs_statuses_have_text_and_expandable_log(): void {
		$html = $this->render( 'sync-logs' );

		$this->assertStringContainsString( 'data-odoowoo-card="sync-logs"', $html );
		$this->assertMatchesRegularExpression( '#<button[^>]*data-action="refresh"[^>]*>\s*Refresh\s*</button>#', $html );
		$this->assertMatchesRegularExpression( '#<ul[^>]*class="odoowoo-log-list"#', $html );
		$this->assertMatchesRegularExpression( '#class="odoowoo-log-status"[^>]*role="status"#', $html, 'Loading and empty states are announced.' );

		$strings = Registry::cards()['sync-logs']['strings'];

		foreach ( array( 'loading', 'empty', 'refresh', 'refreshed', 'odooNone', 'odooRef', 'logLabel', 'itemFailed', 'unknownStatus' ) as $key ) {
			$this->assertArrayHasKey( $key, $strings, $key );
			$this->assertNotSame( '', $strings[ $key ], $key );
		}

		$this->assertSame( 'This item was not synced.', $strings['itemFailed'] );

		// The script builds <details> and writes text only.
		$js = (string) file_get_contents( ODOOWOO_PORTAL_PATH . 'assets/js/card-sync-logs.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( "createElement( 'details' )", $js );
		$this->assertStringNotContainsString( 'innerHTML', $js );
		$this->assertStringContainsString( 'textContent', $js );

		// The badge words come from the real route for every status Django and Odoo write.
		$l = $this->owner();
		$this->fake_django(
			fn() => array(
				200,
				array(
					array( 'woocommerce_order_id' => 'WS_1', 'odoo_order_id' => '1', 'status' => 'success', 'synced_at' => '2026-10-21T10:00:00Z', 'log' => '' ),
					array( 'woocommerce_order_id' => 'WS_2', 'odoo_order_id' => null, 'status' => 'error', 'synced_at' => '2026-10-21T10:00:00Z', 'log' => 'x' ),
					array( 'woocommerce_order_id' => 'WS_3', 'odoo_order_id' => '3', 'status' => 'failed', 'synced_at' => '2026-10-21T10:00:00Z', 'log' => 'x' ),
					array( 'woocommerce_order_id' => 'WS_4', 'odoo_order_id' => '4', 'status' => 'warning', 'synced_at' => '2026-10-21T10:00:00Z', 'log' => 'x' ),
					array( 'woocommerce_order_id' => 'WS_5', 'odoo_order_id' => '5', 'status' => 'pending', 'synced_at' => '2026-10-21T10:00:00Z', 'log' => 'x' ),
				),
			)
		);
		$items = $this->rest( 'GET', $this->url( $l, 'order-syncs' ) )->get_data()['data']['items'];
		$this->assertSame( array( 'Synced', 'Failed', 'Failed', 'Warning', 'Unknown' ), array_column( $items, 'status_text' ) );
		$this->assertSame( array( 'WS_1', 'WS_2', 'WS_3', 'WS_4', 'WS_5' ), array_column( $items, 'woocommerce_order_id' ), 'Django\'s order is kept.' );
	}

	public function test_gift_cards_single_load_button_and_django_error_shown(): void {
		$html = $this->render( 'gift-cards' );

		$this->assertStringContainsString( 'data-odoowoo-card="gift-cards"', $html );
		$this->assertSame( 1, substr_count( $html, 'data-action="load-lists"' ), 'One button loads taxes, journals and accounts.' );
		$this->assertMatchesRegularExpression( '#<button[^>]*data-action="load-lists"[^>]*>\s*Load taxes, journals and accounts\s*</button>#', $html );
		$this->assertStringNotContainsString( ' disabled', $this->tag( $html, 'data-action="load-lists"' ) );
		$this->assertMatchesRegularExpression( '#<button[^>]*type="submit"[^>]*>\s*Save\s*<#', $html );

		// A place for Django's message next to every setting it can refuse.
		foreach ( array( 'enabled', 'enable_expiry_sweep', 'sale_tax_id', 'redeem_journal_id', 'expiry_journal_id', 'expiry_debit_account_id', 'expiry_credit_account_id', 'sale_line_meta_key', 'sale_product_codes', 'redeem_order_meta_key', 'card_api_path', 'paid_flag_field', 'expiry_api_path', 'expiry_status_values', 'expiry_min_balance' ) as $name ) {
			$this->assertMatchesRegularExpression( '#<(input|select)\b[^>]*\bname="' . $name . '"[^>]*>#', $html, $name );
			$this->assertStringContainsString( 'data-error-for="' . $name . '"', $html, 'Error place for ' . $name );
		}

		$this->assertSame( 2, preg_match_all( '#<input[^>]*type="checkbox"#', $html ) );

		foreach ( array( 'Book gift cards this way', 'Release the value of expired cards', 'Exempt tax for card sales', 'Gift card journal', 'Card amount line meta key', 'Gift card product codes', 'Redemption order meta key', 'Card lookup API path', 'Paid flag field', 'Account to debit, the gift card liability', 'Account to credit, breakage income', 'Journal for releases', 'VAT on a release', 'Card listing API path', 'Statuses that mean expired', 'Smallest balance to release' ) as $label ) {
			$this->assertMatchesRegularExpression( '#<label[^>]*>\s*' . preg_quote( $label, '#' ) . '\s*</label>#', $html, $label );
		}

		// Django's refusal reaches the browser as it is, with its field (the card shows it next to the input).
		$l = $this->owner();
		$this->fake_django( fn() => array( 400, array( 'code' => 'gift_card_incomplete', 'message' => 'Choose the exempt tax for gift card sales before turning this on.', 'field' => 'sale_tax_id' ) ) );
		$res = $this->rest( 'PUT', $this->url( $l, 'gift-card-settings' ), array( 'settings' => array( 'enabled' => true ) ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'gift_card_incomplete', $res->get_data()['code'] );
		$this->assertSame( 'Choose the exempt tax for gift card sales before turning this on.', $res->get_data()['message'] );
		$this->assertSame( 'sale_tax_id', $res->get_data()['field'] );

		$this->assertTrue( wp_script_is( 'odoowoo-card-gift-cards', 'enqueued' ) );
	}

	public function test_gift_cards_loader_and_save_are_off_when_the_viewer_may_not_write(): void {
		foreach ( array( 'paused owner' => array( 'paused', false ), 'support' => array( 'ready', true ) ) as $label => list( $state, $support ) ) {
			Renderer::reset();
			$html = $this->render( 'gift-cards', $state, $support );

			$this->assertStringContainsString( 'data-can-write="0"', $html, $label );
			$this->assertStringContainsString( ' disabled', $this->tag( $html, 'data-action="load-lists"' ), $label . ': the loader writes Django rows.' );
			$this->assertStringContainsString( ' disabled', $this->tag( $html, 'type="submit"' ), $label );
		}
	}

	public function test_gift_card_save_never_sends_expiry_tax_id(): void {
		$html = $this->render( 'gift-cards' );

		// No input, and the VAT picker is a disabled select with the one choice.
		$this->assertStringNotContainsString( 'name="expiry_tax_id"', $html );
		$vat = $this->tag( $html, 'name="expiry_vat"', 'select' );
		$this->assertStringContainsString( ' disabled', $vat );
		$this->assertMatchesRegularExpression( '#<select[^>]*name="expiry_vat"[^>]*>\s*<option value="none"[^>]*>\s*No VAT\s*</option>\s*</select>#', $html );

		// The script's field list leaves it out (the behaviour is tests/js/gift-cards.test.mjs).
		$js = (string) file_get_contents( ODOOWOO_PORTAL_PATH . 'assets/js/card-gift-cards.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertMatchesRegularExpression( "#NOT_SENT = \\[ 'expiry_tax_id' \\]#", $js );
	}

	public function test_gift_card_amount_is_a_string_in_the_signed_body(): void {
		$l = $this->owner();
		$this->fake_django( fn() => array( 200, array( 'message' => 'Gift card settings saved.' ) ) );

		$res = $this->rest( 'PUT', $this->url( $l, 'gift-card-settings' ), array( 'settings' => array( 'enabled' => true, 'expiry_min_balance' => '2.50', 'sale_tax_id' => 3, 'legacy_card_cutoff' => null, 'expiry_tax_id' => null ) ) );
		$call = $this->django_calls()[0];

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'POST', $call['method'] );
		$this->assert_signed( $call, $this->tenant( $l ) );
		$this->assertStringContainsString( '"expiry_min_balance":"2.50"', $call['body'], 'A string in the signed body.' );
		$this->assertStringNotContainsString( '"expiry_min_balance":2.5', $call['body'] );
		$this->assertStringContainsString( '"sale_tax_id":3', $call['body'], 'Ids stay numbers.' );
		$this->assertStringContainsString( '"legacy_card_cutoff":null', $call['body'], 'A cleared cutoff stays null.' );
	}

	public function test_the_card_posts_ids_and_the_signed_body_is_clean(): void {
		$l = $this->owner();
		$this->fake_django( fn() => array( 202, array( 'message' => 'Product sync started', 'task_id' => 'tk-1' ) ) );

		// What the card's script sends for "1, 2,,x 3" (syncBody cleans it for the UX; the server cleans for real).
		$this->rest( 'POST', $this->url( $l, 'trigger-sync' ), array( 'product_ids' => array( 1, 2, 3 ) ) );
		$this->rest( 'POST', $this->url( $l, 'trigger-sync' ), array( 'product_ids' => '1, 2,,x 3' ) );
		$this->rest( 'POST', $this->url( $l, 'sync-orders' ), array( 'from_date' => '2026-01-01', 'to_date' => '2026-01-31' ) );

		$bodies = array_map( fn( $c ) => $c['body'], $this->django_calls() );

		$this->assertSame( array( '{"product_ids":[1,2,3]}', '{"product_ids":[1,2,3]}', '{"from_date":"2026-01-01","to_date":"2026-01-31"}' ), $bodies );
		$this->assertStringNotContainsString( 'timezone', implode( '', $bodies ), 'No timezone is sent.' );
	}

	public function test_registry_strings_and_scripts_for_the_three_cards(): void {
		$cards = Registry::cards();

		foreach ( array( 'gift-cards', 'manual-sync', 'sync-logs' ) as $name ) {
			$this->assertNotSame( array(), $cards[ $name ]['strings'], $name );
			$this->assertNotSame( array(), $cards[ $name ]['keys'], $name );
			$this->assertFileExists( ODOOWOO_PORTAL_PATH . 'assets/js/card-' . $name . '.js' );
			$this->assertFileExists( ODOOWOO_PORTAL_PATH . 'templates/cards/' . $name . '.php' );

			foreach ( $cards[ $name ]['strings'] as $key => $value ) {
				$this->assertIsString( $value, $name . '.' . $key );
				$this->assertNotSame( '', $value, $name . '.' . $key );
			}
		}

		$this->assertSame( array( 'gift-card-settings', 'stored-odoo-taxes', 'saved-odoo-journals', 'saved-odoo-accounts', 'fetch-and-store-odoo-taxes', 'odoo-journals', 'odoo-accounts' ), $cards['gift-cards']['keys'] );
		$this->assertSame( array( 'trigger-sync', 'trigger-category-sync', 'trigger-brand-sync', 'sync-stock', 'sync-prices', 'sync-barcodes', 'sync-orders', 'task-status' ), $cards['manual-sync']['keys'] );
		$this->assertSame( array( 'order-syncs' ), $cards['sync-logs']['keys'] );
	}
}
