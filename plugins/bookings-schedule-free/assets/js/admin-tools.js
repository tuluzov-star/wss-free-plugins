(function ($) {
    'use strict';

    var config = window.WSS_BS_ADMIN_TOOLS || {};
    var labels = config.labels || {};

    function normalizeText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function slotIdFromLink(link) {
        if (!link || !link.href) {
            return 0;
        }

        var actionMatch = String(link.href).match(/[?&]wss_booking_action=([^&#]+)/);
        if (!actionMatch || actionMatch[1] !== 'delete_slot') {
            return 0;
        }

        var slotMatch = String(link.href).match(/[?&]slot_id=(\d+)/);
        return slotMatch ? parseInt(slotMatch[1], 10) || 0 : 0;
    }

    function findDeleteLink(row) {
        var links = Array.prototype.slice.call(row.querySelectorAll('a[href]'));
        return links.find(function (link) {
            return slotIdFromLink(link) > 0;
        }) || null;
    }

    function findSlotsTable() {
        var direct = document.querySelector('.wss-bookings-slots-table');
        if (direct) {
            return direct;
        }

        var tables = Array.prototype.slice.call(document.querySelectorAll('.wrap table'));
        return tables.find(function (table) {
            return Array.prototype.slice.call(table.querySelectorAll('tbody tr')).some(function (row) {
                return !!findDeleteLink(row);
            });
        }) || null;
    }

    function initBulkDelete(table) {
        if (!table || table.dataset.wssBulkDatesReady === '1' || !config.ajaxUrl || !config.nonce) {
            return;
        }

        var headerRow = table.querySelector('thead tr:first-child');
        if (!headerRow) {
            return;
        }

        var headers = Array.prototype.slice.call(headerRow.children);
        var expectedDate = normalizeText(labels.date || 'Дата');
        var dateIndex = headers.findIndex(function (header) {
            return normalizeText(header.textContent) === expectedDate;
        });
        if (dateIndex < 0) {
            return;
        }

        var groups = new Map();
        Array.prototype.slice.call(table.querySelectorAll('tbody tr')).forEach(function (row) {
            var cells = Array.prototype.slice.call(row.children);
            var dateCell = cells[dateIndex];
            var deleteLink = findDeleteLink(row);
            var slotId = slotIdFromLink(deleteLink);
            if (!dateCell || !slotId) {
                return;
            }

            var dateLabel = String(dateCell.textContent || '').replace(/\s+/g, ' ').trim();
            if (!dateLabel) {
                return;
            }

            if (!groups.has(dateLabel)) {
                groups.set(dateLabel, []);
            }
            groups.get(dateLabel).push({ row: row, slotId: slotId });
        });

        if (!groups.size) {
            return;
        }

        table.dataset.wssBulkDatesReady = '1';
        document.body.classList.add('wss-bs-schedule-admin-enhanced');

        var selectHeader = document.createElement('th');
        selectHeader.className = 'wss-bs-bulk-check-column';
        var selectAll = document.createElement('input');
        selectAll.type = 'checkbox';
        selectAll.setAttribute('aria-label', labels.selectAll || 'Выбрать все даты');
        selectHeader.appendChild(selectAll);
        headerRow.insertBefore(selectHeader, headerRow.firstChild);

        var dateCheckboxes = [];
        groups.forEach(function (items, dateLabel) {
            items.forEach(function (item, index) {
                var cell = document.createElement(index === 0 ? 'th' : 'td');
                cell.className = 'wss-bs-bulk-check-column';
                if (index === 0) {
                    cell.setAttribute('scope', 'row');
                    var checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.value = dateLabel;
                    checkbox.setAttribute('aria-label', (labels.selectDate || 'Выбрать дату ') + dateLabel);
                    cell.appendChild(checkbox);
                    dateCheckboxes.push(checkbox);
                }
                item.row.insertBefore(cell, item.row.firstChild);
            });
        });

        var toolbar = document.createElement('div');
        toolbar.className = 'wss-bs-bulk-delete-toolbar';
        var deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'button wss-bs-delete-selected-dates';
        deleteButton.textContent = labels.deleteSelected || 'Удалить выбранные';
        deleteButton.disabled = true;
        var counter = document.createElement('span');
        counter.className = 'description';
        toolbar.appendChild(deleteButton);
        toolbar.appendChild(counter);
        table.parentNode.insertBefore(toolbar, table);

        function selectedDates() {
            return dateCheckboxes.filter(function (checkbox) {
                return checkbox.checked;
            }).map(function (checkbox) {
                return checkbox.value;
            });
        }

        function selectedSlotIds() {
            var ids = [];
            selectedDates().forEach(function (dateLabel) {
                (groups.get(dateLabel) || []).forEach(function (item) {
                    ids.push(item.slotId);
                });
            });
            return ids;
        }

        function sync() {
            var selected = selectedDates().length;
            deleteButton.disabled = selected === 0;
            counter.textContent = (labels.selectedCount || 'Выбрано дат: ') + selected;
            selectAll.checked = selected === dateCheckboxes.length;
            selectAll.indeterminate = selected > 0 && selected < dateCheckboxes.length;
        }

        dateCheckboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', sync);
        });

        selectAll.addEventListener('change', function () {
            dateCheckboxes.forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
            sync();
        });

        deleteButton.addEventListener('click', function () {
            var dates = selectedDates();
            var slotIds = selectedSlotIds();
            if (!dates.length || !slotIds.length) {
                return;
            }

            var question = (labels.confirmPrefix || 'Удалить выбранные даты (') + dates.length + (labels.confirmSuffix || ') и все слоты в них? Действие необратимо.');
            if (!window.confirm(question)) {
                return;
            }

            deleteButton.disabled = true;
            selectAll.disabled = true;
            dateCheckboxes.forEach(function (checkbox) {
                checkbox.disabled = true;
            });
            deleteButton.textContent = labels.deleting || 'Удаление…';

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'wss_bs_bulk_delete_slots',
                    nonce: config.nonce,
                    slot_ids: slotIds
                }
            }).done(function (response) {
                if (response && response.success) {
                    window.location.reload();
                    return;
                }
                window.alert(labels.deleteError || 'Не удалось удалить выбранные слоты. Обновите страницу и повторите попытку.');
            }).fail(function () {
                window.alert(labels.deleteError || 'Не удалось удалить выбранные слоты. Обновите страницу и повторите попытку.');
            }).always(function () {
                deleteButton.textContent = labels.deleteSelected || 'Удалить выбранные';
                selectAll.disabled = false;
                dateCheckboxes.forEach(function (checkbox) {
                    checkbox.disabled = false;
                });
                sync();
            });
        });

        sync();
    }

    $(function () {
        initBulkDelete(findSlotsTable());
    });
})(jQuery);
