<?php
/**
 * The frame every card sits in: title, notices, the support banner and the body.
 *
 * Variables: $shell (title, notices, banner, body, attrs). The body is already escaped by the
 * card's own template.
 *
 * @package OdooWooPortal
 */

defined( 'ABSPATH' ) || exit;

?>
<section class="odoowoo-card"<?php foreach ( $shell['attrs'] as $odoowoo_name => $odoowoo_value ) { printf( ' %s="%s"', esc_attr( $odoowoo_name ), esc_attr( $odoowoo_value ) ); } ?>>
	<h2 class="odoowoo-card__title"><?php echo esc_html( $shell['title'] ); ?></h2>
	<?php if ( '' !== $shell['banner'] ) : ?>
		<p class="odoowoo-banner" role="note"><?php echo esc_html( $shell['banner'] ); ?></p>
	<?php endif; ?>
	<?php foreach ( $shell['notices'] as $odoowoo_notice ) : ?>
		<p class="odoowoo-notice" role="status">
			<?php echo esc_html( $odoowoo_notice['text'] ); ?>
			<?php if ( '' !== $odoowoo_notice['link_url'] ) : ?>
				<a href="<?php echo esc_url( $odoowoo_notice['link_url'] ); ?>"><?php echo esc_html( $odoowoo_notice['link_text'] ); ?></a>
			<?php endif; ?>
		</p>
	<?php endforeach; ?>
	<?php if ( '' !== $shell['body'] ) : ?>
		<div class="odoowoo-card__body"><?php echo $shell['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the card's template. ?></div>
	<?php endif; ?>
</section>
