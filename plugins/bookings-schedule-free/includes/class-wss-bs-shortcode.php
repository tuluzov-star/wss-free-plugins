<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WSS_BS_Shortcode {
    public static function init() {
        add_shortcode( 'wss_bookings_schedule', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
    }

    public static function register_assets() {
        wp_register_style(
            'wss-bookings-schedule',
            WSS_BS_URL . 'assets/css/frontend.css',
            array(),
            WSS_BS_VERSION
        );

        wp_register_script(
            'wss-bookings-schedule',
            WSS_BS_URL . 'assets/js/frontend.js',
            array( 'jquery' ),
            WSS_BS_VERSION,
            true
        );

        if ( isset( $_GET['wss_booking_date'] ) || isset( $_GET['wss_booking_time'] ) || isset( $_GET['wss_booking_start'] ) ) {
            wp_enqueue_script( 'wss-bookings-schedule' );
        }
    }

    public static function render( $atts = array() ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return '<div class="wss-bs-notice">Для вывода расписания нужен WooCommerce.</div>';
        }

        $options = WSS_BS_Plugin::get_options();
        $atts    = shortcode_atts(
            array(
                'days'     => $options['days'],
                'products' => '',
                'product'  => 0,
                'category' => '',
                'start'    => isset( $_GET['wss_bs_start'] ) ? sanitize_text_field( wp_unslash( $_GET['wss_bs_start'] ) ) : '',
            ),
            $atts,
            'wss_bookings_schedule'
        );

        $atts['days']     = min( 7, max( 1, absint( $atts['days'] ) ) );
        $atts['products'] = '';
        $atts['product']  = 0;
        $atts['category'] = '';

        $start_date = $atts['start'] ? $atts['start'] : WSS_BS_Query::get_default_start_date( $options );

        $schedule = WSS_BS_Query::get_schedule(
            array(
                'start_date' => $start_date,
                'days'       => $atts['days'],
                'products'   => $atts['products'],
                'product'    => $atts['product'],
                'category'   => $atts['category'],
            )
        );

        wp_enqueue_style( 'wss-bookings-schedule' );
        wp_enqueue_script( 'wss-bookings-schedule' );

        $inline_css = self::build_inline_css( $options );
        wp_add_inline_style( 'wss-bookings-schedule', $inline_css );

        $days = $schedule['days'];
        $prev = clone $schedule['start'];
        $prev->modify( '-' . absint( $atts['days'] ) . ' days' );
        $next = clone $schedule['start'];
        $next->modify( '+' . absint( $atts['days'] ) . ' days' );
        $current = WSS_BS_Query::get_default_start_date( $options );

        $range_label = self::get_range_label( $schedule['start'], $schedule['end'], $options );
        $selected_filter_product = isset( $_GET['wss_bs_product'] ) ? absint( wp_unslash( $_GET['wss_bs_product'] ) ) : 0;
        if ( $selected_filter_product && ! isset( $schedule['products'][ $selected_filter_product ] ) ) {
            $selected_filter_product = 0;
        }
        $base_url = remove_query_arg( array( 'wss_bs_start' ) );
        if ( $selected_filter_product ) {
            $base_url = add_query_arg( 'wss_bs_product', $selected_filter_product, $base_url );
        } else {
            $base_url = remove_query_arg( 'wss_bs_product', $base_url );
        }

        $display_days = array();
        foreach ( $days as $day ) {
            if ( empty( $day['events'] ) && 'yes' !== $options['show_empty_days'] ) {
                continue;
            }
            $display_days[] = $day;
        }

        $active_day_key = '';
        foreach ( $display_days as $day ) {
            if ( ! empty( $day['events'] ) ) {
                $active_day_key = $day['key'];
                break;
            }
        }
        if ( '' === $active_day_key && ! empty( $display_days ) ) {
            $active_day_key = $display_days[0]['key'];
        }

        ob_start();
        ?>
        <div class="wss-bs" data-wss-bs-schedule data-wss-bs-filter-param="wss_bs_product" data-wss-bs-no-events="<?php echo esc_attr( $options['label_no_events'] ); ?>" data-wss-bs-no-product-events="<?php echo esc_attr( $options['label_no_product_events'] ); ?>">
            <div class="wss-bs__header">
                <?php if ( 'yes' === $options['show_title'] && '' !== trim( $options['title'] ) ) : ?>
                    <h2 class="wss-bs__title"><?php echo esc_html( $options['title'] ); ?></h2>
                <?php endif; ?>

                <div class="wss-bs__range"><?php echo esc_html( $range_label ); ?></div>

                <nav class="wss-bs__nav" aria-label="Навигация расписания">
                    <a class="wss-bs__nav-link" href="<?php echo esc_url( add_query_arg( 'wss_bs_start', $prev->format( 'Y-m-d' ), $base_url ) ); ?>">
                        <span aria-hidden="true">←</span>
                        <span><?php echo esc_html( $options['label_prev'] ); ?></span>
                    </a>
                    <a class="wss-bs__nav-link wss-bs__nav-link--current" href="<?php echo esc_url( add_query_arg( 'wss_bs_start', $current, $base_url ) ); ?>">
                        <?php echo esc_html( $options['label_current'] ); ?>
                    </a>
                    <a class="wss-bs__nav-link" href="<?php echo esc_url( add_query_arg( 'wss_bs_start', $next->format( 'Y-m-d' ), $base_url ) ); ?>">
                        <span><?php echo esc_html( $options['label_next'] ); ?></span>
                        <span aria-hidden="true">→</span>
                    </a>
                </nav>
            </div>

            <?php if ( 'yes' === $options['show_product_filter'] && count( $schedule['products'] ) > 1 ) : ?>
                <?php $filter_id = wp_unique_id( 'wss-bs-product-filter-' ); ?>
                <div class="wss-bs__filter-wrap">
                    <label class="screen-reader-text" for="<?php echo esc_attr( $filter_id ); ?>"><?php echo esc_html( $options['label_all_products'] ); ?></label>
                    <select id="<?php echo esc_attr( $filter_id ); ?>" class="wss-bs__filter" data-wss-bs-filter>
                        <option value=""><?php echo esc_html( $options['label_all_products'] ); ?></option>
                        <?php foreach ( $schedule['products'] as $product ) : ?>
                            <option value="<?php echo esc_attr( $product['id'] ); ?>" <?php selected( $selected_filter_product, $product['id'] ); ?>><?php echo esc_html( $product['title'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ( empty( $schedule['events'] ) ) : ?>
                <div class="wss-bs__empty"><?php echo esc_html( $options['label_no_events'] ); ?></div>
            <?php else : ?>
                <div class="wss-bs__day-tabs" aria-label="Дни расписания">
                    <?php foreach ( $display_days as $day ) : ?>
                        <?php
                        $is_active = $day['key'] === $active_day_key;
                        $is_empty  = empty( $day['events'] );
                        ?>
                        <a class="wss-bs__day-tab<?php echo $is_active ? ' is-active' : ''; ?><?php echo $is_empty ? ' is-filter-empty' : ''; ?>" href="#wss-bs-day-<?php echo esc_attr( $day['key'] ); ?>" data-wss-bs-day-tab="<?php echo esc_attr( $day['key'] ); ?>" aria-current="<?php echo $is_active ? 'date' : 'false'; ?>" aria-disabled="<?php echo $is_empty ? 'true' : 'false'; ?>">
                            <span class="wss-bs__day-tab-weekday"><?php echo esc_html( WSS_BS_Query::format_timestamp( 'D', $day['date'], $options ) ); ?></span>
                            <span class="wss-bs__day-tab-date"><?php echo esc_html( WSS_BS_Query::format_timestamp( 'j', $day['date'], $options ) ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="wss-bs__day-helper" data-wss-bs-day-helper hidden><?php echo esc_html( $options['label_no_product_events'] ); ?></div>

                <div class="wss-bs__days">
                    <?php foreach ( $display_days as $day ) : ?>
                        <?php
                        $event_count = count( $day['events'] );
                        $is_active   = $day['key'] === $active_day_key;
                        ?>
                        <section class="wss-bs__day<?php echo $is_active ? ' is-active' : ''; ?><?php echo empty( $day['events'] ) ? ' is-filter-empty' : ''; ?>" id="wss-bs-day-<?php echo esc_attr( $day['key'] ); ?>" data-wss-bs-day="<?php echo esc_attr( $day['key'] ); ?>">
                            <h3 class="wss-bs__day-title"><?php echo esc_html( WSS_BS_Query::format_timestamp( $options['date_format'], $day['date'], $options ) ); ?></h3>

                            <?php if ( empty( $day['events'] ) ) : ?>
                                <div class="wss-bs__day-empty"><?php echo esc_html( $options['label_no_events'] ); ?></div>
                            <?php else : ?>
                                <div class="wss-bs__cards">
                                    <?php foreach ( $day['events'] as $event ) : ?>
                                        <?php echo self::render_event_card( $event, $event_count, $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    private static function render_event_card( $event, $day_event_count, $options ) {
        if ( empty( $event['has_time'] ) ) {
            $button_label = $options['label_book'];
        } else {
            $button_label = 1 === $day_event_count ? $options['label_book'] : $options['label_select'];
        }

        $time_label = self::format_time_range( $event['start'], $event['end'], $event, $options );

        ob_start();
        ?>
        <article class="wss-bs-card" data-wss-bs-product="<?php echo esc_attr( $event['product_id'] ); ?>">
            <div class="wss-bs-card__main">
                <?php if ( '' !== $time_label ) : ?>
                    <div class="wss-bs-card__time"><?php echo esc_html( $time_label ); ?></div>
                <?php endif; ?>

                <h4 class="wss-bs-card__title">
                    <a href="<?php echo esc_url( $event['permalink'] ); ?>"><?php echo esc_html( $event['product_name'] ); ?></a>
                </h4>

                <div class="wss-bs-card__meta">
                    <?php if ( 'yes' === $options['show_price'] && ! empty( $event['price_html'] ) ) : ?>
                        <span class="wss-bs-card__meta-item wss-bs-card__price"><?php echo wp_kses_post( $event['price_html'] ); ?></span>
                    <?php endif; ?>

                    <?php if ( 'yes' === $options['show_capacity'] && null !== $event['capacity'] ) : ?>
                        <span class="wss-bs-card__meta-item wss-bs-card__capacity"><?php echo esc_html( sprintf( $options['label_capacity'], $event['capacity'] ) ); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="wss-bs-card__action">
                <a class="wss-bs-card__button" href="<?php echo esc_url( $event['permalink'] ); ?>"><?php echo esc_html( $button_label ); ?></a>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    private static function format_time_range( $start, $end, $event, $options ) {
        if ( empty( $event['has_time'] ) ) {
            return '';
        }

        $start_time = WSS_BS_Query::format_timestamp( $options['time_format'], $start, $options );
        $end_time   = WSS_BS_Query::format_timestamp( $options['time_format'], $end, $options );

        if ( 'yes' !== $options['show_duration'] || $start_time === $end_time ) {
            return $start_time;
        }

        return $start_time . '–' . $end_time;
    }

    private static function get_range_label( DateTime $start, DateTime $end, $options ) {
        $format = ! empty( $options['range_date_format'] ) ? $options['range_date_format'] : 'j M';

        if ( $start->format( 'Y' ) === $end->format( 'Y' ) ) {
            return WSS_BS_Query::format_timestamp( $format, $start->getTimestamp(), $options ) . ' — ' . WSS_BS_Query::format_timestamp( $format . ', Y', $end->getTimestamp(), $options );
        }

        return WSS_BS_Query::format_timestamp( $format . ', Y', $start->getTimestamp(), $options ) . ' — ' . WSS_BS_Query::format_timestamp( $format . ', Y', $end->getTimestamp(), $options );
    }

    private static function build_inline_css( $options ) {
        $vars = array(
            '--wss-bs-primary'       => $options['primary_color'],
            '--wss-bs-accent'        => $options['accent_color'],
            '--wss-bs-button-bg'     => $options['button_bg'],
            '--wss-bs-button-text'   => $options['button_text'],
            '--wss-bs-card-radius'   => absint( $options['card_radius'] ) . 'px',
            '--wss-bs-button-radius' => absint( $options['button_radius'] ) . 'px',
        );

        $css = '.wss-bs{';
        foreach ( $vars as $name => $value ) {
            $css .= $name . ':' . esc_attr( $value ) . ';';
        }
        $css .= '}';

        return $css;
    }
}
