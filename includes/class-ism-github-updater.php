<?php
/**
 * GitHub Updater for RW Image Size Manager.
 *
 * Checks the configured GitHub repository for a newer release and hooks into
 * WordPress's plugin update mechanism so the standard admin "Updates" flow works.
 *
 * Usage:
 *   new ISM_GitHub_Updater( __FILE__, 'github-username', 'repo-name' );
 *
 * Releases must be tagged in GitHub as "v1.2.3" (with the leading "v").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISM_GitHub_Updater {

	/** plugin-dir/plugin-file.php */
	private $slug;

	/** GitHub account / org name */
	private $github_user;

	/** GitHub repository slug */
	private $github_repo;

	/** GitHub API URL for the latest release */
	private $api_url;

	/** Optional personal-access token for private repos */
	private $access_token;

	/** Cached API response (stdClass or null) */
	private $release = null;

	// ─────────────────────────────────────────────────────────────────────────
	// Boot
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @param string $plugin_file   Absolute path to the main plugin file (__FILE__).
	 * @param string $github_user   GitHub username or organisation.
	 * @param string $github_repo   Repository name (no owner prefix).
	 * @param string $access_token  Optional PAT for private repositories.
	 */
	public function __construct( string $plugin_file, string $github_user, string $github_repo, string $access_token = '' ) {
		$this->slug         = plugin_basename( $plugin_file );
		$this->github_user  = $github_user;
		$this->github_repo  = $github_repo;
		$this->api_url      = "https://api.github.com/repos/{$github_user}/{$github_repo}/releases/latest";
		$this->access_token = $access_token;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_post_install',                 [ $this, 'after_install' ], 10, 3 );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// GitHub API
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Fetch the latest release from GitHub, using a 6-hour transient cache.
	 *
	 * @return \stdClass|null
	 */
	private function get_release() {
		if ( $this->release !== null ) {
			return $this->release;
		}

		$cache_key = 'ism_github_release_' . md5( $this->github_user . '/' . $this->github_repo );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$this->release = $cached;
			return $this->release;
		}

		$args = [
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			],
			'timeout' => 10,
		];

		if ( ! empty( $this->access_token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
		}

		$response = wp_remote_get( $this->api_url, $args );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body ) || empty( $body->tag_name ) ) {
			return null;
		}

		set_transient( $cache_key, $body, 6 * HOUR_IN_SECONDS );

		$this->release = $body;
		return $this->release;
	}

	/**
	 * Delete the cached release so WordPress forces a fresh check on next visit.
	 */
	public function clear_cache(): void {
		$cache_key = 'ism_github_release_' . md5( $this->github_user . '/' . $this->github_repo );
		delete_transient( $cache_key );
		$this->release = null;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// WordPress hooks
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Inject update data into the WordPress plugin update transient.
	 *
	 * @param  \stdClass $transient
	 * @return \stdClass
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release();

		if ( ! $release ) {
			return $transient;
		}

		$remote_version  = ltrim( $release->tag_name, 'v' );
		$current_version = $transient->checked[ $this->slug ] ?? ISM_VERSION;

		if ( version_compare( $remote_version, $current_version, '>' ) ) {
			$download_url = $release->zipball_url;

			// Append token to download URL for private repos.
			if ( ! empty( $this->access_token ) ) {
				$download_url = add_query_arg( 'access_token', $this->access_token, $download_url );
			}

			$update                = new \stdClass();
			$update->slug          = dirname( $this->slug );
			$update->plugin        = $this->slug;
			$update->new_version   = $remote_version;
			$update->url           = "https://github.com/{$this->github_user}/{$this->github_repo}";
			$update->package       = $download_url;
			$update->icons         = [];
			$update->banners       = [];
			$update->tested        = '';
			$update->requires_php  = '';
			$update->compatibility = new \stdClass();

			$transient->response[ $this->slug ] = $update;
		}

		return $transient;
	}

	/**
	 * Provide plugin detail info for the "View version x.x.x details" modal.
	 *
	 * @param  mixed     $result
	 * @param  string    $action
	 * @param  \stdClass $args
	 * @return mixed
	 */
	public function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->slug ) ) {
			return $result;
		}

		$release     = $this->get_release();
		$plugin_data = $this->get_plugin_header_data();

		if ( ! $release ) {
			return $result;
		}

		$info                    = new \stdClass();
		$info->name              = $plugin_data['Name'];
		$info->slug              = dirname( $this->slug );
		$info->version           = ltrim( $release->tag_name, 'v' );
		$info->author            = $plugin_data['AuthorName'];
		$info->homepage          = "https://github.com/{$this->github_user}/{$this->github_repo}";
		$info->short_description = $plugin_data['Description'];
		$info->sections          = [
			'description' => $plugin_data['Description'],
			'changelog'   => ! empty( $release->body ) ? nl2br( esc_html( $release->body ) ) : '',
		];
		$info->download_link     = $release->zipball_url;
		$info->requires          = '6.0';

		return $info;
	}

	/**
	 * After the zip is downloaded and extracted, move the folder to the correct
	 * plugin directory path.
	 *
	 * GitHub zipballs extract to "user-repo-{hash}/" rather than "rw-image-size-manager/",
	 * so we rename the destination to match the expected slug.
	 *
	 * @param  bool|\WP_Error $response
	 * @param  array          $hook_extra
	 * @param  array          $result
	 * @return bool|\WP_Error
	 */
	public function after_install( $response, array $hook_extra, array $result ) {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) {
			return $response;
		}

		$target = trailingslashit( WP_PLUGIN_DIR ) . dirname( $this->slug );

		$wp_filesystem->move( $result['destination'], $target, true );
		$result['destination'] = $target;

		if ( is_plugin_active( $this->slug ) ) {
			activate_plugin( $this->slug );
		}

		return $response;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Return the parsed plugin header data.
	 *
	 * @return array
	 */
	private function get_plugin_header_data(): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugin_data( WP_PLUGIN_DIR . '/' . $this->slug );
	}
}
