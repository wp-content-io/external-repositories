<?php
/**
 * @var array $form_data
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$default_url = 'https://registry.wp-content.io/'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variable, file included from a method scope.
$form_data   = wp_parse_args( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variable, file included from a method scope.
	$form_data,
	array(
		'api_key'          => '',
		'name'             => '',
		'url'              => '',
		'supports_plugins' => true,
		'supports_themes'  => true,
		'active'           => true,
	)
);
?>
<table class="form-table">
	<tr>
		<th scope="row"><label for="name"><?php esc_html_e( 'Name', 'external-repositories' ); ?> *</label></th>
		<td><input type="text" name="name" id="name" class="regular-text"
					value="<?php echo esc_attr( $form_data['name'] ); ?>" required></td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Registry URL', 'external-repositories' ); ?> *</th>
		<td>
			<ul class="external-repositories-url-options">
				<li>
					<label>
						<input type="radio" name="source"
								value="<?php echo esc_attr( $default_url ); ?>" <?php checked( $form_data['url'], $default_url ); ?> />
						<h2>wp-content.io</h2>
						<p><?php esc_html_e( 'Use official wp-content.io registry URL', 'external-repositories' ); ?></p>
					</label>
				</li>
				<li>
					<label>
						<input type="radio" name="source"
								value="" <?php checked( $form_data['url'] !== $default_url ); ?> />
						<h2><?php esc_html_e( 'Custom URL', 'external-repositories' ); ?></h2>
						<p><?php esc_html_e( 'Use a custom registry URL', 'external-repositories' ); ?></p>
					</label>
				</li>
			</ul>

			<input type="url" name="url" id="url" class="external-repositories-url"
					value="<?php echo esc_attr( $form_data['url'] ); ?>" placeholder="https://" required>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="api_key"><?php esc_html_e( 'API Key', 'external-repositories' ); ?></label></th>
		<td>
			<?php if ( empty( $form_data['api_key'] ) ) : ?>
				<input type="password" name="api_key" id="api_key" class="regular-text" value=""
						placeholder="<?php esc_attr_e( 'Enter a read-only API Key to connect registry', 'external-repositories' ); ?>">
			<?php else : ?>
				<a href="#"
					class="replace-api-key"><?php esc_html_e( 'Replace API Key', 'external-repositories' ); ?></a>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Supports', 'external-repositories' ); ?></th>
		<td>
			<label><input type="checkbox" name="supports_plugins"
							value="1" <?php checked( $form_data['supports_plugins'] ); ?>> <?php esc_html_e( 'Plugins', 'external-repositories' ); ?>
			</label><br>
			<label><input type="checkbox" name="supports_themes"
							value="1" <?php checked( $form_data['supports_themes'] ); ?>> <?php esc_html_e( 'Themes', 'external-repositories' ); ?>
			</label>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Enabled', 'external-repositories' ); ?></th>
		<td>
			<label><input type="checkbox" name="active"
							value="1" <?php checked( $form_data['active'] ); ?>> <?php esc_html_e( 'Activate this repository', 'external-repositories' ); ?>
			</label>
		</td>
	</tr>
</table>

<?php if ( $form_data['ID'] ) : ?>
	<ul class="external-repositories-actions">
		<?php foreach ( apply_filters( 'external_repositories_actions', array(), (object) $form_data ) as $external_repository_action => $external_repository_link ) : ?>
			<li><a href="<?php echo esc_url( $external_repository_link['href'] ); ?>"
					data-action="<?php echo esc_attr( $external_repository_action ); ?>"><?php echo esc_html( $external_repository_link['title'] ); ?></a></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<template id="api-key-input">
	<input type="password" name="api_key" id="api_key" class="regular-text" value=""
			placeholder="<?php esc_attr_e( 'Enter a read-only API Key to connect registry', 'external-repositories' ); ?>">
</template>