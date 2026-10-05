<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Add tab and update system for external plugin repository
 */
class ExternalPluginRepository {
	/**
	 * @var ExternalRepository External repository used to call API
	 */
	protected $repository;

	/**
	 * @param ExternalRepository $repository
	 */
	public function __construct( ExternalRepository $repository ) {
		$this->repository = $repository;

		add_filter(
			"update_plugins_{$this->repository->get_hostname()}",
			array(
				$this,
				'check_plugin_update',
			),
			10,
			4
		);
		add_filter( 'install_plugins_tabs', array( $this, 'plugin_tabs' ) );
		add_filter(
			"install_plugins_table_api_args_{$this->repository->slug}",
			array(
				$this,
				'prepare_plugin_tab_args',
			)
		);
		add_action( "install_plugins_{$this->repository->slug}", array( $this, 'display_plugins_table' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'refresh_package' ), 10, 4 );
	}

	/**
	 * Content of tab in "Add plugin" page for this repository.
	 *
	 * @return void
	 * @hook install_plugins_* - 10
	 */
	public function display_plugins_table(): void {
		?>
		<p class="external-repository-actions"><?php $this->repository->get_settings_button( __( 'Manage repository settings', 'external-repositories' ) ); ?></p>
		<style>
			.notice:has(.external-repository-settings) .button {
				margin-top: .5em;
			}

			.notice:has(.external-repository-settings) .hide-if-no-js {
				display: none !important;
			}
		</style>
		<?php

		if ( $this->repository->can_display_plugins() ) {
			global $wp_list_table;
			$wp_list_table->display();
		}
	}

	/**
	 * Register new tab in to display repository plugins list.
	 *
	 * @param array $tabs
	 *
	 * @return array
	 * @hook install_plugins_tabs - 10
	 */
	public function plugin_tabs( array $tabs ): array {
		$tabs[ $this->repository->slug ] = $this->repository->name;

		return $tabs;
	}

	/**
	 * Prepare query params to call repository api plugins list.
	 *
	 * @param $args
	 *
	 * @return array
	 * @hook install_plugins_table_api_args_* - 10
	 */
	public function prepare_plugin_tab_args( $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress filter callback signature.
		global $wp_list_table;

		return array(
			'browse'   => $this->repository->slug,
			'page'     => $wp_list_table->get_pagenum(),
			'per_page' => 36,
		);
	}

	/**
	 * Override plugin api WordPress call.
	 *
	 * @param $res
	 * @param $action
	 * @param $args
	 *
	 * @return mixed|object|WP_Error
	 * @hook plugins_api - 10
	 */
	public function plugins_api( $res, $action, $args ) {
		switch ( $action ) {
			case 'query_plugins':
				if ( property_exists( $args, 'browse' ) && (string) $args->browse === $this->repository->slug ) {
					delete_site_transient( "external_repository_{$this->repository->slug}_plugins" );

					return $this->get_repository_plugins( $args );
				}
				break;

			case 'plugin_information':
				// Check if a cached plugin exists
				$slugs = get_site_transient( "external_repository_{$this->repository->slug}_plugins" );
				if ( is_array( $slugs ) && in_array( (string) $args->slug, $slugs, true ) ) {
					return $this->get_repository_plugin_details( $args );
				}

				// Get from installed plugins
				$installed_plugins = get_plugins();
				foreach ( $installed_plugins as $file => $data ) {
					if ( dirname( $file ) === $args->slug
						&& array_key_exists( 'UpdateURI', $data )
						&& $this->repository->owns_update_uri( $data['UpdateURI'] )
					) {
						return $this->get_repository_plugin_details( $args );
					}
				}
				break;
		}

		return $res;
	}

	/**
	 * Callback to call plugin update.
	 *
	 * @param $update
	 * @param $plugin_data
	 * @param $plugin_file
	 * @param $locales
	 *
	 * @return object|bool
	 * @hook update_plugins_* - 10
	 */
	public function check_plugin_update( $update, $plugin_data, $plugin_file, $locales ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter callback signature.
		// Another repository sharing the same host already answered
		if ( $update ) {
			return $update;
		}

		$slug = dirname( $plugin_file );
		try {
			$result = $this->get_repository_plugin_details(
				(object) array(
					'slug'      => $slug,
					'installed' => 1,
					'active'    => is_plugin_active( $plugin_file ),
				)
			);

			if ( is_wp_error( $result ) ) {
				return $update;
			}

			$plugin_data = array(
				'slug'    => $slug,
				'plugin'  => $plugin_file,
				'version' => $result->version,
				'tested'  => $result->tested ?? '',
				'package' => $result->download_link,
				'icons'   => $result->icons ?? array(),
			);

			foreach ( array( 'banners', 'requires', 'requires_php' ) as $key ) {
				if ( ! empty( $result->$key ) ) {
					$plugin_data[ $key ] = $result->$key;
				}
			}

			return (object) $plugin_data;
		} catch ( Exception $e ) {
			return $update;
		}
	}

	/**
	 * Download a fresh package before updating a plugin of this repository.
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
		if ( false !== $reply || empty( $hook_extra['plugin'] ) ) {
			return $reply;
		}

		$plugins = get_plugins();
		$plugin  = $plugins[ $hook_extra['plugin'] ] ?? array();

		if ( empty( $plugin['UpdateURI'] ) || ! $this->repository->owns_update_uri( $plugin['UpdateURI'] ) ) {
			return $reply;
		}

		return $this->repository->download_fresh_package( 'plugins/' . dirname( $hook_extra['plugin'] ), $package );
	}

	/**
	 * @param $args
	 *
	 * @return object
	 */
	protected function get_repository_plugin_details( $args ) {
		try {
			// Call API and return response as object
			return (object) $this->repository->call_api( 'plugins/' . $args->slug, (array) $args );
		} catch ( Exception $e ) {
			return new WP_Error( 'plugins_api_failed', $e->getMessage() );
		}
	}

	/**
	 * @param $args
	 *
	 * @return object
	 */
	protected function get_repository_plugins( $args ) {
		try {
			// Call API
			$res = $this->repository->call_api( 'plugins', (array) $args );

			// Save response in transient to override plugin information API
			if ( array_key_exists( 'plugins', $res ) ) {
				set_site_transient( "external_repository_{$this->repository->slug}_plugins", array_column( $res['plugins'], 'slug' ) );
			}

			// Return response as object
			return (object) $res;
		} catch ( Exception $e ) {
			return new WP_Error( 'plugins_api_failed', $e->getMessage() );
		}
	}
}