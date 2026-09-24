<?php
/**
 * Optional WooCommerce checkout address-field simplification for Delivery Zones.
 *
 * Disabled by default. The module owns only address-related WooCommerce fields;
 * customer identity/contact fields remain under WooCommerce/theme control.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ydzs_checkout_fields_defaults(): array {
	return array(
		'checkout_simplify_address' => 'no',
		'checkout_show_address_2'   => 'no',
		'checkout_show_city'        => 'no',
		'checkout_show_state'       => 'no',
		'checkout_show_postcode'    => 'no',
		'checkout_fixed_country'    => '',
		'checkout_show_country'     => 'yes',
	);
}

function ydzs_checkout_yes_no( $value, string $default = 'no' ): string {
	if ( 'yes' === $value || '1' === (string) $value || true === $value || 1 === $value ) {
		return 'yes';
	}
	if ( 'no' === $value || '0' === (string) $value || false === $value || 0 === $value || '' === $value || null === $value ) {
		return 'no';
	}
	return 'yes' === $default ? 'yes' : 'no';
}

function ydzs_checkout_fields_settings( ?array $settings = null ): array {
	if ( null === $settings ) {
		$settings = function_exists( 'ydzs_get_settings' ) ? ydzs_get_settings() : array();
	}

	$settings = wp_parse_args( $settings, ydzs_checkout_fields_defaults() );
	foreach ( array( 'checkout_simplify_address', 'checkout_show_address_2', 'checkout_show_city', 'checkout_show_state', 'checkout_show_postcode', 'checkout_show_country' ) as $key ) {
		$settings[ $key ] = ydzs_checkout_yes_no( $settings[ $key ] ?? 'no', 'checkout_show_country' === $key ? 'yes' : 'no' );
	}

	$country = strtoupper( sanitize_key( (string) ( $settings['checkout_fixed_country'] ?? '' ) ) );
	$settings['checkout_fixed_country'] = preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	if ( '' === $settings['checkout_fixed_country'] ) {
		$settings['checkout_show_country'] = 'yes';
	}

	return $settings;
}

function ydzs_checkout_fields_enabled( ?array $settings = null ): bool {
	$settings = ydzs_checkout_fields_settings( $settings );
	return 'yes' === $settings['checkout_simplify_address'];
}

/**
 * Whether the current Classic Checkout request contains an address that was
 * confirmed by Delivery Zones and whose coordinates still pass the server-side
 * HMAC token check.
 *
 * This deliberately does not depend on "Simplify delivery address": custom
 * themes can use their own address UI while still letting Delivery Zones own
 * validation. Unconfirmed, outside, cleared or tampered addresses never bypass
 * WooCommerce's normal address prerequisites.
 */
function ydzs_checkout_has_verified_address_request(): bool {
	if ( ! function_exists( 'ydzs_get_request_data' ) || ! function_exists( 'ydzs_get_validated_address_point_from_request' ) ) {
		return false;
	}

	$data = ydzs_get_request_data();
	if ( ! is_array( $data ) || ! empty( $data['ydzs_address_user_cleared'] ) ) {
		return false;
	}

	$status  = isset( $data['ydzs_address_status'] ) ? sanitize_key( (string) $data['ydzs_address_status'] ) : '';
	$address = isset( $data['ydzs_address_value'] ) ? trim( wp_strip_all_tags( (string) $data['ydzs_address_value'] ) ) : '';

	if ( 'inside' !== $status || '' === $address ) {
		return false;
	}

	return null !== ydzs_get_validated_address_point_from_request( $address );
}

/**
 * WooCommerce normally refuses to calculate shipping when a locale requires
 * state/postcode but a custom checkout does not expose those fields. A verified
 * Delivery Zones point is already a stronger destination signal, so only for
 * that request may the missing component stop being a prerequisite.
 *
 * Existing false values are preserved so Delivery Zones never re-enables a
 * prerequisite another integration has intentionally disabled.
 */
function ydzs_checkout_shipping_component_gate( $enabled ): bool {
	if ( ! $enabled ) {
		return false;
	}

	return ! ydzs_checkout_has_verified_address_request();
}
add_filter( 'woocommerce_shipping_calculator_enable_state', 'ydzs_checkout_shipping_component_gate', 50 );
add_filter( 'woocommerce_shipping_calculator_enable_postcode', 'ydzs_checkout_shipping_component_gate', 50 );

function ydzs_checkout_fixed_country( ?array $settings = null ): string {
	$settings = ydzs_checkout_fields_settings( $settings );
	if ( ! ydzs_checkout_fields_enabled( $settings ) ) {
		return '';
	}

	$country = (string) $settings['checkout_fixed_country'];
	if ( '' === $country ) {
		return '';
	}

	if ( function_exists( 'WC' ) ) {
		$woocommerce = WC();
		if ( $woocommerce && isset( $woocommerce->countries ) && $woocommerce->countries && method_exists( $woocommerce->countries, 'get_countries' ) ) {
			$countries = $woocommerce->countries->get_countries();
			if ( is_array( $countries ) && ! isset( $countries[ $country ] ) ) {
				return '';
			}
		}
	}

	return $country;
}

function ydzs_checkout_hidden_address_fields( ?array $settings = null ): array {
	$settings = ydzs_checkout_fields_settings( $settings );
	if ( ! ydzs_checkout_fields_enabled( $settings ) ) {
		return array();
	}

	$map = array(
		'address_2' => 'checkout_show_address_2',
		'city'      => 'checkout_show_city',
		'state'     => 'checkout_show_state',
		'postcode'  => 'checkout_show_postcode',
	);
	$hidden = array();
	foreach ( $map as $field => $setting ) {
		if ( 'yes' !== (string) $settings[ $setting ] ) {
			$hidden[] = $field;
		}
	}
	return $hidden;
}

/**
 * Resolve only explicit standard WooCommerce address contexts.
 * Custom source fields stay context-neutral so the plugin never guesses whether
 * a custom value belongs to billing or shipping customer data.
 */
function ydzs_checkout_address_context_from_field_name( string $field_name ): string {
	$field_name = sanitize_key( $field_name );
	if ( 0 === strpos( $field_name, 'shipping_' ) ) {
		return 'shipping';
	}
	if ( 0 === strpos( $field_name, 'billing_' ) ) {
		return 'billing';
	}
	return '';
}

/**
 * Build context-scoped customer setter updates for hidden address components.
 * Empty fields are deliberately cleared so stale values from an earlier address
 * cannot survive in WooCommerce customer/session data.
 */
function ydzs_checkout_customer_address_updates( string $context, ?array $settings = null ): array {
	if ( ! in_array( $context, array( 'shipping', 'billing' ), true ) || ! ydzs_checkout_fields_enabled( $settings ) ) {
		return array();
	}

	$updates = array();
	foreach ( ydzs_checkout_hidden_address_fields( $settings ) as $field ) {
		$updates[ 'set_' . $context . '_' . $field ] = '';
	}

	$fixed_country = ydzs_checkout_fixed_country( $settings );
	if ( '' !== $fixed_country ) {
		$updates[ 'set_' . $context . '_country' ] = $fixed_country;
	}

	return $updates;
}

/**
 * Apply the confirmed address and simplified-field cleanup to one explicit
 * WooCommerce customer context. With an unknown/custom context this is a no-op.
 */
function ydzs_checkout_apply_customer_address_updates( $customer, string $context, ?string $address_1 = null, ?array $settings = null ): void {
	if ( ! is_object( $customer ) || ! in_array( $context, array( 'shipping', 'billing' ), true ) ) {
		return;
	}

	if ( null !== $address_1 ) {
		$address_setter = 'set_' . $context . '_address_1';
		if ( method_exists( $customer, $address_setter ) ) {
			$customer->{$address_setter}( $address_1 );
		}
	}

	foreach ( ydzs_checkout_customer_address_updates( $context, $settings ) as $method => $value ) {
		if ( method_exists( $customer, $method ) ) {
			$customer->{$method}( $value );
		}
	}
}

function ydzs_sanitize_checkout_field_settings( array $posted, array $countries ): array {
	$clean = array(
		'checkout_simplify_address' => ! empty( $posted['checkout_simplify_address'] ) ? 'yes' : 'no',
		'checkout_show_address_2'   => ! empty( $posted['checkout_show_address_2'] ) ? 'yes' : 'no',
		'checkout_show_city'        => ! empty( $posted['checkout_show_city'] ) ? 'yes' : 'no',
		'checkout_show_state'       => ! empty( $posted['checkout_show_state'] ) ? 'yes' : 'no',
		'checkout_show_postcode'    => ! empty( $posted['checkout_show_postcode'] ) ? 'yes' : 'no',
		'checkout_fixed_country'    => '',
		'checkout_show_country'     => ! empty( $posted['checkout_show_country'] ) ? 'yes' : 'no',
	);

	$country = strtoupper( sanitize_key( (string) ( $posted['checkout_fixed_country'] ?? '' ) ) );
	if ( '' !== $country && isset( $countries[ $country ] ) ) {
		$clean['checkout_fixed_country'] = $country;
	} else {
		$clean['checkout_show_country'] = 'yes';
	}

	return $clean;
}

function ydzs_checkout_apply_country_locale( array $locale, ?array $settings = null ): array {
	$hidden = ydzs_checkout_hidden_address_fields( $settings );
	if ( ! $hidden ) {
		return $locale;
	}

	foreach ( $locale as $country => $fields ) {
		$fields = is_array( $fields ) ? $fields : array();
		foreach ( $hidden as $field ) {
			$current = isset( $fields[ $field ] ) && is_array( $fields[ $field ] ) ? $fields[ $field ] : array();
			$fields[ $field ] = array_merge( $current, array( 'required' => false, 'hidden' => true ) );
		}
		$locale[ $country ] = $fields;
	}

	$fixed = ydzs_checkout_fixed_country( $settings );
	if ( '' !== $fixed && ! isset( $locale[ $fixed ] ) ) {
		$locale[ $fixed ] = array();
		foreach ( $hidden as $field ) {
			$locale[ $fixed ][ $field ] = array( 'required' => false, 'hidden' => true );
		}
	}

	return $locale;
}

function ydzs_checkout_filter_countries( array $countries, ?array $settings = null ): array {
	$fixed = ydzs_checkout_fixed_country( $settings );
	if ( '' === $fixed || ! isset( $countries[ $fixed ] ) ) {
		return $countries;
	}
	return array( $fixed => $countries[ $fixed ] );
}

function ydzs_checkout_request_is_relevant(): bool {
	if ( ! ydzs_checkout_fields_enabled() ) {
		return false;
	}

	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		return true;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST && false !== strpos( $request_uri, '/wc/store/' ) ) {
		return true;
	}

	$doing_ajax = function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( defined( 'DOING_AJAX' ) && DOING_AJAX );
	if ( $doing_ajax ) {
		$wc_ajax = isset( $_REQUEST['wc-ajax'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['wc-ajax'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $wc_ajax, array( 'update_order_review', 'checkout' ), true ) || 0 === strpos( $action, 'wc_' ) ) {
			return true;
		}
	}

	return false;
}

function ydzs_checkout_country_locale_filter( array $locale ): array {
	return ydzs_checkout_request_is_relevant() ? ydzs_checkout_apply_country_locale( $locale ) : $locale;
}
add_filter( 'woocommerce_get_country_locale', 'ydzs_checkout_country_locale_filter', 30 );

function ydzs_checkout_default_address_fields_filter( array $fields ): array {
	if ( ! ydzs_checkout_request_is_relevant() ) {
		return $fields;
	}
	foreach ( ydzs_checkout_hidden_address_fields() as $field ) {
		if ( isset( $fields[ $field ] ) && is_array( $fields[ $field ] ) ) {
			$fields[ $field ]['required'] = false;
		}
	}
	return $fields;
}
add_filter( 'woocommerce_default_address_fields', 'ydzs_checkout_default_address_fields_filter', 30 );

function ydzs_checkout_fields_filter( array $fields ): array {
	if ( ! ydzs_checkout_request_is_relevant() ) {
		return $fields;
	}
	foreach ( ydzs_checkout_hidden_address_fields() as $field ) {
		unset( $fields['shipping'][ 'shipping_' . $field ], $fields['billing'][ 'billing_' . $field ] );
	}
	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'ydzs_checkout_fields_filter', 30 );

function ydzs_checkout_allowed_countries_filter( array $countries ): array {
	return ydzs_checkout_request_is_relevant() ? ydzs_checkout_filter_countries( $countries ) : $countries;
}
add_filter( 'woocommerce_countries_allowed_countries', 'ydzs_checkout_allowed_countries_filter', 30 );
add_filter( 'woocommerce_countries_shipping_countries', 'ydzs_checkout_allowed_countries_filter', 30 );

function ydzs_checkout_default_location_filter( array $location ): array {
	if ( ! ydzs_checkout_request_is_relevant() ) {
		return $location;
	}
	$fixed = ydzs_checkout_fixed_country();
	if ( '' === $fixed ) {
		return $location;
	}
	$location['country'] = $fixed;
	$location['state']   = '';
	return $location;
}
add_filter( 'woocommerce_customer_default_location_array', 'ydzs_checkout_default_location_filter', 30 );

function ydzs_checkout_body_class( array $classes ): array {
	if ( ! ydzs_checkout_request_is_relevant() ) {
		return $classes;
	}
	$settings  = ydzs_checkout_fields_settings();
	$classes[] = 'ydzs-simplified-checkout';
	if ( '' !== ydzs_checkout_fixed_country( $settings ) && 'yes' !== $settings['checkout_show_country'] ) {
		$classes[] = 'ydzs-fixed-country-hidden';
	}
	return $classes;
}
add_filter( 'body_class', 'ydzs_checkout_body_class', 30 );

function ydzs_checkout_country_styles(): void {
	if ( ! ydzs_checkout_request_is_relevant() ) {
		return;
	}
	$settings = ydzs_checkout_fields_settings();
	if ( '' === ydzs_checkout_fixed_country( $settings ) || 'yes' === $settings['checkout_show_country'] ) {
		return;
	}
	wp_register_style( 'ydzs-checkout-fields', false, array(), defined( 'YDZS_VERSION' ) ? YDZS_VERSION : null );
	wp_enqueue_style( 'ydzs-checkout-fields' );
	wp_add_inline_style(
		'ydzs-checkout-fields',
		'.ydzs-fixed-country-hidden .wc-block-components-address-form__country,' .
		'.ydzs-fixed-country-hidden .wc-block-components-country-input,' .
		'.ydzs-fixed-country-hidden #billing_country_field,' .
		'.ydzs-fixed-country-hidden #shipping_country_field{display:none;}'
	);
}
add_action( 'wp_enqueue_scripts', 'ydzs_checkout_country_styles', 40 );
