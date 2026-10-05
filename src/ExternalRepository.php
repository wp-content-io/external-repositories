<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ExternalRepository
 */
class ExternalRepository {
	/**
	 * @var int Repository ID if saved in Database
	 */
	public $ID;

	/**
	 * @var string Repository display name
	 */
	public $name;

	/**
	 * @var string Repository slug (must be unique)
	 */
	public $slug;

	/**
	 * @var string Repository API url
	 */
	protected $url;

	/**
	 * @var array Provided services. Can take 'plugins' or 'themes' keywords.
	 */
	protected $supports;

	/**
	 * @var bool If the repository is private, an API Key will be required.
	 */
	protected $private;

	/**
	 * @var string|mixed Repository API Key
	 */
	protected $api_key;

	/**
	 * Timeout (in seconds) of the requests sent to the repository API.
	 */
	const REQUEST_TIMEOUT = 10;

	/**
	 * Duration (in seconds) during which an unreachable repository is not called again.
	 */
	const UNAVAILABLE_TTL = 300;

	/**
	 * Helper to register a new repository using config parameters.
	 *
	 * @param $config
	 *
	 * @return void
	 */
	public static function register( $config ): void {
		$config = wp_parse_args(
			$config,
			array(
				'ID'               => '',
				'name'             => '',
				'api_key'          => '',
				'is_encrypted'     => false,
				'url'              => 'https://registry.wp-content.io/',
				'supports_themes'  => true,
				'supports_plugins' => true,
			)
		);

		new static( $config );
	}

	/**
	 * Constructor is protected to prevent call new External Repository.
	 *
	 * @param array $config
	 *
	 * @see static::register() function to create a new instance.
	 */
	protected function __construct( array $config ) {
		$this->ID       = (int) $config['ID'];
		$this->name     = $config['name'];
		$this->slug     = sanitize_title( $config['name'] );
		$this->url      = $config['url'];
		$this->supports = array_filter(
			array(
				$config['supports_themes'] ? 'themes' : null,
				$config['supports_plugins'] ? 'plugins' : null,
			)
		);
		$this->api_key  = apply_filters( 'external_repositories_api_key', $config['api_key'], $config );
		$this->private  = ! empty( $this->api_key );

		if ( in_array( 'plugins', $this->supports, true ) ) {
			new ExternalPluginRepository( $this );
		}

		if ( in_array( 'themes', $this->supports, true ) ) {
			new ExternalThemeRepository( $this );
		}
	}

	/**
	 * Check if the repository supports the resource type.
	 *
	 * @param string $resource
	 *
	 * @return bool
	 */
	public function supports( string $resource ): bool { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.resourceFound -- Public method, kept for named-argument compatibility.
		return in_array( $resource, $this->supports, true );
	}

	/**
	 * Get hostname from repository URL.
	 *
	 * @return string
	 */
	public function get_hostname(): string {
		return (string) wp_parse_url( sanitize_url( $this->url ), PHP_URL_HOST );
	}

	/**
	 * Check if plugins can be displayed.
	 *
	 * @return bool
	 */
	public function can_display_plugins(): bool {
		return $this->supports( 'plugins' ) && ( ! $this->private || $this->api_key );
	}

	/**
	 * Check if themes can be displayed.
	 *
	 * @return bool
	 */
	public function can_display_themes(): bool {
		return $this->supports( 'themes' ) && ( ! $this->private || $this->api_key );
	}

	/**
	 * Helper to call repository API.
	 *
	 * @param $endpoint
	 * @param array $args
	 *
	 * @return array
	 * @throws Exception
	 */
	public function call_api( $endpoint, array $args = array() ): array {
		// Set full URL
		$url = trailingslashit( $this->url ) . $endpoint;

		// Add query args if needed
		if ( $args ) {
			$url = add_query_arg( $args, $url );
		}

		// Do not call again a repository that was unreachable a few minutes ago,
		// to avoid slowing down every admin page while the API is down.
		if ( get_site_transient( $this->get_unavailable_transient_name() ) ) {
			throw new Exception( esc_html__( 'Repository is temporarily unavailable, please try again in a few minutes.', 'external-repositories' ) );
		}

		// Never send the API key in clear
		if ( $this->private && ! self::is_secure_url( $this->url ) ) {
			throw new Exception( esc_html__( 'The repository URL must use HTTPS to send your API key.', 'external-repositories' ) );
		}

		// Prepare request options
		$options = array(
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => array(),
		);
		if ( $this->private ) {
			$options['headers']['x-api-key'] = $this->api_key;
		}

		// Send request
		$request = wp_remote_get( $url, $options );

		if ( is_wp_error( $request ) ) {
			$this->mark_as_unavailable();

			throw new Exception( esc_html( $request->get_error_message() ) );
		}

		$code          = (int) wp_remote_retrieve_response_code( $request );
		$body          = json_decode( wp_remote_retrieve_body( $request ), true );
		$error_message = wp_remote_retrieve_response_message( $request );
		$error_action  = '';

		if ( $code >= 500 ) {
			$this->mark_as_unavailable();
		}

		// Prepare return from response code
		switch ( $code ) {
			case 200:
			case 201:
			case 204:
				if ( ! is_array( $body ) ) {
					throw new Exception( esc_html__( 'Invalid response from repository.', 'external-repositories' ) );
				}

				return $body;
			case 404:
				$messages      = $this->get_response_messages( $body );
				$error_message = $messages ? $messages : esc_html__( 'Cannot found repository, check the API Key you provided.', 'external-repositories' );
				$error_action  = $this->get_settings_button( esc_html__( 'Check repository settings', 'external-repositories' ), false );
				break;
			case 403:
				$error_message = esc_html__( 'API Key you provided missing read permissions.', 'external-repositories' );
				$error_action  = $this->get_settings_button( esc_html__( 'Check repository settings', 'external-repositories' ), false );
				break;
			default:
				$messages      = $this->get_response_messages( $body );
				$error_message = $messages ? $messages : $error_message;
				break;
		}

		throw new Exception(
			wp_kses(
				$error_message . ( $error_action ? '<br>' . $error_action : '' ),
				array(
					'p'  => array(),
					'br' => array(),
					'a'  => array(
						'href'  => array(),
						'class' => array(),
					),
				)
			)
		);
	}

	/**
	 * Check if a repository URL is safe to send an API key to.
	 *
	 * Only HTTPS URLs are accepted. Local development servers can be allowed with the
	 * `external_repositories_allow_insecure_url` filter.
	 *
	 * @param string $url
	 *
	 * @return bool
	 */
	public static function is_secure_url( $url ): bool {
		$scheme = wp_parse_url( (string) $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( (string) $url, PHP_URL_HOST );

		if ( ! $host ) {
			return false;
		}

		return 'https' === $scheme || (bool) apply_filters( 'external_repositories_allow_insecure_url', false, $url );
	}

	/**
	 * Check if an Update URI header points to this repository.
	 *
	 * @param string $update_uri
	 *
	 * @return bool
	 */
	public function owns_update_uri( $update_uri ): bool {
		return $update_uri && $this->get_hostname() === wp_parse_url( $update_uri, PHP_URL_HOST );
	}

	/**
	 * Download a fresh package for an update.
	 *
	 * The download link returned by the API is a short-lived signed URL, while WordPress may keep it in the
	 * update transient for hours: fetch a new link right before downloading.
	 *
	 * @param string $endpoint Item endpoint (eg. "plugins/my-plugin").
	 * @param string $package Package URL WordPress is about to download.
	 *
	 * @return string|false Path of the downloaded file, or false to let WordPress download the original package.
	 */
	public function download_fresh_package( string $endpoint, $package ) {
		try {
			$result = $this->call_api( $endpoint, array( 'installed' => 1 ) );
		} catch ( Exception $e ) {
			return false;
		}

		if ( empty( $result['download_link'] ) || $result['download_link'] === $package ) {
			return false;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $result['download_link'] );

		return is_wp_error( $file ) ? false : $file;
	}

	/**
	 * Extract error messages from an API response body.
	 *
	 * @param mixed $body Decoded response body.
	 *
	 * @return string Escaped messages, or an empty string if none.
	 */
	protected function get_response_messages( $body ): string {
		if ( ! is_array( $body ) || empty( $body['messages'] ) ) {
			return '';
		}

		$messages = is_array( $body['messages'] ) ? implode( "\n", array_values( $body['messages'] ) ) : $body['messages'];

		return esc_html( $messages );
	}

	/**
	 * Name of the transient flagging the repository as unreachable.
	 *
	 * @return string
	 */
	protected function get_unavailable_transient_name(): string {
		return 'external_repository_' . md5( $this->url ) . '_unavailable';
	}

	/**
	 * Flag the repository as unreachable for a few minutes.
	 *
	 * @return void
	 */
	protected function mark_as_unavailable(): void {
		set_site_transient( $this->get_unavailable_transient_name(), 1, self::UNAVAILABLE_TTL );
	}

	/**
	 * Remove the unreachable flag, so the next call reaches the API.
	 *
	 * @param string $url Repository API URL.
	 *
	 * @return void
	 */
	public static function clear_unavailable_flag( string $url ): void {
		delete_site_transient( 'external_repository_' . md5( $url ) . '_unavailable' );
	}

	/**
	 * Generate settings URL.
	 *
	 * @return string
	 */
	public function get_settings_url(): string {
		if ( ! $this->ID ) {
			return '';
		}

		return ExternalRepositoryManager::get_settings_url( $this->ID );
	}

	/**
	 * @param string $text
	 * @param bool   $display
	 *
	 * @return string|void
	 */
	public function get_settings_button( $text, $display = true ) {
		$settings_url = $this->get_settings_url();

		if ( $settings_url ) {
			$button = sprintf( '<a href="%1$s" class="button external-repository-settings">%2$s</a>', esc_url( $settings_url ), $text );

			if ( $display ) {
				echo wp_kses(
					$button,
					array(
						'p' => array(
							'class' => array(),
						),
						'a' => array(
							'href'  => array(),
							'class' => array(),
						),
					)
				);
			} else {
				return $button;
			}
		}
	}

	/**
	 * Get plugins tab URL.
	 *
	 * @return string|null
	 */
	public function get_plugins_url(): ?string {
		return in_array( 'plugins', $this->supports, true ) ? network_admin_url( 'plugin-install.php?tab=' . $this->slug ) : null;
	}

	/**
	 * Get themes tab URL.
	 *
	 * @return string|null
	 */
	public function get_themes_url(): ?string {
		return null;
	}
}
