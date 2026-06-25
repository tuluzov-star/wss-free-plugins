(function () {
    'use strict';

    var MONTHS = [
        'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
        'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'
    ];

    function pad(number) {
        return number < 10 ? '0' + number : String(number);
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

    function toMonthKey(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1);
    }

    function toDateKey(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function groupSlots(slots) {
        var grouped = {};

        slots.forEach(function (slot) {
            if (!grouped[slot.date]) {
                grouped[slot.date] = [];
            }
            grouped[slot.date].push(slot);
        });

        Object.keys(grouped).forEach(function (date) {
            grouped[date].sort(function (a, b) {
                return String(a.start).localeCompare(String(b.start));
            });
        });

        return grouped;
    }

    function getUrlParam(name) {
        if (!window.URLSearchParams) {
            var match = window.location.search.match(new RegExp('[?&]' + name + '=([^&]*)'));
            return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
        }

        return new URLSearchParams(window.location.search).get(name) || '';
    }

    function normalizeQueryTime(value) {
        value = String(value || '').trim();
        if (/^\d{1,2}:\d{2}$/.test(value)) {
            var parts = value.split(':');
            return pad(parseInt(parts[0], 10)) + ':' + parts[1];
        }
        if (/^\d{1,2}:\d{2}:\d{2}$/.test(value)) {
            var timeParts = value.split(':');
            return pad(parseInt(timeParts[0], 10)) + ':' + timeParts[1];
        }
        return '';
    }

    function getPreselectedSlot(slots) {
        var slotId = getUrlParam('wss_booking_slot_id');
        var date = getUrlParam('wss_booking_date');
        var time = normalizeQueryTime(getUrlParam('wss_booking_time'));

        if (slotId) {
            var byId = slots.find(function (slot) {
                return String(slot.id) === String(slotId);
            });
            if (byId) {
                return byId;
            }
        }

        if (date && time) {
            return slots.find(function (slot) {
                return String(slot.date) === String(date) && String(slot.start) === String(time);
            }) || null;
        }

        if (date) {
            return slots.find(function (slot) {
                return String(slot.date) === String(date);
            }) || null;
        }

        return null;
    }



    function toInt(value, fallback) {
        var number = parseInt(value, 10);
        return isNaN(number) ? fallback : number;
    }

    function getQuantityLimit(qty, attr, fallback) {
        if (!qty) {
            return fallback;
        }
        var value = qty.getAttribute(attr);
        if (value === null || value === '') {
            return fallback;
        }
        return toInt(value, fallback);
    }

    function clampQuantity(qty) {
        if (!qty) {
            return;
        }

        var min = getQuantityLimit(qty, 'min', 1);
        var max = getQuantityLimit(qty, 'max', 0);
        var value = toInt(qty.value, min);

        if (value < min) {
            value = min;
        }
        if (max > 0 && value > max) {
            value = max;
        }

        qty.value = String(value);
    }

    function updateQuantityButtons(qty) {
        if (!qty) {
            return;
        }

        var wrapper = qty.closest('.quantity');
        if (!wrapper) {
            return;
        }

        var minus = wrapper.querySelector('.wss-booking-qty-minus');
        var plus = wrapper.querySelector('.wss-booking-qty-plus');
        var min = getQuantityLimit(qty, 'min', 1);
        var max = getQuantityLimit(qty, 'max', 0);
        var value = toInt(qty.value, min);

        if (minus) {
            minus.disabled = value <= min;
        }
        if (plus) {
            plus.disabled = max > 0 && value >= max;
        }
    }

    function enhanceQuantityControls(qty) {
        if (!qty || qty.getAttribute('data-wss-qty-enhanced') === '1') {
            return;
        }

        var wrapper = qty.closest('.quantity');
        if (!wrapper || wrapper.querySelector('.wss-booking-qty-button')) {
            return;
        }

        qty.setAttribute('data-wss-qty-enhanced', '1');
        wrapper.classList.add('wss-booking-quantity');

        var minus = document.createElement('button');
        minus.type = 'button';
        minus.className = 'wss-booking-qty-button wss-booking-qty-minus';
        minus.setAttribute('aria-label', 'Уменьшить количество билетов');
        minus.textContent = '−';

        var plus = document.createElement('button');
        plus.type = 'button';
        plus.className = 'wss-booking-qty-button wss-booking-qty-plus';
        plus.setAttribute('aria-label', 'Увеличить количество билетов');
        plus.textContent = '+';

        wrapper.insertBefore(minus, qty);
        wrapper.appendChild(plus);

        function step(delta) {
            var min = getQuantityLimit(qty, 'min', 1);
            var value = toInt(qty.value, min) + delta;
            qty.value = String(value);
            clampQuantity(qty);
            qty.dispatchEvent(new Event('input', { bubbles: true }));
            qty.dispatchEvent(new Event('change', { bubbles: true }));
            updateQuantityButtons(qty);
        }

        minus.addEventListener('click', function () {
            step(-1);
        });
        plus.addEventListener('click', function () {
            step(1);
        });
        qty.addEventListener('input', function () {
            clampQuantity(qty);
            updateQuantityButtons(qty);
        });
        qty.addEventListener('change', function () {
            clampQuantity(qty);
            updateQuantityButtons(qty);
        });

        clampQuantity(qty);
        updateQuantityButtons(qty);
    }


    function initTicketControls(form, root, qty) {
        var box = root.querySelector('[data-wss-ticket-types]');
        if (!box) {
            return null;
        }

        if (form) {
            form.classList.add('wss-booking-has-ticket-types');
        }

        var rows = Array.prototype.slice.call(box.querySelectorAll('[data-wss-ticket-row]'));
        var totalInput = box.querySelector('[data-wss-ticket-total-qty]');
        var summary = box.querySelector('[data-wss-ticket-summary]');
        var maxAvailable = 0;

        function getTotal() {
            return rows.reduce(function (sum, row) {
                var input = row.querySelector('[data-wss-ticket-input]');
                return sum + toInt(input ? input.value : 0, 0);
            }, 0);
        }

        function sync() {
            var total = getTotal();
            if (totalInput) {
                totalInput.value = String(total);
            }
            if (qty) {
                qty.value = String(Math.max(1, total));
                qty.dispatchEvent(new Event('input', { bubbles: true }));
                qty.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (summary) {
                if (total > 0) {
                    summary.textContent = 'Выбрано билетов: ' + total + '.';
                } else {
                    summary.textContent = 'Выберите количество билетов.';
                }
            }
            rows.forEach(function (row) {
                var input = row.querySelector('[data-wss-ticket-input]');
                var minus = row.querySelector('[data-wss-ticket-minus]');
                var plus = row.querySelector('[data-wss-ticket-plus]');
                var value = toInt(input ? input.value : 0, 0);
                if (minus) {
                    minus.disabled = value <= 0;
                }
                if (plus) {
                    plus.disabled = maxAvailable > 0 && total >= maxAvailable;
                }
            });
        }

        function normalize(input) {
            if (!input) {
                return;
            }
            var value = Math.max(0, toInt(input.value, 0));
            if (maxAvailable > 0) {
                var otherTotal = getTotal() - toInt(input.value, 0);
                value = Math.min(value, Math.max(0, maxAvailable - otherTotal));
            }
            input.value = String(value);
        }

        rows.forEach(function (row) {
            var input = row.querySelector('[data-wss-ticket-input]');
            var minus = row.querySelector('[data-wss-ticket-minus]');
            var plus = row.querySelector('[data-wss-ticket-plus]');
            if (minus) {
                minus.addEventListener('click', function () {
                    input.value = String(Math.max(0, toInt(input.value, 0) - 1));
                    sync();
                });
            }
            if (plus) {
                plus.addEventListener('click', function () {
                    if (maxAvailable > 0 && getTotal() >= maxAvailable) {
                        return;
                    }
                    input.value = String(toInt(input.value, 0) + 1);
                    normalize(input);
                    sync();
                });
            }
            if (input) {
                input.addEventListener('input', function () {
                    normalize(input);
                    sync();
                });
                input.addEventListener('change', function () {
                    normalize(input);
                    sync();
                });
            }
        });

        sync();

        return {
            setMax: function (max) {
                maxAvailable = Math.max(0, toInt(max, 0));
                rows.forEach(function (row) {
                    normalize(row.querySelector('[data-wss-ticket-input]'));
                });
                sync();
            },
            getTotal: getTotal,
            hasTickets: function () {
                return true;
            }
        };
    }

    function initBookingCalendar(root) {
        var dataNode = root.querySelector('.wss-booking-slots-data');
        var input = root.querySelector('.wss-booking-slot-input');
        var grid = root.querySelector('[data-wss-booking-grid]');
        var title = root.querySelector('[data-wss-booking-title]');
        var timesWrap = root.querySelector('.wss-booking-times');
        var timesTitle = root.querySelector('[data-wss-booking-date-title]');
        var timesList = root.querySelector('[data-wss-booking-times]');
        var hint = root.querySelector('.wss-booking-hint');
        var form = root.closest('form.cart') || root.closest('form');
        var qty = form ? form.querySelector('input.qty') : null;

        enhanceQuantityControls(qty);
        var ticketControls = initTicketControls(form, root, qty);

        if (!dataNode || !input || !grid || !title || !timesWrap || !timesList) {
            return;
        }

        var slots = [];
        try {
            slots = JSON.parse(dataNode.textContent || '[]');
        } catch (error) {
            slots = [];
        }

        if (!slots.length) {
            return;
        }

        var grouped = groupSlots(slots);
        var availableDates = Object.keys(grouped).sort();
        var minDate = parseDateKey(availableDates[0]) || new Date();
        var maxDate = parseDateKey(availableDates[availableDates.length - 1]) || minDate;
        var preselectedSlot = getPreselectedSlot(slots);
        var preselectedDate = preselectedSlot ? parseDateKey(preselectedSlot.date) : null;
        var current = preselectedDate ? new Date(preselectedDate.getFullYear(), preselectedDate.getMonth(), 1) : new Date(minDate.getFullYear(), minDate.getMonth(), 1);
        var selectedDate = preselectedSlot ? String(preselectedSlot.date) : '';
        var selectedSlotId = preselectedSlot ? String(preselectedSlot.id) : '';

        function updateNavState() {
            var prev = root.querySelector('[data-wss-booking-prev]');
            var next = root.querySelector('[data-wss-booking-next]');
            var minMonth = toMonthKey(new Date(minDate.getFullYear(), minDate.getMonth(), 1));
            var maxMonth = toMonthKey(new Date(maxDate.getFullYear(), maxDate.getMonth(), 1));
            var currentMonth = toMonthKey(current);

            if (prev) {
                prev.disabled = currentMonth <= minMonth;
            }
            if (next) {
                next.disabled = currentMonth >= maxMonth;
            }
        }

        function renderCalendar() {
            grid.innerHTML = '';
            title.textContent = MONTHS[current.getMonth()] + ' ' + current.getFullYear();
            updateNavState();

            var firstDay = new Date(current.getFullYear(), current.getMonth(), 1);
            var daysInMonth = new Date(current.getFullYear(), current.getMonth() + 1, 0).getDate();
            var offset = (firstDay.getDay() + 6) % 7;
            var i;

            for (i = 0; i < offset; i++) {
                var empty = document.createElement('span');
                empty.className = 'wss-booking-calendar-empty';
                grid.appendChild(empty);
            }

            for (i = 1; i <= daysInMonth; i++) {
                var date = new Date(current.getFullYear(), current.getMonth(), i);
                var key = toDateKey(date);
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'wss-booking-calendar-day';
                button.textContent = String(i);
                button.setAttribute('data-date', key);

                if (grouped[key]) {
                    button.classList.add('has-slots');
                    button.setAttribute('aria-label', 'Выбрать дату ' + grouped[key][0].dateLabel);
                } else {
                    button.disabled = true;
                    button.setAttribute('aria-label', 'Нет доступных слотов');
                }

                if (selectedDate === key) {
                    button.classList.add('is-selected');
                }

                button.addEventListener('click', function () {
                    selectedDate = this.getAttribute('data-date');
                    selectedSlotId = '';
                    input.value = '';
                    renderCalendar();
                    renderTimes();
                });

                grid.appendChild(button);
            }
        }

        function updateQuantityMax(available) {
            if (!qty) {
                return;
            }

            available = parseInt(available, 10);
            if (!available || available < 1) {
                available = 1;
            }

            qty.setAttribute('max', String(available));
            clampQuantity(qty);
            updateQuantityButtons(qty);
            if (ticketControls) {
                ticketControls.setMax(available);
            }
        }

        function updateQuantityMaxForDate(dateSlots) {
            if (!qty || !dateSlots || !dateSlots.length) {
                return;
            }

            var maxAvailable = dateSlots.reduce(function (max, slot) {
                var available = parseInt(slot.available, 10);
                return available > max ? available : max;
            }, 1);

            updateQuantityMax(maxAvailable);
        }

        function setSelectedSlot(slot, button) {
            selectedSlotId = String(slot.id);
            input.value = selectedSlotId;

            timesList.querySelectorAll('.wss-booking-time-button').forEach(function (item) {
                item.classList.remove('is-selected');
            });

            if (button) {
                button.classList.add('is-selected');
            }

            updateQuantityMax(parseInt(slot.available, 10));

            if (hint) {
                hint.textContent = 'Выбрано: ' + slot.dateLabel + ', ' + slot.rangeLabel + '. Свободно: ' + slot.availableLabel + '.';
            }
        }

        function renderTimes() {
            timesList.innerHTML = '';
            input.value = '';

            if (!selectedDate || !grouped[selectedDate]) {
                timesWrap.hidden = true;
                if (hint) {
                    hint.textContent = 'Сначала выберите дату в календаре.';
                }
                return;
            }

            var dateSlots = grouped[selectedDate];
            updateQuantityMaxForDate(dateSlots);

            if (selectedSlotId && !dateSlots.some(function (slot) { return String(slot.id) === String(selectedSlotId); })) {
                selectedSlotId = '';
            }

            var allDaySlots = dateSlots.filter(function (slot) {
                return !!slot.allDay;
            });

            if (dateSlots.length === 1 && allDaySlots.length === 1) {
                var allDaySlot = dateSlots[0];
                selectedSlotId = String(allDaySlot.id);
                input.value = selectedSlotId;
                updateQuantityMax(parseInt(allDaySlot.available, 10));
                timesWrap.hidden = true;

                if (hint) {
                    hint.textContent = 'Выбрана дата: ' + allDaySlot.dateLabel + '. Время не указывается. Свободно: ' + allDaySlot.availableLabel + '.';
                }
                return;
            }

            timesWrap.hidden = false;
            if (timesTitle) {
                timesTitle.textContent = dateSlots[0].dateLabel + ': выберите время';
            }
            if (hint) {
                hint.textContent = dateSlots[0].dateLabel + ': выберите время экскурсии.';
            }

            dateSlots.forEach(function (slot) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'wss-booking-time-button';
                button.setAttribute('data-slot-id', String(slot.id));
                button.innerHTML = '<span>' + slot.timeLabel + '</span><small>Свободно: ' + slot.availableLabel + '</small>';

                button.addEventListener('click', function () {
                    setSelectedSlot(slot, button);
                });

                timesList.appendChild(button);

                if (selectedSlotId === String(slot.id)) {
                    setSelectedSlot(slot, button);
                }
            });
        }

        var prev = root.querySelector('[data-wss-booking-prev]');
        var next = root.querySelector('[data-wss-booking-next]');

        if (prev) {
            prev.addEventListener('click', function () {
                current = new Date(current.getFullYear(), current.getMonth() - 1, 1);
                renderCalendar();
            });
        }

        if (next) {
            next.addEventListener('click', function () {
                current = new Date(current.getFullYear(), current.getMonth() + 1, 1);
                renderCalendar();
            });
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                if (!input.value) {
                    event.preventDefault();
                    if (hint) {
                        hint.textContent = selectedDate ? 'Выберите время экскурсии.' : 'Выберите дату бронирования.';
                    }
                    root.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
                if (ticketControls && ticketControls.getTotal() <= 0) {
                    event.preventDefault();
                    if (hint) {
                        hint.textContent = 'Выберите количество билетов.';
                    }
                    root.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }

        if (availableDates.length === 1 && !selectedDate) {
            selectedDate = availableDates[0];
        }

        renderCalendar();
        renderTimes();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.wss-booking-selector').forEach(initBookingCalendar);
    });
})();
