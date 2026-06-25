<?php
/**
 * Self-hosted updater for Stoke Fluid Clamp.
 *
 * Pulls updates from public GitHub Releases on sdc-ramon/stoke-wp-fluid-clamp.
 * No token required because the repo is public (same model as proelements).
 *
 * To ship an update: bump the `Version:` header in stoke-fluid-clamp.php,
 * commit, then create a GitHub Release whose tag matches the new version
 * (with or without a leading "v"). WordPress sites will see the update on
 * their next check.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stoke_Fluid_Clamp_Updater {

	const GITHUB_USER = 'sdc-ramon';
	const GITHUB_REPO = 'stoke-wp-fluid-clamp';
	const CACHE_KEY   = 'sfc_updater_release';
	const CACHE_TTL   = 6 * HOUR_IN_SECONDS;

	private $plugin_file;   // e.g. stoke-fluid-clamp/stoke-fluid-clamp.php
	private $plugin_slug;   // e.g. stoke-fluid-clamp
	private $version;

	public function __construct( $plugin_file, $version ) {
		$this->plugin_file = plugin_basename( $plugin_file );
		$this->plugin_slug = dirname( $this->plugin_file );
		$this->version     = $version;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
	}

	/**
	 * Fetch the latest GitHub release, cached in a transient.
	 *
	 * @return array|false Normalized release data or false on failure.
	 */
	private function get_latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			self::GITHUB_USER,
			self::GITHUB_REPO
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'StokeFluidClamp-Updater',
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly so a flaky/rate-limited API doesn't hammer on every page load.
			set_site_transient( self::CACHE_KEY, [], MINUTE_IN_SECONDS * 15 );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['tag_name'] ) ) {
			set_site_transient( self::CACHE_KEY, [], MINUTE_IN_SECONDS * 15 );
			return false;
		}

		$version = ltrim( $body['tag_name'], 'vV' );

		// Prefer an attached .zip asset; fall back to GitHub's auto source zipball.
		$package = $body['zipball_url'] ?? '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( isset( $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
		}

		$release = [
			'version'      => $version,
			'package'      => $package,
			'html_url'     => $body['html_url'] ?? '',
			'body'         => $body['body'] ?? '',
			'published_at' => $body['published_at'] ?? '',
		];

		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Inject our update into the plugins update transient.
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( empty( $release ) || empty( $release['package'] ) ) {
			return $transient;
		}

		if ( version_compare( $release['version'], $this->version, '>' ) ) {
			$transient->response[ $this->plugin_file ] = (object) [
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_file,
				'new_version' => $release['version'],
				'package'     => $release['package'],
				'url'         => $release['html_url'],
			];
		} else {
			// Tell WP it's current so the "no update" state is accurate.
			$transient->no_update[ $this->plugin_file ] = (object) [
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_file,
				'new_version' => $this->version,
				'package'     => '',
				'url'         => $release['html_url'],
			];
		}

		return $transient;
	}

	/**
	 * Provide data for the "View details" modal.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( empty( $release ) ) {
			return $result;
		}

		return (object) [
			'name'          => 'Stoke Fluid Clamp',
			'slug'          => $this->plugin_slug,
			'version'       => $release['version'],
			'author'        => 'Stoke Design Co',
			'homepage'      => $release['html_url'],
			'download_link' => $release['package'],
			'last_updated'  => $release['published_at'],
			'sections'      => [
				'changelog' => $release['body'] ? wp_kses_post( wpautop( $release['body'] ) ) : 'See GitHub releases.',
			],
		];
	}

	/**
	 * GitHub source zipballs unpack to a versioned folder
	 * (e.g. sdc-ramon-stoke-wp-fluid-clamp-abc1234/). WordPress needs the
	 * folder to match the plugin slug, so rename it before install.
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = null ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->plugin_slug;

		if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}
}
