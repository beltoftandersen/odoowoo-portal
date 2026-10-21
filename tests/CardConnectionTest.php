<?php
/**
 * The Woo connection, Woo settings and Odoo connection cards: markup, attributes and strings.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Cards\Registry;
use OdooWooPortal\Cards\Renderer;

/**
 * Markup only; the behaviour is in tests/js/connections.test.mjs.
 */
final class CardConnectionTest extends RestTestCase {

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
	 * Renders a card as the license owner on a dashboard page.
	 *
	 * @param string $card Card name.
	 */
	private function render_as_owner( string $card ): string {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => '9',
		);

		$html = Renderer::render( $card );
		$this->assertSame( array(), $this->django_calls(), 'Rendering makes no Django call.' );

		return $html;
	}

	/**
	 * Every input, select and textarea has a label that points at it.
	 *
	 * @param string $html Markup.
	 */
	private function assert_fields_labelled( string $html ): void {
		preg_match_all( '#<(input|select|textarea)\b[^>]*>#', $html, $fields );
		$this->assertNotEmpty( $fields[0] );

		foreach ( $fields[0] as $tag ) {
			if ( preg_match( '#type="(hidden|checkbox)"#', $tag ) && false === strpos( $tag, 'type="checkbox"' ) ) {
				continue;
			}

			$this->assertMatchesRegularExpression( '#\bid="([^"]+)"#', $tag, $tag );
			preg_match( '#\bid="([^"]+)"#', $tag, $id );
			$this->assertMatchesRegularExpression( '#<label[^>]*\bfor="' . preg_quote( $id[1], '#' ) . '"#', $html, 'A label for ' . $id[1] );
		}
	}

	public function test_templates_have_labelled_fields_and_empty_secret_values(): void {
		$woo = $this->render_as_owner( 'woo-connection' );

		$this->assertStringContainsString( 'data-odoowoo-card="woo-connection"', $woo );
		$this->assertStringContainsString( 'data-dashboard="9"', $woo );
		foreach ( array( 'woocommerce_url', 'consumer_key', 'consumer_secret' ) as $name ) {
			$this->assertMatchesRegularExpression( '#<input[^>]*name="' . $name . '"#', $woo, $name );
		}
		$this->assertMatchesRegularExpression( '#<input[^>]*type="password"[^>]*name="consumer_secret"|<input[^>]*name="consumer_secret"[^>]*type="password"#', $woo );
		$this->assertDoesNotMatchRegularExpression( '#name="consumer_secret"[^>]*value="[^"]#', $woo, 'The secret is never prefilled.' );
		$this->assertMatchesRegularExpression( '#<button[^>]*odoowoo-reveal[^>]*aria-controls=#', $woo, 'Show/hide toggle.' );
		foreach ( array( 'Store URL', 'Consumer Key', 'Consumer Secret' ) as $label ) {
			$this->assertMatchesRegularExpression( '#<label[^>]*>\s*' . $label . '\s*</label>#', $woo, $label );
		}
		$this->assert_fields_labelled( $woo );

		Renderer::reset();
		$settings = $this->render_as_owner( 'woo-settings' );
		$this->assertStringContainsString( 'data-odoowoo-card="woo-settings"', $settings );
		$this->assertMatchesRegularExpression( '#<input[^>]*type="checkbox"[^>]*name="enable_stock_update"#', $settings );
		$this->assertMatchesRegularExpression( '#<input[^>]*type="checkbox"[^>]*name="change_status_delivery"#', $settings );
		$this->assertMatchesRegularExpression( '#<select[^>]*name="prefix"#', $settings );
		foreach ( array( 'Update stock when orders sync', 'Close Woo orders when Odoo delivery completes', 'Order prefix' ) as $label ) {
			$this->assertStringContainsString( $label, $settings );
		}
		$this->assert_fields_labelled( $settings );

		Renderer::reset();
		$odoo = $this->render_as_owner( 'odoo-connection' );
		$this->assertStringContainsString( 'data-odoowoo-card="odoo-connection"', $odoo );
		foreach ( array( 'odoo_url', 'odoo_db', 'odoo_user', 'odoo_password' ) as $name ) {
			$this->assertMatchesRegularExpression( '#<input[^>]*name="' . $name . '"#', $odoo, $name );
		}
		$this->assertMatchesRegularExpression( '#<input[^>]*name="odoo_password"[^>]*type="password"|<input[^>]*type="password"[^>]*name="odoo_password"#', $odoo );
		$this->assertDoesNotMatchRegularExpression( '#name="odoo_password"[^>]*value="[^"]#', $odoo );
		foreach ( array( 'Odoo URL', 'Database', 'User', 'Password / API Token' ) as $label ) {
			$this->assertMatchesRegularExpression( '#<label[^>]*>\s*' . preg_quote( $label, '#' ) . '\s*</label>#', $odoo, $label );
		}
		$this->assert_fields_labelled( $odoo );

		foreach ( array( 'woo-connection', 'woo-settings', 'odoo-connection' ) as $card ) {
			$this->assertTrue( wp_script_is( 'odoowoo-card-' . $card, 'enqueued' ), $card );
		}
	}

	public function test_buttons_have_idle_and_busy_labels(): void {
		$cards = Registry::cards();
		$woo   = $cards['woo-connection']['strings'];
		$odoo  = $cards['odoo-connection']['strings'];
		$set   = $cards['woo-settings']['strings'];

		$this->assertSame( 'Saving…', Renderer::core_strings()['saving'] );
		$this->assertSame( 'Verify connection', $woo['verify'] );
		$this->assertSame( 'Verifying…', $woo['verifying'] );
		$this->assertSame( 'Save', $woo['save'] );
		$this->assertSame( 'Transfer webhooks', $woo['transfer'] );
		$this->assertSame( 'Transferring…', $woo['transferring'] );
		$this->assertSame( 'Verify connection', $odoo['verify'] );
		$this->assertSame( 'Verifying…', $odoo['verifying'] );
		$this->assertSame( 'Set up Odoo callbacks', $odoo['setup'] );
		$this->assertSame( 'Setting up…', $odoo['settingUp'] );
		$this->assertSame( 'Save', $set['save'] );

		foreach ( array( 'woo-connection' => 'verify-woocommerce', 'odoo-connection' => 'verify-odoo' ) as $card => $route ) {
			Renderer::reset();
			$html = $this->render_as_owner( $card );
			$this->assertMatchesRegularExpression( '#<button[^>]*data-action="verify"[^>]*>\s*' . preg_quote( 'Verify connection', '#' ) . '#', $html, $card );
			$this->assertMatchesRegularExpression( '#<button[^>]*type="submit"[^>]*>\s*Save\s*<#', $html, $card );
		}

		Renderer::reset();
		$this->assertMatchesRegularExpression( '#<button[^>]*data-action="transfer"[^>]*disabled[^>]*>\s*Transfer webhooks#', $this->render_as_owner( 'woo-connection' ) );
		$this->assertStringContainsString( 'Verify also saves the URL and keys.', $woo['verifyHelp'] );
		$this->assertSame( 'Saved', $woo['badgeSaved'] );
		$this->assertSame( 'Verified', $woo['badgeVerified'] );
		$this->assertSame( 'Webhooks transferred', $woo['badgeWebhooks'] );
	}

	public function test_callback_box_element_is_always_in_the_template(): void {
		$html = $this->render_as_owner( 'odoo-connection' );

		$this->assertMatchesRegularExpression( '#<p[^>]*class="[^"]*odoowoo-callback-box[^"]*"[^>]*role="status"#', $html );
		$this->assertMatchesRegularExpression( '#<button[^>]*data-action="callbacks"[^>]*>\s*Set up Odoo callbacks\s*<#', $html );
		$this->assertStringNotContainsString( 'Copy', $html, 'The key is never shown or copied.' );
		$this->assertSame( 'Callback key is set', Registry::cards()['odoo-connection']['strings']['callbackSet'] );
		$this->assertSame( 'No callback key yet', Registry::cards()['odoo-connection']['strings']['callbackNone'] );
	}

	public function test_callback_confirm_text_is_in_the_strings(): void {
		$odoo = Registry::cards()['odoo-connection']['strings'];

		$this->assertSame( 'This replaces the current callback key. Changes you have not saved are not used.', $odoo['callbackConfirmBody'] );
		$this->assertSame( 'Save your changes first.', $odoo['saveFirst'] );
		$this->assertSame( 'Save your changes first.', Registry::cards()['woo-connection']['strings']['saveFirst'] );
		$this->assertSame( 'We could not read your settings, so nothing was saved. Reload the page and try again.', Renderer::core_strings()['notLoaded'] );
		$this->assertSame( 'Set up Odoo callbacks', $odoo['callbackConfirmTitle'] );
		$this->assertNotSame( '', $odoo['callbackConfirm'] );

		$html = $this->render_as_owner( 'odoo-connection' );
		$boot = Renderer::boot_data();
		$this->assertSame( $odoo['callbackConfirmBody'], $boot['i18n']['callbackConfirmBody'] );
		$this->assertStringNotContainsString( 'callbackConfirmBody', $html );
	}

	public function test_prefix_note_and_unsaved_notes_are_in_the_templates(): void {
		$settings = $this->render_as_owner( 'woo-settings' );
		$this->assertMatchesRegularExpression( '#<p[^>]*class="[^"]*odoowoo-prefix-note[^"]*"[^>]*role="status"[^>]*hidden#', $settings );
		$this->assertSame( 'Other licenses could not be checked. A prefix that is already taken will be refused when you save.', Registry::cards()['woo-settings']['strings']['prefixesPartial'] );

		Renderer::reset();
		$this->assertMatchesRegularExpression( '#<p[^>]*class="[^"]*odoowoo-unsaved-note[^"]*"[^>]*role="status"[^>]*hidden#', $this->render_as_owner( 'woo-connection' ) );
		Renderer::reset();
		$this->assertMatchesRegularExpression( '#<p[^>]*class="[^"]*odoowoo-unsaved-note[^"]*"[^>]*role="status"[^>]*hidden#', $this->render_as_owner( 'odoo-connection' ) );
	}

	public function test_registry_routes_of_the_three_cards(): void {
		$cards = Registry::cards();

		$this->assertSame( array( 'woo-keys', 'verify-woocommerce', 'inject-woo-webhooks' ), $cards['woo-connection']['keys'] );
		$this->assertSame( array( 'woo-keys' ), $cards['woo-settings']['keys'] );
		$this->assertSame( array( 'odoo-settings', 'verify-odoo', 'generate-api-key' ), $cards['odoo-connection']['keys'] );
	}

	public function test_every_connection_string_is_a_non_empty_string(): void {
		foreach ( array( 'woo-connection', 'woo-settings', 'odoo-connection' ) as $card ) {
			$strings = Registry::cards()[ $card ]['strings'];
			$this->assertNotEmpty( $strings, $card );

			foreach ( $strings as $key => $value ) {
				$this->assertIsString( $value, $card . '.' . $key );
				$this->assertNotSame( '', $value, $card . '.' . $key );
			}
		}
	}
}
