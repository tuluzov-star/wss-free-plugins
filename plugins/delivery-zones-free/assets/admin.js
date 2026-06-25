(function ($) {
	'use strict';

	let map = null;
	let polygon = null;
	let provider = 'yandex';

	function parseCenter(value) {
		const fallback = [55.751244, 37.618423];

		if (!value || typeof value !== 'string') {
			return fallback;
		}

		const parts = value.split(',').map(function (item) {
			return parseFloat(String(item).trim());
		});

		if (parts.length < 2 || Number.isNaN(parts[0]) || Number.isNaN(parts[1])) {
			return fallback;
		}

		return [parts[0], parts[1]];
	}

	function getCoordsFromInput() {
		const raw = $('#ydzs_coords').val();

		if (!raw) {
			return [];
		}

		try {
			const coords = JSON.parse(raw);
			return Array.isArray(coords) ? coords : [];
		} catch (e) {
			return [];
		}
	}

	function setCoordsToInput(coords) {
		$('#ydzs_coords').val(JSON.stringify(coords || []));
	}

	function formatCoord(value) {
		const number = parseFloat(value);

		if (Number.isNaN(number)) {
			return '';
		}

		return String(parseFloat(number.toFixed(6)));
	}

	function setMapSaveStatus(message, isError) {
		const $status = $('#ydzs-map-save-status');

		if (!$status.length) {
			return;
		}

		$status
			.removeClass('is-error is-success')
			.addClass(isError ? 'is-error' : 'is-success')
			.text(message || '');
	}

	function getYandexMapView() {
		if (!map || provider !== 'yandex' || typeof map.getCenter !== 'function') {
			return null;
		}

		const center = map.getCenter();
		const zoom = typeof map.getZoom === 'function' ? parseInt(map.getZoom(), 10) : parseInt($('#ydzs_map_zoom').val() || 10, 10);

		if (!center || center.length < 2) {
			return null;
		}

		return {
			center: formatCoord(center[0]) + ',' + formatCoord(center[1]),
			zoom: Number.isNaN(zoom) ? 10 : zoom
		};
	}

	function syncSavedMapViewFields(center, zoom) {
		$('#ydzs_map_center').val(center);
		$('#ydzs_map_zoom').val(zoom);

		if (window.YDZS_ADMIN && window.YDZS_ADMIN.settings) {
			window.YDZS_ADMIN.settings.map_center = center;
			window.YDZS_ADMIN.settings.map_zoom = zoom;
		}
	}

	function saveCurrentMapView() {
		if (provider !== 'yandex') {
			return;
		}

		const view = getYandexMapView();

		if (!view || !view.center) {
			setMapSaveStatus('Не удалось определить текущий центр карты.', true);
			alert('Не удалось определить текущий центр карты. Проверьте, что карта загружена.');
			return;
		}

		if (!window.confirm('Сохранить текущий центр карты ' + view.center + ' и масштаб ' + view.zoom + '?')) {
			return;
		}

		setMapSaveStatus('Сохраняю центр карты...', false);

		$.post(window.YDZS_ADMIN.ajaxUrl, {
			action: 'ydzs_save_map_view',
			nonce: window.YDZS_ADMIN.nonce,
			map_center: view.center,
			map_zoom: view.zoom
		}).done(function (response) {
			const data = response && response.data ? response.data : {};
			const center = data.map_center || view.center;
			const zoom = data.map_zoom || view.zoom;

			if (!response || !response.success) {
				setMapSaveStatus(data.message || 'Не удалось сохранить центр карты.', true);
				return;
			}

			syncSavedMapViewFields(center, zoom);
			setMapSaveStatus('Сохранено: ' + center + ', масштаб ' + zoom + '.', false);
		}).fail(function (xhr) {
			let message = 'Не удалось сохранить центр карты.';

			if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				message = xhr.responseJSON.data.message;
			}

			setMapSaveStatus(message, true);
		});
	}

	function syncPolygonToInput() {
		if (provider !== 'yandex') {
			return;
		}

		if (!polygon) {
			setCoordsToInput([]);
			return;
		}

		const coords = polygon.geometry.getCoordinates();
		setCoordsToInput(coords && coords[0] ? coords[0] : []);
	}

	function removePolygon() {
		if (provider !== 'yandex') {
			return;
		}

		if (polygon && map) {
			map.geoObjects.remove(polygon);
		}

		polygon = null;
		setCoordsToInput([]);
	}

	function createYandexPolygon(coords, startEditing) {
		if (!map || !window.ymaps) {
			return;
		}

		if (polygon) {
			map.geoObjects.remove(polygon);
		}

		polygon = new ymaps.Polygon(
			[coords || []],
			{
				hintContent: 'Зона доставки'
			},
			{
				fillColor: '#2f80ed44',
				strokeColor: '#2f80ed',
				strokeWidth: 3,
				interactivityModel: 'default#transparent',
				editorDrawingCursor: 'crosshair'
			}
		);

		map.geoObjects.add(polygon);
		polygon.geometry.events.add('change', syncPolygonToInput);

		if (startEditing) {
			polygon.editor.startDrawing();
		} else {
			polygon.editor.startEditing();
		}

		syncPolygonToInput();
	}

	function ymapsReady(callback) {
		if (window.ymaps && typeof window.ymaps.ready === 'function') {
			window.ymaps.ready(callback);
		}
	}

	function initYandexMap(settings, center, zoom) {
		if (!window.ymaps || !$('#ydzs-map').length) {
			return;
		}

		ymapsReady(function () {
			map = new ymaps.Map('ydzs-map', {
				center: center,
				zoom: zoom,
				controls: ['zoomControl', 'fullscreenControl', 'searchControl']
			});

			const coords = getCoordsFromInput();

			if (coords.length >= 3) {
				createYandexPolygon(coords, false);
				map.setBounds(polygon.geometry.getBounds(), {
					checkZoomRange: true,
					zoomMargin: 40
				});
			}
		});
	}

	function initMap() {
		if (!$('#ydzs-map').length) {
			return;
		}

		const settings = window.YDZS_ADMIN && window.YDZS_ADMIN.settings ? window.YDZS_ADMIN.settings : {};
		provider = settings.map_provider === 'yandex' ? 'yandex' : String(settings.map_provider || 'yandex');

		if (provider !== 'yandex') {
			return;
		}

		const center = parseCenter(settings.map_center);
		const zoom = parseInt(settings.map_zoom || 10, 10);
		initYandexMap(settings, center, zoom);
	}

	function toggleAdvancedProviderRows() {
		const enabled = $('input[name="advanced_providers"]').is(':checked');
		$('.ydzs-advanced-provider-row').toggleClass('is-hidden', !enabled);
	}

	$(document).on('change', 'input[name="advanced_providers"]', toggleAdvancedProviderRows);

	$(document).on('click', '#ydzs-draw-zone', function (e) {
		if (provider !== 'yandex') {
			return;
		}

		e.preventDefault();

		if (!map || !window.ymaps) {
			alert('Карта не загружена. Проверьте API-ключ Яндекс.Карт и перезагрузите страницу.');
			return;
		}

		removePolygon();
		createYandexPolygon([], true);
	});

	$(document).on('click', '#ydzs-clear-zone', function (e) {
		if (provider !== 'yandex') {
			return;
		}

		e.preventDefault();
		removePolygon();
	});

	$(document).on('click', '#ydzs-save-map-view', function (e) {
		if (provider !== 'yandex') {
			return;
		}

		e.preventDefault();
		saveCurrentMapView();
	});

	$(document).on('click', '#ydzs-add-rule', function (e) {
		e.preventDefault();

		$('#ydzs-rules-body').append(
			'<tr>' +
				'<td><input type="text" name="rules_min[]" value="0"></td>' +
				'<td><input type="text" name="rules_cost[]" value="0"></td>' +
				'<td><button type="button" class="button ydzs-remove-rule">Удалить</button></td>' +
			'</tr>'
		);
	});

	$(document).on('click', '.ydzs-remove-rule', function (e) {
		e.preventDefault();

		const $rows = $('#ydzs-rules-body tr');

		if ($rows.length <= 1) {
			$(this).closest('tr').find('input').val('0');
			return;
		}

		$(this).closest('tr').remove();
	});

	$(document).on('submit', '#ydzs-zone-form', function (e) {
		if (provider !== 'yandex') {
			return true;
		}

		syncPolygonToInput();

		const coords = getCoordsFromInput();

		if (coords.length < 3) {
			e.preventDefault();
			alert('Нужно нарисовать полигон минимум из 3 точек.');
			return false;
		}

		return true;
	});

	$(function () {
		toggleAdvancedProviderRows();
		initMap();
	});
})(jQuery);
