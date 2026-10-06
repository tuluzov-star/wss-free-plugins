<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WSS_WCL_Settings {
    const OPTION = 'wss_wcl_settings';

    public static function defaults() {
        return array(
            'enabled'               => 'yes',
            'mode'                  => 'infinite',
            'mobile_override'       => 'no',
            'mobile_mode'           => 'infinite',
            'mobile_breakpoint'     => 768,
            'root_margin'           => 600,
            'auto_pages'            => 0,
            'history'               => 'yes',
            'session_cache'         => 'yes',
            'hide_result_count'     => 'yes',
            'button_text'           => __( 'Load more', 'wss-woo-catalog-loader' ),
            'loading_text'          => __( 'Loading products…', 'wss-woo-catalog-loader' ),
            'error_text'            => __( 'Could not load products.', 'wss-woo-catalog-loader' ),
            'retry_text'            => __( 'Try again', 'wss-woo-catalog-loader' ),
            'products_selector'     => '.products',
            'product_selector'      => '.product',
            'pagination_selector'   => '.woocommerce-pagination',
            'next_selector'         => '.woocommerce-pagination a.next',
            'result_count_selector' => '.woocommerce-result-count',
        );
    }

    public static function get() {
        return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
    }

    public static function sanitize( $input ) {
        $input = is_array( $input ) ? $input : array();
        $d     = self::defaults();
        $out   = array();

        $out['enabled']         = ! empty( $input['enabled'] ) ? 'yes' : 'no';
        $out['mode']            = in_array( $input['mode'] ?? '', array( 'infinite', 'load_more', 'pagination' ), true ) ? $input['mode'] : $d['mode'];
        $out['mobile_override'] = ! empty( $input['mobile_override'] ) ? 'yes' : 'no';
        $out['mobile_mode']     = in_array( $input['mobile_mode'] ?? '', array( 'infinite', 'load_more', 'pagination' ), true ) ? $input['mobile_mode'] : $d['mobile_mode'];

        $out['mobile_breakpoint'] = min( 2000, max( 320, absint( $input['mobile_breakpoint'] ?? $d['mobile_breakpoint'] ) ) );
        $out['root_margin']       = min( 3000, max( 0, absint( $input['root_margin'] ?? $d['root_margin'] ) ) );
        $out['auto_pages']        = min( 50, max( 0, absint( $input['auto_pages'] ?? $d['auto_pages'] ) ) );

        $out['history']           = ! empty( $input['history'] ) ? 'yes' : 'no';
        $out['session_cache']     = ! empty( $input['session_cache'] ) ? 'yes' : 'no';
        $out['hide_result_count'] = ! empty( $input['hide_result_count'] ) ? 'yes' : 'no';

        foreach ( array( 'button_text', 'loading_text', 'error_text', 'retry_text' ) as $key ) {
            $out[ $key ] = sanitize_text_field( $input[ $key ] ?? $d[ $key ] );
        }

        foreach ( array( 'products_selector', 'product_selector', 'pagination_selector', 'next_selector', 'result_count_selector' ) as $key ) {
            $value       = trim( (string) ( $input[ $key ] ?? $d[ $key ] ) );
            $out[ $key ] = '' !== $value ? sanitize_text_field( $value ) : $d[ $key ];
        }

        return $out;
    }
}
