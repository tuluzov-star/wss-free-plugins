<?php
/**
 * Plugin Name: Delivery Zones on Map for WooCommerce
 * Description: Доставка WooCommerce по нарисованным зонам на карте: полигоны, правила стоимости от суммы корзины, геокодирование адреса и запрет доставки вне зон. Бесплатная версия использует Яндекс; Google, импорт и диагностика подключаются отдельным Pro-дополнением.
 * Version: 1.4.23
 * Author: WSS
 * Author URI: https://website-support.ru/
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YDZS_VERSION', '1.4.23' );
define( 'YDZS_FILE', __FILE__ );
define( 'YDZS_DIR', plugin_dir_path( __FILE__ ) );
define( 'YDZS_URL', plugin_dir_url( __FILE__ ) );
require_once YDZS_DIR . 'includes/class-ydzs-updater.php';
define( 'YDZS_OPTION_SETTINGS', 'ydzs_settings' );
define( 'YDZS_OPTION_ZONES', 'ydzs_zones' );

register_activation_hook( __FILE__, function () {
	$settings = get_option( YDZS_OPTION_SETTINGS, array() );

	$settings = wp_parse_args( $settings, array(
		'api_key'       => '',
		'google_api_key'=> '',
		'provider'      => 'yandex',
		'advanced_providers' => 'no',
		'map_provider'  => 'yandex',
		'geocode_provider' => 'yandex',
		'google_region' => 'ru',
		'map_center'    => '55.751244,37.618423',
		'map_zoom'      => 10,
		'debug_log'     => 'no',
		'default_title'       => 'Доставка',
		'address_field_names' => 'of_address,of_delivery_address,delivery_address,address,order_address,shipping_address_1,billing_address_1',
		'address_selectors'   => '',
		'address_suggest'     => 'yes',
		'address_restrict_to_zones' => 'yes',
		'address_context'     => '',
		'address_placeholder' => 'Например: Санкт-Петербург, Невский проспект, 10',
		'address_hint'        => 'Начните вводить адрес и выберите подходящий вариант из списка. Обязательно укажите населённый пункт, улицу и номер дома.',
		'address_house_hint'  => 'Добавьте номер дома — без него карта может определить только улицу, и доставка может не рассчитаться.',
	) );

	update_option( YDZS_OPTION_SETTINGS, $settings, false );

	if ( false === get_option( YDZS_OPTION_ZONES, false ) ) {
		update_option( YDZS_OPTION_ZONES, array(), false );
	}
} );

if ( is_admin() && class_exists( 'YDZS_Updater' ) ) {
	new YDZS_Updater(
		YDZS_FILE,
		YDZS_VERSION,
		'https://website-support.ru/plugins/delivery-zones-for-woocommerce/'
	);
}

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

function ydzs_is_wc_active(): bool {
	return class_exists( 'WooCommerce' ) && class_exists( 'WC_Shipping_Method' );
}


function ydzs_is_pro_active(): bool {
	return (bool) apply_filters( 'ydzs_is_pro_active', false );
}

function ydzs_feature_enabled( string $feature ): bool {
	$defaults = array(
		'google'             => false,
		'advanced_providers' => false,
		'diagnostic'         => false,
		'import'             => false,
		'unlimited_zones'    => false,
	);

	$default = isset( $defaults[ $feature ] ) ? (bool) $defaults[ $feature ] : false;
	return (bool) apply_filters( 'ydzs_feature_' . $feature, $default );
}

function ydzs_get_available_providers(): array {
	$providers = array(
		'yandex' => 'Яндекс',
	);

	$providers = apply_filters( 'ydzs_available_providers', $providers );
	return is_array( $providers ) && $providers ? $providers : array( 'yandex' => 'Яндекс' );
}

function ydzs_provider_is_available( string $provider ): bool {
	return array_key_exists( $provider, ydzs_get_available_providers() );
}

function ydzs_pro_badge(): string {
	return '<span class="ydzs-pro-badge">Pro</span>';
}

function ydzs_get_settings(): array {
	$raw = get_option( YDZS_OPTION_SETTINGS, array() );
	if ( ! is_array( $raw ) ) {
		$raw = array();
	}

	$settings = wp_parse_args( $raw, array(
		'api_key'       => '',
		'google_api_key'=> '',
		'provider'      => '',
		'advanced_providers' => 'no',
		'map_provider'  => 'yandex',
		'geocode_provider' => 'yandex',
		'google_region' => 'ru',
		'map_center'    => '55.751244,37.618423',
		'map_zoom'      => 10,
		'debug_log'     => 'no',
		'default_title'       => 'Доставка',
		'address_field_names' => 'of_address,of_delivery_address,delivery_address,address,order_address,shipping_address_1,billing_address_1',
		'address_selectors'   => '',
		'address_suggest'     => 'yes',
		'address_restrict_to_zones' => 'yes',
		'address_context'     => '',
		'address_placeholder' => 'Например: Санкт-Петербург, Невский проспект, 10',
		'address_hint'        => 'Начните вводить адрес и выберите подходящий вариант из списка. Обязательно укажите населённый пункт, улицу и номер дома.',
		'address_house_hint'  => 'Добавьте номер дома — без него карта может определить только улицу, и доставка может не рассчитаться.',
	) );

	$valid = array_keys( ydzs_get_available_providers() );
	if ( empty( $valid ) ) {
		$valid = array( 'yandex' );
	}

	$map_provider = in_array( (string) $settings['map_provider'], $valid, true ) ? (string) $settings['map_provider'] : 'yandex';
	$geocode_provider = in_array( (string) $settings['geocode_provider'], $valid, true ) ? (string) $settings['geocode_provider'] : 'yandex';

	// Обновление со старых версий: если провайдеры были разведены вручную — включаем расширенный режим,
	// иначе считаем единым провайдером выбранный провайдер карты.
	if ( empty( $settings['provider'] ) || ! in_array( (string) $settings['provider'], $valid, true ) ) {
		$settings['provider'] = $map_provider;
		if ( $map_provider !== $geocode_provider ) {
			$settings['advanced_providers'] = 'yes';
		}
	}

	$settings['provider'] = in_array( (string) $settings['provider'], $valid, true ) ? (string) $settings['provider'] : 'yandex';
	$settings['advanced_providers'] = 'yes' === (string) $settings['advanced_providers'] ? 'yes' : 'no';
	if ( ! ydzs_feature_enabled( 'advanced_providers' ) ) {
		$settings['advanced_providers'] = 'no';
	}

	if ( 'yes' !== $settings['advanced_providers'] ) {
		$settings['map_provider'] = $settings['provider'];
		$settings['geocode_provider'] = $settings['provider'];
	} else {
		$settings['map_provider'] = $map_provider;
		$settings['geocode_provider'] = $geocode_provider;
	}

	return $settings;
}

function ydzs_get_address_hint_text( ?array $settings = null ): string {
	$settings = $settings ?? ydzs_get_settings();
	$hint     = trim( (string) ( $settings['address_hint'] ?? '' ) );

	return '' !== $hint ? $hint : 'Начните вводить адрес и выберите подходящий вариант из списка. Обязательно укажите населённый пункт, улицу и номер дома.';
}

function ydzs_get_address_placeholder_text( ?array $settings = null ): string {
	$settings    = $settings ?? ydzs_get_settings();
	$placeholder = trim( (string) ( $settings['address_placeholder'] ?? '' ) );

	return '' !== $placeholder ? $placeholder : 'Например: Санкт-Петербург, Невский проспект, 10';
}

function ydzs_get_address_house_hint_text( ?array $settings = null ): string {
	$settings = $settings ?? ydzs_get_settings();
	$hint     = trim( (string) ( $settings['address_house_hint'] ?? '' ) );

	return '' !== $hint ? $hint : 'Добавьте номер дома — без него карта может определить только улицу, и доставка может не рассчитаться.';
}

function ydzs_address_suggest_enabled( ?array $settings = null ): bool {
	$settings = $settings ?? ydzs_get_settings();
	return 'no' !== (string) ( $settings['address_suggest'] ?? 'yes' );
}

function ydzs_address_restrict_to_zones_enabled( ?array $settings = null ): bool {
	$settings = $settings ?? ydzs_get_settings();
	return 'no' !== (string) ( $settings['address_restrict_to_zones'] ?? 'yes' );
}

function ydzs_get_delivery_bounds( ?array $zones = null ): ?array {
	$zones = $zones ?? ydzs_get_zones();
	$south = null;
	$north = null;
	$west  = null;
	$east  = null;

	foreach ( $zones as $zone ) {
		if ( empty( $zone['enabled'] ) || 'yes' !== $zone['enabled'] ) {
			continue;
		}

		$polygon = ydzs_normalize_polygon( $zone['coords'] ?? array() );
		if ( count( $polygon ) < 3 ) {
			continue;
		}

		foreach ( $polygon as $point ) {
			$lat = (float) $point[0];
			$lon = (float) $point[1];

			$south = null === $south ? $lat : min( $south, $lat );
			$north = null === $north ? $lat : max( $north, $lat );
			$west  = null === $west ? $lon : min( $west, $lon );
			$east  = null === $east ? $lon : max( $east, $lon );
		}
	}

	if ( null === $south || null === $north || null === $west || null === $east ) {
		return null;
	}

	$lat_span = max( 0.001, $north - $south );
	$lon_span = max( 0.001, $east - $west );

	// Небольшой запас нужен, чтобы геокодер не отбрасывал адреса на границе полигона.
	$lat_padding = max( 0.01, min( 0.08, $lat_span * 0.15 ) );
	$lon_padding = max( 0.01, min( 0.08, $lon_span * 0.15 ) );

	return array(
		'south' => max( -90, $south - $lat_padding ),
		'north' => min( 90, $north + $lat_padding ),
		'west'  => max( -180, $west - $lon_padding ),
		'east'  => min( 180, $east + $lon_padding ),
	);
}

function ydzs_get_effective_address_bounds( ?array $settings = null ): ?array {
	$settings = $settings ?? ydzs_get_settings();

	if ( ! ydzs_address_restrict_to_zones_enabled( $settings ) ) {
		return null;
	}

	return ydzs_get_delivery_bounds();
}

function ydzs_format_yandex_bbox( ?array $bounds ): string {
	if ( ! is_array( $bounds ) || ! isset( $bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'] ) ) {
		return '';
	}

	return sprintf(
		'%.6F,%.6F~%.6F,%.6F',
		(float) $bounds['west'],
		(float) $bounds['south'],
		(float) $bounds['east'],
		(float) $bounds['north']
	);
}

function ydzs_get_yandex_suggest_bounds( ?array $bounds ): array {
	if ( ! is_array( $bounds ) || ! isset( $bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'] ) ) {
		return array();
	}

	return array(
		array( (float) $bounds['south'], (float) $bounds['west'] ),
		array( (float) $bounds['north'], (float) $bounds['east'] ),
	);
}

function ydzs_geocode_candidates_have_zone_match( array $candidates ): bool {
	foreach ( $candidates as $candidate ) {
		if ( ! isset( $candidate['lat'], $candidate['lon'] ) ) {
			continue;
		}

		if ( ydzs_find_zone_for_point( (float) $candidate['lat'], (float) $candidate['lon'] ) ) {
			return true;
		}
	}

	return false;
}

function ydzs_get_address_context_text( ?array $settings = null ): string {
	$settings = $settings ?? ydzs_get_settings();
	$context  = trim( wp_strip_all_tags( (string) ( $settings['address_context'] ?? '' ) ) );

	return $context;
}

function ydzs_address_contains_context( string $address, string $context ): bool {
	if ( '' === $address || '' === $context ) {
		return false;
	}

	$address_normalized = mb_strtolower( preg_replace( '~\s+~u', ' ', $address ) );
	$context_normalized = mb_strtolower( preg_replace( '~\s+~u', ' ', $context ) );

	return false !== mb_stripos( $address_normalized, $context_normalized );
}

function ydzs_normalize_house_number( string $house ): string {
	$house = mb_strtolower( trim( wp_strip_all_tags( $house ) ) );
	$house = str_replace( array( '№', 'дом', 'д.', 'д ' ), '', $house );
	$house = preg_replace( '~\s+~u', '', $house );
	$house = str_replace( array( 'корпус', 'корп.', 'к.' ), 'к', $house );
	$house = str_replace( array( 'строение', 'стр.' ), 'с', $house );
	$house = preg_replace( '~[^0-9a-zа-яё/\-]~u', '', (string) $house );

	return (string) $house;
}

function ydzs_extract_requested_house_number( string $address ): string {
	$address = trim( wp_strip_all_tags( $address ) );
	if ( '' === $address ) {
		return '';
	}

	$patterns = array(
		'~(?:^|[,\s])(?:д(?:ом)?\.?\s*)?(\d+[а-яёa-z]?(?:\s*(?:[/\-]|к|корп(?:ус)?\.?|стр(?:оение)?\.?)\s*\d+[а-яёa-z]?)?)\s*$~iu',
		'~(?:^|[,\s])(?:д(?:ом)?\.?\s*)?(\d+[а-яёa-z]?)\s*(?:,|$)~iu',
	);

	foreach ( $patterns as $pattern ) {
		if ( preg_match( $pattern, $address, $matches ) ) {
			return ydzs_normalize_house_number( (string) $matches[1] );
		}
	}

	return '';
}

function ydzs_maybe_comma_separate_house( string $address ): string {
	$address = trim( preg_replace( '~\s+~u', ' ', wp_strip_all_tags( $address ) ) );
	if ( '' === $address || false !== mb_strpos( $address, ',' ) ) {
		return $address;
	}

	// Когда пользователь вводит "Невельская улица 5", геокодер часто возвращает улицу,
	// а не дом. Для запроса к геокодеру аккуратно добавляем запятую перед последним номером.
	$pattern = '~^(.+?\D)\s+(д(?:ом)?\.?\s*)?(\d+[а-яёa-z]?(?:\s*(?:[/\-]|к|корп(?:ус)?\.?|стр(?:оение)?\.?)\s*\d+[а-яёa-z]?)?)\s*$~iu';
	if ( preg_match( $pattern, $address, $matches ) ) {
		return trim( (string) $matches[1] ) . ', ' . trim( (string) $matches[3] );
	}

	return $address;
}


function ydzs_expand_common_address_abbreviations( string $address ): string {
	$address = trim( preg_replace( '~\s+~u', ' ', wp_strip_all_tags( $address ) ) );
	if ( '' === $address ) {
		return '';
	}

	$patterns = array(
		'~(^|[,\s])ул\.?(?=\s|$)~iu'        => '$1улица',
		'~(^|[,\s])пр-т\.?(?=\s|$)~iu'      => '$1проспект',
		'~(^|[,\s])пр\.?(?=\s|$)~iu'        => '$1проезд',
		'~(^|[,\s])пер\.?(?=\s|$)~iu'       => '$1переулок',
		'~(^|[,\s])ш\.?(?=\s|$)~iu'         => '$1шоссе',
		'~(^|[,\s])наб\.?(?=\s|$)~iu'       => '$1набережная',
		'~(^|[,\s])пл\.?(?=\s|$)~iu'        => '$1площадь',
	);

	return trim( preg_replace( array_keys( $patterns ), array_values( $patterns ), $address ) );
}

function ydzs_build_geocode_queries( string $address, ?array $settings = null ): array {
	$address  = trim( wp_strip_all_tags( $address ) );
	$settings = $settings ?? ydzs_get_settings();
	$context  = ydzs_get_address_context_text( $settings );
	$queries  = array();

	if ( '' === $address ) {
		return $queries;
	}

	$expanded_address  = ydzs_expand_common_address_abbreviations( $address );
	$canonical_address = ydzs_maybe_comma_separate_house( $address );
	$canonical_expanded = ydzs_maybe_comma_separate_house( $expanded_address );
	$base_queries      = array_values( array_unique( array_filter( array( $canonical_expanded, $expanded_address, $canonical_address, $address ) ) ) );

	foreach ( $base_queries as $query ) {
		if ( '' !== $context && ! ydzs_address_contains_context( $query, $context ) ) {
			$queries[] = $query . ', ' . $context;
		}

		$queries[] = $query;
	}

	return array_values( array_unique( $queries ) );
}

function ydzs_yandex_component_name( array $meta, string $kind ): string {
	$components = $meta['Address']['Components'] ?? array();
	if ( ! is_array( $components ) ) {
		return '';
	}

	foreach ( $components as $component ) {
		if ( ! is_array( $component ) ) {
			continue;
		}

		if ( $kind === (string) ( $component['kind'] ?? '' ) ) {
			return trim( (string) ( $component['name'] ?? '' ) );
		}
	}

	return '';
}

function ydzs_candidate_house_number( array $candidate ): string {
	$house = trim( (string) ( $candidate['house'] ?? '' ) );
	if ( '' !== $house ) {
		return ydzs_normalize_house_number( $house );
	}

	return '';
}

function ydzs_candidate_matches_requested_house( array $candidate, string $requested_house ): bool {
	$requested_house = ydzs_normalize_house_number( $requested_house );
	if ( '' === $requested_house ) {
		return true;
	}

	$candidate_house = ydzs_candidate_house_number( $candidate );
	if ( '' === $candidate_house ) {
		return false;
	}

	return $candidate_house === $requested_house;
}

function ydzs_filter_candidates_by_requested_house( array $candidates, string $address ): array {
	$requested_house = ydzs_extract_requested_house_number( $address );
	if ( '' === $requested_house ) {
		return $candidates;
	}

	$filtered = array();
	foreach ( $candidates as $candidate ) {
		if ( ydzs_candidate_matches_requested_house( $candidate, $requested_house ) ) {
			$filtered[] = $candidate;
		}
	}

	return $filtered;
}

function ydzs_get_address_significant_tokens( string $address ): array {
	$address = mb_strtolower( trim( wp_strip_all_tags( $address ) ) );
	if ( '' === $address ) {
		return array();
	}

	$requested_house = ydzs_extract_requested_house_number( $address );
	if ( '' !== $requested_house ) {
		$address = preg_replace( '~(?<!\d)' . preg_quote( $requested_house, '~' ) . '(?!\d)~u', ' ', $address );
	}

	$address = str_replace( array( 'ё' ), array( 'е' ), $address );
	$address = preg_replace( '~[^0-9a-zа-я\s\-/]+~u', ' ', $address );
	$parts   = preg_split( '~[\s,]+~u', (string) $address );
	$ignore  = array(
		'россия' => true,
		'рф' => true,
		'ленинградская' => true,
		'область' => true,
		'обл' => true,
		'район' => true,
		'р-н' => true,
		'санкт' => true,
		'петербург' => true,
		'спб' => true,
		'город' => true,
		'г' => true,
		'поселение' => true,
		'поселок' => true,
		'посёлок' => true,
		'деревня' => true,
		'село' => true,
		'снт' => true,
		'садоводство' => true,
		'садоводческое' => true,
		'некоммерческое' => true,
		'товарищество' => true,
		'улица' => true,
		'ул' => true,
		'проспект' => true,
		'пр' => true,
		'пр-т' => true,
		'проезд' => true,
		'переулок' => true,
		'шоссе' => true,
		'набережная' => true,
		'площадь' => true,
		'дом' => true,
		'д' => true,
		'корпус' => true,
		'корп' => true,
		'строение' => true,
		'стр' => true,
	);

	$tokens = array();
	foreach ( $parts as $part ) {
		$part = trim( (string) $part, " \t\n\r\0\x0B.-" );
		if ( '' === $part ) {
			continue;
		}

		if ( isset( $ignore[ $part ] ) ) {
			continue;
		}

		// Оставляем названия улиц/СНТ/посёлков, но не одиночные номера, которые часто
		// совпадают случайно и дают нерелевантные подсказки внутри зоны.
		if ( preg_match( '~^\d+$~', $part ) ) {
			continue;
		}

		if ( mb_strlen( $part ) < 3 ) {
			continue;
		}

		$tokens[ $part ] = true;
	}

	return array_keys( $tokens );
}

function ydzs_candidate_matches_requested_text( array $candidate, string $requested_address ): bool {
	$tokens = ydzs_get_address_significant_tokens( $requested_address );
	if ( ! $tokens ) {
		return true;
	}

	$haystack = mb_strtolower( implode( ' ', array(
		(string) ( $candidate['formatted'] ?? '' ),
		(string) ( $candidate['street'] ?? '' ),
		(string) ( $candidate['locality'] ?? '' ),
	) ) );
	$haystack = str_replace( array( 'ё' ), array( 'е' ), $haystack );

	$matched = 0;
	foreach ( $tokens as $token ) {
		if ( false !== mb_strpos( $haystack, $token ) ) {
			$matched++;
		}
	}

	// Для короткого запроса вроде «Хвойная 4» достаточно совпадения одного
	// значимого слова. Для длинного адреса требуем хотя бы два совпадения,
	// чтобы «Невский проспект 41» не превращался в случайное СНТ «Спутник, 41».
	$required = count( $tokens ) >= 3 ? 2 : 1;

	return $matched >= $required;
}

function ydzs_filter_candidates_by_requested_text( array $candidates, string $address ): array {
	$tokens = ydzs_get_address_significant_tokens( $address );
	if ( ! $tokens ) {
		return $candidates;
	}

	$filtered = array();
	foreach ( $candidates as $candidate ) {
		if ( ydzs_candidate_matches_requested_text( $candidate, $address ) ) {
			$filtered[] = $candidate;
		}
	}

	return $filtered;
}

function ydzs_filter_candidates_for_requested_address( array $candidates, string $address ): array {
	$candidates = ydzs_filter_candidates_by_requested_house( $candidates, $address );
	$candidates = ydzs_filter_candidates_by_requested_text( $candidates, $address );

	return $candidates;
}

function ydzs_has_candidates_outside_delivery_zones( array $candidates ): bool {
	foreach ( $candidates as $candidate ) {
		if ( ! isset( $candidate['lat'], $candidate['lon'] ) ) {
			continue;
		}

		if ( ! ydzs_find_zone_for_point( (float) $candidate['lat'], (float) $candidate['lon'] ) ) {
			return true;
		}
	}

	return false;
}

function ydzs_address_outside_zones_message(): string {
	return 'Адрес отсутствует в зонах доставки. Для этого адреса доступен только самовывоз.';
}

function ydzs_normalize_address_for_token( string $address ): string {
	$address = trim( wp_strip_all_tags( $address ) );
	$address = preg_replace( '~\s+~u', ' ', $address );
	return mb_strtolower( (string) $address );
}

function ydzs_normalize_coord_for_token( $value ): string {
	return sprintf( '%.6F', (float) $value );
}

function ydzs_create_address_point_token( string $address, $lat, $lon ): string {
	$payload = implode( '|', array(
		ydzs_normalize_address_for_token( $address ),
		ydzs_normalize_coord_for_token( $lat ),
		ydzs_normalize_coord_for_token( $lon ),
	) );

	return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
}

function ydzs_verify_address_point_token( string $address, $lat, $lon, string $token ): bool {
	if ( '' === trim( $token ) ) {
		return false;
	}

	$expected = ydzs_create_address_point_token( $address, $lat, $lon );
	return hash_equals( $expected, $token );
}

function ydzs_get_validated_address_point_from_request( string $address ): ?array {
	$data = ydzs_get_request_data();

	if ( '' === trim( $address ) || empty( $data['ydzs_address_token'] ) ) {
		return null;
	}

	$lat_raw        = isset( $data['ydzs_address_lat'] ) ? sanitize_text_field( (string) $data['ydzs_address_lat'] ) : '';
	$lon_raw        = isset( $data['ydzs_address_lon'] ) ? sanitize_text_field( (string) $data['ydzs_address_lon'] ) : '';
	$token          = sanitize_text_field( (string) $data['ydzs_address_token'] );
	$stored_status  = isset( $data['ydzs_address_status'] ) ? sanitize_key( (string) $data['ydzs_address_status'] ) : '';
	$stored_address = isset( $data['ydzs_address_value'] ) ? sanitize_text_field( (string) $data['ydzs_address_value'] ) : '';

	if ( 'inside' !== $stored_status || '' === trim( $stored_address ) ) {
		return null;
	}

	if ( ydzs_normalize_address_for_token( $stored_address ) !== ydzs_normalize_address_for_token( $address ) ) {
		return null;
	}

	if ( ! is_numeric( $lat_raw ) || ! is_numeric( $lon_raw ) ) {
		return null;
	}

	$lat = (float) $lat_raw;
	$lon = (float) $lon_raw;

	if ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) {
		return null;
	}

	if ( ! ydzs_verify_address_point_token( $stored_address, $lat, $lon, $token ) ) {
		return null;
	}

	return array(
		'lat'    => $lat,
		'lon'    => $lon,
		'source' => 'validated',
	);
}

function ydzs_request_marks_address_unconfirmed( string $address ): bool {
	$data = ydzs_get_request_data();

	if ( ! empty( $data['ydzs_address_user_cleared'] ) ) {
		return true;
	}

	if ( empty( $data['ydzs_address_status'] ) ) {
		return false;
	}

	$status = sanitize_key( (string) $data['ydzs_address_status'] );
	if ( ! in_array( $status, array( 'ambiguous', 'choose_suggestion', 'pending', 'need_house', 'not_found', 'outside', 'invalid', 'empty', 'error', 'selected' ), true ) ) {
		return false;
	}

	// Если checkout прислал неподтверждённый статус адреса, доставку по зонам
	// нельзя рассчитывать даже по старому адресу, который мог остаться в WC()->customer.
	return true;
}

function ydzs_request_marks_address_ambiguous( string $address ): bool {
	return ydzs_request_marks_address_unconfirmed( $address );
}


function ydzs_get_map_provider( ?array $settings = null ): string {
	$settings = $settings ?? ydzs_get_settings();
	$providers = array_keys( ydzs_get_available_providers() );
	return in_array( (string) ( $settings['map_provider'] ?? 'yandex' ), $providers, true ) ? (string) $settings['map_provider'] : 'yandex';
}

function ydzs_get_geocode_provider( ?array $settings = null ): string {
	$settings = $settings ?? ydzs_get_settings();
	$providers = array_keys( ydzs_get_available_providers() );
	return in_array( (string) ( $settings['geocode_provider'] ?? 'yandex' ), $providers, true ) ? (string) $settings['geocode_provider'] : 'yandex';
}

function ydzs_get_zones(): array {
	$zones = get_option( YDZS_OPTION_ZONES, array() );
	return is_array( $zones ) ? $zones : array();
}

function ydzs_sanitize_rules( $rules ): array {
	$clean = array();

	if ( ! is_array( $rules ) ) {
		return $clean;
	}

	foreach ( $rules as $rule ) {
		if ( ! is_array( $rule ) ) {
			continue;
		}

		$clean[] = array(
			'min'  => ydzs_price_to_float( $rule['min'] ?? 0 ),
			'cost' => ydzs_price_to_float( $rule['cost'] ?? 0 ),
		);
	}

	usort( $clean, function ( $a, $b ) {
		return (float) $a['min'] <=> (float) $b['min'];
	} );

	return $clean;
}

function ydzs_log( string $message, array $context = array() ): void {
	$settings = ydzs_get_settings();
	if ( 'yes' !== $settings['debug_log'] ) {
		return;
	}

	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->debug( $message . ( $context ? ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) : '' ), array(
			'source' => 'yd-zones-shipping',
		) );
	}
}

function ydzs_price_to_float( $value ): float {
	if ( is_numeric( $value ) ) {
		return (float) $value;
	}

	$value = (string) $value;
	$value = str_replace( array( ' ', "\xc2\xa0", ',' ), array( '', '', '.' ), $value );
	return (float) preg_replace( '~[^0-9.\-]~', '', $value );
}

function ydzs_sanitize_map_center( $value, string $fallback = '55.751244,37.618423' ): string {
	$value = trim( str_replace( array( ';', ' ' ), array( ',', '' ), (string) $value ) );
	$parts = array_map( 'trim', explode( ',', $value ) );

	if ( count( $parts ) < 2 || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) ) {
		return $fallback;
	}

	$lat = (float) $parts[0];
	$lon = (float) $parts[1];

	if ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) {
		return $fallback;
	}

	return rtrim( rtrim( sprintf( '%.6F', $lat ), '0' ), '.' ) . ',' . rtrim( rtrim( sprintf( '%.6F', $lon ), '0' ), '.' );
}


function ydzs_get_address_field_names(): array {
	$settings = ydzs_get_settings();
	$raw      = (string) ( $settings['address_field_names'] ?? '' );
	$names    = array();

	foreach ( preg_split( '~[,\n\r]+~', $raw ) as $name ) {
		$name = trim( sanitize_key( $name ) );
		if ( '' !== $name ) {
			$names[] = $name;
		}
	}

	return array_values( array_unique( array_merge( array(
		'shipping_address_1',
		'billing_address_1',
		'delivery_address',
		'address',
		'order_address',
		'of_delivery_address',
	), $names ) ) );
}

function ydzs_get_request_data(): array {
	$data = array();

	if ( isset( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		parse_str( wp_unslash( (string) $_POST['post_data'] ), $data ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	foreach ( $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( is_scalar( $value ) && 'post_data' !== $key ) {
			$data[ sanitize_key( $key ) ] = wp_unslash( (string) $value );
		}
	}

	return $data;
}

function ydzs_get_ordered_address_field_names(): array {
	$names     = ydzs_get_address_field_names();
	$preferred = array( 'of_address', 'of_delivery_address', 'delivery_address', 'order_address', 'address' );

	return array_values( array_unique( array_merge( $preferred, $names ) ) );
}

/**
 * Возвращает адрес из текущего AJAX-запроса checkout.
 *
 * Важно: если кастомное поле адреса присутствует в запросе, но пустое,
 * это НЕ значит "возьми старый адрес из WC()->customer". Это значит,
 * что пользователь удалил адрес, и доставку курьером нужно убрать.
 */
function ydzs_get_address_from_request(): ?string {
	$data    = ydzs_get_request_data();
	$ordered = ydzs_get_ordered_address_field_names();

	// Если пользователь явно очистил адрес, не берём старое значение ни из
	// кастомного поля, ни из shipping_address_1/billing_address_1. Иначе
	// WooCommerce может вернуть ранее выбранный адрес при AJAX-пересчёте.
	if ( ! empty( $data['ydzs_address_user_cleared'] ) ) {
		return '';
	}

	foreach ( $ordered as $name ) {
		if ( array_key_exists( $name, $data ) ) {
			return trim( wc_clean( wp_strip_all_tags( (string) $data[ $name ] ) ) );
		}
	}

	return null;
}

function ydzs_get_posted_address_status(): string {
	$data   = ydzs_get_request_data();
	$status = isset( $data['ydzs_address_status'] ) ? sanitize_key( (string) $data['ydzs_address_status'] ) : '';

	return $status;
}

function ydzs_posted_address_is_unconfirmed(): bool {
	$data = ydzs_get_request_data();
	if ( ! empty( $data['ydzs_address_user_cleared'] ) ) {
		return true;
	}

	$status = ydzs_get_posted_address_status();

	return in_array( $status, array( 'ambiguous', 'choose_suggestion', 'pending', 'need_house', 'not_found', 'outside', 'invalid', 'empty', 'error', 'selected' ), true );
}

function ydzs_reset_shipping_cache(): void {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	for ( $i = 0; $i < 20; $i++ ) {
		WC()->session->__unset( 'shipping_for_package_' . $i );
	}
}

function ydzs_normalize_polygon( $coords ): array {
	if ( ! is_array( $coords ) ) {
		return array();
	}

	$polygon = array();

	foreach ( $coords as $point ) {
		if ( ! is_array( $point ) || count( $point ) < 2 ) {
			continue;
		}

		$lat = isset( $point[0] ) ? (float) $point[0] : null;
		$lon = isset( $point[1] ) ? (float) $point[1] : null;

		if ( null === $lat || null === $lon ) {
			continue;
		}

		$polygon[] = array( $lat, $lon );
	}

	return $polygon;
}

/**
 * Ray-casting point-in-polygon.
 * Координаты храним как [lat, lon], для математики x=lon, y=lat.
 */
function ydzs_point_in_polygon( float $lat, float $lon, array $polygon ): bool {
	$count = count( $polygon );

	if ( $count < 3 ) {
		return false;
	}

	$inside = false;
	$j      = $count - 1;

	for ( $i = 0; $i < $count; $i++ ) {
		$yi = (float) $polygon[ $i ][0]; // lat
		$xi = (float) $polygon[ $i ][1]; // lon
		$yj = (float) $polygon[ $j ][0];
		$xj = (float) $polygon[ $j ][1];

		$intersect = ( ( $yi > $lat ) !== ( $yj > $lat ) )
			&& ( $lon < ( $xj - $xi ) * ( $lat - $yi ) / ( ( $yj - $yi ) ?: 0.0000000001 ) + $xi );

		if ( $intersect ) {
			$inside = ! $inside;
		}

		$j = $i;
	}

	return $inside;
}

function ydzs_build_address_from_package( array $package ): string {
	$dest            = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();
	$request_address = ydzs_get_address_from_request();

	/*
	 * На кастомных checkout-страницах адрес часто лежит в собственном поле
	 * вроде of_address, а WooCommerce в package['destination'] может передать
	 * только страну/область. Такой неполный destination геокодируется не туда.
	 * Поэтому пользовательский адрес из формы имеет приоритет.
	 *
	 * Если поле адреса есть в запросе, но пустое, возвращаем пустую строку —
	 * это явная очистка адреса пользователем, а не повод брать старую сессию.
	 */
	if ( null !== $request_address ) {
		ydzs_log( 'Address taken from checkout request.', array(
			'address'     => $request_address,
			'destination' => $dest,
		) );
		return $request_address;
	}

	$parts = array();

	foreach ( array( 'country', 'state', 'city', 'address', 'address_1', 'address_2', 'postcode' ) as $key ) {
		if ( ! empty( $dest[ $key ] ) ) {
			$parts[] = $dest[ $key ];
		}
	}

	$parts   = array_unique( array_filter( array_map( 'wc_clean', $parts ) ) );
	$address = trim( implode( ', ', $parts ) );

	return $address;
}

add_action( 'woocommerce_checkout_update_order_review', function ( $post_data ) {
	if ( ! WC()->customer ) {
		return;
	}

	$data = array();
	parse_str( (string) $post_data, $data );

	if ( ! empty( $data['ydzs_address_user_cleared'] ) ) {
		WC()->customer->set_shipping_address_1( '' );
		WC()->customer->set_billing_address_1( '' );
		WC()->customer->set_shipping_city( '' );
		WC()->customer->set_billing_city( '' );
		WC()->customer->set_calculated_shipping( true );
		ydzs_reset_shipping_cache();

		if ( method_exists( WC()->customer, 'save' ) ) {
			WC()->customer->save();
		}

		ydzs_log( 'Checkout address was explicitly cleared by customer; WC customer address cleared.' );
		return;
	}

	foreach ( ydzs_get_ordered_address_field_names() as $name ) {
		if ( ! array_key_exists( $name, $data ) ) {
			continue;
		}

		$address = trim( wc_clean( wp_strip_all_tags( (string) $data[ $name ] ) ) );

		if ( '' === $address ) {
			WC()->customer->set_shipping_address_1( '' );
			WC()->customer->set_billing_address_1( '' );
			WC()->customer->set_shipping_city( '' );
			WC()->customer->set_billing_city( '' );
			WC()->customer->set_calculated_shipping( true );
			ydzs_reset_shipping_cache();

			if ( method_exists( WC()->customer, 'save' ) ) {
				WC()->customer->save();
			}

			ydzs_log( 'Checkout address cleared and shipping cache cleared.', array( 'field' => $name ) );
			break;
		}

		if ( ydzs_posted_address_is_unconfirmed() ) {
			WC()->customer->set_calculated_shipping( true );
			ydzs_reset_shipping_cache();

			ydzs_log( 'Checkout address is not confirmed; WC customer address was not overwritten.', array(
				'field'   => $name,
				'address' => $address,
				'status'  => ydzs_get_posted_address_status(),
			) );
			break;
		}

		WC()->customer->set_shipping_address_1( $address );
		WC()->customer->set_billing_address_1( $address );
		WC()->customer->set_calculated_shipping( true );

		// Иначе WooCommerce может взять cached rates из сессии и вообще не вызвать calculate_shipping().
		ydzs_reset_shipping_cache();

		if ( method_exists( WC()->customer, 'save' ) ) {
			WC()->customer->save();
		}

		ydzs_log( 'Checkout address synced to WC customer and shipping cache cleared.', array( 'field' => $name, 'address' => $address ) );
		break;
	}
}, 5 );

function ydzs_yandex_geocode_candidates( string $query, string $api_key, string $provider, string $original_address, ?array $bounds = null ): array {
	$args = array(
		'apikey'  => $api_key,
		'geocode' => $query,
		'format'  => 'json',
		'results' => 10,
		'lang'    => 'ru_RU',
	);

	$bbox = ydzs_format_yandex_bbox( $bounds );
	if ( '' !== $bbox ) {
		$args['bbox'] = $bbox;
		$args['rspn'] = 1;
	}

	$url = add_query_arg(
		$args,
		'https://geocode-maps.yandex.ru/1.x/'
	);

	$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

	if ( is_wp_error( $response ) ) {
		ydzs_log( 'Geocode wp_remote_get error.', array(
			'provider' => $provider,
			'address'  => $original_address,
			'query'    => $query,
			'error'    => $response->get_error_message(),
		) );
		return array();
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );

	if ( 200 !== $code || '' === $body ) {
		ydzs_log( 'Geocode bad response.', array(
			'provider' => $provider,
			'address'  => $original_address,
			'query'    => $query,
			'code'     => $code,
			'body'     => mb_substr( $body, 0, 500 ),
		) );
		return array();
	}

	$data    = json_decode( $body, true );
	$members = $data['response']['GeoObjectCollection']['featureMember'] ?? array();

	if ( ! is_array( $members ) || ! $members ) {
		ydzs_log( 'Geocode no candidates.', array( 'provider' => $provider, 'address' => $original_address, 'query' => $query ) );
		return array();
	}

	$candidates = array();

	foreach ( $members as $member ) {
		$geo_object = $member['GeoObject'] ?? array();
		$pos        = $geo_object['Point']['pos'] ?? '';

		if ( ! $pos ) {
			continue;
		}

		// Yandex Geocoder возвращает "lon lat".
		$parts = preg_split( '~\s+~', trim( (string) $pos ) );
		if ( count( $parts ) < 2 || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) ) {
			continue;
		}

		$meta = $geo_object['metaDataProperty']['GeocoderMetaData'] ?? array();
		$formatted = (string) ( $meta['Address']['formatted'] ?? ( $meta['text'] ?? ( $geo_object['name'] ?? '' ) ) );

		$candidates[] = array(
			'lat'       => (float) $parts[1],
			'lon'       => (float) $parts[0],
			'query'     => $query,
			'formatted' => $formatted,
			'precision' => (string) ( $meta['precision'] ?? '' ),
			'kind'      => (string) ( $meta['kind'] ?? '' ),
			'house'     => ydzs_yandex_component_name( is_array( $meta ) ? $meta : array(), 'house' ),
			'street'    => ydzs_yandex_component_name( is_array( $meta ) ? $meta : array(), 'street' ),
			'locality'  => ydzs_yandex_component_name( is_array( $meta ) ? $meta : array(), 'locality' ),
		);
	}

	return $candidates;
}

function ydzs_candidate_identity_key( array $candidate ): string {
	$lat = isset( $candidate['lat'] ) ? ydzs_normalize_coord_for_token( $candidate['lat'] ) : '';
	$lon = isset( $candidate['lon'] ) ? ydzs_normalize_coord_for_token( $candidate['lon'] ) : '';
	$formatted = mb_strtolower( trim( (string) ( $candidate['formatted'] ?? '' ) ) );

	return $lat . '|' . $lon . '|' . $formatted;
}

function ydzs_unique_geocode_candidates( array $candidates ): array {
	$unique = array();

	foreach ( $candidates as $candidate ) {
		if ( ! isset( $candidate['lat'], $candidate['lon'] ) ) {
			continue;
		}

		$key = ydzs_candidate_identity_key( $candidate );
		if ( isset( $unique[ $key ] ) ) {
			continue;
		}

		$unique[ $key ] = $candidate;
	}

	return array_values( $unique );
}

function ydzs_collect_yandex_geocode_candidates_with_bounds( string $address, string $api_key, string $provider, ?array $settings = null, ?array $bounds = null ): array {
	$settings       = $settings ?? ydzs_get_settings();
	$queries        = ydzs_build_geocode_queries( $address, $settings );
	$all_candidates = array();

	foreach ( $queries as $query ) {
		$candidates = ydzs_yandex_geocode_candidates( $query, $api_key, $provider, $address, $bounds );
		if ( $candidates ) {
			$all_candidates = array_merge( $all_candidates, $candidates );
		}
	}

	return ydzs_unique_geocode_candidates( $all_candidates );
}

function ydzs_collect_yandex_geocode_candidates_global( string $address, string $api_key, string $provider, ?array $settings = null ): array {
	$settings = $settings ?? ydzs_get_settings();

	// Для диагностики адресов вне зон не добавляем контекст доставки к запросу.
	// Иначе адрес вроде «Невский проспект 41» превращается для геокодера в
	// «Невский проспект 41, <район доставки>» и может подменяться похожим СНТ в зоне.
	$global_settings = $settings;
	$global_settings['address_context'] = '';

	return ydzs_collect_yandex_geocode_candidates_with_bounds( $address, $api_key, $provider, $global_settings, null );
}

function ydzs_collect_yandex_geocode_candidates( string $address, string $api_key, string $provider, ?array $settings = null ): array {
	$settings = $settings ?? ydzs_get_settings();
	$bounds   = ydzs_get_effective_address_bounds( $settings );

	$all_candidates = ydzs_collect_yandex_geocode_candidates_with_bounds( $address, $api_key, $provider, $settings, $bounds );

	// Если ограничение рамками зон не дало кандидата внутри зоны, пробуем обычный поиск.
	// Это страховка от слишком плотного bbox и особенностей геокодера, но приоритет всё равно остаётся у адресов внутри зон.
	if ( $bounds && ! ydzs_geocode_candidates_have_zone_match( $all_candidates ) ) {
		$all_candidates = array_merge(
			$all_candidates,
			ydzs_collect_yandex_geocode_candidates_global( $address, $api_key, $provider, $settings )
		);
	}

	return ydzs_unique_geocode_candidates( $all_candidates );
}

function ydzs_get_zone_matched_candidates( array $candidates ): array {
	$matches = array();
	$seen    = array();

	foreach ( $candidates as $candidate ) {
		if ( ! isset( $candidate['lat'], $candidate['lon'] ) ) {
			continue;
		}

		$zone = ydzs_find_zone_for_point( (float) $candidate['lat'], (float) $candidate['lon'] );
		if ( ! $zone ) {
			continue;
		}

		$key = ydzs_candidate_identity_key( $candidate );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}

		$seen[ $key ] = true;
		$candidate['zone_id']   = (string) ( $zone['id'] ?? '' );
		$candidate['zone_name'] = (string) ( $zone['name'] ?? '' );
		$matches[] = $candidate;
	}

	return $matches;
}

function ydzs_candidate_matches_address_text( array $candidate, string $address ): bool {
	$formatted = trim( (string) ( $candidate['formatted'] ?? '' ) );
	if ( '' === $formatted || '' === trim( $address ) ) {
		return false;
	}

	return ydzs_normalize_address_for_token( $formatted ) === ydzs_normalize_address_for_token( $address );
}

function ydzs_pick_geocode_candidate( array $candidates ): ?array {
	if ( ! $candidates ) {
		return null;
	}

	foreach ( $candidates as $candidate ) {
		if ( ! isset( $candidate['lat'], $candidate['lon'] ) ) {
			continue;
		}

		$zone = ydzs_find_zone_for_point( (float) $candidate['lat'], (float) $candidate['lon'] );
		if ( $zone ) {
			ydzs_log( 'Geocode candidate selected inside delivery zone.', array(
				'candidate' => $candidate,
				'zone'      => $zone['name'] ?? '',
			) );
			return $candidate;
		}
	}

	// Если ни один вариант не попал в зону, возвращаем первый ответ геокодера.
	// Дальше calculate_shipping() корректно не добавит способ доставки.
	return $candidates[0];
}

function ydzs_geocode_address( string $address ): ?array {
	$address  = trim( wp_strip_all_tags( $address ) );
	$settings = ydzs_get_settings();
	$provider = ydzs_get_geocode_provider( $settings );
	$api_key  = 'google' === $provider ? trim( (string) ( $settings['google_api_key'] ?? '' ) ) : trim( (string) ( $settings['api_key'] ?? '' ) );
	$context  = ydzs_get_address_context_text( $settings );
	$bounds   = 'yandex' === $provider ? ydzs_get_effective_address_bounds( $settings ) : null;

	if ( '' === $address ) {
		ydzs_log( 'Geocode skipped: empty address.', array( 'provider' => $provider ) );
		return null;
	}

	$validated = ydzs_get_validated_address_point_from_request( $address );
	if ( $validated ) {
		ydzs_log( 'Geocode skipped: validated checkout coordinates used.', array(
			'provider' => $provider,
			'address'  => $address,
			'lat'      => $validated['lat'],
			'lon'      => $validated['lon'],
		) );
		return $validated;
	}

	if ( ydzs_request_marks_address_ambiguous( $address ) ) {
		ydzs_log( 'Geocode skipped: address was marked as ambiguous by frontend validation.', array(
			'provider' => $provider,
			'address'  => $address,
		) );
		return null;
	}

	if ( '' === $api_key ) {
		ydzs_log( 'Geocode skipped: empty api key.', array( 'provider' => $provider, 'address' => $address ) );
		return null;
	}

	$cache_key = 'ydzs_geo_' . $provider . '_' . md5( mb_strtolower( $address . '|' . $context . '|' . wp_json_encode( $bounds ) ) );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) && isset( $cached['lat'], $cached['lon'] ) ) {
		return array(
			'lat' => (float) $cached['lat'],
			'lon' => (float) $cached['lon'],
		);
	}

	if ( 'yandex' !== $provider ) {
		/**
		 * External providers are implemented by add-ons, not by the Free core.
		 * Example hook name for Google: ydzs_geocode_provider_google.
		 */
		$external = apply_filters( 'ydzs_geocode_provider_' . $provider, null, $address, $settings, $api_key );
		if ( is_array( $external ) && isset( $external['lat'], $external['lon'] ) ) {
			$result = array( 'lat' => (float) $external['lat'], 'lon' => (float) $external['lon'] );
			set_transient( $cache_key, $result, WEEK_IN_SECONDS );
			ydzs_log( 'Geocode success.', array(
				'provider' => $provider,
				'address'  => $address,
				'lat'      => $result['lat'],
				'lon'      => $result['lon'],
			) );
			return $result;
		}

		ydzs_log( 'Geocode skipped: external provider is unavailable.', array( 'provider' => $provider, 'address' => $address ) );
		return null;
	}

	$all_candidates  = ydzs_collect_yandex_geocode_candidates( $address, $api_key, $provider, $settings );
	$requested_house = ydzs_extract_requested_house_number( $address );

	if ( '' !== $requested_house ) {
		$all_candidates = ydzs_filter_candidates_by_requested_house( $all_candidates, $address );
		if ( ! $all_candidates ) {
			ydzs_log( 'Geocode skipped: no candidate with requested house number.', array(
				'provider' => $provider,
				'address'  => $address,
				'house'    => $requested_house,
			) );
			return null;
		}
	}

	$result = ydzs_pick_geocode_candidate( $all_candidates );

	if ( ! $result ) {
		ydzs_log( 'Geocode no position.', array( 'provider' => $provider, 'address' => $address ) );
		return null;
	}

	$result = array(
		'lat' => (float) $result['lat'],
		'lon' => (float) $result['lon'],
	);

	set_transient( $cache_key, $result, WEEK_IN_SECONDS );

	ydzs_log( 'Geocode success.', array(
		'provider' => $provider,
		'address'  => $address,
		'context'  => $context,
		'bounds'   => $bounds,
		'lat'      => $result['lat'],
		'lon'      => $result['lon'],
	) );

	return $result;
}

function ydzs_polygon_area( array $polygon ): float {
	$count = count( $polygon );
	if ( $count < 3 ) {
		return PHP_FLOAT_MAX;
	}

	$area = 0.0;
	$j    = $count - 1;

	for ( $i = 0; $i < $count; $i++ ) {
		$xi = (float) $polygon[ $i ][1]; // lon
		$yi = (float) $polygon[ $i ][0]; // lat
		$xj = (float) $polygon[ $j ][1];
		$yj = (float) $polygon[ $j ][0];

		$area += ( $xj + $xi ) * ( $yj - $yi );
		$j     = $i;
	}

	return abs( $area / 2 );
}

function ydzs_find_zone_for_point( float $lat, float $lon ): ?array {
	$zones      = ydzs_get_zones();
	$matches    = array();
	$best_zone  = null;
	$best_area  = PHP_FLOAT_MAX;

	foreach ( $zones as $zone ) {
		if ( empty( $zone['enabled'] ) || 'yes' !== $zone['enabled'] ) {
			continue;
		}

		$polygon = ydzs_normalize_polygon( $zone['coords'] ?? array() );

		if ( ydzs_point_in_polygon( $lat, $lon, $polygon ) ) {
			$area      = ydzs_polygon_area( $polygon );
			$matches[] = array(
				'name' => $zone['name'] ?? '',
				'area' => $area,
			);

			// Если зоны пересекаются, выбираем самую маленькую/точную зону, а не первую созданную.
			if ( null === $best_zone || $area < $best_area ) {
				$best_zone = $zone;
				$best_area = $area;
			}
		}
	}

	if ( $matches ) {
		ydzs_log( 'Zone match candidates.', array(
			'lat'      => $lat,
			'lon'      => $lon,
			'matches'  => $matches,
			'selected' => $best_zone['name'] ?? '',
		) );
	}

	return $best_zone;
}

function ydzs_pick_rule_for_zone( array $zone, float $cart_total ): ?array {
	$rules = isset( $zone['rules'] ) && is_array( $zone['rules'] ) ? $zone['rules'] : array();

	$matched = null;

	foreach ( $rules as $rule ) {
		$min  = ydzs_price_to_float( $rule['min'] ?? 0 );
		$cost = ydzs_price_to_float( $rule['cost'] ?? 0 );

		if ( $cart_total >= $min ) {
			if ( null === $matched || $min >= ydzs_price_to_float( $matched['min'] ?? 0 ) ) {
				$matched = array(
					'min'  => $min,
					'cost' => $cost,
				);
			}
		}
	}

	return $matched;
}


function ydzs_get_next_rule_for_zone( array $zone, float $cart_total ): ?array {
	$rules = isset( $zone['rules'] ) && is_array( $zone['rules'] ) ? $zone['rules'] : array();
	$next  = null;

	foreach ( $rules as $rule ) {
		$min  = ydzs_price_to_float( $rule['min'] ?? 0 );
		$cost = ydzs_price_to_float( $rule['cost'] ?? 0 );

		if ( $cart_total < $min && ( null === $next || $min < ydzs_price_to_float( $next['min'] ?? 0 ) ) ) {
			$next = array(
				'min'  => $min,
				'cost' => $cost,
			);
		}
	}

	return $next;
}

function ydzs_get_current_cart_total_for_rules(): float {
	if ( function_exists( 'WC' ) && WC()->cart ) {
		return (float) WC()->cart->get_subtotal();
	}

	return 0.0;
}

function ydzs_format_money_plain( float $amount ): string {
	$amount = max( 0, $amount );

	if ( function_exists( 'wc_price' ) ) {
		$price = wp_strip_all_tags( wc_price( $amount ) );
		$price = html_entity_decode( $price, ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '~\s+~u', ' ', $price ) );
	}

	$decimals = abs( $amount - round( $amount ) ) > 0.001 ? 2 : 0;
	return number_format_i18n( $amount, $decimals ) . ' ₽';
}

function ydzs_build_min_order_message( float $min_total, float $cart_total, string $zone_name = '' ): string {
	$left = max( 0, $min_total - $cart_total );

	$message = 'Адрес входит в зону доставки';
	if ( '' !== trim( $zone_name ) ) {
		$message .= ': ' . trim( $zone_name );
	}

	$message .= '. Доставка по этому адресу доступна при сумме заказа от ' . ydzs_format_money_plain( $min_total ) . '.';

	if ( $left > 0 ) {
		$message .= ' Сейчас в корзине ' . ydzs_format_money_plain( $cart_total ) . ', осталось добавить товаров на ' . ydzs_format_money_plain( $left ) . '. Можно выбрать самовывоз.';
	}

	return $message;
}

function ydzs_get_delivery_rule_status_for_zone( array $zone, float $cart_total ): array {
	$matched = ydzs_pick_rule_for_zone( $zone, $cart_total );

	if ( $matched ) {
		return array(
			'available'   => true,
			'rule'        => $matched,
			'next_rule'   => null,
			'min_total'   => ydzs_price_to_float( $matched['min'] ?? 0 ),
			'cart_total'  => $cart_total,
			'amount_left' => 0,
			'message'     => '',
		);
	}

	$next = ydzs_get_next_rule_for_zone( $zone, $cart_total );
	if ( $next ) {
		$min_total = ydzs_price_to_float( $next['min'] ?? 0 );
		return array(
			'available'   => false,
			'rule'        => null,
			'next_rule'   => $next,
			'min_total'   => $min_total,
			'cart_total'  => $cart_total,
			'amount_left' => max( 0, $min_total - $cart_total ),
			'message'     => ydzs_build_min_order_message( $min_total, $cart_total, (string) ( $zone['name'] ?? '' ) ),
		);
	}

	return array(
		'available'   => false,
		'rule'        => null,
		'next_rule'   => null,
		'min_total'   => 0,
		'cart_total'  => $cart_total,
		'amount_left' => 0,
		'message'     => ydzs_address_outside_zones_message(),
	);
}

function ydzs_store_last_delivery_rule_status( array $status ): void {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	WC()->session->set( 'ydzs_last_delivery_rule_status', $status );
	WC()->session->set( 'ydzs_last_min_order_message', ! empty( $status['message'] ) ? (string) $status['message'] : '' );
}

function ydzs_clear_last_delivery_rule_status(): void {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	WC()->session->set( 'ydzs_last_delivery_rule_status', null );
	WC()->session->set( 'ydzs_last_min_order_message', '' );
}


add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	$chosen = array();

	if ( isset( $_POST['shipping_method'] ) && is_array( $_POST['shipping_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$chosen = array_map( 'wc_clean', wp_unslash( $_POST['shipping_method'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	} elseif ( function_exists( 'WC' ) && WC()->session ) {
		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
	}

	$uses_ydzs = false;
	foreach ( $chosen as $method_id ) {
		if ( 0 === strpos( (string) $method_id, 'ydzs_shipping' ) ) {
			$uses_ydzs = true;
			break;
		}
	}

	if ( ! $uses_ydzs ) {
		return;
	}

	$address = ydzs_get_address_from_request();
	if ( null === $address ) {
		$address = function_exists( 'WC' ) && WC()->customer ? trim( (string) WC()->customer->get_shipping_address_1() ) : '';
	}

	if ( '' === trim( (string) $address ) ) {
		$errors->add( 'ydzs_empty_address', ydzs_get_address_hint_text() . ' Или выберите самовывоз.' );
		return;
	}

	$point = ydzs_geocode_address( (string) $address );
	$zone  = $point ? ydzs_find_zone_for_point( (float) $point['lat'], (float) $point['lon'] ) : null;

	if ( ! $point || ! $zone ) {
		$errors->add( 'ydzs_outside_zone', 'По указанному адресу доставка не осуществляется. Выберите точный адрес из выпадающего списка, проверьте населённый пункт, улицу и номер дома, либо выберите самовывоз.' );
		return;
	}

	$cart_total  = ydzs_get_current_cart_total_for_rules();
	$rule_status = ydzs_get_delivery_rule_status_for_zone( $zone, $cart_total );
	if ( empty( $rule_status['available'] ) && ! empty( $rule_status['message'] ) ) {
		$errors->add( 'ydzs_min_order', (string) $rule_status['message'] );
	}
}, 20, 2 );

add_action( 'plugins_loaded', function () {
	if ( ! ydzs_is_wc_active() ) {
		return;
	}

	add_action( 'woocommerce_shipping_init', function () {
		if ( class_exists( 'WC_YDZS_Shipping_Method' ) ) {
			return;
		}

		class WC_YDZS_Shipping_Method extends WC_Shipping_Method {
			public function __construct( $instance_id = 0 ) {
				$this->id                 = 'ydzs_shipping';
				$this->instance_id        = absint( $instance_id );
				$this->method_title       = 'Доставка по зонам на карте';
				$this->method_description = 'Стоимость доставки рассчитывается по нарисованным полигонам на карте и сумме корзины.';
				$this->supports           = array(
					'shipping-zones',
					'instance-settings',
					'instance-settings-modal',
				);

				$this->init();
			}

			public function init(): void {
				$this->init_form_fields();
				$this->init_instance_form_fields();
				$this->init_settings();

				$global_settings = ydzs_get_settings();

				$this->enabled = $this->get_option( 'enabled', 'yes' );
				$this->title   = $this->get_option( 'title', $global_settings['default_title'] );

				add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
				add_action( 'woocommerce_update_options_shipping_' . $this->id . '_' . $this->instance_id, array( $this, 'process_admin_options' ) );
			}

			public function init_form_fields(): void {
				$this->form_fields = $this->get_ydzs_fields();
			}

			public function init_instance_form_fields(): void {
				$this->instance_form_fields = $this->get_ydzs_fields();
			}

			private function get_ydzs_fields(): array {
				return array(
					'enabled' => array(
						'title'   => 'Включить',
						'type'    => 'checkbox',
						'label'   => 'Включить доставку по зонам на карте',
						'default' => 'yes',
					),
					'title' => array(
						'title'       => 'Название метода',
						'type'        => 'text',
						'description' => 'Показывается пользователю в корзине и на оформлении заказа.',
						'default'     => 'Доставка',
					),
				);
			}

			public function calculate_shipping( $package = array() ): void {
				if ( 'yes' !== $this->enabled ) {
					return;
				}

				$address = ydzs_build_address_from_package( is_array( $package ) ? $package : array() );

				ydzs_log( 'Shipping calculation started.', array(
					'address'     => $address,
					'destination' => is_array( $package ) && isset( $package['destination'] ) ? $package['destination'] : array(),
					'contents_cost' => is_array( $package ) && isset( $package['contents_cost'] ) ? $package['contents_cost'] : null,
				) );

				if ( '' === $address ) {
					ydzs_log( 'Shipping skipped: empty package address.', array( 'package' => $package ) );
					return;
				}

				$point = ydzs_geocode_address( $address );

				if ( ! $point ) {
					ydzs_log( 'Shipping skipped: geocode failed.', array( 'address' => $address ) );
					return;
				}

				$zone = ydzs_find_zone_for_point( (float) $point['lat'], (float) $point['lon'] );

				if ( ! $zone ) {
					ydzs_log( 'Shipping skipped: point outside zones.', array(
						'address' => $address,
						'point'   => $point,
					) );
					return;
				}

				$cart_total = 0.0;

				if ( isset( $package['contents_cost'] ) ) {
					$cart_total = (float) $package['contents_cost'];
				} elseif ( WC()->cart ) {
					$cart_total = (float) WC()->cart->get_subtotal();
				}

				$rule_status = ydzs_get_delivery_rule_status_for_zone( $zone, $cart_total );
				$rule        = $rule_status['rule'] ?? null;

				if ( ! $rule ) {
					ydzs_store_last_delivery_rule_status( $rule_status );
					ydzs_log( 'Shipping skipped: no matching price rule.', array(
						'zone'       => $zone['name'] ?? '',
						'cart_total' => $cart_total,
						'min_total'  => $rule_status['min_total'] ?? 0,
					) );
					return;
				}

				ydzs_clear_last_delivery_rule_status();

				$label = $this->title;

				$this->add_rate( array(
					'id'       => $this->get_rate_id(),
					'label'    => $label,
					'cost'     => max( 0, (float) $rule['cost'] ),
					'package'  => $package,
					'taxes'    => '',
					'calc_tax' => 'per_order',
					'meta_data' => array(
						'Зона доставки' => $zone['name'] ?? '',
					),
				) );

				ydzs_log( 'Shipping rate added.', array(
					'address'    => $address,
					'point'      => $point,
					'zone'       => $zone['name'] ?? '',
					'cart_total' => $cart_total,
					'cost'       => $rule['cost'],
				) );
			}
		}
	} );

	add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
		$methods['ydzs_shipping'] = 'WC_YDZS_Shipping_Method';
		return $methods;
	} );

	add_filter( 'woocommerce_no_shipping_available_html', 'ydzs_no_shipping_message' );
	add_filter( 'woocommerce_cart_no_shipping_available_html', 'ydzs_no_shipping_message' );
} );


function ydzs_prepare_address_suggest_items( array $candidates, bool $inside_only = true, int $limit = 7, string $requested_address = '' ): array {
	$items           = array();
	$seen            = array();
	$requested_house = ydzs_extract_requested_house_number( $requested_address );

	foreach ( $candidates as $candidate ) {
		if ( ! isset( $candidate['lat'], $candidate['lon'] ) ) {
			continue;
		}

		if ( '' !== $requested_house && ! ydzs_candidate_matches_requested_house( $candidate, $requested_house ) ) {
			continue;
		}

		if ( '' !== trim( $requested_address ) && ! ydzs_candidate_matches_requested_text( $candidate, $requested_address ) ) {
			continue;
		}

		$zone = ydzs_find_zone_for_point( (float) $candidate['lat'], (float) $candidate['lon'] );
		if ( $inside_only && ! $zone ) {
			continue;
		}

		$address = trim( (string) ( $candidate['formatted'] ?? '' ) );
		if ( '' === $address ) {
			continue;
		}

		$key = ydzs_normalize_address_for_token( $address ) . '|' . ydzs_normalize_coord_for_token( $candidate['lat'] ) . '|' . ydzs_normalize_coord_for_token( $candidate['lon'] );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}

		$seen[ $key ] = true;
		$items[] = array(
			'address'   => $address,
			'lat'       => ydzs_normalize_coord_for_token( $candidate['lat'] ),
			'lon'       => ydzs_normalize_coord_for_token( $candidate['lon'] ),
			'zoneId'    => $zone ? (string) ( $zone['id'] ?? '' ) : '',
			'zoneName'  => $zone ? (string) ( $zone['name'] ?? '' ) : '',
			'inside'    => (bool) $zone,
			'house'     => (string) ( $candidate['house'] ?? '' ),
			'precision' => (string) ( $candidate['precision'] ?? '' ),
			'kind'      => (string) ( $candidate['kind'] ?? '' ),
		);

		if ( count( $items ) >= $limit ) {
			break;
		}
	}

	return $items;
}

function ydzs_ajax_address_suggest(): void {
	check_ajax_referer( 'ydzs_frontend', 'nonce' );

	$address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
	$address = trim( wp_strip_all_tags( (string) $address ) );

	if ( mb_strlen( $address ) < 3 ) {
		wp_send_json_success( array(
			'candidates' => array(),
		) );
	}

	$settings = ydzs_get_settings();
	$provider = ydzs_get_geocode_provider( $settings );
	$api_key  = 'google' === $provider ? trim( (string) ( $settings['google_api_key'] ?? '' ) ) : trim( (string) ( $settings['api_key'] ?? '' ) );

	if ( 'yandex' !== $provider || '' === $api_key ) {
		wp_send_json_success( array(
			'candidates' => array(),
		) );
	}

	$candidates       = ydzs_collect_yandex_geocode_candidates( $address, $api_key, $provider, $settings );
	$requested_house  = ydzs_extract_requested_house_number( $address );
	$matched_candidates = ydzs_filter_candidates_for_requested_address( $candidates, $address );
	$items            = ydzs_prepare_address_suggest_items( $matched_candidates, ydzs_address_restrict_to_zones_enabled( $settings ), 7, $address );
	$message          = '';
	$status           = '';

	if ( ! $items && ydzs_address_restrict_to_zones_enabled( $settings ) ) {
		$global_candidates = ydzs_collect_yandex_geocode_candidates_global( $address, $api_key, $provider, $settings );
		$global_candidates = ydzs_filter_candidates_for_requested_address( $global_candidates, $address );

		if ( $global_candidates && ydzs_has_candidates_outside_delivery_zones( $global_candidates ) ) {
			$message = ydzs_address_outside_zones_message();
			$status  = 'outside';
		}
	}

	if ( '' === $message && '' !== $requested_house && ! $items ) {
		$message = 'Не нашли точный вариант адреса в зонах доставки. Уточните населённый пункт, улицу и дом или выберите адрес из списка подсказок.';
		$status  = 'not_found';
	}

	wp_send_json_success( array(
		'candidates' => $items,
		'message'    => $message,
		'status'     => $status,
	) );
}
add_action( 'wp_ajax_ydzs_address_suggest', 'ydzs_ajax_address_suggest' );
add_action( 'wp_ajax_nopriv_ydzs_address_suggest', 'ydzs_ajax_address_suggest' );

function ydzs_ajax_validate_address(): void {
	check_ajax_referer( 'ydzs_frontend', 'nonce' );

	$address  = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
	$address  = trim( wp_strip_all_tags( (string) $address ) );
	$selected = ! empty( $_POST['selected'] );

	if ( '' === $address ) {
		wp_send_json_success( array(
			'status'  => 'empty',
			'message' => ydzs_get_address_hint_text(),
		) );
	}

	if ( ! preg_match( '~\d~', $address ) ) {
		wp_send_json_success( array(
			'status'  => 'need_house',
			'message' => ydzs_get_address_house_hint_text(),
		) );
	}

	$settings = ydzs_get_settings();
	$provider = ydzs_get_geocode_provider( $settings );
	$api_key  = 'google' === $provider ? trim( (string) ( $settings['google_api_key'] ?? '' ) ) : trim( (string) ( $settings['api_key'] ?? '' ) );

	if ( '' === $api_key ) {
		wp_send_json_success( array(
			'status'  => 'no_api_key',
			'message' => 'Адрес будет проверен при пересчёте доставки. Для живой проверки нужен API-ключ карт.',
		) );
	}

	if ( 'yandex' !== $provider ) {
		$point = ydzs_geocode_address( $address );
		$zone  = $point ? ydzs_find_zone_for_point( (float) $point['lat'], (float) $point['lon'] ) : null;

		if ( ! $point ) {
			wp_send_json_success( array(
				'status'  => 'not_found',
				'message' => 'Не удалось определить адрес. Проверьте населённый пункт, улицу и номер дома.',
			) );
		}

		if ( ! $zone ) {
			wp_send_json_success( array(
				'status'  => 'outside',
				'message' => ydzs_address_outside_zones_message(),
			) );
		}

		$formatted   = $address;
		$cart_total  = ydzs_get_current_cart_total_for_rules();
		$rule_status = ydzs_get_delivery_rule_status_for_zone( $zone, $cart_total );
		$message     = ! empty( $rule_status['message'] ) ? (string) $rule_status['message'] : 'Адрес входит в зону доставки' . ( ! empty( $zone['name'] ) ? ': ' . $zone['name'] : '' ) . '.';

		wp_send_json_success( array(
			'status'            => 'inside',
			'message'           => $message,
			'address'           => $formatted,
			'lat'               => ydzs_normalize_coord_for_token( $point['lat'] ),
			'lon'               => ydzs_normalize_coord_for_token( $point['lon'] ),
			'token'             => ydzs_create_address_point_token( $formatted, $point['lat'], $point['lon'] ),
			'zoneId'            => (string) ( $zone['id'] ?? '' ),
			'zoneName'          => (string) ( $zone['name'] ?? '' ),
			'deliveryAvailable' => ! empty( $rule_status['available'] ),
			'minTotal'          => $rule_status['min_total'] ?? 0,
			'cartTotal'         => $rule_status['cart_total'] ?? $cart_total,
			'amountLeft'        => $rule_status['amount_left'] ?? 0,
			'minMessage'        => ! empty( $rule_status['message'] ) ? (string) $rule_status['message'] : '',
		) );
	}

	$candidates = ydzs_collect_yandex_geocode_candidates( $address, $api_key, $provider, $settings );
	$candidates = ydzs_filter_candidates_for_requested_address( $candidates, $address );

	if ( ! $candidates ) {
		$global_candidates = ydzs_collect_yandex_geocode_candidates_global( $address, $api_key, $provider, $settings );
		$global_candidates = ydzs_filter_candidates_for_requested_address( $global_candidates, $address );

		if ( $global_candidates && ydzs_has_candidates_outside_delivery_zones( $global_candidates ) ) {
			wp_send_json_success( array(
				'status'  => 'outside',
				'message' => ydzs_address_outside_zones_message(),
			) );
		}

		wp_send_json_success( array(
			'status'  => 'not_found',
			'message' => 'Не удалось найти точный адрес в зонах доставки. Уточните населённый пункт, улицу и дом или выберите адрес из списка подсказок.',
		) );
	}

	$inside = ydzs_get_zone_matched_candidates( $candidates );

	if ( ! $inside ) {
		wp_send_json_success( array(
			'status'  => 'outside',
			'message' => ydzs_address_outside_zones_message(),
		) );
	}

	$selected_candidate = null;
	foreach ( $inside as $candidate ) {
		if ( ydzs_candidate_matches_address_text( $candidate, $address ) ) {
			$selected_candidate = $candidate;
			break;
		}
	}

	$suggest_items = ydzs_prepare_address_suggest_items( $inside, false, 7, $address );

	// Если адрес не был выбран из подсказки и текст не совпадает с полным адресом геокодера,
	// не подставляем первый найденный вариант молча. Для одинаковых улиц/домов это опасно.
	if ( ! $selected && ! $selected_candidate ) {
		wp_send_json_success( array(
			'status'     => 'choose_suggestion',
			'message'    => 'Найден похожий адрес в зоне доставки. Выберите точный вариант из списка подсказок, чтобы мы не рассчитали доставку по другому адресу.',
			'candidates' => $suggest_items,
		) );
	}

	if ( ! $selected_candidate ) {
		$selected_candidate = $inside[0];
	}

	$formatted = trim( (string) ( $selected_candidate['formatted'] ?? '' ) );
	if ( '' === $formatted ) {
		$formatted = $address;
	}

	$zone        = ydzs_find_zone_for_point( (float) $selected_candidate['lat'], (float) $selected_candidate['lon'] );
	$cart_total  = ydzs_get_current_cart_total_for_rules();
	$rule_status = $zone ? ydzs_get_delivery_rule_status_for_zone( $zone, $cart_total ) : array(
		'available'   => false,
		'min_total'   => 0,
		'cart_total'  => $cart_total,
		'amount_left' => 0,
		'message'     => ydzs_address_outside_zones_message(),
	);
	$message     = ! empty( $rule_status['message'] ) ? (string) $rule_status['message'] : 'Адрес входит в зону доставки' . ( ! empty( $selected_candidate['zone_name'] ) ? ': ' . $selected_candidate['zone_name'] : '' ) . '.';

	wp_send_json_success( array(
		'status'            => 'inside',
		'message'           => $message,
		'address'           => $formatted,
		'lat'               => ydzs_normalize_coord_for_token( $selected_candidate['lat'] ),
		'lon'               => ydzs_normalize_coord_for_token( $selected_candidate['lon'] ),
		'token'             => ydzs_create_address_point_token( $formatted, $selected_candidate['lat'], $selected_candidate['lon'] ),
		'zoneId'            => (string) ( $selected_candidate['zone_id'] ?? '' ),
		'zoneName'          => (string) ( $selected_candidate['zone_name'] ?? '' ),
		'deliveryAvailable' => ! empty( $rule_status['available'] ),
		'minTotal'          => $rule_status['min_total'] ?? 0,
		'cartTotal'         => $rule_status['cart_total'] ?? $cart_total,
		'amountLeft'        => $rule_status['amount_left'] ?? 0,
		'minMessage'        => ! empty( $rule_status['message'] ) ? (string) $rule_status['message'] : '',
	) );
}
add_action( 'wp_ajax_ydzs_validate_address', 'ydzs_ajax_validate_address' );
add_action( 'wp_ajax_nopriv_ydzs_validate_address', 'ydzs_ajax_validate_address' );

function ydzs_no_shipping_message( string $message ): string {
	if ( function_exists( 'WC' ) && WC()->session ) {
		$min_message = (string) WC()->session->get( 'ydzs_last_min_order_message', '' );
		if ( '' !== trim( $min_message ) ) {
			return $min_message;
		}
	}

	return 'Адрес отсутствует в зонах доставки. Для этого адреса доступен только самовывоз.';
}


add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() || ! function_exists( 'is_checkout' ) || ! function_exists( 'is_cart' ) ) {
		return;
	}

	$is_checkout_context = is_checkout() || is_cart();
	$force_frontend      = (bool) apply_filters( 'ydzs_should_enqueue_frontend_assets', false );

	if ( ! $is_checkout_context && ! $force_frontend ) {
		return;
	}

	$settings = ydzs_get_settings();
	$deps     = array( 'jquery' );
	$api_key  = trim( (string) ( $settings['api_key'] ?? '' ) );
	$suggest_enabled = ydzs_address_suggest_enabled( $settings ) && 'yandex' === ydzs_get_geocode_provider( $settings ) && '' !== $api_key;

	wp_enqueue_style( 'ydzs-frontend', YDZS_URL . 'assets/frontend.css', array(), YDZS_VERSION );


	wp_enqueue_script( 'ydzs-frontend', YDZS_URL . 'assets/frontend.js', $deps, YDZS_VERSION, true );
	$address_bounds = ydzs_get_effective_address_bounds( $settings );

	wp_localize_script( 'ydzs-frontend', 'YDZS_FRONTEND', array(
		'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
		'nonce'             => wp_create_nonce( 'ydzs_frontend' ),
		'fieldNames'        => ydzs_get_address_field_names(),
		'selectors'         => (string) ( $settings['address_selectors'] ?? '' ),
		'placeholder'       => ydzs_get_address_placeholder_text( $settings ),
		'hint'              => ydzs_get_address_hint_text( $settings ),
		'houseHint'         => ydzs_get_address_house_hint_text( $settings ),
		'suggestEnabled'    => $suggest_enabled,
		'validateEnabled'   => 'yandex' === ydzs_get_geocode_provider( $settings ) && '' !== $api_key,
		'addressContext'    => ydzs_get_address_context_text( $settings ),
		'restrictToZones'   => ydzs_address_restrict_to_zones_enabled( $settings ),
		'suggestBounds'     => ydzs_get_yandex_suggest_bounds( $address_bounds ),
		'checkoutContext'   => $is_checkout_context,
		'globalPopupMode'   => ! $is_checkout_context && $force_frontend,
	) );
} );

add_action( 'admin_menu', function () {
	add_submenu_page(
		'woocommerce',
		'Зоны доставки на карте',
		'Зоны доставки на карте',
		'manage_woocommerce',
		'ydzs-zones',
		'ydzs_render_admin_page'
	);
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'woocommerce_page_ydzs-zones' !== $hook ) {
		return;
	}

	$settings     = ydzs_get_settings();
	$map_provider = ydzs_get_map_provider( $settings );
	$api_key      = 'google' === $map_provider ? trim( (string) ( $settings['google_api_key'] ?? '' ) ) : trim( (string) ( $settings['api_key'] ?? '' ) );

	wp_enqueue_style( 'ydzs-admin', YDZS_URL . 'assets/admin.css', array(), YDZS_VERSION );

	if ( $api_key && 'yandex' === $map_provider ) {
		wp_enqueue_script(
			'ymaps',
			'https://api-maps.yandex.ru/2.1/?apikey=' . rawurlencode( $api_key ) . '&lang=ru_RU',
			array(),
			null,
			true
		);
	} elseif ( $api_key ) {
		/**
		 * External map providers are implemented by add-ons.
		 */
		do_action( 'ydzs_admin_enqueue_map_provider', $map_provider, $settings );
	}

	wp_enqueue_script( 'ydzs-admin', YDZS_URL . 'assets/admin.js', array( 'jquery' ), YDZS_VERSION, true );

	wp_localize_script( 'ydzs-admin', 'YDZS_ADMIN', array(
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'ydzs_admin' ),
		'settings' => $settings,
		'zones'    => ydzs_get_zones(),
	) );
} );

function ydzs_render_admin_page(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Недостаточно прав.' );
	}

	$settings = ydzs_get_settings();
	$zones    = ydzs_get_zones();
	$edit_id  = isset( $_GET['zone_id'] ) ? sanitize_text_field( wp_unslash( $_GET['zone_id'] ) ) : '';
	$edit     = null;

	foreach ( $zones as $zone ) {
		if ( isset( $zone['id'] ) && $zone['id'] === $edit_id ) {
			$edit = $zone;
			break;
		}
	}

	if ( ! $edit ) {
		$edit = array(
			'id'      => '',
			'name'    => '',
			'enabled' => 'yes',
			'coords'  => array(),
			'rules'   => array(
				array( 'min' => 0, 'cost' => 0 ),
			),
		);
	}

	?>
	<div class="wrap ydzs-wrap">
		<h1>
			Зоны доставки на карте
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=ydzs-zones' ) ); ?>" class="page-title-action">Добавить новую</a>
		</h1>

		<?php if ( ! ydzs_is_wc_active() ) : ?>
			<div class="notice notice-error"><p>WooCommerce не активен. Расчет доставки работать не будет.</p></div>
		<?php endif; ?>

		<?php
		$map_key_ok = 'google' === ydzs_get_map_provider( $settings ) ? ! empty( $settings['google_api_key'] ) : ! empty( $settings['api_key'] );
		$geo_key_ok = 'google' === ydzs_get_geocode_provider( $settings ) ? ! empty( $settings['google_api_key'] ) : ! empty( $settings['api_key'] );
		?>
		<?php if ( ! $map_key_ok || ! $geo_key_ok ) : ?>
			<div class="notice notice-warning"><p>Укажите API-ключ выбранного провайдера карт/геокодирования. Без него карта и расчет по адресу не заработают.</p></div>
		<?php endif; ?>

		<?php if ( isset( $_GET['ydzs_imported'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Зоны импортированы: <?php echo esc_html( absint( $_GET['ydzs_imported'] ) ); ?>.</p></div>
		<?php endif; ?>

		<?php if ( isset( $_GET['ydzs_import_error'] ) ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ydzs_import_error'] ) ) ); ?></p></div>
		<?php endif; ?>

		<?php if ( isset( $_GET['ydzs_zone_error'] ) ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ydzs_zone_error'] ) ) ); ?></p></div>
		<?php endif; ?>

		<div class="ydzs-grid">
			<div class="ydzs-card">
				<h2>Настройки</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ydzs_save_settings' ); ?>
					<input type="hidden" name="action" value="ydzs_save_settings">

					<table class="form-table">
						<?php $available_providers = ydzs_get_available_providers(); ?>
						<tr>
							<th><label for="ydzs_provider">Провайдер карт и геокодирования</label></th>
							<td>
								<select id="ydzs_provider" name="provider">
									<?php foreach ( $available_providers as $provider_key => $provider_label ) : ?>
										<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $settings['provider'] ?? 'yandex', $provider_key ); ?>><?php echo esc_html( $provider_label ); ?></option>
									<?php endforeach; ?>
									<?php if ( ! ydzs_feature_enabled( 'google' ) ) : ?>
										<option value="google" disabled>Google <?php echo wp_kses_post( ydzs_pro_badge() ); ?></option>
									<?php endif; ?>
								</select>
								<p class="description">В обычном режиме выбранный провайдер используется и для карты, и для геокодирования, и для проверки адреса. Для РФ/СНГ обычно удобнее Яндекс, для зарубежных проектов — Google в Pro.</p>
								<?php if ( ydzs_feature_enabled( 'advanced_providers' ) ) : ?>
									<label style="display:block;margin-top:8px;">
										<input type="checkbox" name="advanced_providers" value="yes" <?php checked( $settings['advanced_providers'] ?? 'no', 'yes' ); ?>>
										Расширенные настройки провайдеров
									</label>
								<?php else : ?>
									<p class="description">Расширенные настройки провайдеров доступны в Pro.</p>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( ydzs_feature_enabled( 'advanced_providers' ) ) : ?>
						<tr class="ydzs-advanced-provider-row">
							<th><label for="ydzs_map_provider">Провайдер карты</label></th>
							<td>
								<select id="ydzs_map_provider" name="map_provider">
									<?php foreach ( $available_providers as $provider_key => $provider_label ) : ?>
										<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $settings['map_provider'] ?? 'yandex', $provider_key ); ?>><?php echo esc_html( $provider_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr class="ydzs-advanced-provider-row">
							<th><label for="ydzs_geocode_provider">Провайдер геокодирования</label></th>
							<td>
								<select id="ydzs_geocode_provider" name="geocode_provider">
									<?php foreach ( $available_providers as $provider_key => $provider_label ) : ?>
										<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $settings['geocode_provider'] ?? 'yandex', $provider_key ); ?>><?php echo esc_html( $provider_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<?php endif; ?>
						<tr>
							<th><label for="ydzs_api_key">API-ключ Яндекс</label></th>
							<td>
								<input type="text" class="regular-text" id="ydzs_api_key" name="api_key" value="<?php echo esc_attr( $settings['api_key'] ); ?>">
								<p class="description">Нужен для Яндекс.Карт и Яндекс Геокодера.</p>
							</td>
						</tr>
						<?php if ( ydzs_feature_enabled( 'google' ) ) : ?>
						<tr>
							<th><label for="ydzs_google_api_key">API-ключ Google</label></th>
							<td>
								<input type="text" class="regular-text" id="ydzs_google_api_key" name="google_api_key" value="<?php echo esc_attr( $settings['google_api_key'] ?? '' ); ?>">
								<p class="description">Нужен для Google Maps JavaScript API и Google Geocoding API. В Google Cloud должен быть включен billing.</p>
							</td>
						</tr>
						<tr>
							<th><label for="ydzs_google_region">Регион Google</label></th>
							<td>
								<input type="text" class="small-text" id="ydzs_google_region" name="google_region" value="<?php echo esc_attr( $settings['google_region'] ?? 'ru' ); ?>">
								<p class="description">Например: ru, nl, ee. Используется как подсказка региона для Google Geocoding API.</p>
							</td>
						</tr>
						<?php else : ?>
						<tr>
							<th>Google Maps</th>
							<td><p class="description">Google Maps и Google Geocoding доступны в Pro-дополнении.</p></td>
						</tr>
						<?php endif; ?>
						<tr>
							<th><label for="ydzs_map_center">Центр карты</label></th>
							<td>
								<input type="text" class="regular-text" id="ydzs_map_center" name="map_center" value="<?php echo esc_attr( $settings['map_center'] ); ?>">
								<p class="description">Формат: широта,долгота. Например: 55.751244,37.618423. Можно также переместить карту и нажать «Сохранить текущий вид карты».</p>
							</td>
						</tr>
						<tr>
							<th><label for="ydzs_map_zoom">Масштаб карты</label></th>
							<td><input type="number" min="1" max="19" id="ydzs_map_zoom" name="map_zoom" value="<?php echo esc_attr( $settings['map_zoom'] ); ?>"></td>
						</tr>
						<tr>
							<th><label for="ydzs_default_title">Название доставки</label></th>
							<td><input type="text" class="regular-text" id="ydzs_default_title" name="default_title" value="<?php echo esc_attr( $settings['default_title'] ); ?>"></td>
						</tr>
						<tr>
							<th><label for="ydzs_address_field_names">Имена полей адреса</label></th>
							<td>
								<textarea class="large-text" rows="3" id="ydzs_address_field_names" name="address_field_names"><?php echo esc_textarea( $settings['address_field_names'] ?? '' ); ?></textarea>
								<p class="description">Через запятую или с новой строки. Нужно для кастомных checkout-страниц, где адрес хранится не в стандартном shipping_address_1.</p>
							</td>
						</tr>
						<tr>
							<th><label for="ydzs_address_selectors">CSS-селекторы поля адреса</label></th>
							<td>
								<textarea class="large-text" rows="3" id="ydzs_address_selectors" name="address_selectors"><?php echo esc_textarea( $settings['address_selectors'] ?? '' ); ?></textarea>
								<p class="description">Необязательно. Например: #delivery-address, input[name="your_delivery_address"]. При изменении этих полей плагин будет запускать пересчет checkout.</p>
							</td>
						</tr>
						<tr>
							<th>Автоподсказки адреса</th>
							<td>
								<label>
									<input type="checkbox" name="address_suggest" value="yes" <?php checked( ydzs_address_suggest_enabled( $settings ) ); ?>>
									Показывать покупателю выпадающий список адресов Яндекс.Карт при вводе
								</label>
								<p class="description">Для работы нужен API-ключ Яндекс.Карт. Покупателю проще выбрать полный адрес из списка, а плагин сразу проверит, входит ли выбранный адрес в зону доставки.</p>
							</td>
						</tr>
						<tr>
							<th>Границы поиска адреса</th>
							<td>
								<label>
									<input type="checkbox" name="address_restrict_to_zones" value="yes" <?php checked( ydzs_address_restrict_to_zones_enabled( $settings ) ); ?>>
									Искать и подсказывать адреса в пределах нарисованных зон доставки
								</label>
								<p class="description">Плагин строит техническую рамку по полигонам зон и передаёт её в Яндекс.Карты/геокодер. Это лучше, чем общий регион вроде «Ленинградская область», потому что одинаковые улицы из других районов не должны попадать в приоритет.</p>
							</td>
						</tr>
						<tr>
							<th><label for="ydzs_address_context">Текстовый контекст для коротких адресов</label></th>
							<td>
								<input type="text" class="regular-text" id="ydzs_address_context" name="address_context" value="<?php echo esc_attr( ydzs_get_address_context_text( $settings ) ); ?>">
								<p class="description">Необязательно. Это только дополнительный текстовый хвост для совсем коротких адресов. Основное уточнение теперь делается по границам нарисованных зон доставки.</p>
							</td>
						</tr>
						<tr>
							<th><label for="ydzs_address_placeholder">Пример адреса</label></th>
							<td>
								<input type="text" class="regular-text" id="ydzs_address_placeholder" name="address_placeholder" value="<?php echo esc_attr( ydzs_get_address_placeholder_text( $settings ) ); ?>">
								<p class="description">Этот текст подставляется в placeholder поля адреса, если у поля ещё нет своего placeholder.</p>
							</td>
						</tr>
						<tr>
							<th><label for="ydzs_address_hint">Подсказка под полем адреса</label></th>
							<td>
								<textarea class="large-text" rows="2" id="ydzs_address_hint" name="address_hint"><?php echo esc_textarea( ydzs_get_address_hint_text( $settings ) ); ?></textarea>
								<p class="description">Показывается покупателю в checkout рядом с найденным полем адреса.</p>
							</td>
						</tr>
						<tr>
							<th><label for="ydzs_address_house_hint">Подсказка без номера дома</label></th>
							<td>
								<textarea class="large-text" rows="2" id="ydzs_address_house_hint" name="address_house_hint"><?php echo esc_textarea( ydzs_get_address_house_hint_text( $settings ) ); ?></textarea>
								<p class="description">Показывается, когда покупатель ввёл адрес без цифр. Это мягкая подсказка, а не блокировка оформления.</p>
							</td>
						</tr>
						<tr>
							<th>Логирование</th>
							<td>
								<label>
									<input type="checkbox" name="debug_log" value="yes" <?php checked( $settings['debug_log'], 'yes' ); ?>>
									Писать лог WooCommerce → Статус → Журналы → yd-zones-shipping
								</label>
							</td>
						</tr>
					</table>

					<?php submit_button( 'Сохранить настройки' ); ?>
				</form>
			</div>

			<div class="ydzs-card">
				<h2><?php echo $edit['id'] ? 'Редактировать зону' : 'Добавить зону'; ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ydzs-zone-form">
					<?php wp_nonce_field( 'ydzs_save_zone' ); ?>
					<input type="hidden" name="action" value="ydzs_save_zone">
					<input type="hidden" name="zone_id" value="<?php echo esc_attr( $edit['id'] ); ?>">
					<input type="hidden" name="coords" id="ydzs_coords" value="<?php echo esc_attr( wp_json_encode( $edit['coords'], JSON_UNESCAPED_UNICODE ) ); ?>">

					<table class="form-table">
						<tr>
							<th><label for="ydzs_zone_name">Название зоны</label></th>
							<td><input type="text" class="regular-text" id="ydzs_zone_name" name="name" required value="<?php echo esc_attr( $edit['name'] ); ?>"></td>
						</tr>
						<tr>
							<th>Активность</th>
							<td>
								<label>
									<input type="checkbox" name="enabled" value="yes" <?php checked( $edit['enabled'], 'yes' ); ?>>
									Зона включена
								</label>
							</td>
						</tr>
						<tr>
							<th>Полигон</th>
							<td>
								<div class="ydzs-map-actions">
									<button type="button" class="button" id="ydzs-draw-zone">Нарисовать / перерисовать</button>
									<button type="button" class="button" id="ydzs-clear-zone">Очистить</button>
									<button type="button" class="button button-secondary" id="ydzs-save-map-view">Сохранить текущий вид карты</button>
									<span class="ydzs-map-save-status" id="ydzs-map-save-status" aria-live="polite"></span>
								</div>
								<div id="ydzs-map"></div>
								<p class="description">Нажмите «Нарисовать», кликами поставьте точки полигона. Двойной клик завершает рисование. Потом вершины можно двигать.</p>
							</td>
						</tr>
					</table>

					<h3>Правила стоимости</h3>
					<p class="description">Правило выбирается по максимальной подходящей сумме «от». Например: от 0 — 300 ₽, от 3000 — 0 ₽.</p>

					<table class="widefat striped ydzs-rules-table">
						<thead>
							<tr>
								<th>Сумма корзины от</th>
								<th>Стоимость доставки</th>
								<th></th>
							</tr>
						</thead>
						<tbody id="ydzs-rules-body">
							<?php foreach ( (array) $edit['rules'] as $rule ) : ?>
								<tr>
									<td><input type="text" name="rules_min[]" value="<?php echo esc_attr( $rule['min'] ?? 0 ); ?>"></td>
									<td><input type="text" name="rules_cost[]" value="<?php echo esc_attr( $rule['cost'] ?? 0 ); ?>"></td>
									<td><button type="button" class="button ydzs-remove-rule">Удалить</button></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p>
						<button type="button" class="button" id="ydzs-add-rule">Добавить правило</button>
					</p>

					<?php submit_button( $edit['id'] ? 'Сохранить зону' : 'Добавить зону' ); ?>
				</form>
			</div>
		</div>

		<div class="ydzs-card">
			<h2>Созданные зоны</h2>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Название</th>
						<th>Статус</th>
						<th>Точек полигона</th>
						<th>Правила</th>
						<th>Действия</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $zones ) ) : ?>
					<tr><td colspan="5">Зоны пока не созданы.</td></tr>
				<?php else : ?>
					<?php foreach ( $zones as $zone ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $zone['name'] ?? '' ); ?></strong></td>
							<td><?php echo ( ! empty( $zone['enabled'] ) && 'yes' === $zone['enabled'] ) ? 'Включена' : 'Отключена'; ?></td>
							<td><?php echo esc_html( count( ydzs_normalize_polygon( $zone['coords'] ?? array() ) ) ); ?></td>
							<td>
								<?php
								$rules = array();
								foreach ( (array) ( $zone['rules'] ?? array() ) as $rule ) {
									$rules[] = 'от ' . esc_html( $rule['min'] ?? 0 ) . ' → ' . esc_html( $rule['cost'] ?? 0 );
								}
								echo implode( '<br>', $rules ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</td>
							<td>
								<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ydzs-zones', 'zone_id' => $zone['id'] ?? '' ), admin_url( 'admin.php' ) ) ); ?>">Редактировать</a>
								<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ydzs_delete_zone', 'zone_id' => $zone['id'] ?? '' ), admin_url( 'admin-post.php' ) ), 'ydzs_delete_zone' ) ); ?>" onclick="return confirm('Удалить зону?')">Удалить</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div class="ydzs-card">
			<h2>Проверка адреса <?php if ( ! ydzs_feature_enabled( 'diagnostic' ) ) { echo wp_kses_post( ydzs_pro_badge() ); } ?></h2>
			<?php if ( ydzs_feature_enabled( 'diagnostic' ) ) : ?>
				<p>Диагностика показывает, как текущий геокодер понимает адрес, в какую зону он попадает и какая стоимость будет применена.</p>
				<div class="ydzs-diagnostic">
					<input type="text" class="regular-text" id="ydzs-check-address" placeholder="Введите адрес для проверки">
					<input type="number" class="small-text" id="ydzs-check-total" min="0" step="0.01" value="0" title="Сумма корзины">
					<button type="button" class="button button-primary" id="ydzs-check-address-btn">Проверить</button>
				</div>
				<div id="ydzs-check-result" class="ydzs-check-result" aria-live="polite"></div>
			<?php else : ?>
				<p>Диагностика адреса доступна в Pro: видно координаты, выбранную зону, правило стоимости и причину отказа доставки.</p>
			<?php endif; ?>
		</div>

		<div class="ydzs-card">
			<h2>Экспорт / импорт зон</h2>
			<p>Экспортируются только нарисованные зоны с полигонами и правилами стоимости. API-ключи карт и остальные настройки сайта в файл не попадают.</p>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ydzs_export_zones' ), 'ydzs_export_zones' ) ); ?>">Скачать зоны JSON</a>
			</p>

			<?php if ( ydzs_feature_enabled( 'import' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'ydzs_import_zones' ); ?>
					<input type="hidden" name="action" value="ydzs_import_zones">

					<table class="form-table">
						<tr>
							<th><label for="ydzs_import_file">JSON-файл</label></th>
							<td><input type="file" id="ydzs_import_file" name="import_file" accept="application/json,.json" required></td>
						</tr>
						<tr>
							<th>Режим импорта</th>
							<td>
								<label><input type="radio" name="import_mode" value="merge" checked> Добавить к текущим зонам</label><br>
								<label><input type="radio" name="import_mode" value="replace"> Заменить все текущие зоны</label>
								<p class="description">При добавлении к текущим зонам дублирующиеся ID будут заменены, чтобы не перезаписать существующие зоны.</p>
							</td>
						</tr>
					</table>

					<?php submit_button( 'Импортировать зоны' ); ?>
				</form>
			<?php else : ?>
				<p>Импорт зон доступен в Pro. В бесплатной версии можно экспортировать настройки зон в JSON.</p>
			<?php endif; ?>
		</div>

		<div class="ydzs-card">
			<h2>Подключение в WooCommerce</h2>
			<ol>
				<li>Откройте WooCommerce → Настройки → Доставка → Зоны доставки.</li>
				<li>Добавьте или откройте нужную стандартную зону WooCommerce, например «Россия» или «Санкт-Петербург».</li>
				<li>Добавьте метод доставки «Доставка по зонам на карте».</li>
				<li>На чекауте WooCommerce передаст адрес в этот метод, плагин геокодирует адрес и проверит попадание в нарисованный полигон.</li>
			</ol>
		</div>
	</div>
	<?php
}


add_action( 'wp_ajax_ydzs_save_map_view', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Недостаточно прав.' ), 403 );
	}

	check_ajax_referer( 'ydzs_admin', 'nonce' );

	$center = isset( $_POST['map_center'] ) ? ydzs_sanitize_map_center( wp_unslash( $_POST['map_center'] ) ) : '';
	$zoom   = isset( $_POST['map_zoom'] ) ? max( 1, min( 19, absint( $_POST['map_zoom'] ) ) ) : 10;

	if ( '' === $center ) {
		wp_send_json_error( array( 'message' => 'Не удалось определить центр карты.' ), 400 );
	}

	$settings = ydzs_get_settings();
	$settings['map_center'] = $center;
	$settings['map_zoom']   = $zoom;

	update_option( YDZS_OPTION_SETTINGS, $settings, false );

	wp_send_json_success( array(
		'message'    => 'Центр карты сохранён.',
		'map_center' => $center,
		'map_zoom'   => $zoom,
	) );
} );

add_action( 'admin_post_ydzs_save_settings', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Недостаточно прав.' );
	}

	check_admin_referer( 'ydzs_save_settings' );

	$available_provider_keys = array_keys( ydzs_get_available_providers() );
	if ( empty( $available_provider_keys ) ) {
		$available_provider_keys = array( 'yandex' );
	}

	$provider = isset( $_POST['provider'] ) && in_array( sanitize_key( wp_unslash( $_POST['provider'] ) ), $available_provider_keys, true ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'yandex';
	$advanced_providers = ( isset( $_POST['advanced_providers'] ) && ydzs_feature_enabled( 'advanced_providers' ) ) ? 'yes' : 'no';
	$map_provider = isset( $_POST['map_provider'] ) && in_array( sanitize_key( wp_unslash( $_POST['map_provider'] ) ), $available_provider_keys, true ) ? sanitize_key( wp_unslash( $_POST['map_provider'] ) ) : $provider;
	$geocode_provider = isset( $_POST['geocode_provider'] ) && in_array( sanitize_key( wp_unslash( $_POST['geocode_provider'] ) ), $available_provider_keys, true ) ? sanitize_key( wp_unslash( $_POST['geocode_provider'] ) ) : $provider;

	if ( 'yes' !== $advanced_providers ) {
		$map_provider = $provider;
		$geocode_provider = $provider;
	}

	$settings = array(
		'api_key'       => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '',
		'google_api_key'=> isset( $_POST['google_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['google_api_key'] ) ) : '',
		'provider'      => $provider,
		'advanced_providers' => $advanced_providers,
		'map_provider'  => $map_provider,
		'geocode_provider' => $geocode_provider,
		'google_region' => isset( $_POST['google_region'] ) ? sanitize_key( wp_unslash( $_POST['google_region'] ) ) : 'ru',
		'map_center'    => isset( $_POST['map_center'] ) ? ydzs_sanitize_map_center( wp_unslash( $_POST['map_center'] ) ) : '55.751244,37.618423',
		'map_zoom'      => isset( $_POST['map_zoom'] ) ? max( 1, min( 19, absint( $_POST['map_zoom'] ) ) ) : 10,
		'debug_log'     => isset( $_POST['debug_log'] ) ? 'yes' : 'no',
		'default_title'       => isset( $_POST['default_title'] ) ? sanitize_text_field( wp_unslash( $_POST['default_title'] ) ) : 'Доставка',
		'address_field_names' => isset( $_POST['address_field_names'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address_field_names'] ) ) : 'of_address,of_delivery_address,delivery_address,address,order_address,shipping_address_1,billing_address_1',
		'address_selectors'   => isset( $_POST['address_selectors'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address_selectors'] ) ) : '',
		'address_suggest'     => isset( $_POST['address_suggest'] ) ? 'yes' : 'no',
		'address_restrict_to_zones' => isset( $_POST['address_restrict_to_zones'] ) ? 'yes' : 'no',
		'address_context'     => isset( $_POST['address_context'] ) ? sanitize_text_field( wp_unslash( $_POST['address_context'] ) ) : '',
		'address_placeholder' => isset( $_POST['address_placeholder'] ) ? sanitize_text_field( wp_unslash( $_POST['address_placeholder'] ) ) : 'Например: Санкт-Петербург, Невский проспект, 10',
		'address_hint'        => isset( $_POST['address_hint'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address_hint'] ) ) : 'Начните вводить адрес и выберите подходящий вариант из списка. Обязательно укажите населённый пункт, улицу и номер дома.',
		'address_house_hint'  => isset( $_POST['address_house_hint'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address_house_hint'] ) ) : 'Добавьте номер дома — без него карта может определить только улицу, и доставка может не рассчитаться.',
	);

	update_option( YDZS_OPTION_SETTINGS, $settings, false );

	wp_safe_redirect( add_query_arg( array( 'page' => 'ydzs-zones', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
	exit;
} );

add_action( 'admin_post_ydzs_save_zone', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Недостаточно прав.' );
	}

	check_admin_referer( 'ydzs_save_zone' );

	$zones   = ydzs_get_zones();
	$zone_id = isset( $_POST['zone_id'] ) ? sanitize_text_field( wp_unslash( $_POST['zone_id'] ) ) : '';

	if ( '' === $zone_id ) {
		$zone_id = 'zone_' . wp_generate_uuid4();
	}

	$is_new_zone = true;
	foreach ( $zones as $zone ) {
		if ( isset( $zone['id'] ) && $zone['id'] === $zone_id ) {
			$is_new_zone = false;
			break;
		}
	}

	if ( $is_new_zone && ! ydzs_feature_enabled( 'unlimited_zones' ) && count( $zones ) >= 3 ) {
		wp_safe_redirect( add_query_arg( array(
			'page'            => 'ydzs-zones',
			'ydzs_zone_error' => rawurlencode( 'В бесплатной версии можно создать до 3 зон. Для большего количества зон нужен Pro.' ),
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$enabled = isset( $_POST['enabled'] ) ? 'yes' : 'no';

	$coords_raw = isset( $_POST['coords'] ) ? wp_unslash( $_POST['coords'] ) : '[]';
	$coords     = json_decode( $coords_raw, true );
	$coords     = ydzs_normalize_polygon( $coords );

	$rules_min  = isset( $_POST['rules_min'] ) && is_array( $_POST['rules_min'] ) ? array_map( 'wp_unslash', $_POST['rules_min'] ) : array();
	$rules_cost = isset( $_POST['rules_cost'] ) && is_array( $_POST['rules_cost'] ) ? array_map( 'wp_unslash', $_POST['rules_cost'] ) : array();

	$raw_rules = array();
	foreach ( $rules_min as $i => $min ) {
		$raw_rules[] = array(
			'min'  => sanitize_text_field( $min ),
			'cost' => sanitize_text_field( $rules_cost[ $i ] ?? 0 ),
		);
	}

	$rules = ydzs_sanitize_rules( $raw_rules );

	$new_zone = array(
		'id'      => $zone_id,
		'name'    => $name,
		'enabled' => $enabled,
		'coords'  => $coords,
		'rules'   => $rules,
	);

	$updated = false;
	foreach ( $zones as $index => $zone ) {
		if ( isset( $zone['id'] ) && $zone['id'] === $zone_id ) {
			$zones[ $index ] = $new_zone;
			$updated         = true;
			break;
		}
	}

	if ( ! $updated ) {
		$zones[] = $new_zone;
	}

	update_option( YDZS_OPTION_ZONES, $zones, false );

	wp_safe_redirect( add_query_arg( array( 'page' => 'ydzs-zones', 'zone_id' => $zone_id, 'updated' => 'true' ), admin_url( 'admin.php' ) ) );
	exit;
} );

add_action( 'admin_post_ydzs_export_zones', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Недостаточно прав.' );
	}

	check_admin_referer( 'ydzs_export_zones' );

	$payload = array(
		'plugin'      => 'yd-zones-shipping',
		'version'     => YDZS_VERSION,
		'exported_at' => gmdate( 'c' ),
		'zones'       => ydzs_get_zones(),
	);

	$filename = 'yd-zones-shipping-zones-' . gmdate( 'Y-m-d-H-i-s' ) . '.json';

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'X-Content-Type-Options: nosniff' );

	echo wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
} );

add_action( 'admin_post_ydzs_delete_zone', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Недостаточно прав.' );
	}

	check_admin_referer( 'ydzs_delete_zone' );

	$zone_id = isset( $_GET['zone_id'] ) ? sanitize_text_field( wp_unslash( $_GET['zone_id'] ) ) : '';
	$zones   = ydzs_get_zones();

	$zones = array_values( array_filter( $zones, function ( $zone ) use ( $zone_id ) {
		return ! isset( $zone['id'] ) || $zone['id'] !== $zone_id;
	} ) );

	update_option( YDZS_OPTION_ZONES, $zones, false );

	wp_safe_redirect( add_query_arg( array( 'page' => 'ydzs-zones', 'deleted' => 'true' ), admin_url( 'admin.php' ) ) );
	exit;
} );
