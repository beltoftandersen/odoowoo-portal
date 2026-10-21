<?php
/**
 * Base class of the card elements.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks;

use OdooWooPortal\Cards\Registry;
use OdooWooPortal\Cards\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * One OdooWoo card as a Bricks element. Loaded only once Bricks' element base class exists
 * (Elements::register(), on init priority 11), because it extends it.
 *
 * Variables: the card's defaults live on its own .odoowoo-card section, so the style controls
 * target that selector (a rule from the element's id outranks the stylesheet's default) and not
 * the wrapper, whose custom properties the section would redeclare.
 */
abstract class Card_Element extends \Bricks\Element {

	public $category     = 'odoowoo';
	public $icon         = 'ti-layout-tab';
	public $css_selector = '.odoowoo-card';

	/**
	 * The registry name of the card (Registry::cards()).
	 */
	abstract protected function card(): string;

	/**
	 * The card's name in the builder panel, without the group prefix.
	 */
	abstract protected function title(): string;

	/**
	 * "OdooWoo: <title>". Read in Bricks' constructor.
	 */
	public function get_label() {
		/* translators: %s: card name, e.g. "Sync logs". */
		return sprintf( __( 'OdooWoo: %s', 'odoowoo-portal' ), $this->title() );
	}

	/**
	 * The group the style controls sit in.
	 */
	public function set_control_groups() {
		$this->control_groups['odoowoo'] = array(
			'title' => esc_html__( 'OdooWoo card', 'odoowoo-portal' ),
			'tab'   => 'style',
		);
	}

	/**
	 * Style controls only: the card's custom properties, plus Bricks' own typography, border and
	 * padding on the card.
	 */
	public function set_controls() {
		$variable = function ( string $label, string $type, string $property, array $extra = array() ): array {
			return array_merge(
				array(
					'tab'   => 'style',
					'group' => 'odoowoo',
					'label' => $label,
					'type'  => $type,
					'css'   => array(
						array(
							'property' => $property,
							'selector' => '.odoowoo-card',
						),
					),
				),
				$extra
			);
		};

		$this->controls['textColor']   = $variable( esc_html__( 'Text colour', 'odoowoo-portal' ), 'color', '--odoowoo-text' );
		$this->controls['mutedColor']  = $variable( esc_html__( 'Muted text colour', 'odoowoo-portal' ), 'color', '--odoowoo-muted' );
		$this->controls['bgColor']     = $variable( esc_html__( 'Background colour', 'odoowoo-portal' ), 'color', '--odoowoo-bg' );
		$this->controls['borderColor'] = $variable( esc_html__( 'Border colour', 'odoowoo-portal' ), 'color', '--odoowoo-border' );
		$this->controls['accentColor'] = $variable( esc_html__( 'Accent colour', 'odoowoo-portal' ), 'color', '--odoowoo-accent' );
		$this->controls['dangerColor'] = $variable( esc_html__( 'Error colour', 'odoowoo-portal' ), 'color', '--odoowoo-danger' );
		$this->controls['radius']      = $variable( esc_html__( 'Corner radius', 'odoowoo-portal' ), 'number', '--odoowoo-radius', array( 'units' => true ) );
		$this->controls['gap']         = $variable( esc_html__( 'Spacing', 'odoowoo-portal' ), 'number', '--odoowoo-gap', array( 'units' => true ) );
		$this->controls['fontFamily']  = $variable( esc_html__( 'Font family', 'odoowoo-portal' ), 'text', '--odoowoo-font' );
		$this->controls['typography']  = $variable( esc_html__( 'Typography', 'odoowoo-portal' ), 'typography', 'font' );
		$this->controls['border']      = $variable( esc_html__( 'Border', 'odoowoo-portal' ), 'border', 'border' );
		$this->controls['padding']     = $variable( esc_html__( 'Padding', 'odoowoo-portal' ), 'dimensions', 'padding' );
	}

	/**
	 * The card's style is always wanted, in the builder too. Its script is enqueued by the
	 * renderer, which runs only on the frontend and only for a viewer who may use the app.
	 */
	public function enqueue_scripts() {
		Renderer::register_assets();
		wp_enqueue_style( 'odoowoo' );
	}

	/**
	 * Echoes the element (Bricks captures it). In the builder, and in its AJAX re-renders, it is
	 * static sample markup: no REST call, no ids, no script, no data of the viewer.
	 */
	public function render() {
		$this->set_attribute( '_root', 'class', 'odoowoo-element' );

		echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks builds the attribute string and escapes it.
		echo $this->is_frontend ? Renderer::render( $this->card() ) : $this->sample(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer and by sample().
		echo '</div>';
	}

	/**
	 * What the builder shows in place of the live card.
	 */
	private function sample(): string {
		$cards = Registry::cards();
		$title = isset( $cards[ $this->card() ]['title'] ) ? (string) $cards[ $this->card() ]['title'] : $this->title();

		return sprintf(
			'<section class="odoowoo-card" data-odoowoo-card="%1$s"><h2 class="odoowoo-card__title">%2$s</h2><p class="odoowoo-notice" role="status" aria-live="polite">%3$s</p></section>',
			esc_attr( $this->card() ),
			esc_html( $title ),
			esc_html__( 'Preview: this card shows the customer\'s own data on the live page.', 'odoowoo-portal' )
		);
	}
}
