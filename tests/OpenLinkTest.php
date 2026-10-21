<?php
/**
 * The "Open OdooWoo" button on My Account → Licenses.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Account\OpenLink;
use OdooWooPortal\Context;
use OdooWooPortal\Urls;

/**
 * The button is on the owner's OdooWoo licenses only.
 */
final class OpenLinkTest extends TestCase {

	/**
	 * Runs the action the way beltoft-licenses does and returns what it printed.
	 *
	 * @param array<string, mixed> $license License row.
	 */
	private function printed( array $license ): string {
		ob_start();
		do_action( 'beltoft_licenses_account_license', $license, array( 'license' => $license ) );

		return (string) ob_get_clean();
	}

	private function list_page(): int {
		$list = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'odoowoo' ) );
		$this->settings( array( 'list_page_id' => $list ) );

		return $list;
	}

	public function test_open_button_only_on_odoowoo_license_and_links_to_list_page(): void {
		$this->list_page();
		$this->connection();
		Context::reset();

		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );

		$html = $this->printed( $license );

		$this->assertSame( 1, substr_count( $html, '<a ' ) );
		$this->assertStringContainsString( 'href="' . esc_url( Urls::license_list( (int) $license['id'] ) ) . '"', $html );
		$this->assertStringContainsString( '>Open OdooWoo<', $html );

		// Another product's license of the same user: nothing.
		$other = \BeltoftLicenses\Licenses::create(
			array(
				'product_slug'    => 'something-else',
				'email'           => 'customer@example.com',
				'user_id'         => (int) $license['user_id'],
				'max_activations' => 1,
				'source'          => 'order',
			)
		);
		$this->assertIsArray( $other );
		$this->assertSame( '', $this->printed( $other ) );
	}

	public function test_no_button_without_list_page_setting(): void {
		$this->settings( array( 'list_page_id' => 0 ) );
		Context::reset();

		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );

		$this->assertSame( '', $this->printed( $license ) );
		$this->assertSame( '', $this->printed( $license + array() ), 'Stays empty on a second call.' );
	}

	public function test_no_button_for_another_users_license(): void {
		$this->list_page();
		Context::reset();

		$license = $this->odoowoo_license();
		$this->as_user( self::factory()->user->create() );

		$this->assertSame( '', $this->printed( $license ) );

		$this->as_user( 0 );
		$this->assertSame( '', $this->printed( $license ) );

		// Garbage arguments never fatal.
		ob_start();
		OpenLink::render( 'x' );
		OpenLink::render( array() );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_button_text_is_translatable(): void {
		$this->list_page();
		Context::reset();

		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );

		$filter = static function ( $translation, $text, $domain ) {
			return 'odoowoo-portal' === $domain && 'Open OdooWoo' === $text ? 'Aabn OdooWoo' : $translation;
		};
		add_filter( 'gettext', $filter, 10, 3 );
		$html = $this->printed( $license );
		remove_filter( 'gettext', $filter, 10 );

		$this->assertStringContainsString( '>Aabn OdooWoo<', $html );
		$this->assertStringNotContainsString( '>Open OdooWoo<', $html );

		$pot = (string) file_get_contents( dirname( __DIR__ ) . '/languages/odoowoo-portal.pot' );
		$this->assertStringContainsString( 'msgid "Open OdooWoo"', $pot );
	}
}
