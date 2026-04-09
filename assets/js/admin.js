(function () {
	'use strict';

	var cfg =
		typeof abilitiesAuditAdmin !== 'undefined' ? abilitiesAuditAdmin : {};
	var ajaxUrl = cfg.ajaxurl || '';
	var i18n = cfg.i18n || {};
	var hideLabel = i18n.hide || 'Hide';
	var viewLabel = i18n.view || 'View';
	var errorLabel = i18n.error || 'Error';
	var onLabel = i18n.on || 'On';
	var offLabel = i18n.off || 'Off';
	var summaryTpl = cfg.summaryTemplate || '';
	var schemaAnnotationsLabel = i18n.schemaAnnotations || 'Annotations';
	var schemaInputLabel = i18n.schemaInput || 'Input Schema';
	var schemaOutputLabel = i18n.schemaOutput || 'Output Schema';
	var schemaRawDataLabel = i18n.schemaRawData || 'Raw Data';

	function ariaForState(state, abilityName) {
		var tpl =
			state === 'disabled'
				? i18n.enableAria || 'Enable %s'
				: i18n.disableAria || 'Disable %s';
		if (typeof tpl === 'string' && tpl.indexOf('%s') !== -1) {
			return tpl.replace('%s', abilityName);
		}
		return tpl;
	}

	function updateSummaryFooter(newState) {
		var th = document.getElementById('abilities-audit-summary');
		if (!th || !summaryTpl) {
			return;
		}
		var total = parseInt(th.getAttribute('data-total') || '0', 10);
		var on = parseInt(th.getAttribute('data-on') || '0', 10);
		var off = parseInt(th.getAttribute('data-off') || '0', 10);
		if (newState === 'disabled') {
			on = Math.max(0, on - 1);
			off = off + 1;
		} else {
			on = on + 1;
			off = Math.max(0, off - 1);
		}
		th.setAttribute('data-on', String(on));
		th.setAttribute('data-off', String(off));
		th.textContent = summaryTpl
			.replace('%1$d', String(total))
			.replace('%2$d', String(on))
			.replace('%3$d', String(off));
	}

	function isNonEmptySchemaValue(val) {
		if (val === null || val === undefined) {
			return false;
		}
		if (Array.isArray(val)) {
			return val.length > 0;
		}
		if (typeof val === 'object') {
			return Object.keys(val).length > 0;
		}
		return true;
	}

	function appendSchemaSection(container, title, data) {
		if (!isNonEmptySchemaValue(data)) {
			return;
		}
		var section = document.createElement('div');
		section.className = 'abilities-audit-schema-section';
		var strong = document.createElement('strong');
		strong.textContent = title;
		var pre = document.createElement('pre');
		pre.style.margin = '4px 0 12px';
		pre.style.whiteSpace = 'pre-wrap';
		var jsonText;
		try {
			jsonText = JSON.stringify(data, null, 2);
		} catch (e) {
			jsonText = String(data);
		}
		pre.textContent = jsonText;
		section.appendChild(strong);
		section.appendChild(pre);
		container.appendChild(section);
	}

	function fillSchemaDetailCell(td, schema) {
		td.textContent = '';
		appendSchemaSection(td, schemaRawDataLabel, schema.raw_data);
		appendSchemaSection(td, schemaAnnotationsLabel, schema.annotations);
		appendSchemaSection(td, schemaInputLabel, schema.input_schema);
		appendSchemaSection(td, schemaOutputLabel, schema.output_schema);
	}

	function getSchemaRow(mainRow, schemaTargetId) {
		var id = 'schema-' + schemaTargetId;
		var el = document.getElementById(id);
		if (el && el.classList.contains('abilities-audit-schema-row')) {
			return el;
		}
		var next = mainRow.nextElementSibling;
		if (
			next &&
			next.classList.contains('abilities-audit-schema-row') &&
			next.id === id
		) {
			return next;
		}
		return null;
	}

	function ensureSchemaRow(mainRow, schemaTargetId) {
		var existing = getSchemaRow(mainRow, schemaTargetId);
		if (existing) {
			return existing;
		}
		var tr = document.createElement('tr');
		tr.className = 'abilities-audit-schema-row';
		tr.id = 'schema-' + schemaTargetId;
		tr.style.display = 'none';
		var td = document.createElement('td');
		td.colSpan = 6;
		td.style.padding = '12px 20px';
		td.style.background = '#f9f9f9';
		tr.appendChild(td);
		if (mainRow.parentNode) {
			mainRow.parentNode.insertBefore(tr, mainRow.nextSibling);
		}
		return tr;
	}

	function updateRowFromToggleResponse(mainRow, payload) {
		if (!mainRow || !payload) {
			return;
		}
		var descCell = mainRow.querySelector('.column-description');
		if (descCell && typeof payload.description === 'string') {
			descCell.textContent = payload.description;
		}
		var schemaCell = mainRow.querySelector('.column-schema');
		if (!schemaCell) {
			return;
		}
		var schemaTargetId = payload.schema_target_id || '';
		var hasSchema = !!payload.has_schema;
		var schema = payload.schema || {};

		if (!hasSchema) {
			schemaCell.innerHTML =
				'<span class="description">&mdash;</span>';
			var detailHidden = getSchemaRow(mainRow, schemaTargetId);
			if (detailHidden) {
				detailHidden.style.display = 'none';
			}
			return;
		}

		var targetAttr = 'schema-' + schemaTargetId;
		var viewBtn = document.createElement('button');
		viewBtn.type = 'button';
		viewBtn.className =
			'button button-small abilities-audit-schema-toggle';
		viewBtn.setAttribute('data-target', targetAttr);
		viewBtn.textContent = viewLabel;
		schemaCell.textContent = '';
		schemaCell.appendChild(viewBtn);

		var detailRow = ensureSchemaRow(mainRow, schemaTargetId);
		var td = detailRow.querySelector('td');
		if (td) {
			fillSchemaDetailCell(td, {
				annotations: schema.annotations,
				input_schema: schema.input_schema,
				output_schema: schema.output_schema,
				raw_data: schema.raw_data,
			});
		}
		detailRow.style.display = 'none';
	}

	// Schema expand / collapse (delegated so dynamically added View buttons work).
	var tbody = document.querySelector('#abilities-audit-table tbody');
	if (tbody) {
		tbody.addEventListener('click', function (e) {
			var btn = e.target.closest('.abilities-audit-schema-toggle');
			if (!btn) {
				return;
			}
			var targetId = btn.getAttribute('data-target');
			var target = targetId ? document.getElementById(targetId) : null;
			if (target) {
				var isHidden = target.style.display === 'none';
				target.style.display = isHidden ? 'table-row' : 'none';
				btn.textContent = isHidden ? hideLabel : viewLabel;
			}
		});
	}

	// AJAX toggle (in-place UI; no full page reload).
	document.querySelectorAll('.abilities-audit-toggle').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var button = this;
			var ability = button.getAttribute('data-ability');
			var nonce = button.getAttribute('data-nonce');

			button.disabled = true;
			button.textContent = '...';

			var xhr = new XMLHttpRequest();
			xhr.open('POST', ajaxUrl);
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			xhr.onload = function () {
				if (xhr.status === 200) {
					try {
						var response = JSON.parse(xhr.responseText);
						if (response.success && response.data) {
							var state = response.data.state;
							var row = button.closest('tr');
							if (state === 'disabled') {
								button.textContent = offLabel;
								button.classList.remove('button-primary');
								button.classList.add('button-secondary');
								button.setAttribute('aria-label', ariaForState('disabled', ability));
								if (row) {
									row.classList.add('abilities-audit-row--disabled');
								}
							} else {
								button.textContent = onLabel;
								button.classList.remove('button-secondary');
								button.classList.add('button-primary');
								button.setAttribute('aria-label', ariaForState('enabled', ability));
								if (row) {
									row.classList.remove('abilities-audit-row--disabled');
								}
							}
							updateSummaryFooter(state);
							if (row) {
								updateRowFromToggleResponse(row, response.data);
							}
							button.disabled = false;
							return;
						}
					} catch (e) {
						// Fall through to error handling.
					}
				}
				button.disabled = false;
				button.textContent = errorLabel;
			};
			xhr.onerror = function () {
				button.disabled = false;
				button.textContent = errorLabel;
			};

			xhr.send(
				'action=abilities_audit_toggle' +
					'&ability=' +
					encodeURIComponent(ability) +
					'&_wpnonce=' +
					encodeURIComponent(nonce)
			);
		});
	});
})();
