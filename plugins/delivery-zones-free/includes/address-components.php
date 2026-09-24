<?php
/**
 * Provider-neutral normalized address components used by Delivery Zones checkout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ydzs_empty_address_components(): array {
	return array(
		'formatted'    => '',
		'country_code' => '',
		'country'      => '',
		'state'        => '',
		'city'         => '',
		'postcode'     => '',
		'street'       => '',
		'house'        => '',
		'address_1'    => '',
	);
}

function ydzs_normalize_address_components( array $raw ): array {
	$clean = ydzs_empty_address_components();
	foreach ( array_keys( $clean ) as $key ) {
		if ( ! isset( $raw[ $key ] ) || is_array( $raw[ $key ] ) || is_object( $raw[ $key ] ) ) {
			continue;
		}
		$clean[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
	}

	$country_code = strtoupper( sanitize_key( $clean['country_code'] ) );
	$clean['country_code'] = preg_match( '/^[A-Z]{2}$/', $country_code ) ? $country_code : '';

	if ( '' === $clean['address_1'] && '' !== $clean['street'] && '' !== $clean['house'] ) {
		$clean['address_1'] = $clean['street'] . ', ' . $clean['house'];
	}

	return $clean;
}

function ydzs_address_component_from_yandex( array $meta, string $kind ): string {
	$components = $meta['Address']['Components'] ?? array();
	if ( ! is_array( $components ) ) {
		return '';
	}
	foreach ( $components as $component ) {
		if ( ! is_array( $component ) || $kind !== (string) ( $component['kind'] ?? '' ) ) {
			continue;
		}
		return sanitize_text_field( (string) ( $component['name'] ?? '' ) );
	}
	return '';
}

function ydzs_yandex_address_components( array $meta ): array {
	$address = isset( $meta['Address'] ) && is_array( $meta['Address'] ) ? $meta['Address'] : array();
	return ydzs_normalize_address_components(
		array(
			'formatted'    => (string) ( $address['formatted'] ?? ( $meta['text'] ?? '' ) ),
			'country_code' => (string) ( $address['country_code'] ?? '' ),
			'country'      => ydzs_address_component_from_yandex( $meta, 'country' ),
			'state'        => ydzs_address_component_from_yandex( $meta, 'province' ),
			'city'         => ydzs_address_component_from_yandex( $meta, 'locality' ),
			'postcode'     => (string) ( $address['postal_code'] ?? '' ),
			'street'       => ydzs_address_component_from_yandex( $meta, 'street' ),
			'house'        => ydzs_address_component_from_yandex( $meta, 'house' ),
		)
	);
}

function ydzs_google_component( array $result, array $types, bool $short = false ): string {
	$components = $result['address_components'] ?? array();
	if ( ! is_array( $components ) ) {
		return '';
	}
	foreach ( $types as $wanted_type ) {
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			$component_types = isset( $component['types'] ) && is_array( $component['types'] ) ? $component['types'] : array();
			if ( ! in_array( $wanted_type, $component_types, true ) ) {
				continue;
			}
			$key = $short ? 'short_name' : 'long_name';
			return sanitize_text_field( (string) ( $component[ $key ] ?? '' ) );
		}
	}
	return '';
}

function ydzs_google_address_components( array $result ): array {
	return ydzs_normalize_address_components(
		array(
			'formatted'    => (string) ( $result['formatted_address'] ?? '' ),
			'country_code' => ydzs_google_component( $result, array( 'country' ), true ),
			'country'      => ydzs_google_component( $result, array( 'country' ) ),
			'state'        => ydzs_google_component( $result, array( 'administrative_area_level_1', 'administrative_area_level_2' ) ),
			'city'         => ydzs_google_component( $result, array( 'postal_town', 'locality', 'administrative_area_level_3' ) ),
			'postcode'     => ydzs_google_component( $result, array( 'postal_code' ) ),
			'street'       => ydzs_google_component( $result, array( 'route' ) ),
			'house'        => ydzs_google_component( $result, array( 'street_number' ) ),
		)
	);
}

function ydzs_normalize_address_compare_value( string $value ): string {
	$value = trim( preg_replace( '/\s+/u', ' ', $value ) );
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
}

function ydzs_match_wc_state_code( string $country_code, string $state, array $states ): string {
	unset( $country_code );
	$state = trim( $state );
	if ( '' === $state || ! $states ) {
		return '';
	}
	$state_normalized = ydzs_normalize_address_compare_value( $state );
	foreach ( $states as $code => $label ) {
		if ( ydzs_normalize_address_compare_value( (string) $code ) === $state_normalized || ydzs_normalize_address_compare_value( (string) $label ) === $state_normalized ) {
			return sanitize_text_field( (string) $code );
		}
	}
	return '';
}

function ydzs_public_address_components( array $components ): array {
	$components = ydzs_normalize_address_components( $components );
	$public = array();
	foreach ( array( 'formatted', 'country_code', 'country', 'state', 'city', 'postcode', 'street', 'house', 'address_1' ) as $key ) {
		$public[ $key ] = sanitize_text_field( (string) $components[ $key ] );
	}
	return $public;
}

/**
 * Normalize state to the WooCommerce state code when that country has a state list.
 * For countries without a state list, a textual state remains valid for a text field.
 */
function ydzs_checkout_address_components( array $components ): array {
	$components = ydzs_public_address_components( $components );
	if ( '' === $components['state'] || '' === $components['country_code'] || ! function_exists( 'WC' ) ) {
		return $components;
	}

	$woocommerce = WC();
	if ( ! $woocommerce || ! isset( $woocommerce->countries ) || ! $woocommerce->countries || ! method_exists( $woocommerce->countries, 'get_states' ) ) {
		return $components;
	}

	$states = $woocommerce->countries->get_states( $components['country_code'] );
	if ( is_array( $states ) && $states ) {
		$components['state'] = ydzs_match_wc_state_code( $components['country_code'], $components['state'], $states );
	}
	return $components;
}
