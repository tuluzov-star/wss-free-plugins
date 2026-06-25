(function ($) {
    'use strict';

    function isMobileSchedule() {
        return window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
    }

    function getDayKeyFromHash(hash) {
        hash = String(hash || '');
        var prefix = '#wss-bs-day-';
        return hash.indexOf(prefix) === 0 ? hash.replace(prefix, '') : '';
    }

    function getNoEventsText($schedule) {
        var productId = String($schedule.find('[data-wss-bs-filter]').val() || '');
        if (productId) {
            return String($schedule.data('wss-bs-no-product-events') || 'На эту дату нет выбранной экскурсии.');
        }
        return String($schedule.data('wss-bs-no-events') || 'На выбранную дату доступных экскурсий нет.');
    }

    function showDayHelper($schedule) {
        var $helper = $schedule.find('[data-wss-bs-day-helper]');
        if (!$helper.length) {
            return;
        }

        $helper.text(getNoEventsText($schedule)).prop('hidden', false);
    }

    function hideDayHelper($schedule) {
        $schedule.find('[data-wss-bs-day-helper]').prop('hidden', true);
    }

    function urlWithParam(url, param, value) {
        try {
            var parsed = new URL(url, window.location.href);
            if (value) {
                parsed.searchParams.set(param, value);
            } else {
                parsed.searchParams.delete(param);
            }
            return parsed.toString();
        } catch (e) {
            return url;
        }
    }

    function syncFilterUrls($schedule, productId, replaceCurrentUrl) {
        var param = String($schedule.data('wss-bs-filter-param') || 'wss_bs_product');

        $schedule.find('.wss-bs__nav-link[href]').each(function () {
            var $link = $(this);
            $link.attr('href', urlWithParam($link.attr('href'), param, productId));
        });

        if (replaceCurrentUrl && window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, urlWithParam(window.location.href, param, productId));
        }
    }

    function activateDay($schedule, dayKey, scrollToDay) {
        if (!dayKey) {
            return false;
        }

        var $tab = $schedule.find('[data-wss-bs-day-tab="' + dayKey + '"]');
        var $day = $schedule.find('[data-wss-bs-day="' + dayKey + '"]');

        if (!$tab.length || !$day.length || $day.hasClass('is-filter-empty') || $tab.hasClass('is-filter-empty')) {
            showDayHelper($schedule);
            return false;
        }

        hideDayHelper($schedule);

        $schedule.find('[data-wss-bs-day-tab]').removeClass('is-active').attr('aria-current', 'false');
        $schedule.find('[data-wss-bs-day]').removeClass('is-active');

        $tab.addClass('is-active').attr('aria-current', 'date');
        $day.addClass('is-active');

        if (scrollToDay && !isMobileSchedule()) {
            try {
                document.getElementById('wss-bs-day-' + dayKey).scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (e) {
                window.location.hash = 'wss-bs-day-' + dayKey;
            }
        }

        return true;
    }

    function activateFirstVisibleDay($schedule) {
        var activated = false;

        $schedule.find('[data-wss-bs-day-tab]').each(function () {
            var $tab = $(this);
            var dayKey = String($tab.data('wss-bs-day-tab') || '');

            if (activated || $tab.hasClass('is-filter-empty')) {
                return;
            }

            activated = activateDay($schedule, dayKey, false);
        });

        if (!activated) {
            showDayHelper($schedule);
        }
    }

    function initMobileDayTabs() {
        $('[data-wss-bs-schedule]').each(function () {
            var $schedule = $(this);
            var initialKey = isMobileSchedule() ? getDayKeyFromHash(window.location.hash) : '';

            if (initialKey) {
                activateDay($schedule, initialKey, false);
            } else {
                activateFirstVisibleDay($schedule);
            }

            $schedule.on('click', '[data-wss-bs-day-tab]', function (event) {
                var $tab = $(this);
                var dayKey = String($tab.data('wss-bs-day-tab') || '');

                if (!dayKey) {
                    return;
                }

                if ($tab.hasClass('is-filter-empty')) {
                    event.preventDefault();
                    showDayHelper($schedule);
                    return;
                }

                if (isMobileSchedule()) {
                    event.preventDefault();
                    activateDay($schedule, dayKey, false);
                    return;
                }

                activateDay($schedule, dayKey, true);
            });
        });
    }

    function applyScheduleFilter($schedule, persistUrl) {
        var $filter = $schedule.find('[data-wss-bs-filter]');
        var productId = String($filter.val() || '');

        syncFilterUrls($schedule, productId, !!persistUrl);
        hideDayHelper($schedule);

        $schedule.find('[data-wss-bs-product]').each(function () {
            var $card = $(this);
            var matches = !productId || String($card.data('wss-bs-product')) === productId;
            $card.prop('hidden', !matches);
        });

        $schedule.find('[data-wss-bs-day]').each(function () {
            var $day = $(this);
            var dayKey = String($day.data('wss-bs-day') || '');
            var $tab = $schedule.find('[data-wss-bs-day-tab="' + dayKey + '"]');
            var visibleCards = $day.find('[data-wss-bs-product]').filter(function () {
                return !this.hidden;
            }).length;
            var isEmpty = visibleCards === 0;

            $day.toggleClass('is-filter-empty', isEmpty);
            $tab.toggleClass('is-filter-empty', isEmpty).attr('aria-disabled', isEmpty ? 'true' : 'false');
        });

        activateFirstVisibleDay($schedule);
    }

    function initScheduleFilters() {
        $('[data-wss-bs-schedule]').each(function () {
            var $schedule = $(this);
            var $filter = $schedule.find('[data-wss-bs-filter]');

            if (!$filter.length) {
                return;
            }

            $filter.on('change', function () {
                applyScheduleFilter($schedule, true);
            });

            applyScheduleFilter($schedule, false);
        });
    }

    function getUrlParams() {
        var params = new URLSearchParams(window.location.search || '');
        return {
            date: params.get('wss_booking_date') || '',
            time: params.get('wss_booking_time') || '',
            start: params.get('wss_booking_start') || ''
        };
    }

    function setValueAndTrigger($field, value) {
        if (!$field.length || value === null || value === undefined || value === '') {
            return false;
        }

        $field.val(value).trigger('change').trigger('input');
        return true;
    }

    function prefillBookingForm() {
        var params = getUrlParams();

        if (!params.date && !params.time && !params.start) {
            return;
        }

        var parts = params.date ? params.date.split('-') : [];
        var year = parts[0] || '';
        var month = parts[1] ? String(parseInt(parts[1], 10)) : '';
        var day = parts[2] ? String(parseInt(parts[2], 10)) : '';

        setValueAndTrigger($('[name="wc_bookings_field_start_date_year"]'), year);
        setValueAndTrigger($('[name="wc_bookings_field_start_date_month"]'), month);
        setValueAndTrigger($('[name="wc_bookings_field_start_date_day"]'), day);

        var $dateField = $('[name="wc_bookings_field_start_date"], input.wc-bookings-date-picker-date-field').first();
        if ($dateField.length && params.date) {
            setValueAndTrigger($dateField, params.date);
        }

        var $datepicker = $('.wc-bookings-date-picker, .wc-bookings-date-picker-date-fields').first();
        if ($datepicker.length && params.date && $.fn.datepicker) {
            try {
                $datepicker.datepicker('setDate', new Date(parseInt(year, 10), parseInt(month, 10) - 1, parseInt(day, 10)));
            } catch (e) {
                // WooCommerce Bookings может использовать собственную обертку вокруг datepicker.
            }
        }

        window.setTimeout(function () {
            prefillBookingTime(params);
        }, 350);

        window.setTimeout(function () {
            prefillBookingTime(params);
        }, 900);
    }

    function prefillBookingTime(params) {
        if (!params.time && !params.start) {
            return;
        }

        var $time = $('[name="wc_bookings_field_start_date_time"], select.wc-bookings-booking-form-time, select[name*="start_date_time"]').first();

        if (!$time.length) {
            return;
        }

        var selected = false;

        if (params.start) {
            $time.find('option').each(function () {
                if (String($(this).val()) === String(params.start)) {
                    $(this).prop('selected', true);
                    selected = true;
                    return false;
                }
            });
        }

        if (!selected && params.time) {
            $time.find('option').each(function () {
                var value = String($(this).val() || '');
                var text = String($(this).text() || '');
                if (value.indexOf(params.time) !== -1 || text.indexOf(params.time) !== -1) {
                    $(this).prop('selected', true);
                    selected = true;
                    return false;
                }
            });
        }

        if (selected) {
            $time.trigger('change');
        }
    }

    $(function () {
        initMobileDayTabs();
        initScheduleFilters();
        prefillBookingForm();
    });
})(jQuery);
