(function () {
    'use strict';

    var MONTHS = [
        'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
        'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'
    ];

    function pad(number) {
        return number < 10 ? '0' + number : String(number);
    }

    function toDateKey(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function parseDateKey(key) {
        var parts = String(key || '').split('-').map(function (part) {
            return parseInt(part, 10);
        });

        if (parts.length !== 3 || !parts[0] || !parts[1] || !parts[2]) {
            return null;
        }

        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    function formatDate(key) {
        var date = parseDateKey(key);
        if (!date) {
            return key;
        }

        return pad(date.getDate()) + '.' + pad(date.getMonth() + 1) + '.' + date.getFullYear();
    }

    function initPicker(root) {
        var inputSelector = root.getAttribute('data-input');
        var input = inputSelector ? document.querySelector(inputSelector) : null;
        var grid = root.querySelector('[data-wss-calendar-grid]');
        var title = root.querySelector('[data-wss-calendar-title]');
        var selectedText = root.querySelector('[data-wss-selected-dates-text]');
        var selectedCount = root.querySelector('[data-wss-selected-count]');
        var error = root.querySelector('[data-wss-calendar-error]');

        if (!input || !grid || !title) {
            return;
        }

        var start = parseDateKey(root.getAttribute('data-start-month')) || new Date();
        var current = new Date(start.getFullYear(), start.getMonth(), 1);
        var selected = new Set((input.value || '').split(',').filter(Boolean));

        function sync() {
            var sorted = Array.from(selected).sort();
            input.value = sorted.join(',');

            if (selectedCount) {
                selectedCount.textContent = String(sorted.length);
            }

            if (selectedText) {
                selectedText.textContent = sorted.length ? sorted.map(formatDate).join(', ') : 'Пока ничего не выбрано';
            }

            if (error) {
                error.hidden = sorted.length > 0;
            }
        }

        function render() {
            grid.innerHTML = '';
            title.textContent = MONTHS[current.getMonth()] + ' ' + current.getFullYear();

            var firstDay = new Date(current.getFullYear(), current.getMonth(), 1);
            var daysInMonth = new Date(current.getFullYear(), current.getMonth() + 1, 0).getDate();
            var offset = (firstDay.getDay() + 6) % 7;
            var i;

            for (i = 0; i < offset; i++) {
                var empty = document.createElement('span');
                empty.className = 'wss-bookings-calendar-empty';
                grid.appendChild(empty);
            }

            for (i = 1; i <= daysInMonth; i++) {
                var date = new Date(current.getFullYear(), current.getMonth(), i);
                var key = toDateKey(date);
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'wss-bookings-calendar-day';
                button.textContent = String(i);
                button.setAttribute('data-date', key);
                button.setAttribute('aria-pressed', selected.has(key) ? 'true' : 'false');

                if (selected.has(key)) {
                    button.classList.add('is-selected');
                }

                button.addEventListener('click', function () {
                    var dateKey = this.getAttribute('data-date');
                    if (selected.has(dateKey)) {
                        selected.delete(dateKey);
                    } else {
                        selected.add(dateKey);
                    }
                    sync();
                    render();
                });

                grid.appendChild(button);
            }
        }

        var prev = root.querySelector('[data-wss-calendar-prev]');
        var next = root.querySelector('[data-wss-calendar-next]');
        var clear = root.querySelector('[data-wss-calendar-clear]');
        var selectMonth = root.querySelector('[data-wss-calendar-select-month]');
        var form = root.closest('form');

        if (prev) {
            prev.addEventListener('click', function () {
                current = new Date(current.getFullYear(), current.getMonth() - 1, 1);
                render();
            });
        }

        if (next) {
            next.addEventListener('click', function () {
                current = new Date(current.getFullYear(), current.getMonth() + 1, 1);
                render();
            });
        }

        if (clear) {
            clear.addEventListener('click', function () {
                selected.clear();
                sync();
                render();
            });
        }

        if (selectMonth) {
            selectMonth.addEventListener('click', function () {
                var daysInMonth = new Date(current.getFullYear(), current.getMonth() + 1, 0).getDate();
                for (var day = 1; day <= daysInMonth; day++) {
                    selected.add(toDateKey(new Date(current.getFullYear(), current.getMonth(), day)));
                }
                sync();
                render();
            });
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                sync();

                var hasWeekdayGeneration = false;
                if (form.classList.contains('wss-bookings-generator-form')) {
                    hasWeekdayGeneration = !!form.querySelector('input[name="generate_weekdays[]"]:checked');
                }

                if (!input.value && !hasWeekdayGeneration) {
                    event.preventDefault();
                    if (error) {
                        error.hidden = false;
                    }
                    root.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }

        sync();
        render();
    }

    function initDangerForms() {
        document.querySelectorAll('.wss-bookings-danger-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var checkbox = form.querySelector('input[name="confirm_delete_all"]');
                var text = form.querySelector('input[name="confirm_delete_text"]');

                if (!checkbox || !checkbox.checked || !text || text.value.trim() !== 'УДАЛИТЬ') {
                    event.preventDefault();
                    alert('Для удаления всех расписаний поставьте галку и введите УДАЛИТЬ.');
                    return;
                }

                if (!confirm('Первое подтверждение: удалить ВСЕ расписания WSS Bookings для всех товаров?')) {
                    event.preventDefault();
                    return;
                }

                if (!confirm('Второе подтверждение: действие необратимо. Точно удалить все слоты расписания?')) {
                    event.preventDefault();
                }
            });
        });

        document.querySelectorAll('.wss-bookings-import-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!confirm('Импорт заменит текущие WSS-слоты у импортируемых товаров расписанием из WooCommerce Bookings. Продолжить?')) {
                    event.preventDefault();
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.wss-bookings-date-picker').forEach(initPicker);
        initDangerForms();
    });
})();
