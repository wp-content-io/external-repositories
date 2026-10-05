<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Add tab and update system for external theme repository
 */
class ExternalThemeRepository {
	/**
	 * @var ExternalRepository External repository used to call API
	 */
	protected $repository;

	/**
	 * @param ExternalRepository $repository
	 */
	public function __construct( ExternalRepository $repository ) {
		$this->repository = $repository;

		add_filter( "update_themes_{$this->repository->get_hostname()}", array( $this, 'check_theme_update' ), 10, 4 );
		add_filter( 'external_theme_repositories', array( $this, 'register_repository' ) );
		add_filter( 'install_themes_tabs', array( $this, 'theme_tabs' ) );
		add_filter(
			"install_themes_table_api_args_{$this->repository->slug}",
			array(
				$this,
				'prepare_theme_tab_args',
			)
		);
		add_filter( 'themes_api', array( $this, 'themes_api' ), 10, 3 );
		add_action( 'install_themes_pre_theme-preview', array( $this, 'theme_preview' ) );
		add_filter( 'upgrader_pre_download', array( $this, 'refresh_package' ), 10, 4 );
	}

	/**
	 * Register repository to add tab in themes list (will be added to js).
	 *
	 * @param $repositories
	 *
	 * @return mixed
	 * @hook external_theme_repositories - 10
	 */
	public function register_repository( $repositories ): array {
		$repositories[] = array(
			'ID'   => $this->repository->ID,
			'slug' => $this->repository->slug,
			'name' => $this->repository->name,
		);

		return $repositories;
	}

	/**
	 * Register new tab in to display repository themes list.
	 *
	 * @param array $tabs
	 *
	 * @return array
	 * @hook install_themes_tabs - 10
	 */
	public function theme_tabs( array $tabs ): array {
		$tabs[ $this->repository->slug ] = $this->repository->name;

		return $tabs;
	}

	/**
	 * Prepare query params to call repository api themes list.
	 *
	 * @param $args
	 *
	 * @return array
	 * @hook install_themes_table_api_args_* - 10
	 */
	public function prepare_theme_tab_args( $args ): array {
		$args = wp_parse_args(
			$args,
			array(
				'page'     => 1,
				'per_page' => 100,
			)
		);

		return array(
			'browse'   => $this->repository->slug,
			'page'     => $args['page'],
			'per_page' => $args['per_page'],
		);
	}

	/**
	 * Override theme api WordPress call.
	 *
	 * @param $res
	 * @param $action
	 * @param $args
	 *
	 * @return mixed
	 */
	public function themes_api( $res, $action, $args ) {
		switch ( $action ) {
			case 'query_themes':
				if ( property_exists( $args, 'browse' ) && (string) $args->browse === $this->repository->slug ) {
					// Slugs are merged page after page (infinite scroll), reset them on the first page only
					if ( empty( $args->page ) || 1 === (int) $args->page ) {
						delete_site_transient( "external_repository_{$this->repository->slug}_themes" );
					}

					return $this->get_repository_themes( $args );
				}
				break;

			case 'theme_information':
				// Check if a cached plugin exists
				$slugs = get_site_transient( "external_repository_{$this->repository->slug}_themes" );
				if ( is_array( $slugs ) && in_array( (string) $args->slug, $slugs, true ) ) {
					return $this->get_repository_theme_details( $args );
				}

				// Get from installed themes
				$installed_themes = wp_get_themes();
				foreach ( $installed_themes as $stylesheet => $data ) {
					if ( $stylesheet === $args->slug
						&& $this->repository->owns_update_uri( $data->get( 'UpdateURI' ) )
					) {
						return $this->get_repository_theme_details( $args );
					}
				}
				break;
		}

		return $res;
	}

	public function theme_preview() {
		global $body_id;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only theme preview request on the core theme-install screen.
		if ( empty( $_REQUEST['theme'] ) ) {
			return;
		}

		$api = themes_api(
			'theme_information',
			array(
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only theme preview request on the core theme-install screen; sanitized.
				'slug' => sanitize_text_field( wp_unslash( $_REQUEST['theme'] ) ),
			)
		);

		if ( is_wp_error( $api ) ) {
			wp_die( wp_kses_post( $api->get_error_message() ) );
		}

		if ( ! defined( 'IFRAME_REQUEST' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant read by iframe_header().
			define( 'IFRAME_REQUEST', true );
		}

		$body_id      = 'theme-information'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core global read by iframe_header().
		$show_details = ! empty( $_REQUEST['details'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag of the theme preview.
		wp_enqueue_style( 'theme-information', EXTERNAL_REPOSITORIES_PLUGIN_DIR_URL . 'assets/css/theme-information.css', array(), '1.1' );
		iframe_header( __( 'Theme details', 'external-repositories' ) );
		$theme = $this->parse_theme( $api );
		include __DIR__ . '/../views/theme-preview.php';
		iframe_footer();
		exit;
	}

	/**
	 * Callback to call theme update.
	 *
	 * @param $update
	 * @param $theme_data
	 * @param $theme_stylesheet
	 * @param $locales
	 *
	 * @return bool|object
	 */
	public function check_theme_update( $update, $theme_data, $theme_stylesheet, $locales ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter callback signature.
		// Another repository sharing the same host already answered
		if ( $update ) {
			return $update;
		}

		try {
			$result = $this->get_repository_theme_details(
				(object) array(
					'slug'      => $theme_stylesheet,
					'installed' => 1,
					'active'    => false,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $update;
			}

			$theme_data = array(
				'theme'   => $theme_stylesheet,
				'version' => $result->version,
				'package' => $result->download_link,
				'url'     => $this->get_theme_preview_url( $theme_stylesheet, true ),
			);

			foreach ( array( 'requires', 'requires_php' ) as $key ) {
				if ( ! empty( $result->$key ) ) {
					$theme_data[ $key ] = $result->$key;
				}
			}

			return (object) $theme_data;
		} catch ( Exception $e ) {
			return $update;
		}
	}

	/**
	 * Download a fresh package before updating a theme of this repository.
	 *
	 * @param bool|string|WP_Error $reply
	 * @param string               $package
	 * @param WP_Upgrader          $upgrader
	 * @param array                $hook_extra
	 *
	 * @return bool|string|WP_Error
	 * @hook upgrader_pre_download - 10
	 */
	public function refresh_package( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( false !== $reply || empty( $hook_extra['theme'] ) ) {
			return $reply;
		}

		$theme = wp_get_theme( $hook_extra['theme'] );

		if ( ! $theme->exists() || ! $this->repository->owns_update_uri( $theme->get( 'UpdateURI' ) ) ) {
			return $reply;
		}

		return $this->repository->download_fresh_package( 'themes/' . $hook_extra['theme'], $package );
	}

	/**
	 * @param $args
	 *
	 * @return object|WP_Error
	 */
	protected function get_repository_theme_details( $args ) {
		try {
			// Call API and return response as object
			return (object) $this->repository->call_api( 'themes/' . $args->slug, (array) $args );
		} catch ( Exception $e ) {
			return new WP_Error( 'themes_api_failed', $e->getMessage() );
		}
	}

	/**
	 * @param $args
	 *
	 * @return object|WP_Error
	 */
	protected function get_repository_themes( $args ) {
		try {
			// Call API
			$res = $this->repository->call_api( 'themes', (array) $args );

			// Save response in transient to override theme information API
			if ( ! array_key_exists( 'themes', $res ) || ! is_array( $res['themes'] ) ) {
				$res['themes'] = array();
			}

			$slugs = get_site_transient( "external_repository_{$this->repository->slug}_themes" );
			$slugs = array_values( array_unique( array_merge( is_array( $slugs ) ? $slugs : array(), array_column( $res['themes'], 'slug' ) ) ) );
			set_site_transient( "external_repository_{$this->repository->slug}_themes", $slugs );

			// Return response as object
			$res = (object) $res;
			foreach ( $res->themes as $i => $theme ) {
				$res->themes[ $i ] = $this->parse_theme( $theme );
			}

			return $res;
		} catch ( Exception $e ) {
			return new WP_Error( 'themes_api_failed', $e->getMessage() );
		}
	}

	protected function parse_theme( $theme ) {
		$theme = (object) $theme;

		if ( empty( $theme->screenshot_url ) ) {
			$theme->screenshot_url = EXTERNAL_REPOSITORIES_PLUGIN_DIR_URL . 'assets/img/screenshot-default.png';
		}

		if ( empty( $theme->preview_url ) ) {
			$theme->preview_url = $this->get_theme_preview_url( $theme->slug );
		}

		return $theme;
	}

	protected function get_theme_preview_url( $theme, $show_details = false ) {
		return add_query_arg(
			array(
				'tab'     => 'theme-preview',
				'theme'   => $theme,
				'details' => (int) $show_details,
			),
			network_admin_url( 'theme-install.php' )
		);
	}
}
