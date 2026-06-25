<?php
/**
 * Plugin Name: WSS Yandex Calendar for WooCommerce Bookings
 * Description: Бесплатная версия: односторонняя синхронизация новых будущих бронирований WooCommerce Bookings в Яндекс.Календарь через CalDAV.
 * Version: 1.0.1
 * Author: WSS
 * Author URI: https://website-support.ru/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.0
 * Text Domain: wss-wcb-yandex-calendar
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WSS_WCB_YC_VERSION', '1.0.1');
define('WSS_WCB_YC_FILE', __FILE__);
define('WSS_WCB_YC_PATH', plugin_dir_path(__FILE__));
define('WSS_WCB_YC_URL', plugin_dir_url(__FILE__));
require_once WSS_WCB_YC_PATH . 'includes/class-wss-wcb-yc-updater.php';

if (is_admin() && class_exists('WSS_WCB_YC_Updater')) {
    new WSS_WCB_YC_Updater(
        WSS_WCB_YC_FILE,
        WSS_WCB_YC_VERSION,
        'https://website-support.ru/plugins/yandex-calendar-for-woocommerce-bookings/'
    );
}

add_action('before_woocommerce_init', static function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

require_once WSS_WCB_YC_PATH . 'includes/class-wss-wcb-yc-caldav-client.php';
require_once WSS_WCB_YC_PATH . 'includes/class-wss-wcb-yc-ics-builder.php';
require_once WSS_WCB_YC_PATH . 'includes/class-wss-wcb-yc-plugin.php';

add_action('plugins_loaded', static function () {
    WSS_WCB_YC_Plugin::instance();
});

register_activation_hook(__FILE__, static function () {
    update_option('wss_wcb_yc_needs_flush', 1, false);
});
