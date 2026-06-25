<?php
/**
 * Plugin Name: WSS Bookings Schedule Lite
 * Description: Витрина расписания для booking-товаров WooCommerce. Совместима с WSS WooCommerce Bookings и WooCommerce Bookings.
 * Version: 0.3.5
 * Author: WSS
 * Author URI: https://website-support.ru/
 * Text Domain: wss-bookings-schedule
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WSS_BS_VERSION', '0.3.5' );
define( 'WSS_BS_FILE', __FILE__ );
define( 'WSS_BS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSS_BS_URL', plugin_dir_url( __FILE__ ) );
require_once WSS_BS_DIR . 'includes/class-wss-bs-updater.php';
define( 'WSS_BS_IS_PRO', false );
define( 'WSS_BS_UPGRADE_URL', 'https://website-support.ru/plugins/wss-bookings-schedule/' );

require_once WSS_BS_DIR . 'includes/class-wss-bs-plugin.php';
require_once WSS_BS_DIR . 'includes/class-wss-bs-settings.php';
require_once WSS_BS_DIR . 'includes/class-wss-bs-shortcode.php';
require_once WSS_BS_DIR . 'includes/class-wss-bs-query.php';

register_activation_hook( __FILE__, array( 'WSS_BS_Plugin', 'activate' ) );

if ( is_admin() && class_exists( 'WSS_BS_Updater' ) ) {
	new WSS_BS_Updater(
		WSS_BS_FILE,
		WSS_BS_VERSION,
		'https://website-support.ru/plugins/wss-bookings-schedule/'
	);
}

add_action( 'plugins_loaded', array( 'WSS_BS_Plugin', 'init' ) );
