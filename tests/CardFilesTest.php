<?php
/**
 * Every card has its template and script, and its markup is accessible.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Api\Routes;
use OdooWooPortal\Cards\Registry;
use OdooWooPortal\Cards\Renderer;
use OdooWooPortal\Context;

/**
 * Cross-card checks.
 */
final class CardFilesTest extends RestTestCase {

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
		Routes::reset();
		Context::reset();
		$GLOBALS['wp_scripts'] = new \WP_Scripts();
		$GLOBALS['wp_styles']  = new \WP_Styles();
	}

	public function tear_down(): void {
		$_GET = $this->get;
		Renderer::reset();
		Routes::reset();
		Context::reset();
		parent::tear_down();
	}

	public function test_every_registry_card_has_template_and_script(): void {
		$cards = Registry::cards();

		$this->assertCount( 15, $cards );

		foreach ( $cards as $name => $card ) {
			$this->assertFileExists( ODOOWOO_PORTAL_PATH . 'templates/' . $card['template'], "Template of $name" );
			$this->assertFileExists( ODOOWOO_PORTAL_PATH . $card['script'], "Script of $name" );
			$this->assertGreaterThan( 0, filesize( ODOOWOO_PORTAL_PATH . 'templates/' . $card['template'] ), "Template of $name is empty" );
			$this->assertGreaterThan( 0, filesize( ODOOWOO_PORTAL_PATH . $card['script'] ), "Script of $name is empty" );

			foreach ( $card['requires'] ?? array() as $handle ) {
				$this->assertStringStartsWith( 'odoowoo-', $handle );
			}
		}
	}

	public function test_markup_has_labelled_inputs_and_roles(): void {
		$license = $this->odoowoo_license();
		$this->as_user( (int) $license['user_id'] );
		$this->fake_django( fn() => array( 500, 'unexpected' ) );
		$_GET = array(
			'license'   => (string) $license['id'],
			'dashboard' => '5',
		);

		$fields = 0;

		foreach ( array_keys( Registry::cards() ) as $name ) {
			Renderer::reset();
			$html = Renderer::render( $name );

			$this->assertStringContainsString( 'data-odoowoo-card="' . $name . '"', $html, $name );

			$doc = new \DOMDocument();
			libxml_use_internal_errors( true );
			$doc->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
			libxml_clear_errors();

			$xpath  = new \DOMXPath( $doc );
			$labels = array();

			foreach ( $xpath->query( '//label[@for]' ) as $label ) {
				$labels[ $label->getAttribute( 'for' ) ] = true;
			}

			foreach ( $xpath->query( '//input[not(@type="hidden")]|//select|//textarea' ) as $field ) {
				++$fields;
				$id    = $field->getAttribute( 'id' );
				$named = '' !== trim( $field->getAttribute( 'aria-label' ) ) || '' !== trim( $field->getAttribute( 'aria-labelledby' ) ) || ( '' !== $id && isset( $labels[ $id ] ) );

				$this->assertTrue( $named, $name . ': a <' . $field->nodeName . ' name="' . $field->getAttribute( 'name' ) . '"> has no label' );
			}

			// Every status element announces itself.
			foreach ( $xpath->query( '//*[@role="status" or contains(concat(" ", normalize-space(@class), " "), " odoowoo-form-status ")]' ) as $status ) {
				$this->assertSame( 'polite', $status->getAttribute( 'aria-live' ), $name . ': ' . $status->getAttribute( 'class' ) );
			}

			// A dialog is never in the static markup (the scripts build them, with role="dialog"); a button has text or a label.
			foreach ( $xpath->query( '//button' ) as $button ) {
				$this->assertTrue( '' !== trim( $button->textContent ) || '' !== $button->getAttribute( 'aria-label' ), $name . ': a button without a name' );
			}
		}

		$this->assertGreaterThan( 10, $fields, 'The sweep found the cards\' fields.' );
	}
}
