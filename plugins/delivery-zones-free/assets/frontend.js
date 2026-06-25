(function ($) {
	'use strict';

	const cfg = window.YDZS_FRONTEND || {};
	const names = Array.isArray(cfg.fieldNames) ? cfg.fieldNames : [];
	const extraSelectors = typeof cfg.selectors === 'string' ? cfg.selectors.trim() : '';
	const placeholder = typeof cfg.placeholder === 'string' ? cfg.placeholder.trim() : '';
	const hint = typeof cfg.hint === 'string' ? cfg.hint.trim() : '';
	const houseHint = typeof cfg.houseHint === 'string' ? cfg.houseHint.trim() : '';
	const suggestEnabled = !!cfg.suggestEnabled && typeof cfg.ajaxUrl === 'string' && cfg.ajaxUrl;
	const validateEnabled = !!cfg.validateEnabled && typeof cfg.ajaxUrl === 'string' && cfg.ajaxUrl;
	const ajaxUrl = typeof cfg.ajaxUrl === 'string' ? cfg.ajaxUrl : '';
	const nonce = typeof cfg.nonce === 'string' ? cfg.nonce : '';
	const checkoutContext = !!cfg.checkoutContext;
	const globalPopupMode = !!cfg.globalPopupMode;
	let recalcTimer = null;
	let validateTimer = null;
	let suggestTimer = null;
	let mutationTimer = null;
	let popupLiveTimer = null;
	let validateSequence = 0;
	let suggestRequest = null;
	let lastCheckoutKey = null;
	let lastValidateKey = null;
	let lastSuggestKey = null;
	let isRequesting = false;
	let helperIndex = 0;
	let shouldAutoSelectDelivery = false;
	let autoSelectTimer = null;
	let internalShippingChange = false;
	let suggestCache = {};
	let userClearedAddress = false;
	let userClearedUntil = 0;

	function escapeAttr(value) {
		return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
	}

	function buildSelector() {
		const selectors = [];
		const popupSelectors = [
			'#of-delivery-modal [name="of_delivery_address"]',
			'#of-delivery-modal [name="address"]',
			'#of-delivery-modal #of-address'
		];

		if (globalPopupMode) {
			popupSelectors.forEach(function (selector) {
				selectors.push(selector);
			});

			if (extraSelectors) selectors.push(extraSelectors);

			return selectors.filter(Boolean).join(',');
		}

		names.forEach(function (name) {
			if (name) selectors.push('[name="' + escapeAttr(name) + '"]');
		});

		// На checkout попап может быть открыт поверх формы. В этом режиме основной
		// селектор собирается по checkout-полям, поэтому поле адреса внутри попапа
		// раньше не получало живые подсказки при вставке/вводе. Подключаем его
		// дополнительно, но не меняем логику фоновых checkout-полей.
		if (document.getElementById('of-delivery-modal')) {
			popupSelectors.forEach(function (selector) {
				selectors.push(selector);
			});
		}

		if (extraSelectors) selectors.push(extraSelectors);

		return selectors.join(',');
	}

	function getPreferredAddressValue(changedEl) {
		const selector = buildSelector();
		let value = '';

		// Если событие пришло именно от поля адреса, даже пустое значение важно.
		// Иначе при очистке поля WooCommerce мог снова подставить старый shipping_address_1.
		if (changedEl) {
			return String($(changedEl).val() || '').trim();
		}

		const preferredNames = ['of_address', 'of_delivery_address', 'delivery_address', 'order_address', 'address'];
		for (let i = 0; i < preferredNames.length; i++) {
			value = String($('[name="' + escapeAttr(preferredNames[i]) + '"]').val() || '').trim();
			if (value) return value;
		}

		if (!selector) return '';

		$(selector).each(function () {
			const current = String($(this).val() || '').trim();
			if (current) {
				value = current;
				return false;
			}
		});

		return value;
	}

	function syncStandardWooFields(address) {
		const $shipping = $('[name="shipping_address_1"]');
		const $billing = $('[name="billing_address_1"]');

		if ($shipping.length && $shipping.val() !== address) $shipping.val(address);
		if ($billing.length && $billing.val() !== address) $billing.val(address);
	}

	function syncAllAddressFields(address) {
		const selector = buildSelector();
		if (selector) {
			$(selector).each(function () {
				const $current = $(this);
				if ($current.val() !== address) {
					$current.val(address);
				}
			});
		}

		syncStandardWooFields(address);
	}

	function canSyncWooFields($field, address) {
		if (!String(address || '').trim()) return true;
		if (!$field || !$field.length) return false;

		const status = String(ensureHiddenField($field, 'ydzs_address_status').val() || '');
		const token = String(ensureHiddenField($field, 'ydzs_address_token').val() || '');
		const confirmedAddress = String(ensureHiddenField($field, 'ydzs_address_value').val() || '').trim();

		return status === 'inside' && !!token && confirmedAddress === String(address || '').trim();
	}

	function findYdzsShippingRates() {
		return $('input[type="radio"][name^="shipping_method"][value^="ydzs_shipping"]');
	}

	function selectYdzsShippingRate() {
		const $rates = findYdzsShippingRates();
		if (!$rates.length) return false;

		const $rate = $rates.first();
		if ($rate.prop('checked')) return true;

		internalShippingChange = true;
		$rate.prop('checked', true).trigger('click').trigger('change');
		internalShippingChange = false;

		return true;
	}

	function scheduleAutoSelectDelivery() {
		shouldAutoSelectDelivery = true;
		clearTimeout(autoSelectTimer);
		autoSelectTimer = setTimeout(function () {
			if (shouldAutoSelectDelivery) {
				selectYdzsShippingRate();
			}
		}, 150);
	}

	function syncConfirmedAddressAvailabilityFromRates() {
		// После изменения корзины WooCommerce может снова вернуть ydzs_shipping,
		// но скрытые поля и helper под адресом ещё содержат старое сообщение
		// про минимальную сумму. Если rate уже есть, сумма достаточна.
		if (!findYdzsShippingRates().length) return;

		const selector = buildSelector();
		if (!selector) return;

		$(selector).each(function () {
			const $field = $(this);
			if (!isHintableField($field)) return;

			const status = String(ensureHiddenField($field, 'ydzs_address_status').val() || '');
			const token = getHiddenToken($field);
			if (status !== 'inside' || !token) return;

			ensureHiddenField($field, 'ydzs_delivery_available').val('yes');
			ensureHiddenField($field, 'ydzs_delivery_min_total').val('');
			ensureHiddenField($field, 'ydzs_delivery_cart_total').val('');
			ensureHiddenField($field, 'ydzs_delivery_amount_left').val('');
			ensureHiddenField($field, 'ydzs_delivery_min_message').val('');

			restoreFieldStatus($field);
		});
	}

	function isHintableField($field) {
		if (!$field.length || !$field.is(':visible')) return false;
		if ($field.is('[type="hidden"], [type="checkbox"], [type="radio"], button')) return false;
		if ($field.prop('disabled')) return false;

		return $field.is('input, textarea');
	}

	function hasHouseNumber(value) {
		return /\d/.test(String(value || ''));
	}

	function getForm($field) {
		const $form = $field.closest('form.checkout, form.woocommerce-checkout, form');
		return $form.length ? $form.first() : $('form.checkout, form.woocommerce-checkout').first();
	}

	function ensureHiddenField($field, name) {
		let $form = getForm($field);
		let $input = $form.length ? $form.find('input[name="' + name + '"]').first() : $('input[name="' + name + '"]').first();

		if (!$input.length) {
			$input = $('<input/>', {
				type: 'hidden',
				name: name,
				value: ''
			});

			if ($form.length) {
				$form.append($input);
			} else {
				$field.after($input);
			}
		}

		return $input;
	}

	function setHiddenAddressData($field, data) {
		const values = data || {};

		ensureHiddenField($field, 'ydzs_address_value').val(values.address || '');
		ensureHiddenField($field, 'ydzs_address_lat').val(values.lat || '');
		ensureHiddenField($field, 'ydzs_address_lon').val(values.lon || '');
		ensureHiddenField($field, 'ydzs_address_token').val(values.token || '');
		ensureHiddenField($field, 'ydzs_address_zone_id').val(values.zoneId || '');
		ensureHiddenField($field, 'ydzs_address_zone_name').val(values.zoneName || '');
		ensureHiddenField($field, 'ydzs_address_status').val(values.status || '');
		ensureHiddenField($field, 'ydzs_address_user_cleared').val(values.userCleared || '');
		ensureHiddenField($field, 'ydzs_delivery_available').val(values.deliveryAvailable || '');
		ensureHiddenField($field, 'ydzs_delivery_min_total').val(values.minTotal || '');
		ensureHiddenField($field, 'ydzs_delivery_cart_total').val(values.cartTotal || '');
		ensureHiddenField($field, 'ydzs_delivery_amount_left').val(values.amountLeft || '');
		ensureHiddenField($field, 'ydzs_delivery_min_message').val(values.minMessage || '');
	}

	function isPopupField($field) {
		return !!($field && $field.length && $field.closest('#of-delivery-modal').length);
	}

	function setUserClearedAddress($field, isCleared) {
		if (isPopupField($field)) {
			ensureHiddenField($field, 'ydzs_address_user_cleared').val(isCleared ? '1' : '');
			return;
		}

		userClearedAddress = !!isCleared;
		userClearedUntil = isCleared ? Date.now() + 12000 : 0;

		if ($field && $field.length) {
			ensureHiddenField($field, 'ydzs_address_user_cleared').val(isCleared ? '1' : '');
		}
	}

	function markUserAddressEdit($field) {
		if ($field && $field.length) {
			$field.data('ydzsLastUserEditAt', Date.now());
		}
	}

	function isFreshUserAddressEdit($field, event) {
		if (event && event.originalEvent && event.originalEvent.isTrusted !== false) {
			return true;
		}

		const lastEdit = $field && $field.length ? Number($field.data('ydzsLastUserEditAt') || 0) : 0;
		return !!lastEdit && Date.now() - lastEdit < 1200;
	}

	function isPasteLikeEvent(event) {
		const original = event && event.originalEvent ? event.originalEvent : event;
		if (!original) return false;

		const eventType = String(original.type || event.type || '');
		const inputType = String(original.inputType || '');

		return eventType === 'paste' || eventType === 'drop' || inputType === 'insertFromPaste' || inputType === 'insertFromDrop';
	}

	function updatePopupAddressLiveState($field, event, options) {
		if (!$field || !$field.length) return;

		const opts = options || {};
		const original = event && event.originalEvent ? event.originalEvent : event;
		const eventType = String(event && event.type ? event.type : (original && original.type ? original.type : ''));
		const address = String($field.val() || '').trim();

		ensureAddressHints();
		markUserAddressEdit($field);
		setUserClearedAddress($field, false);
		clearTimeout(validateTimer);

		if (!address) {
			clearHiddenAddressData($field, 'empty');
			ensureHiddenField($field, 'ydzs_address_user_cleared').val('1');
			hideSuggestions($field);
			lastSuggestKey = null;
			lastValidateKey = null;
			$field.removeData('ydzsPopupLiveValue');
			updateFieldHint($field);
			return;
		}

		const confirmedStatus = getConfirmedAddressStatus($field);
		if (confirmedStatus.ok) {
			restoreFieldStatus($field);
			hideSuggestions($field);
			return;
		}

		const hiddenAddress = String(ensureHiddenField($field, 'ydzs_address_value').val() || '').trim();
		if (hiddenAddress !== address) {
			clearHiddenAddressData($field, 'pending');
			lastValidateKey = null;
		}

		if (hasHouseNumber(address)) {
			setHelperStatus($field, 'Выберите точный адрес из списка подсказок, чтобы мы не рассчитали доставку по другому адресу.', 'warning');
		} else {
			updateFieldHint($field);
		}

		const previousLiveValue = String($field.data('ydzsPopupLiveValue') || '');
		const valueChanged = previousLiveValue !== address;
		$field.data('ydzsPopupLiveValue', address);

		// Для попапа делаем только один лёгкий запрос подсказок после ввода/вставки.
		// Полную проверку зоны запускаем после выбора подсказки или при подтверждении формы.
		requestSuggestions($field, !!opts.forceSuggest || valueChanged || isPasteLikeEvent(event));
	}

	function schedulePopupAddressLiveState($field, event, options) {
		const opts = options || {};
		const delay = typeof opts.delay === 'number' ? opts.delay : (isPasteLikeEvent(event) ? 90 : 0);

		clearTimeout(popupLiveTimer);
		popupLiveTimer = setTimeout(function () {
			updatePopupAddressLiveState($field, event, opts);
		}, delay);
	}

	function requestSuggestionsAfterAddressMutation(field, options) {
		const $field = $(field);
		if (!$field.length) return;

		if (isPopupField($field)) {
			schedulePopupAddressLiveState($field, null, Object.assign({ forceSuggest: true, delay: 90 }, options || {}));
			return;
		}

		const opts = options || {};
		const delay = typeof opts.delay === 'number' ? opts.delay : 120;
		const forceSuggest = !!opts.forceSuggest;

		// Вставка/drop в разных браузерах попадает в value чуть позже события paste.
		// Достаточно одной отложенной проверки; серия таймеров давала лишние AJAX-запросы
		// и визуальное мигание строки подсказки в попапе.
		clearTimeout(mutationTimer);
		mutationTimer = setTimeout(function () {
			const address = String($field.val() || '').trim();
			if (!address) return;

			ensureAddressHints();
			markUserAddressEdit($field);
			setUserClearedAddress($field, false);

			const hiddenAddress = String(ensureHiddenField($field, 'ydzs_address_value').val() || '').trim();
			const confirmedStatus = getConfirmedAddressStatus($field);

			if (confirmedStatus.ok) {
				restoreFieldStatus($field);
				hideSuggestions($field);
				return;
			}

			if (hiddenAddress !== address) {
				clearHiddenAddressData($field, 'pending');
			}

			requestSuggestions($field, forceSuggest);

			// Не запускаем автоматическую валидацию после вставки/ввода.
			// Иначе одновременно приходят ответ подсказок и ответ проверки зоны,
			// из-за чего helper меняет смысл несколько раз подряд.
			clearTimeout(validateTimer);
		}, delay);
	}

	function suppressProgrammaticAddressRestore($field) {
		if (!$field || !$field.length) return;

		$field.val('');
		if (!isPopupField($field)) {
			syncAllAddressFields('');
		}
		setHiddenAddressData($field, {
			address: '',
			status: 'empty',
			userCleared: '1'
		});
		hideSuggestions($field);
		lastSuggestKey = null;
		lastValidateKey = null;
		updateFieldHint($field);
	}

	function clearHiddenAddressData($field, status) {
		shouldAutoSelectDelivery = false;
		setHiddenAddressData($field, {
			address: $field && $field.length ? String($field.val() || '').trim() : '',
			status: status || ''
		});
	}

	function getHiddenToken($field) {
		return String(ensureHiddenField($field, 'ydzs_address_token').val() || '');
	}

	function getConfirmedAddressStatus($field) {
		const address = String($field && $field.length ? ($field.val() || '') : '').trim();
		const status = String(ensureHiddenField($field, 'ydzs_address_status').val() || '');
		const token = getHiddenToken($field);
		const confirmedAddress = String(ensureHiddenField($field, 'ydzs_address_value').val() || '').trim();

		return {
			ok: status === 'inside' && !!token && !!address && confirmedAddress === address,
			status: status,
			token: token,
			address: confirmedAddress
		};
	}

	function setHelperStatus($field, message, status) {
		const helperId = $field.attr('data-ydzs-helper-id');
		if (!helperId) return;

		const $helper = $('#' + helperId);
		if (!$helper.length) return;

		$helper
			.removeClass('ydzs-address-helper--warning ydzs-address-helper--success ydzs-address-helper--error ydzs-address-helper--loading')
			.addClass(status ? 'ydzs-address-helper--' + status : '')
			.text(message || hint);
	}

	function updateFieldHint($field) {
		const value = String($field.val() || '').trim();
		const needsHouseNumber = value.length >= 6 && !hasHouseNumber(value) && houseHint;

		if (needsHouseNumber) {
			setHelperStatus($field, houseHint, 'warning');
			return;
		}

		setHelperStatus($field, hint, '');
	}

	function restoreFieldStatus($field) {
		const status = String(ensureHiddenField($field, 'ydzs_address_status').val() || '');
		const token = getHiddenToken($field);
		const zoneName = String(ensureHiddenField($field, 'ydzs_address_zone_name').val() || '');

		if (status === 'inside' && token) {
			const deliveryAvailable = String(ensureHiddenField($field, 'ydzs_delivery_available').val() || '');
			const minMessage = String(ensureHiddenField($field, 'ydzs_delivery_min_message').val() || '');

			if (deliveryAvailable === 'no' && minMessage) {
				setHelperStatus($field, minMessage, 'warning');
				return;
			}

			setHelperStatus($field, 'Адрес входит в зону доставки' + (zoneName ? ': ' + zoneName : '') + '.', 'success');
			return;
		}

		if (status === 'outside') {
			setHelperStatus($field, 'Адрес отсутствует в зонах доставки. Для этого адреса доступен только самовывоз.', 'error');
			return;
		}

		if (status === 'not_found') {
			setHelperStatus($field, 'Не удалось найти подходящий адрес. Уточните населённый пункт, улицу и дом или выберите адрес из списка подсказок.', 'error');
			return;
		}

		if (status === 'ambiguous' || status === 'choose_suggestion') {
			setHelperStatus($field, 'Выберите точный адрес из списка подсказок, чтобы мы не рассчитали доставку по другому адресу.', 'warning');
			return;
		}

		if (status === 'need_house') {
			setHelperStatus($field, houseHint || hint, 'warning');
			return;
		}

		updateFieldHint($field);
	}

	function getCheckoutKey($field) {
		const address = getPreferredAddressValue($field && $field.length ? $field.get(0) : null);
		const token = $field && $field.length ? getHiddenToken($field) : '';
		const status = $field && $field.length ? String(ensureHiddenField($field, 'ydzs_address_status').val() || '') : '';
		const cleared = $field && $field.length ? String(ensureHiddenField($field, 'ydzs_address_user_cleared').val() || '') : '';
		return address + '|' + token + '|' + status + '|' + cleared;
	}

	function triggerCheckoutUpdate($field, force) {
		if (!checkoutContext || !$('form.checkout, form.woocommerce-checkout').length) return;
		if ($field && $field.length && $field.closest('#of-delivery-modal').length) return;

		const address = getPreferredAddressValue($field && $field.length ? $field.get(0) : null);
		const key = getCheckoutKey($field || $());

		if (!force && (key === lastCheckoutKey || isRequesting)) return;

		lastCheckoutKey = key;
		if (canSyncWooFields($field || $(), address)) {
			syncStandardWooFields(address);
		}
		isRequesting = true;
		$(document.body).trigger('update_checkout');
	}

	function scheduleCheckoutUpdate($field) {
		clearTimeout(recalcTimer);
		recalcTimer = setTimeout(function () {
			triggerCheckoutUpdate($field, false);
		}, 850);
	}

	function ensureSuggestionsBox($field) {
		let boxId = $field.attr('data-ydzs-suggestions-id');
		let $box = boxId ? $('#' + boxId) : $();

		if ($box.length) return $box;

		helperIndex += 1;
		boxId = 'ydzs-address-suggestions-' + helperIndex;
		$box = $('<div/>', {
			class: 'ydzs-address-suggestions',
			id: boxId,
			role: 'listbox',
			'aria-label': 'Подсказки адреса'
		});

		$field.attr('data-ydzs-suggestions-id', boxId);
		$field.after($box);

		return $box;
	}

	function hideSuggestions($field) {
		const boxId = $field.attr('data-ydzs-suggestions-id');
		if (!boxId) return;
		$('#' + boxId).removeClass('is-active').empty();
	}

	function showSuggestions($field, candidates, message) {
		const $box = ensureSuggestionsBox($field);
		const list = Array.isArray(candidates) ? candidates : [];

		$box.empty();

		if (!list.length) {
			if (message) {
				$box.append($('<div/>', {
					class: 'ydzs-address-suggestions__empty',
					text: message
				}));
				$box.addClass('is-active');
			} else {
				$box.removeClass('is-active');
			}
			return;
		}

		list.forEach(function (candidate) {
			const address = String(candidate.address || '').trim();
			if (!address) return;

			const $button = $('<button/>', {
				type: 'button',
				class: 'ydzs-address-suggestions__item',
				role: 'option'
			});
			$button.append($('<span/>', {
				class: 'ydzs-address-suggestions__address',
				text: address
			}));

			if (candidate.zoneName) {
				$button.append($('<span/>', {
					class: 'ydzs-address-suggestions__zone',
					text: 'Зона доставки: ' + candidate.zoneName
				}));
			}

			$button.data('ydzsCandidate', candidate);
			$box.append($button);
		});

		$box.toggleClass('is-active', !!$box.children().length);
	}

	function requestSuggestions($field, force) {
		if (!suggestEnabled || !nonce) return;

		const address = String($field.val() || '').trim();
		clearTimeout(suggestTimer);

		if (address.length < 3) {
			hideSuggestions($field);
			return;
		}

		// Если адрес уже подтвержден и поле не меняли, не открываем подсказки заново
		// при фокусе/инициализации checkout. Иначе после попапа на checkout появляется
		// список вариантов поверх уже корректного адреса.
		if (getConfirmedAddressStatus($field).ok) {
			hideSuggestions($field);
			return;
		}

		suggestTimer = setTimeout(function () {
			const currentAddress = String($field.val() || '').trim();
			if (currentAddress.length < 3) {
				hideSuggestions($field);
				return;
			}

			const key = currentAddress;
			if (!force && key === lastSuggestKey) {
				if (suggestCache[key]) {
					showSuggestions($field, suggestCache[key].candidates || [], suggestCache[key].message || '');
				}
				return;
			}
			lastSuggestKey = key;

			if (suggestRequest && typeof suggestRequest.abort === 'function') {
				suggestRequest.abort();
			}

			suggestRequest = $.post(ajaxUrl, {
				action: 'ydzs_address_suggest',
				nonce: nonce,
				address: currentAddress
			}).done(function (response) {
				if (String($field.val() || '').trim() !== currentAddress) return;

				const data = response && response.data ? response.data : {};
				const candidates = data.candidates || [];
				const message = data.message || '';
				const responseStatus = String(data.status || '');

				suggestCache[key] = {
					candidates: candidates,
					message: message
				};

				if (getConfirmedAddressStatus($field).ok) {
					hideSuggestions($field);
					return;
				}

				// Во время ручного ввода не запускаем вторую «полную» проверку адреса.
				// Подсказки отвечают только за выбор точного варианта, а не за финальный статус зоны.
				// Это убирает скачок сообщений «не найден» -> «вне зоны».
				if (candidates.length) {
					if (hasHouseNumber(currentAddress)) {
						setHelperStatus($field, 'Выберите точный адрес из списка подсказок, чтобы мы не рассчитали доставку по другому адресу.', 'warning');
					}
					showSuggestions($field, candidates, '');
				} else {
					hideSuggestions($field);
					if (message) {
						setHelperStatus($field, message, responseStatus === 'outside' ? 'error' : 'warning');
					} else if (hasHouseNumber(currentAddress)) {
						setHelperStatus($field, 'Не нашли точный адрес в зонах доставки. Уточните населённый пункт, улицу и дом или выберите адрес из списка подсказок.', 'warning');
					}
				}
			}).fail(function (xhr, status) {
				if (status !== 'abort') hideSuggestions($field);
			});
		}, 300);
	}

	function applyValidationResponse($field, response, selected) {
		const data = response && response.data ? response.data : {};
		const status = String(data.status || '');
		const message = String(data.message || hint);

		if (status === 'inside' && data.token && data.lat && data.lon) {
			if (selected && data.address && String($field.val() || '').trim() !== String(data.address).trim()) {
				$field.val(data.address);
			}

			const deliveryAvailable = data.deliveryAvailable !== false && String(data.deliveryAvailable || '') !== 'false';
			const minMessage = String(data.minMessage || (!deliveryAvailable ? message : '') || '');

			setHiddenAddressData($field, {
				address: String(data.address || $field.val() || '').trim(),
				lat: data.lat,
				lon: data.lon,
				token: data.token,
				zoneId: data.zoneId || '',
				zoneName: data.zoneName || '',
				status: 'inside',
				deliveryAvailable: deliveryAvailable ? 'yes' : 'no',
				minTotal: data.minTotal || '',
				cartTotal: data.cartTotal || '',
				amountLeft: data.amountLeft || '',
				minMessage: minMessage
			});

			hideSuggestions($field);
			setHelperStatus($field, message, deliveryAvailable ? 'success' : 'warning');
			if (deliveryAvailable) {
				scheduleAutoSelectDelivery();
			} else {
				shouldAutoSelectDelivery = false;
			}
			triggerCheckoutUpdate($field, true);
			return;
		}

		clearHiddenAddressData($field, status || 'invalid');

		if (status === 'ambiguous' || status === 'choose_suggestion') {
			setHiddenAddressData($field, {
				address: String($field.val() || '').trim(),
				status: status
			});
			if ($field.data('ydzsSuppressSuggestionsOnce')) {
				$field.removeData('ydzsSuppressSuggestionsOnce');
				hideSuggestions($field);
			} else {
				showSuggestions($field, data.candidates || [], data.candidates && data.candidates.length ? '' : 'Уточните адрес и выберите вариант из списка.');
			}
			setHelperStatus($field, message, 'warning');
			triggerCheckoutUpdate($field, true);
			return;
		}

		if (status === 'outside' || status === 'not_found') {
			hideSuggestions($field);
			setHelperStatus($field, message, 'error');
			triggerCheckoutUpdate($field, true);
			return;
		}

		if (status === 'need_house') {
			setHelperStatus($field, message, 'warning');
			scheduleCheckoutUpdate($field);
			return;
		}

		setHelperStatus($field, message, selected ? 'warning' : '');
		scheduleCheckoutUpdate($field);
	}

	function validateAddress($field, selected) {
		const requestId = ++validateSequence;
		const address = String($field.val() || '').trim();
		clearTimeout(validateTimer);

		if (!address) {
			setUserClearedAddress($field, true);
			clearHiddenAddressData($field, 'empty');
			ensureHiddenField($field, 'ydzs_address_user_cleared').val('1');
			hideSuggestions($field);
			if (!isPopupField($field)) {
				syncAllAddressFields('');
			}
			lastSuggestKey = null;
			updateFieldHint($field);
			triggerCheckoutUpdate($field, true);
			return;
		}

		if (!userClearedAddress || Date.now() > userClearedUntil) {
			setUserClearedAddress($field, false);
		}

		if (!hasHouseNumber(address)) {
			clearHiddenAddressData($field, 'need_house');
			setHelperStatus($field, houseHint || hint, 'warning');
			scheduleCheckoutUpdate($field);
			return;
		}

		if (!validateEnabled || !nonce) {
			clearHiddenAddressData($field, 'pending');
			scheduleCheckoutUpdate($field);
			return;
		}

		const validateKey = address + '|' + (selected ? 'selected' : 'manual');
		if (validateKey === lastValidateKey) {
			if (getConfirmedAddressStatus($field).ok) {
				restoreFieldStatus($field);
				hideSuggestions($field);
				return;
			}

			scheduleCheckoutUpdate($field);
			return;
		}

		lastValidateKey = validateKey;
		setHelperStatus($field, 'Проверяем адрес...', 'loading');

		$.post(ajaxUrl, {
			action: 'ydzs_validate_address',
			nonce: nonce,
			address: address,
			selected: selected ? '1' : '0'
		}).done(function (response) {
			if (requestId !== validateSequence) return;
			applyValidationResponse($field, response, selected);
		}).fail(function () {
			if (requestId !== validateSequence) return;
			clearHiddenAddressData($field, 'error');
			setHelperStatus($field, 'Не удалось проверить адрес сейчас. Уточните адрес или попробуйте оформить заказ ещё раз.', 'warning');
			scheduleCheckoutUpdate($field);
		});
	}

	function requestRecalculate(e) {
		const $field = e && e.currentTarget ? $(e.currentTarget) : $();
		if (!$field.length) return;

		const address = String($field.val() || '').trim();
		const isUserEdit = isFreshUserAddressEdit($field, e || null);
		const forceSuggest = isPasteLikeEvent(e || null);

		if (isPopupField($field)) {
			schedulePopupAddressLiveState($field, e || null, { forceSuggest: forceSuggest });
			return;
		}

		// После явной очистки адреса некоторые checkout-шаблоны или WooCommerce могут
		// программно вернуть старый shipping_address_1 обратно в поле. Такой возврат
		// не считаем новым вводом пользователя и сразу очищаем снова.
		if (address && userClearedAddress && Date.now() <= userClearedUntil && !isUserEdit) {
			suppressProgrammaticAddressRestore($field);
			triggerCheckoutUpdate($field, true);
			return;
		}

		if (!address) {
			setUserClearedAddress($field, true);
			if (!isPopupField($field)) {
				syncAllAddressFields('');
			}
			clearHiddenAddressData($field, 'empty');
			ensureHiddenField($field, 'ydzs_address_user_cleared').val('1');
			hideSuggestions($field);
			updateFieldHint($field);
			lastSuggestKey = null;
			clearTimeout(validateTimer);
			triggerCheckoutUpdate($field, true);
			return;
		}

		if (isUserEdit || !userClearedAddress || Date.now() > userClearedUntil) {
			setUserClearedAddress($field, false);
		}
		const hiddenAddress = String(ensureHiddenField($field, 'ydzs_address_value').val() || '').trim();
		const confirmedStatus = getConfirmedAddressStatus($field);

		// Если пользователь уже выбрал точный адрес из подсказки и поле не изменилось,
		// не сбрасываем подпись обратно на базовую инструкцию при клике/blur/change.
		// Иначе на checkout под корректным адресом снова появлялось сообщение
		// "Начните вводить адрес...", хотя адрес уже подтвержден.
		if (confirmedStatus.ok) {
			restoreFieldStatus($field);
			hideSuggestions($field);
			clearTimeout(validateTimer);
			return;
		}

		if (!hiddenAddress || hiddenAddress !== address) {
			clearHiddenAddressData($field, 'pending');
			lastSuggestKey = null;
		}

		if (hasHouseNumber(address)) {
			setHelperStatus($field, 'Выберите точный адрес из списка подсказок, чтобы мы не рассчитали доставку по другому адресу.', 'warning');
		} else {
			updateFieldHint($field);
		}
		requestSuggestions($field, forceSuggest);
		clearTimeout(validateTimer);
	}

	function enforceRecentlyClearedAddress() {
		if (!userClearedAddress || Date.now() > userClearedUntil) return;

		const selector = buildSelector();
		if (!selector) return;

		$(selector).each(function () {
			const $field = $(this);
			if ($field.val()) {
				$field.val('');
			}
			setHiddenAddressData($field, {
				address: '',
				status: 'empty',
				userCleared: '1'
			});
		});

		syncStandardWooFields('');
		lastSuggestKey = null;
		lastValidateKey = null;
	}

	function ensureAddressHints() {
		const selector = buildSelector();
		if (!selector || !hint) return;

		$(selector).each(function () {
			const $field = $(this);
			if (!isHintableField($field)) return;

			if (placeholder && !$field.attr('placeholder')) {
				$field.attr('placeholder', placeholder);
			}

			if ($field.attr('data-ydzs-address-hint') === '1') {
				const existingHelperId = $field.attr('data-ydzs-helper-id');
				if (existingHelperId && $('#' + existingHelperId).length) {
					restoreFieldStatus($field);
					return;
				}

				$field.removeAttr('data-ydzs-address-hint data-ydzs-helper-id data-ydzs-suggestions-id');
			}

			helperIndex += 1;
			const helperId = 'ydzs-address-helper-' + helperIndex;
			const describedBy = String($field.attr('aria-describedby') || '').trim();
			const nextDescribedBy = describedBy ? describedBy + ' ' + helperId : helperId;
			const $wrapper = $field.closest('.woocommerce-input-wrapper');
			const $helper = $('<p/>', {
				class: 'ydzs-address-helper',
				id: helperId,
				text: hint
			});

			$field
				.attr('data-ydzs-address-hint', '1')
				.attr('data-ydzs-helper-id', helperId)
				.attr('aria-describedby', nextDescribedBy);

			if ($wrapper.length) {
				$wrapper.append($helper);
			} else {
				$field.after($helper);
			}

			const initialAddress = String($field.val() || '').trim();
			const confirmedStatus = getConfirmedAddressStatus($field);

			if (confirmedStatus.ok) {
				restoreFieldStatus($field);
				hideSuggestions($field);
				return;
			}

			setHiddenAddressData($field, {
				address: initialAddress,
				status: 'pending'
			});
			updateFieldHint($field);

			if (initialAddress.length >= 3 && hasHouseNumber(initialAddress) && $field.attr('data-ydzs-initial-validated') !== '1') {
				$field.attr('data-ydzs-initial-validated', '1');
				$field.data('ydzsSuppressSuggestionsOnce', true);
				setTimeout(function () {
					if (String($field.val() || '').trim() === initialAddress) {
						validateAddress($field, false);
					}
				}, 250);
			}
		});
	}

	$(document).on('mousedown', '.ydzs-address-suggestions__item', function (e) {
		e.preventDefault();
		const $button = $(this);
		const $box = $button.closest('.ydzs-address-suggestions');
		const $field = $('[data-ydzs-suggestions-id="' + escapeAttr($box.attr('id')) + '"]').first();
		const candidate = $button.data('ydzsCandidate') || {};
		const address = String(candidate.address || '').trim();

		if (!$field.length || !address) return;

		if (suggestRequest && typeof suggestRequest.abort === 'function') {
			suggestRequest.abort();
		}
		setUserClearedAddress($field, false);
		clearTimeout(suggestTimer);
		clearTimeout(recalcTimer);
		clearTimeout(validateTimer);

		$field.val(address);
		hideSuggestions($field);
		setHiddenAddressData($field, {
			address: address,
			status: 'selected'
		});

		lastValidateKey = null;
		lastSuggestKey = address;
		setHelperStatus($field, 'Проверяем выбранный адрес...', 'loading');
		validateAddress($field, true);
	});

	$(document).on('mousedown', function (e) {
		if ($(e.target).closest('.ydzs-address-suggestions, ' + buildSelector()).length) return;
		$('.ydzs-address-suggestions').removeClass('is-active').empty();
	});

	$(document).on('change', 'input[type="radio"][name^="shipping_method"]', function () {
		if (internalShippingChange) return;
		if (!String($(this).val() || '').startsWith('ydzs_shipping')) {
			shouldAutoSelectDelivery = false;
		}
	});

	const selector = buildSelector();
	if (selector) {
		$(ensureAddressHints);
		$(document).on('keydown', selector, function (e) {
			if (e.originalEvent && e.originalEvent.isTrusted !== false) {
				markUserAddressEdit($(e.currentTarget));
			}
		});
		$(document).on('input change blur', selector, function (e) {
			const $field = $(e.currentTarget);
			if (e.originalEvent && e.originalEvent.isTrusted !== false && String($field.val() || '').trim()) {
				markUserAddressEdit($field);
			}
			requestRecalculate(e);
		});
		$(document).on('focus click', selector, function (e) {
			const $field = $(e.currentTarget);
			if (!isHintableField($field)) return;
			if (getConfirmedAddressStatus($field).ok) {
				restoreFieldStatus($field);
				hideSuggestions($field);
				return;
			}
			if (isPopupField($field)) {
				schedulePopupAddressLiveState($field, e, { forceSuggest: true });
				return;
			}
			requestSuggestions($field, true);
		});
		$(document).on('paste drop', selector, function (e) {
			const field = e.currentTarget;
			const $field = $(field);
			markUserAddressEdit($field);
			if (isPopupField($field)) {
				schedulePopupAddressLiveState($field, e, { forceSuggest: true, delay: 90 });
				return;
			}
			requestSuggestionsAfterAddressMutation(field);
		});
	}

	$(document.body).on('updated_checkout checkout_error', function () {
		isRequesting = false;
		enforceRecentlyClearedAddress();
		ensureAddressHints();
		syncConfirmedAddressAvailabilityFromRates();
		enforceRecentlyClearedAddress();
		setTimeout(enforceRecentlyClearedAddress, 80);
		setTimeout(enforceRecentlyClearedAddress, 250);
		if (shouldAutoSelectDelivery) {
			if (selectYdzsShippingRate()) {
				shouldAutoSelectDelivery = false;
			}
		}
	});

	function getAddressStatusForElement(element) {
		const $field = $(element);
		if (!$field.length) {
			return { status: '', ok: false, message: '' };
		}

		const status = String(ensureHiddenField($field, 'ydzs_address_status').val() || '');
		const token = getHiddenToken($field);
		const address = String($field.val() || '').trim();
		const confirmedAddress = String(ensureHiddenField($field, 'ydzs_address_value').val() || '').trim();
		const zoneName = String(ensureHiddenField($field, 'ydzs_address_zone_name').val() || '');
		const deliveryAvailable = String(ensureHiddenField($field, 'ydzs_delivery_available').val() || '');
		const minMessage = String(ensureHiddenField($field, 'ydzs_delivery_min_message').val() || '');
		const isConfirmed = status === 'inside' && !!token && confirmedAddress === address;

		return {
			status: status,
			ok: isConfirmed,
			deliveryAvailable: deliveryAvailable,
			address: confirmedAddress,
			zoneName: zoneName,
			message: isConfirmed && deliveryAvailable === 'no' && minMessage ? minMessage : (status === 'inside' ? ('Адрес входит в зону доставки' + (zoneName ? ': ' + zoneName : '') + '.') : '')
		};
	}

	function validateElement(element, selected) {
		const $field = $(element);

		return new Promise(function (resolve) {
			if (!$field.length) {
				resolve({ ok: false, status: '', message: 'Поле адреса не найдено.' });
				return;
			}

			ensureAddressHints();

			const address = String($field.val() || '').trim();
			if (!address) {
				clearHiddenAddressData($field, 'empty');
				setHelperStatus($field, hint, '');
				resolve({ ok: false, status: 'empty', message: hint });
				return;
			}

			if (!hasHouseNumber(address)) {
				clearHiddenAddressData($field, 'need_house');
				setHelperStatus($field, houseHint || hint, 'warning');
				resolve({ ok: false, status: 'need_house', message: houseHint || hint });
				return;
			}

			const current = getAddressStatusForElement($field.get(0));
			if (current.ok) {
				resolve(current);
				return;
			}

			if (!validateEnabled || !nonce || !ajaxUrl) {
				clearHiddenAddressData($field, 'pending');
				setHelperStatus($field, 'Адрес будет проверен при оформлении заказа.', 'warning');
				resolve({ ok: true, status: 'pending', message: 'Адрес будет проверен при оформлении заказа.' });
				return;
			}

			setHelperStatus($field, 'Проверяем адрес...', 'loading');

			$.post(ajaxUrl, {
				action: 'ydzs_validate_address',
				nonce: nonce,
				address: address,
				selected: selected ? '1' : '0'
			}).done(function (response) {
				applyValidationResponse($field, response, !!selected);

				const data = response && response.data ? response.data : {};
				const status = String(data.status || '');
				resolve({
					ok: status === 'inside',
					status: status,
					message: String(data.message || hint),
					data: data
				});
			}).fail(function () {
				clearHiddenAddressData($field, 'error');
				const message = 'Не удалось проверить адрес сейчас. Попробуйте ещё раз.';
				setHelperStatus($field, message, 'warning');
				resolve({ ok: false, status: 'error', message: message });
			});
		});
	}

	function handlePublicAddressMutation(element, options) {
		const $field = $(element);
		if (!$field.length) return;

		ensureAddressHints();
		requestSuggestionsAfterAddressMutation($field.get(0), options || { validateDelay: 750 });
	}

	function refreshPublicFields() {
		ensureAddressHints();
	}

	document.addEventListener('of:delivery-popup-open', function () {
		ensureAddressHints();

		const selector = buildSelector();
		if (!selector) return;

		$(selector).each(function () {
			const $field = $(this);
			if (!$field.closest('#of-delivery-modal').length) return;
			const value = String($field.val() || '').trim();
			if (value.length >= 3) {
				if (getConfirmedAddressStatus($field).ok) {
					restoreFieldStatus($field);
					hideSuggestions($field);
				} else {
					schedulePopupAddressLiveState($field, null, { forceSuggest: false, delay: 120 });
				}
			}
		});
	});

	window.YDZSFrontend = window.YDZSFrontend || {};
	window.YDZSFrontend.refresh = refreshPublicFields;
	window.YDZSFrontend.handleAddressMutation = handlePublicAddressMutation;
	window.YDZSFrontend.validateElement = validateElement;
	window.YDZSFrontend.getAddressStatusForElement = getAddressStatusForElement;

})(jQuery);
