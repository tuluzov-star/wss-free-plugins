<?php
/**
 * WordPress updater for WSS Bookings Schedule Lite.
 *
 * @package WSS_Bookings_Schedule
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_BS_Updater {
	private const PRODUCT_ID      = 'wss_bookings_schedule_free';
	private const UPDATE_ENDPOINT = 'https://website-support.ru/wp-json/wss-updates/v1/check';
	private const TRANSIENT_KEY   = 'wss_bs_free_update_info';

	private string $plugin_file;
	private string $plugin_basename;
	private string $version;
	private string $homepage;

	public function __construct( string $plugin_file, string $version, string $homepage ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->version         = $version;
		$this->homepage        = $homepage;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_plugin_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api_info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_update_cache' ), 10, 2 );
	}

	public function inject_plugin_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		$update = $this->get_update_info();
		if ( empty( $update['new_version'] ) || empty( $update['package'] ) ) {
			return $transient;
		}

		if ( version_compare( $this->version, (string) $update['new_version'], '>=' ) ) {
			return $transient;
		}

		$transient->response[ $this->plugin_basename ] = (object) array(
			'slug'        => dirname( $this->plugin_basename ),
			'plugin'      => $this->plugin_basename,
			'new_version' => (string) $update['new_version'],
			'url'         => (string) ( $update['homepage'] ?? $this->homepage ),
			'package'     => (string) $update['package'],
		);

		return $transient;
	}

	public function plugins_api_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( $this->plugin_basename ) !== $args->slug ) {
			return $result;
		}

		$update = $this->get_update_info();

		return (object) array(
			'name'              => 'WSS Bookings Schedule Lite',
			'slug'              => dirname( $this->plugin_basename ),
			'version'           => (string) ( $update['new_version'] ?? $this->version ),
			'author'            => '<a href="https://website-support.ru/">WSS</a>',
			'homepage'          => (string) ( $update['homepage'] ?? $this->homepage ),
			'short_description' => __( 'Витрина расписания для booking-товаров WooCommerce.', 'wss-bookings-schedule' ),
			'sections'          => array(
				'description' => __( 'Плагин выводит доступные слоты, навигацию по неделям и адаптивную витрину расписания. Совместим с WSS WooCommerce Bookings и WooCommerce Bookings.', 'wss-bookings-schedule' ),
			),
			'download_link'     => (string) ( $update['package'] ?? '' ),
		);
	}

	public function clear_update_cache( $upgrader, array $hook_extra ): void {
		if ( empty( $hook_extra['plugins'] ) || ! is_array( $hook_extra['plugins'] ) ) {
			return;
		}

		if ( in_array( $this->plugin_basename, $hook_extra['plugins'], true ) ) {
			delete_site_transient( self::TRANSIENT_KEY );
		}
	}

	private function get_update_info(): array {
		$cached = get_site_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'product' => self::PRODUCT_ID,
					'version' => $this->version,
					'domain'  => home_url(),
				),
				self::UPDATE_ENDPOINT
			),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return array();
		}

		set_site_transient( self::TRANSIENT_KEY, $body, 6 * HOUR_IN_SECONDS );
		return $body;
	}
}
