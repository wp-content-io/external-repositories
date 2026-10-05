<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * @param array  $plugin_data
 * @param string $slug
 *
 * @return array
 * @throws Exception
 */
function external_repositories_manifest( $plugin_data, $slug = 'external-repositories' ) {
	$manifest_url = sprintf( '%1$splugins/%2$s/manifest.json', trailingslashit( $plugin_data['UpdateURI'] ), $slug );
	$result       = wp_remote_get( $manifest_url, array( 'timeout' => 10 ) );

	if ( is_wp_error( $result ) ) {
		throw new Exception( esc_html( $result->get_error_message() ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $result ), true );

	if ( 200 !== (int) wp_remote_retrieve_response_code( $result ) || ! is_array( $body ) || empty( $body['Version'] ) ) {
		throw new Exception( esc_html__( 'Invalid plugin manifest.', 'external-repositories' ) );
	}

	// Versions never carry a "v" prefix: version_compare() would rank "v1.3.0" below "1.2.0"
	$body['Version'] = ltrim( (string) $body['Version'], 'vV' );

	// Legacy location of the package, next to the manifest. Still used when the manifest
	// does not provide a valid PackageURI (and by 1.2.x installs, which always use it).
	$legacy_package = sprintf( '%1$splugins/%2$s/%2$s.%3$s.zip', trailingslashit( $plugin_data['UpdateURI'] ), $slug, $body['Version'] );

	$package = isset( $body['PackageURI'] ) ? (string) $body['PackageURI'] : '';

	$body['PackageURI'] = external_repositories_is_allowed_package( $package, $plugin_data['UpdateURI'] ) ? $package : $legacy_package;

	return $body;
}

/**
 * Check if a package URL announced by the manifest can be downloaded.
 *
 * Only HTTPS URLs are accepted, on the Update URI host or on the GitHub releases of the
 * wp-content-io organization, so a tampered manifest cannot point to an arbitrary server.
 *
 * @param string $package Package URL from the manifest.
 * @param string $update_uri Update URI of the plugin.
 *
 * @return bool
 */
function external_repositories_is_allowed_package( $package, $update_uri ) {
	if ( ! $package || 'https' !== wp_parse_url( $package, PHP_URL_SCHEME ) ) {
		return false;
	}

	$host = wp_parse_url( $package, PHP_URL_HOST );
	$path = (string) wp_parse_url( $package, PHP_URL_PATH );

	$allowed = wp_parse_url( $update_uri, PHP_URL_HOST ) === $host
		|| ( 'github.com' === $host && 1 === preg_match( '#^/wp-content-io/[^/]+/releases/download/#', $path ) );

	/**
	 * Filter whether a package URL announced by the self-update manifest is allowed.
	 *
	 * @param bool $allowed
	 * @param string $package
	 * @param string $update_uri
	 */
	return (bool) apply_filters( 'external_repositories_allowed_package', $allowed, $package, $update_uri );
}

/**
 * Read a header field (eg. "Tested up to") from the plugin readme.txt.
 *
 * @param string $field
 *
 * @return string
 */
function external_repositories_readme_header( $field ) {
	$content = file_get_contents( __DIR__ . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file bundled with the plugin.

	if ( $content && preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $content, $match ) ) {
		return trim( $match[1] );
	}

	return '';
}

function external_repositories_readme_sections() {
	require_once __DIR__ . '/src/Slimdown.php';

	External_Repositories_Slimdown::add_rule( '/= (.*?) =/', '<h4>\1</h4>' );

	$content = file_get_contents( __DIR__ . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file bundled with the plugin.
	$result  = array();

	if ( preg_match_all( '/^== (.*?) ==\s*(.*?)(?=(^== |\z))/ms', $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$key            = esc_html( trim( strtolower( $match[1] ) ) );
			$value          = External_Repositories_Slimdown::render( trim( $match[2] ) );
			$result[ $key ] = $value;
		}
	}

	return $result;
}

/**
 * @param $update
 * @param $plugin_data
 * @param $plugin_file
 * @param $locales
 *
 * @return mixed
 * @hook update_plugins_downloads.wp-content.io - 10 4
 */
function external_repositories_updater( $update, $plugin_data, $plugin_file, $locales ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter callback signature.
	try {
		$slug     = dirname( $plugin_file );
		$manifest = external_repositories_manifest( $plugin_data, $slug );

		return (object) array(
			'slug'    => $slug,
			'plugin'  => $plugin_file,
			'version' => $manifest['Version'],
			'tested'  => ! empty( $manifest['Tested'] ) ? $manifest['Tested'] : external_repositories_readme_header( 'Tested up to' ),
			'package' => $manifest['PackageURI'],
			'icons'   => array(
				'default' => plugin_dir_url( __FILE__ ) . 'assets/img/icon.png',
			),
		);

	} catch ( Exception $e ) {
		return $update;
	}
}

add_filter( 'update_plugins_downloads.wp-content.io', 'external_repositories_updater', 10, 4 );

/**
 * Verify the self-update package against the SHA-256 checksum announced by the manifest.
 *
 * @param bool|string|WP_Error $reply
 * @param string               $package
 * @param WP_Upgrader          $upgrader
 * @param array                $hook_extra
 *
 * @return bool|string|WP_Error
 * @hook upgrader_pre_download - 10 4
 */
function external_repositories_verify_package( $reply, $package, $upgrader, $hook_extra = array() ) {
	if ( false !== $reply || empty( $hook_extra['plugin'] ) || plugin_basename( __DIR__ . '/external-repositories.php' ) !== $hook_extra['plugin'] ) {
		return $reply;
	}

	try {
		$plugin_data = get_plugin_data( __DIR__ . '/external-repositories.php', false, false );
		$manifest    = external_repositories_manifest( $plugin_data, dirname( $hook_extra['plugin'] ) );
	} catch ( Exception $e ) {
		return $reply;
	}

	// Nothing to verify: let WordPress download the package
	if ( empty( $manifest['Sha256'] ) || $manifest['PackageURI'] !== $package ) {
		return $reply;
	}

	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$file = download_url( $package );

	if ( is_wp_error( $file ) ) {
		return $file;
	}

	if ( ! hash_equals( strtolower( (string) $manifest['Sha256'] ), (string) hash_file( 'sha256', $file ) ) ) {
		wp_delete_file( $file );

		return new WP_Error( 'external_repositories_checksum', __( 'The downloaded package does not match the expected checksum.', 'external-repositories' ) );
	}

	return $file;
}

add_filter( 'upgrader_pre_download', 'external_repositories_verify_package', 10, 4 );

/**
 * @param $res
 * @param $action
 * @param $args
 *
 * @return mixed
 * @hook plugins_api - 10 3
 */
function external_repositories_details( $res, $action, $args ) {
	if ( 'plugin_information' !== $action ) {
		return $res;
	}

	if ( 'external-repositories' === $args->slug ) {
		try {
			$plugin_data = get_plugin_data( __DIR__ . '/external-repositories.php' );
			$manifest    = external_repositories_manifest( $plugin_data, $args->slug );
		} catch ( Exception $e ) {
			return $res;
		}

		$res                 = new stdClass();
		$res->name           = $manifest['Name'] ?? $plugin_data['Name'];
		$res->slug           = $args->slug;
		$res->author         = $manifest['Author'] ?? '';
		$res->author_profile = $manifest['AuthorURI'] ?? '';
		$res->version        = $manifest['Version'];
		$res->tested         = ! empty( $manifest['Tested'] ) ? $manifest['Tested'] : external_repositories_readme_header( 'Tested up to' );
		$res->requires       = $manifest['RequiresWP'] ?? '';
		$res->requires_php   = $manifest['RequiresPHP'] ?? '';
		$res->download_link  = $manifest['PackageURI'];
		$res->trunk          = $manifest['PackageURI'];
		$res->sections       = external_repositories_readme_sections();
		$res->banners        = array(
			'low'  => 'https://downloads.wp-content.io/plugins/external-repositories/banner-default-low.png',
			'high' => 'https://downloads.wp-content.io/plugins/external-repositories/banner-default-high.png',
		);
	}

	return $res;
}

add_filter( 'plugins_api', 'external_repositories_details', 10, 3 );
