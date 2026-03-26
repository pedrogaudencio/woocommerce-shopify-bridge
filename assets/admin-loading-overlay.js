(function () {
	'use strict';

	var overlayVisible = false;
	var hideTimer = null;
	var localized = window.swbLoadingOverlay || {};
	var bulkImageSyncStorageKey = 'swbBulkImageSyncInProgress';

	function getLocalizedString(key, fallback) {
		if (localized && typeof localized[key] === 'string' && localized[key].length > 0) {
			return localized[key];
		}

		return fallback;
	}

	function interpolateTokens(template, values) {
		if (!template || !values) {
			return template;
		}

		return template.replace(/%([a-zA-Z0-9_]+)%/g, function (match, token) {
			if (Object.prototype.hasOwnProperty.call(values, token)) {
				return String(values[token]);
			}

			return match;
		});
	}

	function ensureOverlay() {
		var overlay = document.getElementById('swb-loading-overlay');
		if (overlay) {
			return overlay;
		}

		overlay = document.createElement('div');
		overlay.id = 'swb-loading-overlay';
		overlay.setAttribute('aria-hidden', 'true');
		overlay.innerHTML =
			'<div class="swb-loading-overlay-inner" role="status" aria-live="polite">' +
			'<span class="swb-loading-spinner" aria-hidden="true"></span>' +
			'<span class="swb-loading-message"></span>' +
			'</div>';

		document.body.appendChild(overlay);
		return overlay;
	}

	function getQueryParam(name) {
		if (typeof window.URLSearchParams === 'undefined') {
			return '';
		}

		var params = new window.URLSearchParams(window.location.search || '');
		return params.get(name) || '';
	}

	function isBulkImageSyncContinuation() {
		return '1' === getQueryParam('swb_bulk_sync_images_continue') || '1' === getQueryParam('swb_bulk_sync_images_selected_continue');
	}

	function isBulkImageSyncCompleteNotice() {
		if ('1' !== getQueryParam('swb_notice')) {
			return false;
		}

		var message = getQueryParam('swb_message');
		return message.indexOf('Bulk image sync complete') !== -1;
	}

	function getBulkImageSyncState() {
		try {
			return window.sessionStorage.getItem(bulkImageSyncStorageKey) === '1';
		} catch (e) {
			return false;
		}
	}

	function setBulkImageSyncState(active) {
		try {
			if (active) {
				window.sessionStorage.setItem(bulkImageSyncStorageKey, '1');
				return;
			}

			window.sessionStorage.removeItem(bulkImageSyncStorageKey);
		} catch (e) {
			// Ignore storage access failures.
		}
	}

	function isBulkImageSyncSubmit(form, bulkActionContext) {
		if (!(form instanceof HTMLFormElement)) {
			return false;
		}

		var topSyncInput = form.querySelector('input[name="swb_bulk_sync_images"]');
		if (topSyncInput && topSyncInput.value === '1') {
			return true;
		}

		return !!(bulkActionContext && bulkActionContext.value === 'bulk-sync-images');
	}

	function getBulkImageSyncContinuationMessage() {
		return getLocalizedString('bulkImageSyncContinuationMessage', 'Syncing images... batch in progress. Please keep this page open until completion.');
	}

	function showOverlay(customMessage) {
		var overlay = ensureOverlay();
		var messageNode = overlay.querySelector('.swb-loading-message');
		var message = customMessage || getLocalizedString('message', 'Working... please wait.');

		if (messageNode) {
			messageNode.textContent = message;
		}

		overlay.classList.add('is-active');
		document.body.classList.add('swb-loading-active');
		overlayVisible = true;

		if (hideTimer) {
			window.clearTimeout(hideTimer);
		}

		// Fallback for file-download flows where page may stay open.
		hideTimer = window.setTimeout(function () {
			hideOverlay();
		}, 45000);
	}

	function getBulkActionContext(form) {
		var topSelect = form.querySelector('select[name="action"]');
		var bottomSelect = form.querySelector('select[name="action2"]');
		var actionSelect = null;
		var actionValue = '';

		if (topSelect && topSelect.value && '-1' !== topSelect.value) {
			actionSelect = topSelect;
			actionValue = topSelect.value;
		} else if (bottomSelect && bottomSelect.value && '-1' !== bottomSelect.value) {
			actionSelect = bottomSelect;
			actionValue = bottomSelect.value;
		}

		if (!actionSelect || !actionValue || '-1' === actionValue) {
			return null;
		}

		var selectedOption = actionSelect.options[actionSelect.selectedIndex];
		var actionLabel = selectedOption ? (selectedOption.text || '').trim() : actionValue;
		var selectedCount = form.querySelectorAll('tbody .check-column input[type="checkbox"]:checked').length;
		var templateKey = 1 === selectedCount ? 'bulkActionMessageSingular' : 'bulkActionMessagePlural';
		var messageTemplate = getLocalizedString(
			templateKey,
			'Running bulk action "%action%" for %count% selected mapping(s). This can take a while, so please keep this page open until it finishes.'
		);

		return {
			value: actionValue,
			message: interpolateTokens(messageTemplate, {
				action: actionLabel,
				count: selectedCount
			})
		};
	}

	function hideOverlay() {
		if (getBulkImageSyncState() && isBulkImageSyncContinuation()) {
			return;
		}

		var overlay = document.getElementById('swb-loading-overlay');
		if (overlay) {
			overlay.classList.remove('is-active');
		}
		document.body.classList.remove('swb-loading-active');
		overlayVisible = false;
	}

	function markBusy(trigger, event, message) {
		if (!trigger) {
			return;
		}

		if (trigger.getAttribute('data-swb-busy') === '1') {
			if (event && typeof event.preventDefault === 'function') {
				event.preventDefault();
			}
			return;
		}

		trigger.setAttribute('data-swb-busy', '1');
		if (trigger.tagName !== 'A') {
			trigger.disabled = true;
		}
		showOverlay(message);
	}

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest('[data-swb-long-action="1"]');
		if (!trigger) {
			return;
		}

		var triggerMessage = trigger.getAttribute('data-swb-loading-message');
		markBusy(trigger, event, triggerMessage);
	});

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!(form instanceof HTMLFormElement)) {
			return;
		}

		var bulkActionContext = getBulkActionContext(form);
		if (isBulkImageSyncSubmit(form, bulkActionContext)) {
			setBulkImageSyncState(true);
			showOverlay(getBulkImageSyncContinuationMessage());
			return;
		}

		if (form.getAttribute('data-swb-long-action') === '1') {
			showOverlay(form.getAttribute('data-swb-loading-message'));
			return;
		}

		if (bulkActionContext) {
			showOverlay(bulkActionContext.message);
		}
	});

	if (isBulkImageSyncContinuation() || getBulkImageSyncState()) {
		showOverlay(getBulkImageSyncContinuationMessage());
	}

	if (isBulkImageSyncCompleteNotice()) {
		setBulkImageSyncState(false);
		hideOverlay();
	}

	window.addEventListener('pageshow', hideOverlay);
	window.addEventListener('focus', function () {
		if (getBulkImageSyncState() && isBulkImageSyncContinuation()) {
			return;
		}

		if (overlayVisible) {
			window.setTimeout(hideOverlay, 200);
		}
	});
})();

