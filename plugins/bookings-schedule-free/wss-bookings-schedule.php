<?php
/**
 * Plugin Name: WSS Bookings Schedule Lite
 * Description: Витрина расписания для booking-товаров WooCommerce. Совместима с WSS WooCommerce Bookings и WooCommerce Bookings.
 * Version: 0.3.14
 * Author: WSS
 * Author URI: https://website-support.ru/
 * Text Domain: wss-bookings-schedule
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/wss-i18n.php';
WSS_Plugin_I18n_202609::register(__FILE__, 'wss-bookings-schedule');

define( 'WSS_BS_VERSION', '0.3.14' );
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

/** Use the official WooCommerce Bookings default-date filter. */
function wss_bs_override_booking_default_date( $default_date, $picker ) {
    if ( isset( $_GET['wss_booking_start'] ) ) {
        $timestamp = absint( wp_unslash( $_GET['wss_booking_start'] ) );
        if ( $timestamp > 0 ) {
            return $timestamp;
        }
    }
    if ( isset( $_GET['wss_booking_date'] ) ) {
        $date = sanitize_text_field( wp_unslash( $_GET['wss_booking_date'] ) );
        $dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
        if ( $dt instanceof DateTimeImmutable && $dt->format( 'Y-m-d' ) === $date ) {
            return $dt->getTimestamp();
        }
    }
    return $default_date;
}
add_filter( 'woocommerce_bookings_override_form_default_date', 'wss_bs_override_booking_default_date', 10, 2 );
