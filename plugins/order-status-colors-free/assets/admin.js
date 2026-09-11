(function ($) {
    'use strict';

    var config = window.WSS_OSC || {};
    var colors = config.colors || {};
    var buttons = config.buttons || {};

    function statusVariants(statusKey) {
        var normalized = String(statusKey || '').replace(/^wc-/, '');
        return [
            'status-' + normalized,
            'status-wc-' + normalized,
            'order-status-' + normalized,
            'order-status-wc-' + normalized
        ];
    }

    function statusFromMark(mark) {
        if (!mark || !mark.classList) {
            return '';
        }

        for (var i = 0; i < mark.classList.length; i += 1) {
            var className = mark.classList.item(i) || '';
            if (className.indexOf('status-') !== 0) {
                continue;
            }
            var candidate = 'wc-' + className.replace(/^status-/, '').replace(/^wc-/, '');
            if (colors[candidate]) {
                return candidate;
            }
        }
        return '';
    }

    function styleButtons(row) {
        if (!row || !buttons || !parseInt(buttons.enabled, 10)) {
            return;
        }
        row.classList.add('wss-osc-buttons-styled');
        row.style.setProperty('--wss-osc-button-bg', buttons.background || '#3157e7');
        row.style.setProperty('--wss-osc-button-text', buttons.text || '#ffffff');
        row.style.setProperty('--wss-osc-button-border', buttons.border || buttons.background || '#3157e7');
        row.style.setProperty('--wss-osc-button-hover-bg', buttons.hover_background || buttons.background || '#2444bd');
        row.style.setProperty('--wss-osc-button-hover-text', buttons.hover_text || buttons.text || '#ffffff');
        row.style.setProperty('--wss-osc-button-radius', parseInt(buttons.border_radius || 4, 10) + 'px');
    }

    function styleRow(row, mark, color) {
        if (!row || !color || !color.background || !color.text) {
            return;
        }

        row.classList.add('wss-osc-colored-row');
        row.style.setProperty('--wss-osc-bg', color.background);
        row.style.setProperty('--wss-osc-text', color.text);
        styleButtons(row);

        if (mark) {
            mark.style.backgroundColor = color.background;
            mark.style.color = color.text;
            mark.style.borderColor = 'rgba(0,0,0,.12)';
            var span = mark.querySelector('span');
            if (span) {
                span.style.color = color.text;
            }
        }
    }

    function applyStatusColors() {
        Array.prototype.slice.call(document.querySelectorAll('.wp-list-table .order-status, .wp-list-table mark[class*="status-"]')).forEach(function (mark) {
            var statusKey = statusFromMark(mark);
            if (!statusKey || !colors[statusKey]) {
                return;
            }
            styleRow(mark.closest('tr'), mark, colors[statusKey]);
        });

        Object.keys(colors).forEach(function (statusKey) {
            statusVariants(statusKey).forEach(function (className) {
                Array.prototype.slice.call(document.querySelectorAll('.wp-list-table tr.' + className)).forEach(function (row) {
                    styleRow(row, null, colors[statusKey]);
                });
            });
        });
    }

    var refreshTimer = null;
    function scheduleRefresh() {
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(applyStatusColors, 60);
    }

    $(applyStatusColors);
    $(document).on('ajaxComplete.wssOsc', scheduleRefresh);
})(jQuery);
