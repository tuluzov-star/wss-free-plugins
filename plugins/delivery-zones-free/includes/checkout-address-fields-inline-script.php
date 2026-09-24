<?php
/**
 * Inline checkout component autofill runtime.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ydzs_get_checkout_address_fields_inline_script(): string {
	return <<<'YDZS_CHECKOUT_FIELDS_JS'
(function ($) {
	'use strict';

	const cfg = window.YDZS_CHECKOUT_FIELDS || {};
	const visible = cfg.visible && typeof cfg.visible === 'object' ? cfg.visible : {};
	const autoFilledValues = {};
	const userOwnedFields = {};
	let internalWrite = false;

	function resolveContext(fieldElement) {
		const name = String($(fieldElement).attr('name') || '');
		if (name.indexOf('shipping_') === 0) return 'shipping';
		if (name.indexOf('billing_') === 0) return 'billing';
		return '';
	}

	function fieldKey(context, suffix) {
		return context + '_' + suffix;
	}

	function fieldFor(context, suffix) {
		const name = context + '_' + suffix;
		return $('[name="' + name + '"], #' + context + '-' + suffix + ', #' + name).first();
	}

	function isUserOwned($field, context, suffix) {
		const key = fieldKey(context, suffix);
		return !!userOwnedFields[key] || !!($field.length && $field.data('ydzsUserOwned'));
	}

	function canAutofill($field, context, suffix) {
		if (!$field.length || $field.prop('disabled')) return false;
		if (isUserOwned($field, context, suffix)) return false;

		const key = fieldKey(context, suffix);
		const previous = Object.prototype.hasOwnProperty.call(autoFilledValues, key)
			? autoFilledValues[key]
			: $field.data('ydzsAutoFilledValue');
		if (typeof previous === 'undefined') return true;
		return String($field.val() || '') === String(previous || '');
	}

	function writeOwnedValue(context, suffix, nextValue) {
		const $field = fieldFor(context, suffix);
		if (!$field.length || !canAutofill($field, context, suffix)) return false;

		const key = fieldKey(context, suffix);
		const next = String(nextValue || '');
		const current = String($field.val() || '');
		if (current === next) {
			autoFilledValues[key] = next;
			$field.data('ydzsAutoFilledValue', next);
			return false;
		}

		internalWrite = true;
		autoFilledValues[key] = next;
		$field.val(next).data('ydzsAutoFilledValue', next).trigger('input').trigger('change');
		internalWrite = false;
		return true;
	}

	function getBlocksCartStore(sourceField) {
		if (!sourceField || !$(sourceField).closest('.wc-block-components-address-form').length) return null;
		if (!window.wp || !wp.data || typeof wp.data.select !== 'function' || typeof wp.data.dispatch !== 'function') return null;
		if (!window.wc || !wc.wcBlocksData || !wc.wcBlocksData.cartStore) return null;
		return wc.wcBlocksData.cartStore;
	}

	function setBlockAddressValue(nextAddress, context, suffix, value, force) {
		const $field = fieldFor(context, suffix);
		if (!force && isUserOwned($field, context, suffix)) return false;

		const next = String(value || '');
		if (String(nextAddress[suffix] || '') === next) return false;
		nextAddress[suffix] = next;
		return true;
	}

	function clearHiddenBlockAddress(nextAddress, context) {
		let changed = false;
		['address_2', 'city', 'state', 'postcode'].forEach(function (suffix) {
			if (visible[suffix]) return;
			changed = setBlockAddressValue(nextAddress, context, suffix, '', true) || changed;
		});
		return changed;
	}

	function syncCheckoutBlock(context, sourceField, components) {
		const cartStore = getBlocksCartStore(sourceField);
		if (!cartStore) return;

		const selector = wp.data.select(cartStore);
		const actions = wp.data.dispatch(cartStore);
		if (!selector || typeof selector.getCustomerData !== 'function' || !actions || typeof actions.updateCustomerData !== 'function') return;

		const customerData = selector.getCustomerData() || {};
		const addressKey = context === 'shipping' ? 'shippingAddress' : 'billingAddress';
		const apiKey = context === 'shipping' ? 'shipping_address' : 'billing_address';
		const currentAddress = customerData[addressKey] && typeof customerData[addressKey] === 'object' ? customerData[addressKey] : {};
		const nextAddress = Object.assign({}, currentAddress);
		const values = components && typeof components === 'object' ? components : {};
		const sourceName = String($(sourceField).attr('name') || '');
		let changed = clearHiddenBlockAddress(nextAddress, context);

		if (visible.address_1 !== false) {
			const address1 = sourceName === context + '_address_1'
				? String($(sourceField).val() || values.address_1 || '')
				: String(values.address_1 || '');
			changed = setBlockAddressValue(nextAddress, context, 'address_1', address1, sourceName === context + '_address_1') || changed;
		}
		if (visible.city) {
			changed = setBlockAddressValue(nextAddress, context, 'city', values.city || '', false) || changed;
		}
		if (visible.postcode) {
			changed = setBlockAddressValue(nextAddress, context, 'postcode', values.postcode || '', false) || changed;
		}
		if (visible.state) {
			changed = setBlockAddressValue(nextAddress, context, 'state', values.state || '', false) || changed;
		}

		const fixedCountry = String(cfg.fixedCountry || '');
		if (fixedCountry) {
			changed = setBlockAddressValue(nextAddress, context, 'country', fixedCountry, true) || changed;
		} else if (visible.country) {
			changed = setBlockAddressValue(nextAddress, context, 'country', values.country_code || '', false) || changed;
		}

		if (!changed) return;

		if (context === 'shipping' && typeof actions.setShippingAddress === 'function') {
			actions.setShippingAddress(nextAddress);
		} else if (context === 'billing' && typeof actions.setBillingAddress === 'function') {
			actions.setBillingAddress(nextAddress);
		}

		const payload = {};
		payload[apiKey] = nextAddress;
		const request = actions.updateCustomerData(payload, true);
		if (request && typeof request.catch === 'function') {
			request.catch(function () {});
		}
	}

	function applyComponents(context, sourceField, components) {
		const values = components && typeof components === 'object' ? components : {};
		const sourceName = String($(sourceField).attr('name') || '');

		if (visible.address_1 !== false && sourceName !== context + '_address_1') {
			writeOwnedValue(context, 'address_1', values.address_1 || '');
		}
		if (visible.city) {
			writeOwnedValue(context, 'city', values.city || '');
		}
		if (visible.postcode) {
			writeOwnedValue(context, 'postcode', values.postcode || '');
		}
		if (visible.country) {
			writeOwnedValue(context, 'country', values.country_code || cfg.fixedCountry || '');
		}
		if (visible.state) {
			writeOwnedValue(context, 'state', values.state || '');
		}

		syncCheckoutBlock(context, sourceField, values);
	}

	$(document).on('input change', '[name^="shipping_"], [name^="billing_"]', function () {
		if (internalWrite) return;
		const $field = $(this);
		const name = String($field.attr('name') || '');
		const context = name.indexOf('shipping_') === 0 ? 'shipping' : (name.indexOf('billing_') === 0 ? 'billing' : '');
		if (!context) return;

		const suffix = name.substring(context.length + 1);
		const key = fieldKey(context, suffix);
		const previous = Object.prototype.hasOwnProperty.call(autoFilledValues, key)
			? autoFilledValues[key]
			: $field.data('ydzsAutoFilledValue');
		if (typeof previous !== 'undefined' && String($field.val() || '') !== String(previous || '')) {
			delete autoFilledValues[key];
			userOwnedFields[key] = true;
			$field.removeData('ydzsAutoFilledValue');
			$field.data('ydzsUserOwned', true);
		}
	});

	$(document.body).on('ydzs_address_confirmed', function (event, fieldElement, responseData) {
		if (!cfg.enabled || !fieldElement) return;
		const context = resolveContext(fieldElement);
		if (!context) return;
		const data = responseData && typeof responseData === 'object' ? responseData : {};
		applyComponents(context, fieldElement, data.components || {});
	});
})(jQuery);
YDZS_CHECKOUT_FIELDS_JS;
}
