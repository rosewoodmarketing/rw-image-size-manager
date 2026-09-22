<?php
/**
 * Hub updater for RW Image Manager via Rosewood License Manager.
 *
 * This updater intentionally omits license-key UI/collection and relies on
 * the hub-side plugin mapping setting to determine whether a key is required.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISM_Hub_Updater {

	/** @var string */
	private $plugin_file;

	/** @var string */
	private $slug;

	/** @var string */
	private $hub_url;

	/**
	 * @param string $plugin_file Main plugin file path.
	 * @param string $slug        Plugin slug configured in RWLM Plugin Mappings.
	 * @param string $hub_url     Base URL of the RWLM hub.
	 */
	public function __construct( string $plugin_file, string $slug, string $hub_url ) {
		$this->plugin_file = $plugin_file;
		$this->slug        = sanitize_title( $slug );
		$this->hub_url     = untrailingslashit( $hub_url );

		add_action( 'init', [ $this, 'register_update_checker' ] );
	}

	/**
	 * Register Plugin Update Checker against the hub endpoint.
	 */
	public function register_update_checker(): void {
		if ( ! class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
			return;
		}
		if ( '' === $this->hub_url || '' === $this->slug ) {
			return;
		}

		$check_url = add_query_arg(
			array(
				'slug'     => $this->slug,
				'site_url' => rawurlencode( home_url() ),
			),
			$this->hub_url . '/wp-json/rwlm/v1/update-check'
		);

		\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			$check_url,
			$this->plugin_file,
			$this->slug
		);
	}
}
