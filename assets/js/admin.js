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

	// Schema expand / collapse.
	document.querySelectorAll('.abilities-audit-schema-toggle').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var targetId = this.getAttribute('data-target');
			var target = targetId ? document.getElementById(targetId) : null;
			if (target) {
				var isHidden = target.style.display === 'none';
				target.style.display = isHidden ? 'table-row' : 'none';
				this.textContent = isHidden ? hideLabel : viewLabel;
			}
		});
	});

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
