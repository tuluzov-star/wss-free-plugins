<?php
/**
 * Plugin Name: WSS Bookings Schedule Lite
 * Description: Витрина расписания для booking-товаров WooCommerce. Совместима с WSS WooCommerce Bookings и WooCommerce Bookings.
 * Version: 0.3.10
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

define( 'WSS_BS_VERSION', '0.3.10' );
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

add_action(
    'admin_enqueue_scripts',
    static function ( $hook_suffix ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $is_booking_page = false !== strpos( $page, 'booking' ) || false !== strpos( $page, 'schedule' );
        $is_woocommerce_page = false !== strpos( (string) $hook_suffix, 'woocommerce' );

        if ( ! $is_booking_page && ! $is_woocommerce_page ) {
            return;
        }

        wp_enqueue_style(
            'wss-bookings-schedule-admin-tools',
            WSS_BS_URL . 'assets/css/admin-tools.css',
            array(),
            WSS_BS_VERSION
        );
        wp_enqueue_script(
            'wss-bookings-schedule-admin-tools',
            WSS_BS_URL . 'assets/js/admin-tools.js',
            array( 'jquery' ),
            WSS_BS_VERSION,
            true
        );
        wp_localize_script(
            'wss-bookings-schedule-admin-tools',
            'WSS_BS_ADMIN_TOOLS',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wss_bs_bulk_delete_slots' ),
                'labels'  => array(
                    'date'           => __( 'Дата', 'wss-bookings-schedule' ),
                    'selectAll'      => __( 'Выбрать все даты', 'wss-bookings-schedule' ),
                    'selectDate'     => __( 'Выбрать дату ', 'wss-bookings-schedule' ),
                    'deleteSelected' => __( 'Удалить выбранные', 'wss-bookings-schedule' ),
                    'selectedCount'  => __( 'Выбрано дат: ', 'wss-bookings-schedule' ),
                    'confirmPrefix'  => __( 'Удалить выбранные даты (', 'wss-bookings-schedule' ),
                    'confirmSuffix'  => __( ') и все слоты в них? Действие необратимо.', 'wss-bookings-schedule' ),
                    'deleting'       => __( 'Удаление…', 'wss-bookings-schedule' ),
                    'deleteError'    => __( 'Не удалось удалить выбранные слоты. Обновите страницу и повторите попытку.', 'wss-bookings-schedule' ),
                ),
            )
        );
    }
);

add_action(
    'wp_ajax_wss_bs_bulk_delete_slots',
    static function () {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'wss-bookings-schedule' ) ), 403 );
        }

        check_ajax_referer( 'wss_bs_bulk_delete_slots', 'nonce' );

        $raw_ids = isset( $_POST['slot_ids'] ) ? (array) wp_unslash( $_POST['slot_ids'] ) : array();
        $slot_ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
        if ( ! $slot_ids ) {
            wp_send_json_error( array( 'message' => __( 'Не выбраны слоты для удаления.', 'wss-bookings-schedule' ) ), 400 );
        }

        if ( ! class_exists( 'WSS_WooCommerce_Bookings' ) || ! is_callable( array( 'WSS_WooCommerce_Bookings', 'table_name' ) ) ) {
            wp_send_json_error( array( 'message' => __( 'WSS WooCommerce Bookings недоступен.', 'wss-bookings-schedule' ) ), 400 );
        }

        global $wpdb;
        $table = WSS_WooCommerce_Bookings::table_name();
        $deleted = 0;
        foreach ( $slot_ids as $slot_id ) {
            $result = $wpdb->delete( $table, array( 'id' => $slot_id ), array( '%d' ) );
            if ( false !== $result ) {
                $deleted += (int) $result;
            }
        }

        wp_send_json_success( array( 'deleted' => $deleted ) );
    }
);

register_activation_hook( __FILE__, array( 'WSS_BS_Plugin', 'activate' ) );

if ( is_admin() && class_exists( 'WSS_BS_Updater' ) ) {
	new WSS_BS_Updater(
		WSS_BS_FILE,
		WSS_BS_VERSION,
		'https://website-support.ru/plugins/wss-bookings-schedule/'
	);
}

add_action( 'plugins_loaded', array( 'WSS_BS_Plugin', 'init' ) );
