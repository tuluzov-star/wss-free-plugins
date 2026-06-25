<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_BS_Query {
	public static function get_schedule( $args = array() ) {
		$options = WSS_BS_Plugin::get_options();
		$tz      = self::get_timezone( $options );
		$args    = wp_parse_args(
			$args,
			array(
				'start_date' => self::get_default_start_date( $options ),
				'days'       => absint( $options['days'] ?? 7 ),
				'products'   => '',
				'category'   => '',
				'product'    => 0,
			)
		);

		$days = max( 1, min( 31, absint( $args['days'] ) ) );

		try {
			$start = new DateTime( sanitize_text_field( $args['start_date'] ), $tz );
		} catch ( Exception $e ) {
			$start = new DateTime( self::get_default_start_date( $options ), $tz );
		}

		$start->setTime( 0, 0, 0 );
		$end = clone $start;
		$end->modify( '+' . ( $days - 1 ) . ' days' )->setTime( 23, 59, 59 );

		$days_list = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$date = clone $start;
			$date->modify( '+' . $i . ' days' );
			$key = $date->format( 'Y-m-d' );
			$days_list[ $key ] = array(
				'key'    => $key,
				'date'   => $date->getTimestamp(),
				'events' => array(),
			);
		}

		$product_ids = self::get_product_ids( $args, $options );
		$events      = array();
		$products    = array();
		$event_keys  = array();

		foreach ( self::get_wss_events( $product_ids, $start, $end, $days_list, $options, $tz ) as $event ) {
			self::add_event( $event, $events, $days_list, $products, $event_keys );
		}

		// Официальные WooCommerce Bookings-слоты добавляем всегда, а не только как fallback.
		foreach ( self::get_wc_bookings_events( $product_ids, $start, $end, $days_list, $options ) as $event ) {
			self::add_event( $event, $events, $days_list, $products, $event_keys );
		}

		usort( $events, array( __CLASS__, 'sort_events' ) );
		foreach ( $days_list as $key => $day ) {
			if ( count( $day['events'] ) > 1 ) {
				usort( $days_list[ $key ]['events'], array( __CLASS__, 'sort_events' ) );
			}
		}

		return array(
			'start'       => $start,
			'end'         => $end,
			'days'        => $days_list,
			'events'      => $events,
			'products'    => $products,
			'product_ids' => $product_ids,
		);
	}

	public static function get_default_start_date( $options = array() ) {
		if ( empty( $options ) ) {
			$options = WSS_BS_Plugin::get_options();
		}

		$date = new DateTime( 'now', self::get_timezone( $options ) );
		if ( 'week' === ( $options['start_mode'] ?? '' ) ) {
			$weekday = (int) $date->format( 'N' );
			if ( $weekday > 1 ) {
				$date->modify( '-' . ( $weekday - 1 ) . ' days' );
			}
		}

		return $date->format( 'Y-m-d' );
	}

	public static function get_timezone( $options = array() ) {
		if ( empty( $options ) ) {
			$options = WSS_BS_Plugin::get_options();
		}

		$timezone_string = isset( $options['timezone_string'] ) ? trim( (string) $options['timezone_string'] ) : '';
		if ( class_exists( 'WSS_BS_Settings' ) ) {
			$timezone_string = WSS_BS_Settings::normalize_timezone_value( $timezone_string );
		}

		if ( '' !== $timezone_string ) {
			try {
				return new DateTimeZone( $timezone_string );
			} catch ( Exception $e ) {
				// Ниже будет использована таймзона WordPress.
			}
		}

		return wp_timezone();
	}

	public static function format_timestamp( $format, $timestamp, $options = array() ) {
		return wp_date( $format, (int) $timestamp, self::get_timezone( $options ) );
	}

	public static function get_product_ids( $args, $options ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$forced_products  = self::csv_to_ids( $args['products'] ?? '' );
		$selected_product = absint( $args['product'] ?? 0 );
		if ( empty( $forced_products ) && $selected_product ) {
			$forced_products = array( $selected_product );
		}

		$selected_products = self::csv_to_ids( $options['selected_products'] ?? '' );
		$excluded_products = self::csv_to_ids( $options['excluded_products'] ?? '' );
		$category_slugs    = self::get_category_slugs( $args, $options );

		$ids = array_merge(
			self::get_wss_booking_product_ids( $category_slugs ),
			self::get_wc_booking_product_ids( $category_slugs ),
			$forced_products,
			$selected_products
		);

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ! empty( $selected_products ) ) {
			$ids = array_values( array_intersect( $ids, $selected_products ) );
		}
		if ( ! empty( $forced_products ) ) {
			$ids = array_values( array_intersect( $ids, $forced_products ) );
		}
		if ( ! empty( $excluded_products ) ) {
			$ids = array_values( array_diff( $ids, $excluded_products ) );
		}

		return $ids;
	}

	private static function get_category_slugs( $args, $options ) {
		$slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) ( $options['selected_categories'] ?? '' ) ) ) );
		if ( ! empty( $args['category'] ) ) {
			$slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) $args['category'] ) ) );
		}
		return $slugs;
	}

	private static function add_event( $event, &$events, &$days_list, &$products, &$event_keys ) {
		$product_id = absint( $event['product_id'] ?? 0 );
		$day_key    = (string) ( $event['day_key'] ?? '' );
		$start      = absint( $event['start'] ?? 0 );

		if ( ! $product_id || '' === $day_key || ! isset( $days_list[ $day_key ] ) || ! $start ) {
			return;
		}

		$key = $product_id . ':' . $day_key . ':' . $start;
		if ( isset( $event_keys[ $key ] ) ) {
			return;
		}

		$event_keys[ $key ] = true;
		$events[]           = $event;
		$days_list[ $day_key ]['events'][] = $event;

		if ( ! isset( $products[ $product_id ] ) ) {
			$products[ $product_id ] = array(
				'id'    => $product_id,
				'title' => $event['product_name'],
			);
		}
	}

	private static function get_wss_booking_product_ids( $category_slugs = array() ) {
		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => '_wss_booking_enabled',
					'value'   => 'yes',
					'compare' => '=',
				),
			),
		);

		if ( ! empty( $category_slugs ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => $category_slugs,
				),
			);
		}

		return array_map( 'absint', get_posts( $query_args ) );
	}

	private static function get_wc_booking_product_ids( $category_slugs = array() ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$query_args = array(
			'status'  => 'publish',
			'type'    => 'booking',
			'limit'   => -1,
			'return'  => 'ids',
			'orderby' => 'menu_order',
			'order'   => 'ASC',
		);

		if ( ! empty( $category_slugs ) ) {
			$query_args['category'] = $category_slugs;
		}

		$ids = wc_get_products( $query_args );
		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	private static function get_wss_events( $product_ids, DateTime $start, DateTime $end, $days_list, $options, DateTimeZone $tz ) {
		$events = array();

		foreach ( self::get_wss_slots( $product_ids, $start, $end ) as $slot ) {
			$product_id = absint( $slot->product_id ?? 0 );
			$day_key    = (string) ( $slot->slot_date ?? '' );

			if ( ! $product_id || ! isset( $days_list[ $day_key ] ) || self::is_wss_date_blocked( $product_id, $day_key ) ) {
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				continue;
			}

			$block_start = self::datetime_to_timestamp( $day_key, (string) $slot->start_time, $tz );
			$block_end   = self::datetime_to_timestamp( $day_key, (string) $slot->end_time, $tz );
			if ( ! $block_start ) {
				continue;
			}
			if ( $block_end <= $block_start ) {
				$block_end = $block_start + HOUR_IN_SECONDS;
			}

			$all_day = self::is_wss_all_day_slot( $slot );
			if ( self::should_hide_past_slot( $block_start, $block_end, $all_day, $options ) ) {
				continue;
			}

			$available = max( 0, absint( $slot->capacity ?? 0 ) - absint( $slot->booked ?? 0 ) );
			if ( $available < 1 ) {
				continue;
			}

			$events[] = self::build_event( $product, $block_start, $block_end, $day_key, ! $all_day, $available, self::build_booking_url( $product, $block_start, $block_end, $options, $slot ) );
		}

		return $events;
	}

	private static function get_wc_bookings_events( $product_ids, DateTime $start, DateTime $end, $days_list, $options ) {
		$events = array();

		foreach ( (array) $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				continue;
			}
			if ( method_exists( $product, 'get_type' ) && 'booking' !== $product->get_type() ) {
				continue;
			}
			if ( ! method_exists( $product, 'get_blocks_in_range' ) ) {
				continue;
			}

			try {
				$raw_blocks = $product->get_blocks_in_range( $start->getTimestamp(), $end->getTimestamp() );
			} catch ( Throwable $e ) {
				$raw_blocks = array();
			}

			foreach ( self::normalize_blocks( $raw_blocks ) as $block_start ) {
				$block_start = absint( $block_start );
				$day_key     = self::format_timestamp( 'Y-m-d', $block_start, $options );
				if ( ! isset( $days_list[ $day_key ] ) ) {
					continue;
				}

				$block_end = $block_start + self::duration_to_seconds( $product );
				$all_day   = self::is_date_only_product( $product );
				if ( self::should_hide_past_slot( $block_start, $block_end, $all_day, $options ) ) {
					continue;
				}

				$capacity = self::get_available_capacity( $product, $block_start, $block_end );
				if ( false === $capacity ) {
					continue;
				}

				$events[] = self::build_event( $product, $block_start, $block_end, $day_key, ! $all_day, is_numeric( $capacity ) ? (int) $capacity : null, self::build_booking_url( $product, $block_start, $block_end, $options ) );
			}
		}

		return $events;
	}

	private static function build_event( $product, $start, $end, $day_key, $has_time, $capacity, $permalink ) {
		return array(
			'product_id'   => $product->get_id(),
			'product_name' => $product->get_name(),
			'permalink'    => $permalink,
			'start'        => $start,
			'end'          => $end,
			'day_key'      => $day_key,
			'has_time'     => $has_time,
			'price_html'   => $product->get_price_html(),
			'capacity'     => $capacity,
		);
	}

	private static function get_wss_slots( $product_ids, DateTime $start, DateTime $end ) {
		global $wpdb;
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );
		if ( empty( $product_ids ) || ! self::table_exists( self::wss_slots_table_name() ) ) {
			return array();
		}

		$table        = self::wss_slots_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$values       = array_merge( $product_ids, array( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ) );
		$sql          = "SELECT * FROM {$table} WHERE product_id IN ({$placeholders}) AND status = 'open' AND capacity > booked AND slot_date BETWEEN %s AND %s ORDER BY slot_date ASC, start_time ASC, product_id ASC";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		return is_array( $rows ) ? $rows : array();
	}

	private static function wss_slots_table_name() {
		global $wpdb;
		if ( class_exists( 'WSS_WooCommerce_Bookings' ) && method_exists( 'WSS_WooCommerce_Bookings', 'table_name' ) ) {
			return WSS_WooCommerce_Bookings::table_name();
		}
		return $wpdb->prefix . 'wss_booking_slots';
	}

	private static function wss_blocks_table_name() {
		global $wpdb;
		if ( class_exists( 'WSS_WooCommerce_Bookings' ) && method_exists( 'WSS_WooCommerce_Bookings', 'blocks_table_name' ) ) {
			return WSS_WooCommerce_Bookings::blocks_table_name();
		}
		return $wpdb->prefix . 'wss_booking_blocks';
	}

	private static function table_exists( $table ) {
		static $cache = array();
		global $wpdb;
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}
		$cache[ $table ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		return $cache[ $table ];
	}

	private static function is_wss_date_blocked( $product_id, $date ) {
		static $cache = array();
		$product_id = absint( $product_id );
		$date       = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? (string) $date : '';
		$table      = self::wss_blocks_table_name();

		if ( ! $product_id || '' === $date || ! self::table_exists( $table ) ) {
			return false;
		}

		$key = $product_id . ':' . $date;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		global $wpdb;
		$weekday = (int) date( 'N', strtotime( $date . ' 00:00:00' ) );
		$count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id IN (0, %d) AND (block_date = %s OR (weekday = %d AND date_from <= %s AND date_to >= %s))", $product_id, $date, $weekday, $date, $date ) );

		$cache[ $key ] = $count > 0;
		return $cache[ $key ];
	}

	private static function datetime_to_timestamp( $date, $time, DateTimeZone $tz ) {
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? (string) $date : '';
		$time = preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', (string) $time ) ? (string) $time : '00:00:00';
		if ( '' === $date ) {
			return 0;
		}
		if ( 5 === strlen( $time ) ) {
			$time .= ':00';
		}
		$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $date . ' ' . $time, $tz );
		return $dt ? $dt->getTimestamp() : 0;
	}

	private static function is_wss_all_day_slot( $slot ) {
		$start = (string) ( $slot->start_time ?? '' );
		$end   = (string) ( $slot->end_time ?? '' );
		$note  = (string) ( $slot->note ?? '' );
		return ( '00:00:00' === $start && in_array( $end, array( '23:59:00', '23:59:59' ), true ) ) || false !== strpos( $note, 'Автоматический слот на весь день' );
	}

	private static function should_hide_past_slot( $start, $end, $all_day, $options ) {
		if ( 'yes' !== ( $options['hide_past_today'] ?? 'yes' ) ) {
			return false;
		}
		return $all_day ? $end < time() : $start < time();
	}

	private static function normalize_blocks( $raw_blocks ) {
		$blocks = array();
		if ( ! is_array( $raw_blocks ) ) {
			return $blocks;
		}

		foreach ( $raw_blocks as $key => $block ) {
			if ( is_numeric( $block ) ) {
				$blocks[] = (int) $block;
				continue;
			}
			if ( is_array( $block ) ) {
				foreach ( array( 'start', 'from', 'timestamp', 'time' ) as $field ) {
					if ( isset( $block[ $field ] ) && is_numeric( $block[ $field ] ) ) {
						$blocks[] = (int) $block[ $field ];
						continue 2;
					}
				}
			}
			if ( is_numeric( $key ) ) {
				$blocks[] = (int) $key;
			}
		}

		return array_values( array_unique( array_filter( $blocks ) ) );
	}

	private static function is_date_only_product( $product ) {
		$unit = method_exists( $product, 'get_duration_unit' ) ? (string) $product->get_duration_unit() : '';
		return in_array( $unit, array( 'day', 'days', 'month', 'months' ), true );
	}

	private static function duration_to_seconds( $product ) {
		$duration = method_exists( $product, 'get_duration' ) ? absint( $product->get_duration() ) : 1;
		$unit     = method_exists( $product, 'get_duration_unit' ) ? $product->get_duration_unit() : 'hour';
		if ( $duration < 1 ) {
			$duration = 1;
		}

		switch ( $unit ) {
			case 'minute':
			case 'minutes':
				return $duration * MINUTE_IN_SECONDS;
			case 'day':
			case 'days':
				return $duration * DAY_IN_SECONDS;
			case 'month':
			case 'months':
				return $duration * 30 * DAY_IN_SECONDS;
		}

		return $duration * HOUR_IN_SECONDS;
	}

	private static function get_available_capacity( $product, $start, $end ) {
		if ( ! method_exists( $product, 'get_available_bookings' ) ) {
			return null;
		}

		try {
			$available = $product->get_available_bookings( $start, $end, 0, 1 );
		} catch ( Throwable $e ) {
			return null;
		}

		if ( is_wp_error( $available ) || false === $available || 0 === $available || '0' === $available ) {
			return false;
		}

		return is_numeric( $available ) ? max( 0, (int) $available ) : null;
	}

	private static function build_booking_url( $product, $start, $end, $options, $slot = null ) {
		$args = array(
			'wss_booking_date'  => self::format_timestamp( 'Y-m-d', $start, $options ),
			'wss_booking_start' => $start,
			'wss_booking_end'   => $end,
		);
		if ( $slot && isset( $slot->id ) ) {
			$args['wss_booking_slot_id'] = absint( $slot->id );
		}
		if ( ! $slot || ! self::is_wss_all_day_slot( $slot ) ) {
			$args['wss_booking_time'] = self::format_timestamp( 'H:i', $start, $options );
		}
		return add_query_arg( $args, get_permalink( $product->get_id() ) );
	}

	private static function csv_to_ids( $value ) {
		return array_values( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) );
	}

	private static function sort_events( $a, $b ) {
		return $a['start'] === $b['start'] ? strcasecmp( $a['product_name'], $b['product_name'] ) : $a['start'] <=> $b['start'];
	}
}
