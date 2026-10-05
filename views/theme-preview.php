<?php
/**
 * @var bool $show_details
 * @var object $theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variable, file included from a method scope.
$theme_allowedtags = array(
	'a'          => array(
		'href'   => array(),
		'title'  => array(),
		'target' => array(),
	),
	'abbr'       => array( 'title' => array() ),
	'acronym'    => array( 'title' => array() ),
	'code'       => array(),
	'pre'        => array(),
	'em'         => array(),
	'strong'     => array(),
	'div'        => array( 'class' => array() ),
	'span'       => array( 'class' => array() ),
	'p'          => array(),
	'br'         => array(),
	'ul'         => array(),
	'ol'         => array(),
	'li'         => array(),
	'h1'         => array(),
	'h2'         => array(),
	'h3'         => array(),
	'h4'         => array(),
	'h5'         => array(),
	'h6'         => array(),
	'img'        => array(
		'src'   => array(),
		'class' => array(),
		'alt'   => array(),
	),
	'blockquote' => array( 'cite' => true ),
);

?>
<div id="theme-information-scrollable">
	<?php if ( $show_details ) : ?>
		<div id="theme-information-title">
			<?php // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage ?>
			<img src="<?php echo esc_url( $theme->screenshot_url ); ?>" alt="">
			<div>
				<h2><?php echo esc_html( $theme->name ); ?></h2>
				<?php if ( isset( $theme->author ) && is_array( $theme->author ) ) : ?>
					<span class="theme-by"><?php esc_html_e( 'By', 'external-repositories' ); ?>
						<?php if ( ! empty( $theme->author['author_url'] ) ) : ?>
							<a href="<?php echo esc_url( $theme->author['author_url'] ); ?>"
								target="_blank"><?php echo esc_html( $theme->author['author'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $theme->author['author'] ); ?>
						<?php endif; ?>
				</span>
				<?php endif; ?>
				<p><?php echo wp_kses( $theme->description ?? '', $theme_allowedtags ); ?></p>
			</div>
		</div>
	<?php endif; ?>
	<div id="theme-information-content">
		<h3><?php esc_html_e( 'Changelog', 'external-repositories' ); ?></h3>
		<?php echo wp_kses( $theme->sections['changelog'] ?? '', $theme_allowedtags ); ?>
	</div>
</div>
