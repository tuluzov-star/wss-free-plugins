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

    function showDayHelper($schedule) {
        var $helper = $schedule.find('[data-wss-bs-day-helper]');
        if (!$helper.length) {
            return;
        }

        $helper.text(String($schedule.data('wss-bs-no-events') || '')).prop('hidden', false);
    }

    function hideDayHelper($schedule) {
        $schedule.find('[data-wss-bs-day-helper]').prop('hidden', true);
    }

    function activateDay($schedule, dayKey) {
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

            activated = activateDay($schedule, dayKey);
        });

        if (!activated) {
            showDayHelper($schedule);
        }
    }

    function initMobileDayTabs() {
        if (!isMobileSchedule()) {
            return;
        }

        $('[data-wss-bs-schedule]').each(function () {
            var $schedule = $(this);
            var initialKey = getDayKeyFromHash(window.location.hash);

            if (!initialKey || !activateDay($schedule, initialKey)) {
                activateFirstVisibleDay($schedule);
            }

            $schedule.on('click', '[data-wss-bs-day-tab]', function (event) {
                var dayKey = String($(this).data('wss-bs-day-tab') || '');
                if (!dayKey) {
                    return;
                }

                event.preventDefault();
                activateDay($schedule, dayKey);
            });
        });
    }

    $(initMobileDayTabs);
})(jQuery);
