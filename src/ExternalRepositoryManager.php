<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ExternalRepositoryFactory
 *
 * @package ExternalRepositories
 */
final class ExternalRepositoryManager {
	/**
	 * @var ExternalRepositoryManager|null Shared instance
	 */
	private static $shared = null;

	const DEFAULT_CAPABILITY = 'manage_options';

	/**
	 * Version of the database schema, bump it when changing init_db().
	 */
	const DB_VERSION                       = '1';
	const PAGE_SLUG                        = 'external-repositories';
	const ACTION_UPDATE_REPOSITORY_OPTIONS = 'external-repository-update-options';

	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_theme_scripts' ) );
		add_filter( 'all_plugins', array( $this, 'filter_plugins_list' ) );
		add_filter( 'screen_settings', array( $this, 'register_plugins_screen_settings' ), 10, 2 );
		add_action( 'wp_ajax_hidden-external-repositories', array( $this, 'update_plugin_visibility' ) );
		add_action( 'admin_init', array( $this, 'handle_action' ) );

		if ( is_multisite() ) {
			// Repositories feed the network-wide update transients: only the network admin can manage them
			add_action( 'network_admin_menu', array( $this, 'register_admin_menu' ) );
			add_action( 'network_admin_notices', array( $this, 'admin_notices' ) );
			add_action( 'network_admin_edit_' . self::ACTION_UPDATE_REPOSITORY_OPTIONS, array( $this, 'save_options' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			add_action( 'admin_post_' . self::ACTION_UPDATE_REPOSITORY_OPTIONS, array( $this, 'save_options' ) );
		}

		add_filter( 'external_repositories_api_key', array( $this, 'decrypt_api_key' ), 10, 2 );
		add_filter( 'external_repositories_actions', array( $this, 'repository_actions' ), 10, 2 );
		add_action( 'external_repositories_action_delete', array( $this, 'delete_repository' ) );
	}

	/**
	 * @return void
	 * @hook admin_enqueue_scripts - 10
	 */
	public function enqueue_theme_scripts(): void {
		if ( ! wp_script_is( 'theme', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'external-themes',
			plugins_url( 'assets/js/external-themes.js', __DIR__ ),
			array(
				'jquery',
				'theme',
			),
			'1.3.0',
			true
		);
		wp_localize_script(
			'theme',
			'external_themes_settings',
			array(
				'repositories' => apply_filters( 'external_theme_repositories', array() ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public hook kept for backward compatibility.
				'i18n'         => array(
					'error_message'   => __( 'An error occurred while fetching themes from the repository. This may be due to an invalid API key, missing permissions, domain restrictions, or a server issue. If everything looks correct, please contact support.', 'external-repositories' ),
					'error_button'    => __( 'Check repository settings', 'external-repositories' ),
					// Raw values: the script inserts them with .text() / .attr()
					'settings_url'    => esc_url_raw( self::get_settings_url( ':ID:' ) ),
					'settings_button' => __( 'Manage repository settings', 'external-repositories' ),
				),
			)
		);
	}

	/**
	 * @return array
	 * @hook all_plugins - 10
	 */
	public function filter_plugins_list( $plugins ) {
		$plugin_hidden = ( is_multisite() && ! is_network_admin() ) || get_site_option( 'hide_external_repositories_plugin' );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public filter kept for backward compatibility with existing sites.
		if ( apply_filters( 'hide_external_repositories_plugin', $plugin_hidden ) ) {
			unset( $plugins['external-repositories/external-repositories.php'] );
		}

		return $plugins;
	}

	protected function current_user_can_update_plugin_visibility() {
		return current_user_can( self::get_capability() ) && ! has_filter( 'hide_external_repositories_plugin' );
	}

	/**
	 * Capability required to manage repositories: on multisite, repositories are shared by the whole network.
	 *
	 * @return string
	 */
	public static function get_capability(): string {
		return is_multisite() ? 'manage_network_options' : self::DEFAULT_CAPABILITY;
	}

	/**
	 * Repositories table, shared by every site of a network.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;

		return $wpdb->base_prefix . 'external_repositories';
	}

	public function register_plugins_screen_settings( $settings, $screen ) {
		if ( ( is_multisite() ? 'plugins-network' : 'plugins' ) !== $screen->id ) {
			return $settings;
		}

		if ( $this->current_user_can_update_plugin_visibility() ) {

			$plugin_hidden = boolval( get_site_option( 'hide_external_repositories_plugin' ) );
			$settings     .= '<fieldset class="external-repositories-settings">';
			$settings     .= '<legend>' . esc_html__( 'External Repositories plugin visibility', 'external-repositories' ) . '</legend>';
			$settings     .= '<label><input type="checkbox" class="external-repositories-visibility-toggle" ' . checked( $plugin_hidden, true, false ) . ' /> ' . __( 'Hide from the plugins list', 'external-repositories' ) . '</label>';
			$settings     .= '</fieldset>';
		}

		return $settings;
	}

	public function update_plugin_visibility() {
		check_ajax_referer( 'screen-options-nonce', 'screenoptionnonce' );

		if ( ! is_user_logged_in() || ! $this->current_user_can_update_plugin_visibility() ) {
			wp_die( - 1 );
		}

		update_site_option( 'hide_external_repositories_plugin', ! empty( $_POST['hidden'] ) );
		wp_die( 1 );
	}

	/**
	 * @param string $name
	 * @param mixed  $default_value
	 *
	 * @return mixed
	 */
	protected static function query_param( $name, $default_value = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET parameter (screen routing); state changes verify a nonce in the caller.
		if ( ! isset( $_GET[ $name ] ) ) {
			return $default_value;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET parameter, sanitized; state changes verify a nonce in the caller.
		return sanitize_text_field( wp_unslash( $_GET[ $name ] ) );
	}

	/**
	 * @param numeric|'new' $repository_id
	 *
	 * @return string
	 */
	public static function get_settings_url( $repository_id = null ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'edit' => $repository_id,
			),
			network_admin_url( is_multisite() ? 'settings.php' : 'options-general.php' )
		);
	}

	/**
	 * Generate URL with action parameter
	 *
	 * @param string $action
	 *
	 * @return string
	 */
	public static function get_action_url( $action ): string {
		$endpoint = is_multisite() ? network_admin_url( 'edit.php' ) : admin_url( 'admin-post.php' );

		return add_query_arg(
			array(
				'action' => $action,
			),
			$endpoint
		);
	}

	/**
	 * @return void
	 */
	public static function init_db(): void {
		global $wpdb;

		$table        = self::get_table_name();
		$wpdb_collate = $wpdb->collate;

		// Keep the dbDelta() format: one column per line, two spaces after PRIMARY KEY, named keys
		$sql = "CREATE TABLE $table (
  ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  url varchar(255) NOT NULL,
  api_key varchar(255),
  is_encrypted tinyint(1) DEFAULT 0,
  supports_plugins tinyint(1) DEFAULT 0,
  supports_themes tinyint(1) DEFAULT 0,
  active tinyint(1) DEFAULT 1,
  PRIMARY KEY  (ID),
  UNIQUE KEY name (name)
) COLLATE $wpdb_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_site_option( 'external_repositories_db_version', self::DB_VERSION );
	}

	/**
	 * @param bool|numeric|null $active
	 *
	 * @return array
	 */
	public static function get_repositories( $active = null ): array {
		global $wpdb;

		$table = self::get_table_name();

		// %i (identifier placeholder) requires WordPress 6.2+
		if ( ! is_null( $active ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE active = %d', $table, (int) $active ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ) );
		}
	}

	/**
	 * Shared instance getter
	 *
	 * @return self
	 */
	public static function init(): self {
		if ( ! self::$shared ) {
			self::$shared = new self();
		}

		return self::$shared;
	}

	/**
	 * @return self
	 */
	public static function load(): self {
		$instance = self::init();

		// Create or upgrade the table when needed (the plugin is loaded before its activation hook runs)
		if ( self::DB_VERSION !== get_site_option( 'external_repositories_db_version' ) ) {
			self::init_db();
		}

		foreach ( apply_filters( 'external_repositories', self::get_repositories( true ) ) as $repository ) {
			ExternalRepository::register( $repository );
		}

		return $instance;
	}

	/**
	 * @return bool
	 */
	public static function is_admin_page(): bool {
		return self::query_param( 'page' ) === self::PAGE_SLUG;
	}

	/**
	 * @return void
	 * @hook admin_init
	 */
	public function handle_action(): void {
		if ( ! self::is_admin_page() || ! current_user_can( self::get_capability() ) ) {
			return;
		}

		$action     = self::query_param( 'action' );
		$queried_id = self::query_param( 'edit' );

		if ( ! $action ) {
			if ( ! $queried_id ) {
				$repositories = self::get_repositories();
				wp_safe_redirect( self::get_settings_url( count( $repositories ) ? $repositories[0]->ID : 'new' ) );
				exit;
			}

			return;
		}

		if ( wp_verify_nonce( self::query_param( '_wpnonce' ), $action . '-' . (int) $queried_id ) ) {
			do_action( 'external_repositories_action_' . $action, (int) $queried_id );
		}
	}

	/**
	 * Add submenu page to settings
	 *
	 * @return void
	 * @hooked admin_menu - 10
	 */
	public function register_admin_menu(): void {
		$menu_page_hook = add_submenu_page(
			is_multisite() ? 'settings.php' : 'options-general.php',
			'Manage External Repositories',
			'External Repositories',
			self::get_capability(),
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);

		add_action( 'load-' . $menu_page_hook, array( $this, 'register_screen_settings' ) );
	}

	public function register_screen_settings(): void {
		$screen = get_current_screen();

		$screen->add_help_tab(
			array(
				'id'      => 'quick_help',
				'title'   => esc_html__( 'Quick Help', 'external-repositories' ),
				'content' => $this->get_help_content( 'quick_help' ),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'faq',
				'title'   => esc_html__( 'FAQ', 'external-repositories' ),
				'content' => $this->get_help_content( 'faq' ),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'about',
				'title'   => esc_html__( 'About', 'external-repositories' ),
				'content' => $this->get_help_content( 'about' ),
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'Need help?', 'external-repositories' ) . '</strong></p>' .
			'<p><a href="https://docs.wp-content.io/guides/external-repositories" target="_blank">' . esc_html__( 'Full documentation', 'external-repositories' ) . '</a></p>' .
			'<p><a href="mailto:support@wp-content.io">' . esc_html__( 'Contact support', 'external-repositories' ) . '</a></p>'
		);
	}

	/**
	 * Returns the help content for the given tab.
	 *
	 * @param string $tab
	 *
	 * @return string
	 */
	protected function get_help_content( string $tab ) {
		switch ( $tab ) {
			case 'quick_help':
				$content  = '<p><strong>' . esc_html__( 'What is an External Repository?', 'external-repositories' ) . '</strong></p>';
				$content .= '<p>' . esc_html__( 'An external repository allows you to manage and serve private plugins and themes, outside of the official WordPress.org directory.', 'external-repositories' ) . '</p>';
				$content .= '<table class="widefat striped" style="margin-top:1em;"><tbody>';
				$content .= '<tr><th>' . esc_html__( 'Name', 'external-repositories' ) . '</th><td>' . esc_html__( 'Used to identify your repository within the interface. It can be any label you like.', 'external-repositories' ) . '</td></tr>';
				$content .= '<tr><th>' . esc_html__( 'Registry URL', 'external-repositories' ) . '</th><td>' . esc_html__( 'Choose wp-content.io to use the official registry.', 'external-repositories' ) . ' ' . esc_html__( 'Select Custom URL if you’re hosting your own registry (e.g., a local server or self-hosted API).', 'external-repositories' ) . ' ' . esc_html__( 'Must be a valid URL ending with a slash (/).', 'external-repositories' ) . '</td></tr>';
				$content .= '<tr><th>' . esc_html__( 'API Key', 'external-repositories' ) . '</th><td>' . esc_html__( 'This key authenticates your requests and grants access to your private packages. Keep it safe.', 'external-repositories' ) . '</td></tr>';
				$content .= '<tr><th>' . esc_html__( 'Supports', 'external-repositories' ) . '</th><td>' . esc_html__( 'Check whether this repository distributes plugins, themes, or both. Only the selected types will be fetched and displayed.', 'external-repositories' ) . '</td></tr>';
				$content .= '<tr><th>' . esc_html__( 'Enabled', 'external-repositories' ) . '</th><td>' . esc_html__( 'When enabled, WordPress will check this repository for updates and new packages during regular update checks.', 'external-repositories' ) . '</td></tr>';
				$content .= '</tbody></table>';

				return $content;

			case 'faq':
				$content  = '<div class="faq-section">';
				$content .= '<p><strong>' . esc_html__( 'Can I connect multiple repositories?', 'external-repositories' ) . '</strong><br>' . esc_html__( 'Yes, but each name must be unique. Be aware that conflicts may occur if multiple repositories contain plugins with the same slug.', 'external-repositories' ) . '</p>';
				$content .= '<p><strong>' . esc_html__( 'Can I add repositories programmatically?', 'external-repositories' ) . '</strong><br>' . esc_html__( 'Yes, you can use the `external_repositories` filter to declare repositories. Those added this way won’t appear in this screen.', 'external-repositories' ) . '</p>';
				$content .= '<p><strong>' . esc_html__( 'Is the API key encrypted?', 'external-repositories' ) . '</strong><br>' . esc_html__( 'Yes, if the SECURE_AUTH_KEY constant is defined in wp-config.php. Be careful: if this value changes, you’ll need to re-save your repositories to restore access.', 'external-repositories' ) . '</p>';
				$content .= '<p><strong>' . esc_html__( 'What happens if I uninstall the plugin?', 'external-repositories' ) . '</strong><br>' . esc_html__( 'Uninstalling the plugin will remove the database tables and repository configuration. However, any plugins or themes already installed from private repositories will remain. Updates will no longer be checked.', 'external-repositories' ) . '</p>';
				$content .= '</div>';

				return $content;

			case 'about':
				$content  = '<p>' . esc_html__( 'This plugin is developed and maintained by Alexandre Chastan. It is freely distributed via wp-content.io.', 'external-repositories' ) . '</p>';
				$content .= '<p><a href="https://wp-content.io" target="_blank">' . esc_html__( 'Visit wp-content.io', 'external-repositories' ) . '</a></p>';
				$content .= '<p><a href="https://wp-content.io/external-repositories/" target="_blank">' . esc_html__( 'Plugin official page', 'external-repositories' ) . '</a></p>';

				return $content;

			default:
				return '';
		}
	}

	/**
	 * Render admin page settings.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( self::get_capability() ) ) {
			return;
		}

		$repositories = self::get_repositories();
		$form_data    = null;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';

		foreach ( $repositories as $repository ) {
			$tab_classes = 'nav-tab';

			if ( self::query_param( 'edit' ) === (string) $repository->ID ) {
				$form_data    = $repository;
				$tab_classes .= ' nav-tab-active';
			}

			printf( '<a href="%1$s" class="%3$s">%2$s</a>', esc_url( self::get_settings_url( $repository->ID ) ), esc_html( $repository->name ), esc_attr( $tab_classes ) );
		}

		$form_data = wp_parse_args(
			$form_data,
			array(
				'ID'   => '',
				'name' => count( $repositories ) ? '' : 'wp-content.io',
				'url'  => 'https://registry.wp-content.io/',
			)
		);

		if ( ! empty( $form_data['api_key'] ) ) {
			$api_key = $this->decrypt_api_key( $form_data['api_key'], $form_data );

			if ( ! $api_key ) {
				unset( $form_data['api_key'] );
			}
		}

		printf( '<a href="%1$s" class="%3$s"><span class="dashicons dashicons-plus"></span> %2$s</a>', esc_url( self::get_settings_url( 'new' ) ), 'Add new repository', empty( $form_data['ID'] ) ? 'nav-tab nav-tab-active' : 'nav-tab' );
		echo '</nav>';

		printf( '<form method="post" action="%s">', esc_url( self::get_action_url( self::ACTION_UPDATE_REPOSITORY_OPTIONS ) ) );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION_UPDATE_REPOSITORY_OPTIONS ) );
		printf( '<input type="hidden" name="repository" value="%s">', esc_attr( $form_data['ID'] ) );
		printf( '<input type="hidden" name="repository" value="%s">', esc_attr( $form_data['ID'] ) );
		printf( '<input type="hidden" name="url" value="%s">', esc_attr( $form_data['url'] ) );
		wp_nonce_field( self::ACTION_UPDATE_REPOSITORY_OPTIONS, 'external_repository_nonce' );

		echo '<div class="tab-content">';
		include_once dirname( __DIR__ ) . '/views/repository-settings.php';
		submit_button( 'Save settings' );
		echo '</div>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Update repository options
	 *
	 * @return void
	 * @hook admin_post_update-external-repository-options - 10
	 * @hook network_admin_edit_update-external-repository-options - 10
	 */
	public function save_options(): void {
		$referer = wp_get_referer();

		if ( ! isset( $_POST['external_repository_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['external_repository_nonce'] ) ), self::ACTION_UPDATE_REPOSITORY_OPTIONS ) ) {
			wp_die( esc_html__( 'Security check failed', 'external-repositories' ) );
		}

		if ( ! current_user_can( self::get_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage repositories.', 'external-repositories' ), 403 );
		}

		if ( ! array_key_exists( 'repository', $_POST ) ) {
			wp_safe_redirect( $referer );
			exit;
		}

		global $wpdb;

		$repository_id    = (int) $_POST['repository'];
		$name             = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$url              = trailingslashit( sanitize_text_field( wp_unslash( $_POST['url'] ?? '' ) ) );
		$supports_plugins = isset( $_POST['supports_plugins'] ) ? 1 : 0;
		$supports_themes  = isset( $_POST['supports_themes'] ) ? 1 : 0;
		$active           = isset( $_POST['active'] ) ? 1 : 0;

		// The API key is sent with every request: never accept a URL that would send it in clear
		if ( ! ExternalRepository::is_secure_url( $url ) ) {
			$this->add_notice( __( 'The repository URL must be a valid HTTPS URL.', 'external-repositories' ) );
			wp_safe_redirect( $referer );
			exit;
		}

		// Let the next request reach the API, even if it was flagged as unreachable
		ExternalRepository::clear_unavailable_flag( $url );

		$data = array(
			'name'             => $name,
			'url'              => $url,
			'supports_plugins' => $supports_plugins,
			'supports_themes'  => $supports_themes,
			'active'           => $active,
		);

		if ( isset( $_POST['api_key'] ) ) {
			$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ) );

			$encrypted_api_key = $this->encrypt_api_key( $api_key );

			if ( $encrypted_api_key ) {
				$data['api_key']      = $encrypted_api_key;
				$data['is_encrypted'] = 1;
			} else {
				$data['api_key']      = $api_key;
				$data['is_encrypted'] = 0;
			}
		}

		if ( empty( $repository_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result  = $wpdb->insert( self::get_table_name(), $data );
			$referer = self::get_settings_url( $wpdb->insert_id );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->update( self::get_table_name(), $data, array( 'ID' => $repository_id ) );
		}

		// Add admin notice
		if ( false !== $result ) {
			$referer = add_query_arg( 'settings-updated', 'true', $referer );
			$this->add_notice( __( 'Settings updated', 'external-repositories' ), 'success' );
		} else {
			$this->add_notice( $wpdb->last_error );
		}

		// Redirect back
		wp_safe_redirect( $referer );
		exit;
	}

	/**
	 * @param $message
	 * @param $type
	 *
	 * @return void
	 */
	protected function add_notice( $message, $type = 'error' ): void {
		set_site_transient(
			'external_repository_notice',
			array(
				'message' => $message,
				'type'    => $type,
			)
		);
	}

	/**
	 * Display notices from transient if not empty
	 *
	 * @return void
	 * @hook admin_notices - 10
	 * @hook network_admin_notices - 10
	 */
	public function admin_notices(): void {
		$notice = get_site_transient( 'external_repository_notice' );

		if ( $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
			delete_site_transient( 'external_repository_notice' );
		}
	}

	/**
	 * @param $api_key
	 *
	 * @return false|string
	 */
	protected function encrypt_api_key( $api_key ) {
		if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
			return false;
		}

		$iv        = substr( openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) ), 0, 16 );
		$encrypted = openssl_encrypt( $api_key, 'aes-256-cbc', SECURE_AUTH_KEY, OPENSSL_RAW_DATA, $iv );

		return base64_encode( $encrypted . '::' . $iv ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Stores the encrypted API key as text.
	}

	/**
	 * @param string $api_key
	 * @param array  $config
	 *
	 * @return mixed
	 * @hook external_repositories_api_key - 10 2
	 */
	public function decrypt_api_key( $api_key, $config = array() ) {
		$config = wp_parse_args(
			$config,
			array(
				'is_encrypted' => false,
			)
		);

		if ( ! defined( 'SECURE_AUTH_KEY' ) || ! $config['is_encrypted'] ) {
			return $api_key;
		}

		$parts = explode( '::', (string) base64_decode( (string) $api_key ), 2 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reads the encrypted API key stored as text.

		if ( 2 !== count( $parts ) ) {
			return '';
		}

		$decrypted = openssl_decrypt( $parts[0], 'aes-256-cbc', SECURE_AUTH_KEY, OPENSSL_RAW_DATA, $parts[1] );

		return false === $decrypted ? '' : $decrypted;
	}

	/**
	 * @param array  $actions
	 * @param object $repository
	 *
	 * @return mixed
	 * @hook external_repositories_actions - 10 - 2
	 */
	public function repository_actions( $actions, $repository ) {
		$slug = sanitize_title( $repository->name );

		if ( $repository->supports_plugins ) {
			$actions['browse_plugins'] = array(
				'href'  => network_admin_url( 'plugin-install.php?tab=' . $slug ),
				'title' => __( 'Browse plugins', 'external-repositories' ),
			);
		}

		if ( $repository->supports_themes ) {
			$actions['browse_themes'] = array(
				'href'  => network_admin_url( 'theme-install.php?browse=' . $slug ),
				'title' => __( 'Browse themes', 'external-repositories' ),
			);
		}

		$actions['delete'] = array(
			'href'  => add_query_arg(
				array(
					'action'   => 'delete',
					'_wpnonce' => wp_create_nonce( 'delete-' . (int) $repository->ID ),
				),
				self::get_settings_url( $repository->ID )
			),
			'title' => __( 'Delete repository', 'external-repositories' ),
		);

		return $actions;
	}

	/**
	 * @param numeric $repository_id
	 *
	 * @return void
	 * @hook external_repositories_action_delete - 10
	 */
	public function delete_repository( $repository_id ) {
		global $wpdb;

		if ( $repository_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( self::get_table_name(), array( 'ID' => $repository_id ) );
			$this->add_notice( __( 'Repository deleted', 'external-repositories' ), 'success' );

			wp_safe_redirect( self::get_settings_url( 'new' ) );
			exit;
		}
	}
}
