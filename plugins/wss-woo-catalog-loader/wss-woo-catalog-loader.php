<?php
/**
 * Plugin Name: WSS Woo Catalog Loader
 * Plugin URI: https://github.com/tuluzov-star/wss-free-plugins/tree/main/plugins/wss-woo-catalog-loader
 * Description: Infinite scroll, Load More and AJAX pagination for classic WooCommerce product archives without a jQuery dependency.
 * Version: 1.1.0
 * Author: Alex Tuluzov / WSS
 * Author URI: https://website-support.ru/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 * Text Domain: wss-woo-catalog-loader
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WSS_WCL_VERSION', '1.1.0' );
define( 'WSS_WCL_FILE', __FILE__ );
define( 'WSS_WCL_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSS_WCL_URL', plugin_dir_url( __FILE__ ) );

require_once WSS_WCL_DIR . 'includes/class-wss-wcl-settings.php';
require_once WSS_WCL_DIR . 'includes/class-wss-wcl-plugin.php';

add_action(
    'init',
    static function () {
        load_plugin_textdomain(
            'wss-woo-catalog-loader',
            false,
            dirname( plugin_basename( WSS_WCL_FILE ) ) . '/languages'
        );
    }
);

add_action(
    'before_woocommerce_init',
    static function () {
        if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);

add_action(
    'plugins_loaded',
    static function () {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action(
                'admin_notices',
                static function () {
                    if ( ! current_user_can( 'activate_plugins' ) ) {
                        return;
                    }
                    ?>
                    <div class="notice notice-warning">
                        <p><?php esc_html_e( 'WSS Woo Catalog Loader requires WooCommerce to be installed and active.', 'wss-woo-catalog-loader' ); ?></p>
                    </div>
                    <?php
                }
            );
            return;
        }

        WSS_WCL_Plugin::instance();
    },
    20
);
