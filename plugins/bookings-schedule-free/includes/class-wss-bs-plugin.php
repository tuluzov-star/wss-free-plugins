<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WSS_BS_Plugin {
    const OPTION_NAME = 'wss_bs_options';

    public static function init() {
        WSS_BS_Settings::init();

        if ( class_exists( 'WooCommerce' ) && self::is_bookings_engine_available() ) {
            WSS_BS_Shortcode::init();
        } else {
            add_shortcode( 'wss_bookings_schedule', array( __CLASS__, 'render_missing_engine_notice' ) );
        }

        add_action( 'admin_notices', array( __CLASS__, 'dependencies_notice' ) );
    }

    public static function render_missing_engine_notice() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return '<div class="wss-bs-notice">Для вывода расписания нужен активный WooCommerce.</div>';
        }

        return '<div class="wss-bs-notice">Для вывода расписания нужен активный WSS WooCommerce Bookings или WooCommerce Bookings.</div>';
    }

    public static function activate() {
        $existing = get_option( self::OPTION_NAME, array() );
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }

        update_option( self::OPTION_NAME, wp_parse_args( $existing, self::defaults() ) );
    }

    public static function defaults() {
        return array(
            'days'                    => 7,
            'start_mode'              => 'week',
            'selected_products'       => '',
            'excluded_products'       => '',
            'selected_categories'     => '',
            'hide_resource_products'  => 'yes',
            'show_product_filter'     => 'no',
            'show_price'              => 'yes',
            'show_capacity'           => 'no',
            'show_duration'           => 'yes',
            'show_empty_days'         => 'no',
            'hide_past_today'         => 'yes',
            'timezone_string'         => '',
            'wc_time_mode'            => 'local_wall',
            'show_title'              => 'no',
            'title'                   => 'Расписание экскурсий',
            'label_prev'              => 'Предыдущая неделя',
            'label_current'           => 'Эта неделя',
            'label_next'              => 'Следующая неделя',
            'label_all_products'      => 'Все экскурсии',
            'label_no_events'         => 'На выбранные даты доступных экскурсий нет.',
            'label_no_product_events' => 'На эту дату нет выбранной экскурсии.',
            'label_book'              => 'Забронировать',
            'label_select'            => 'Выбрать',
            'label_time'              => 'Время',
            'label_price'             => 'Цена',
            'label_capacity'          => 'Осталось мест: %s',
            'label_sold_out'          => 'Мест нет',
            'label_resource_skipped'  => 'Товар использует ресурсы и скрыт в расписании.',
            'date_format'             => 'j F, Y',
            'range_date_format'       => 'j M',
            'time_format'             => 'H:i',
            'primary_color'           => '#142432',
            'accent_color'            => '#244f7a',
            'button_bg'               => '#2d343b',
            'button_text'             => '#ffffff',
            'card_radius'             => 18,
            'button_radius'           => 999,
            'single_day_button_mode'  => 'product',
        );
    }

    public static function get_options() {
        $options = get_option( self::OPTION_NAME, array() );
        if ( ! is_array( $options ) ) {
            $options = array();
        }

        $options = wp_parse_args( $options, self::defaults() );

        return self::apply_lite_limits( $options );
    }

    private static function apply_lite_limits( $options ) {
        $options['days']                  = min( 7, max( 1, absint( $options['days'] ) ) );
        $options['selected_products']     = '';
        $options['excluded_products']     = '';
        $options['selected_categories']   = '';
        $options['show_product_filter']   = 'no';
        $options['show_capacity']         = 'no';
        $options['timezone_string']       = '';
        $options['wc_time_mode']          = 'local_wall';
        $options['primary_color']         = '#142432';
        $options['accent_color']          = '#244f7a';
        $options['button_bg']             = '#2d343b';
        $options['button_text']           = '#ffffff';
        $options['card_radius']           = 18;
        $options['button_radius']         = 999;

        return $options;
    }

    public static function get_option( $key, $default = null ) {
        $options = self::get_options();
        return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
    }

    public static function dependencies_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            echo '<div class="notice notice-error"><p><strong>WSS Bookings Schedule Lite:</strong> требуется установленный и активный WooCommerce.</p></div>';
            return;
        }

        if ( ! self::is_bookings_engine_available() ) {
            echo '<div class="notice notice-warning"><p><strong>WSS Bookings Schedule Lite:</strong> требуется активный WSS WooCommerce Bookings или WooCommerce Bookings. Данные расписания в базе сами по себе не считаются активным движком бронирований.</p></div>';
        }
    }

    public static function is_bookings_engine_available() {
        return class_exists( 'WSS_WooCommerce_Bookings' )
            || class_exists( 'WC_Product_Booking' )
            || class_exists( 'WC_Bookings' )
            || function_exists( 'get_wc_booking' );
    }
}
