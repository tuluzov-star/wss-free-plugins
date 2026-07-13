(function () {
	'use strict';

	function normalizeText(value) {
		return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
	}

	function findSlotsTable() {
		var direct = document.querySelector('.wss-bookings-slots-table');
		if (direct) {
			return direct;
		}

		var headings = Array.prototype.slice.call(document.querySelectorAll('.wrap h2, .wrap h3'));
		var heading = headings.find(function (item) {
			return normalizeText(item.textContent).indexOf('слоты, созданные wss') !== -1;
		});

		if (heading) {
			var node = heading.nextElementSibling;
			while (node) {
				if (node.matches && node.matches('table')) {
					return node;
				}
				var nested = node.querySelector ? node.querySelector('table') : null;
				if (nested) {
					return nested;
				}
				node = node.nextElementSibling;
			}
		}

		return Array.prototype.slice.call(document.querySelectorAll('.wrap table')).find(function (table) {
			var header = normalizeText(table.querySelector('thead') ? table.querySelector('thead').textContent : '');
			var hasDelete = Array.prototype.slice.call(table.querySelectorAll('a, button')).some(function (control) {
				return normalizeText(control.textContent) === 'удалить';
			});
			return header.indexOf('дата') !== -1 && header.indexOf('действ') !== -1 && hasDelete;
		}) || null;
	}

	function findDeleteLink(row) {
		return Array.prototype.slice.call(row.querySelectorAll('a[href]')).find(function (link) {
			return normalizeText(link.textContent) === 'удалить';
		}) || null;
	}

	function initBulkDelete(table) {
		if (!table || table.dataset.wssBulkDatesReady === '1') {
			return;
		}

		var headers = Array.prototype.slice.call(table.querySelectorAll('thead tr:first-child th'));
		var dateIndex = headers.findIndex(function (header) {
			return normalizeText(header.textContent) === 'дата';
		});
		if (dateIndex < 0) {
			return;
		}

		var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
		var groups = new Map();

		rows.forEach(function (row) {
			var cells = Array.prototype.slice.call(row.children);
			var dateCell = cells[dateIndex];
			var deleteLink = findDeleteLink(row);
			if (!dateCell || !deleteLink) {
				return;
			}

			var dateLabel = String(dateCell.textContent || '').replace(/\s+/g, ' ').trim();
			if (!dateLabel) {
				return;
			}

			if (!groups.has(dateLabel)) {
				groups.set(dateLabel, []);
			}
			groups.get(dateLabel).push({ row: row, deleteLink: deleteLink });
		});

		if (!groups.size) {
			return;
		}

		table.dataset.wssBulkDatesReady = '1';
		document.body.classList.add('wss-bs-schedule-admin-enhanced');

		var headerRow = table.querySelector('thead tr:first-child');
		var selectHeader = document.createElement('th');
		selectHeader.className = 'wss-bs-bulk-check-column';
		var selectAll = document.createElement('input');
		selectAll.type = 'checkbox';
		selectAll.setAttribute('aria-label', 'Выбрать все даты');
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
					checkbox.dataset.wssBulkDate = dateLabel;
					checkbox.setAttribute('aria-label', 'Выбрать дату ' + dateLabel);
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
		deleteButton.textContent = 'Удалить выбранные';
		deleteButton.disabled = true;
		var counter = document.createElement('span');
		counter.className = 'description';
		counter.textContent = 'Выбрано дат: 0';
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

		function sync() {
			var selected = selectedDates().length;
			deleteButton.disabled = selected === 0;
			counter.textContent = 'Выбрано дат: ' + selected;
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

		deleteButton.addEventListener('click', async function () {
			var dates = selectedDates();
			if (!dates.length) {
				return;
			}

			var requests = [];
			dates.forEach(function (dateLabel) {
				(groups.get(dateLabel) || []).forEach(function (item) {
					requests.push(item.deleteLink.href);
				});
			});
			requests = Array.from(new Set(requests));

			if (!window.confirm('Удалить выбранные даты (' + dates.length + ') и все слоты в них? Действие необратимо.')) {
				return;
			}

			deleteButton.disabled = true;
			selectAll.disabled = true;
			dateCheckboxes.forEach(function (checkbox) {
				checkbox.disabled = true;
			});
			deleteButton.textContent = 'Удаление…';

			try {
				for (var index = 0; index < requests.length; index += 1) {
					var response = await window.fetch(requests[index], {
						method: 'GET',
						credentials: 'same-origin',
						redirect: 'follow'
					});
					if (!response.ok) {
						throw new Error('HTTP ' + response.status);
					}
				}
				window.location.reload();
			} catch (error) {
				window.alert('Не удалось удалить все выбранные даты. Обновите страницу и повторите попытку.');
				deleteButton.textContent = 'Удалить выбранные';
				selectAll.disabled = false;
				dateCheckboxes.forEach(function (checkbox) {
					checkbox.disabled = false;
				});
				sync();
			}
		});

		sync();
	}

	document.addEventListener('DOMContentLoaded', function () {
		var table = findSlotsTable();
		if (table) {
			initBulkDelete(table);
		}
	});
})();
