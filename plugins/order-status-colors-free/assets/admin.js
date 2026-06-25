(function () {
    'use strict';

    var config = window.WSS_OSC || {};
    var colors = config.colors || {};
    var buttons = config.buttons || {};

    function statusKeyVariants(statusKey) {
        var normalized = String(statusKey || '').replace(/^wc-/, '');
        return [
            'status-' + normalized,
            'status-wc-' + normalized,
            'order-status-' + normalized,
            'order-status-wc-' + normalized
        ];
    }

    function findStatusKeyFromElement(el) {
        if (!el || !el.classList) {
            return '';
        }

        var classes = Array.prototype.slice.call(el.classList);
        for (var i = 0; i < classes.length; i++) {
            var className = classes[i];
            if (className.indexOf('status-') !== 0) {
                continue;
            }

            var slug = className.replace(/^status-/, '').replace(/^wc-/, '');
            var candidate = 'wc-' + slug;
            if (colors[candidate]) {
                return candidate;
            }
        }

        return '';
    }

    function applyButtonStyles(row) {
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

    function applyColorToRow(row, mark, color) {
        if (!row || !color || !color.background || !color.text) {
            return;
        }

        row.classList.add('wss-osc-colored-row');
        row.style.setProperty('--wss-osc-bg', color.background);
        row.style.setProperty('--wss-osc-text', color.text);
        applyButtonStyles(row);

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
        var marks = document.querySelectorAll('.wp-list-table .order-status, .wp-list-table mark[class*="status-"]');

        marks.forEach(function (mark) {
            var statusKey = findStatusKeyFromElement(mark);
            if (!statusKey || !colors[statusKey]) {
                return;
            }

            var row = mark.closest('tr');
            applyColorToRow(row, mark, colors[statusKey]);
        });

        Object.keys(colors).forEach(function (statusKey) {
            statusKeyVariants(statusKey).forEach(function (className) {
                var rows = document.querySelectorAll('.wp-list-table tr.' + className);
                rows.forEach(function (row) {
                    applyColorToRow(row, null, colors[statusKey]);
                });
            });
        });
    }

    function debounce(fn, delay) {
        var timer = null;
        return function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(fn, delay);
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyStatusColors);
    } else {
        applyStatusColors();
    }

    var observer = new MutationObserver(debounce(applyStatusColors, 80));
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
})();
