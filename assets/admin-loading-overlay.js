(function () {
	'use strict';

	var overlayVisible = false;
	var hideTimer = null;
	var localized = window.swbLoadingOverlay || {};
	var bulkActionStorageKey = 'swbBulkActionInProgress';
	var bulkActionMaxAgeMs = 30 * 60 * 1000;

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

	function isBulkStockSyncContinuation() {
		return '1' === getQueryParam('swb_bulk_sync_stock_continue');
	}

	function isBulkActionContinuation() {
		return isBulkImageSyncContinuation() || isBulkStockSyncContinuation();
	}

	function isBulkImageSyncCompleteNotice() {
		if ('1' !== getQueryParam('swb_notice')) {
			return false;
		}

		var message = getQueryParam('swb_message');
		return message.indexOf('Bulk image sync complete') !== -1;
	}

	function isBulkStockSyncCompleteNotice() {
		if ('1' !== getQueryParam('swb_notice')) {
			return false;
		}

		var message = getQueryParam('swb_message');
		return message.indexOf('Bulk stock sync complete') !== -1;
	}

	function getBulkActionState() {
		try {
			var raw = window.sessionStorage.getItem(bulkActionStorageKey);
			if (!raw) {
				return null;
			}

			var parsed = JSON.parse(raw);
			if (!parsed || (parsed.type !== 'images' && parsed.type !== 'stock')) {
				window.sessionStorage.removeItem(bulkActionStorageKey);
				return null;
			}

			var startedAt = Number(parsed.startedAt || 0);
			if (!startedAt || Date.now() - startedAt > bulkActionMaxAgeMs) {
				window.sessionStorage.removeItem(bulkActionStorageKey);
				return null;
			}

			return parsed;
		} catch (e) {
			return null;
		}
	}

	function setBulkActionState(type) {
		try {
			if (type === 'images' || type === 'stock') {
				window.sessionStorage.setItem(
					bulkActionStorageKey,
					JSON.stringify({
						type: type,
						startedAt: Date.now()
					})
				);
				return;
			}

			window.sessionStorage.removeItem(bulkActionStorageKey);
		} catch (e) {
			// Ignore storage access failures.
		}
	}

	function isBulkActionInProgress() {
		return !!getBulkActionState();
	}

	function getBulkImageSyncContinuationMessage() {
		return getLocalizedString('bulkImageSyncContinuationMessage', 'Syncing images... batch in progress. Please keep this page open until completion.');
	}

	function getBulkStockSyncContinuationMessage() {
		return getLocalizedString('bulkStockSyncContinuationMessage', 'Syncing stock... batch in progress. Please keep this page open until completion.');
	}

	function getBulkActionContinuationMessage(actionType) {
		if ('stock' === actionType) {
			return getBulkStockSyncContinuationMessage();
		}

		return getBulkImageSyncContinuationMessage();
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

		// Fallback only for non-persistent actions.
		hideTimer = window.setTimeout(function () {
			hideOverlay();
		}, 45000);
	}

	function showPersistentOverlayForBulkAction(actionType) {
		showOverlay(getBulkActionContinuationMessage(actionType));

		// Keep overlay visible for long-running server-side processing.
		if (hideTimer) {
			window.clearTimeout(hideTimer);
		}

		hideTimer = window.setTimeout(function () {
			setBulkActionState(null);
			hideOverlay();
		}, bulkActionMaxAgeMs);
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
		if (isBulkActionInProgress()) {
			return;
		}

		var overlay = document.getElementById('swb-loading-overlay');
		if (overlay) {
			overlay.classList.remove('is-active');
		}
		document.body.classList.remove('swb-loading-active');
		overlayVisible = false;
	}

	function detectBulkActionFromSubmit(form, bulkActionContext) {
		if (!(form instanceof HTMLFormElement)) {
			return '';
		}

		var imageSyncInput = form.querySelector('input[name="swb_bulk_sync_images"]');
		if (imageSyncInput && imageSyncInput.value === '1') {
			return 'images';
		}

		var stockSyncInput = form.querySelector('input[name="swb_bulk_sync_stock"]');
		if (stockSyncInput && stockSyncInput.value === '1') {
			return 'stock';
		}

		if (bulkActionContext && bulkActionContext.value === 'bulk-sync-images') {
			return 'images';
		}

		if (bulkActionContext && bulkActionContext.value === 'bulk-sync-stock') {
			return 'stock';
		}

		return '';
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
		var bulkActionType = detectBulkActionFromSubmit(form, bulkActionContext);
		if (bulkActionType) {
			setBulkActionState(bulkActionType);
			showPersistentOverlayForBulkAction(bulkActionType);
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

	var activeBulkAction = getBulkActionState();
	if (isBulkActionContinuation() || activeBulkAction) {
		showPersistentOverlayForBulkAction(activeBulkAction ? activeBulkAction.type : 'images');
	}

	if (isBulkImageSyncCompleteNotice() || isBulkStockSyncCompleteNotice()) {
		setBulkActionState(null);
		hideOverlay();
	}

	window.addEventListener('pageshow', hideOverlay);
	window.addEventListener('focus', function () {
		if (isBulkActionInProgress()) {
			return;
		}

		if (overlayVisible) {
			window.setTimeout(hideOverlay, 200);
		}
	});
})();

