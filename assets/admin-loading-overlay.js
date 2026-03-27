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

	function formatStatusMessageForDisplay(statusMessage) {
		var message = (statusMessage || '').trim();
		if (!message) {
			return '';
		}

		// Keep non-image-sync statuses unchanged.
		if (message.indexOf('Downloaded:') === -1 && message.indexOf('Reused:') === -1) {
			return message;
		}

		return message
			.replace('. Downloaded:', '.\nDownloaded:')
			.replace(', Reused:', ',\nReused:')
			.replace(', Variation gallery applied:', ',\nVariation gallery applied:')
			.replace(', Gallery applied:', ',\nGallery applied:');
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
			'<div class="swb-loading-header">' +
			'<span class="swb-loading-spinner" aria-hidden="true"></span>' +
			'<span class="swb-loading-message"></span>' +
			'</div>' +
			'<div class="swb-loading-progress-wrap" hidden>' +
			'<div class="swb-loading-progress-track" aria-hidden="true">' +
			'<div class="swb-loading-progress-fill"></div>' +
			'</div>' +
			'<div class="swb-loading-progress-text"></div>' +
			'<div class="swb-loading-current-status"></div>' +
			'<div class="swb-loading-current-item"></div>' +
			'</div>' +
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

	function getProgressMetaFromQuery() {
		var handled = parseInt(getQueryParam('swb_progress_handled'), 10);
		var processed = parseInt(getQueryParam('swb_progress_processed'), 10);
		var total = parseInt(getQueryParam('swb_progress_total'), 10);
		var changed = parseInt(getQueryParam('swb_progress_changed'), 10);
		var unchanged = parseInt(getQueryParam('swb_progress_unchanged'), 10);
		var failed = parseInt(getQueryParam('swb_progress_failed'), 10);

		return {
			action: getQueryParam('swb_progress_action') || '',
			handled: isNaN(handled) ? 0 : Math.max(0, handled),
			processed: isNaN(processed) ? 0 : Math.max(0, processed),
			total: isNaN(total) ? 0 : Math.max(0, total),
			changed: isNaN(changed) ? 0 : Math.max(0, changed),
			unchanged: isNaN(unchanged) ? 0 : Math.max(0, unchanged),
			failed: isNaN(failed) ? 0 : Math.max(0, failed),
			statusMessage: getQueryParam('swb_progress_status_message') || '',
			wcSku: getQueryParam('swb_progress_wc_sku') || '',
			shopifyItemId: getQueryParam('swb_progress_shopify_item_id') || ''
		};
	}

	function renderOverlayProgress(progressMeta) {
		var overlay = ensureOverlay();
		var wrapNode = overlay.querySelector('.swb-loading-progress-wrap');
		var fillNode = overlay.querySelector('.swb-loading-progress-fill');
		var textNode = overlay.querySelector('.swb-loading-progress-text');
		var statusNode = overlay.querySelector('.swb-loading-current-status');
		var itemNode = overlay.querySelector('.swb-loading-current-item');

		if (!wrapNode || !fillNode || !textNode || !statusNode || !itemNode) {
			return;
		}

		var processed = progressMeta && typeof progressMeta.processed === 'number' ? progressMeta.processed : 0;
		var handled = progressMeta && typeof progressMeta.handled === 'number' ? progressMeta.handled : 0;
		var total = progressMeta && typeof progressMeta.total === 'number' ? progressMeta.total : 0;
		var indeterminate = !!(progressMeta && progressMeta.indeterminate);
		var changed = progressMeta && typeof progressMeta.changed === 'number' ? progressMeta.changed : 0;
		var unchanged = progressMeta && typeof progressMeta.unchanged === 'number' ? progressMeta.unchanged : 0;
		var failed = progressMeta && typeof progressMeta.failed === 'number' ? progressMeta.failed : 0;
		var statusMessage = progressMeta && progressMeta.statusMessage ? progressMeta.statusMessage : '';
		var wcSku = progressMeta && progressMeta.wcSku ? progressMeta.wcSku : '';
		var shopifyItemId = progressMeta && progressMeta.shopifyItemId ? progressMeta.shopifyItemId : '';
		var statusProcessed = changed + unchanged + failed;
		var completed = Math.max(processed, handled);

		if ((progressMeta && progressMeta.action === 'images') && statusProcessed > 0) {
			completed = statusProcessed;
		}
		var hasProgress = total > 0;
		var hasItemDetails = wcSku.length > 0 || shopifyItemId.length > 0;
		var hasStatusMessage = statusMessage.length > 0;

		if (!hasProgress && !hasItemDetails && !hasStatusMessage && !indeterminate && completed <= 0) {
			wrapNode.hidden = true;
			wrapNode.classList.remove('is-indeterminate');
			fillNode.style.width = '0%';
			textNode.textContent = '';
			statusNode.textContent = '';
			itemNode.textContent = '';
			return;
		}

		wrapNode.hidden = false;
		wrapNode.classList.toggle('is-indeterminate', indeterminate && !hasProgress);

		if (hasProgress) {
			var clampedProcessed = Math.min(completed, total);
			var percent = Math.max(0, Math.min(100, Math.round((clampedProcessed / total) * 100)));
			fillNode.style.width = String(percent) + '%';
			textNode.textContent = interpolateTokens(
				getLocalizedString('progressTemplate', 'Progress: %processed%/%total% (%percent%%)'),
				{
					processed: clampedProcessed,
					total: total,
					percent: percent
				}
			);
		} else if (completed > 0) {
			fillNode.style.width = indeterminate ? '35%' : '0%';
			textNode.textContent = interpolateTokens(
				getLocalizedString('processedOnlyTemplate', 'Processed: %processed%'),
				{ processed: completed }
			);
		} else if (indeterminate) {
			fillNode.style.width = '35%';
			textNode.textContent = getLocalizedString('indeterminateProgressText', 'Processing...');
		} else {
			fillNode.style.width = '0%';
			textNode.textContent = '';
		}

		if (hasStatusMessage) {
			statusNode.textContent = interpolateTokens(
				getLocalizedString('currentStatusTemplate', 'Status: %status%'),
				{ status: formatStatusMessageForDisplay(statusMessage) }
			);
		} else if (progressMeta && progressMeta.action === 'images' && completed > 0) {
			statusNode.textContent = interpolateTokens(
				getLocalizedString('imageStatusSummaryTemplate', 'Image sync status - Changed: %changed%, Unchanged: %unchanged%, Failed: %failed%'),
				{ changed: changed, unchanged: unchanged, failed: failed }
			);
		} else {
			statusNode.textContent = '';
		}

		if (hasItemDetails) {
			itemNode.textContent = interpolateTokens(
				getLocalizedString('currentItemTemplate', 'Currently processing - SKU: %sku% | Inventory ID: %inventory%'),
				{
					sku: wcSku || getLocalizedString('missingValue', 'N/A'),
					inventory: shopifyItemId || getLocalizedString('missingValue', 'N/A')
				}
			);
		} else {
			itemNode.textContent = '';
		}
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

	function showPersistentOverlayForBulkAction(actionType, progressMeta) {
		showOverlay(getBulkActionContinuationMessage(actionType));
		renderOverlayProgress(progressMeta || null);

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
			selectedCount: selectedCount,
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

	function canRunBulkImageSyncAjax(form) {
		if (!(form instanceof HTMLFormElement)) {
			return false;
		}

		if (!localized || !localized.ajaxUrl || !localized.bulkImageSyncStartAction || !localized.bulkImageSyncTickAction || !localized.bulkImageSyncAjaxNonce) {
			return false;
		}

		var topBulkImageInput = form.querySelector('input[name="swb_bulk_sync_images"]');
		if (!topBulkImageInput || topBulkImageInput.value !== '1') {
			return false;
		}

		// Avoid hijacking list-table bulk action forms; this loop is for the top "Sync images" form.
		return !form.querySelector('select[name="action"]') && !form.querySelector('select[name="action2"]');
	}

	function canRunSelectedBulkImageSyncAjax(form, bulkActionContext) {
		if (!(form instanceof HTMLFormElement)) {
			return false;
		}

		if (!localized || !localized.ajaxUrl || !localized.selectedBulkImageSyncStartAction || !localized.selectedBulkImageSyncTickAction || !localized.bulkImageSyncAjaxNonce) {
			return false;
		}

		return !!(bulkActionContext && bulkActionContext.value === 'bulk-sync-images');
	}

	function getSelectedMappingIds(form) {
		var nodes = form.querySelectorAll('tbody .check-column input[type="checkbox"][name="bulk-delete[]"]:checked');
		var ids = [];

		nodes.forEach(function (node) {
			var parsed = parseInt(node.value, 10);
			if (!isNaN(parsed) && parsed > 0) {
				ids.push(parsed);
			}
		});

		return ids;
	}

	function getMappingsStateArgsFromUrl() {
		if (typeof window.URLSearchParams === 'undefined') {
			return {};
		}

		var allowed = ['swb_product_type', 'swb_mapping_state', 's', 'orderby', 'order', 'paged'];
		var params = new window.URLSearchParams(window.location.search || '');
		var state = {};

		allowed.forEach(function (key) {
			var value = params.get(key);
			if (value) {
				state[key] = value;
			}
		});

		return state;
	}

	function postAjaxForm(actionName, extraData) {
		var body = new window.URLSearchParams();
		body.set('action', actionName);

		if (extraData) {
			Object.keys(extraData).forEach(function (key) {
				var value = extraData[key];
				if (typeof value === 'undefined' || value === null) {
					return;
				}

				if (Array.isArray(value)) {
					value.forEach(function (item) {
						if (typeof item !== 'undefined' && item !== null) {
							body.append(key + '[]', String(item));
						}
					});
					return;
				}

				if (typeof value === 'object') {
					Object.keys(value).forEach(function (subKey) {
						if (typeof value[subKey] !== 'undefined' && value[subKey] !== null) {
							body.append(key + '[' + subKey + ']', String(value[subKey]));
						}
					});
					return;
				}

				body.set(key, String(value));
			});
		}

		return fetch(localized.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			credentials: 'same-origin',
			body: body.toString()
		}).then(function (response) {
			return response.json();
		});
	}

	function normalizeProgress(progress) {
		var p = progress || {};
		return {
			action: p.action || 'images',
			handled: Number(p.handled || 0),
			processed: Number(p.processed || 0),
			total: Number(p.total || 0),
			changed: Number(p.changed || 0),
			unchanged: Number(p.unchanged || 0),
			failed: Number(p.failed || 0),
			statusMessage: p.statusMessage || '',
			wcSku: p.wcSku || '',
			shopifyItemId: p.shopifyItemId || '',
			indeterminate: false
		};
	}

	function runBulkImageSyncAjaxLoop(form) {
		if (form.getAttribute('data-swb-ajax-running') === '1') {
			return;
		}

		form.setAttribute('data-swb-ajax-running', '1');
		setBulkActionState('images');
		showPersistentOverlayForBulkAction('images', { action: 'images', processed: 0, handled: 0, total: 0, indeterminate: true });

		var productTypeInput = form.querySelector('input[name="swb_product_type"]');
		var productType = productTypeInput ? (productTypeInput.value || 'all') : 'all';

		postAjaxForm(localized.bulkImageSyncStartAction, {
			nonce: localized.bulkImageSyncAjaxNonce,
			swb_product_type: productType
		}).then(function (json) {
			if (!json || !json.success || !json.data || !json.data.jobToken) {
				throw new Error((json && json.data && json.data.message) ? json.data.message : 'Could not start bulk image sync job.');
			}

			var jobToken = json.data.jobToken;
			var initialProgress = normalizeProgress(json.data.progress);
			showPersistentOverlayForBulkAction('images', initialProgress);

			var tick = function () {
				postAjaxForm(localized.bulkImageSyncTickAction, {
					nonce: localized.bulkImageSyncAjaxNonce,
					job_token: jobToken
				}).then(function (tickJson) {
					if (!tickJson || !tickJson.success || !tickJson.data) {
						throw new Error((tickJson && tickJson.data && tickJson.data.message) ? tickJson.data.message : 'Bulk image sync tick failed.');
					}

					var data = tickJson.data;
					showPersistentOverlayForBulkAction('images', normalizeProgress(data.progress));

					if (data.done) {
						setBulkActionState(null);
						if (data.redirectUrl) {
							window.location.assign(data.redirectUrl);
							return;
						}

						hideOverlay();
						return;
					}

					window.setTimeout(tick, 50);
				}).catch(function (error) {
					setBulkActionState(null);
					hideOverlay();
					window.alert(error && error.message ? error.message : 'Bulk image sync failed.');
				});
			};

			window.setTimeout(tick, 50);
		}).catch(function (error) {
			setBulkActionState(null);
			hideOverlay();
			window.alert(error && error.message ? error.message : 'Could not start bulk image sync.');
		});
	}

	function runSelectedBulkImageSyncAjaxLoop(form, bulkActionContext) {
		if (form.getAttribute('data-swb-ajax-running') === '1') {
			return;
		}

		var mappingIds = getSelectedMappingIds(form);
		if (!mappingIds.length) {
			window.alert(getLocalizedString('noMappingsSelectedMessage', 'No mappings selected.'));
			return;
		}

		form.setAttribute('data-swb-ajax-running', '1');
		setBulkActionState('images');
		showPersistentOverlayForBulkAction('images', {
			action: 'images',
			handled: 0,
			processed: 0,
			total: mappingIds.length,
			indeterminate: false
		});

		postAjaxForm(localized.selectedBulkImageSyncStartAction, {
			nonce: localized.bulkImageSyncAjaxNonce,
			mapping_ids: mappingIds,
			state_args: getMappingsStateArgsFromUrl()
		}).then(function (json) {
			if (!json || !json.success || !json.data || !json.data.jobToken) {
				throw new Error((json && json.data && json.data.message) ? json.data.message : 'Could not start selected bulk image sync job.');
			}

			var jobToken = json.data.jobToken;
			showPersistentOverlayForBulkAction('images', normalizeProgress(json.data.progress));

			var tick = function () {
				postAjaxForm(localized.selectedBulkImageSyncTickAction, {
					nonce: localized.bulkImageSyncAjaxNonce,
					job_token: jobToken
				}).then(function (tickJson) {
					if (!tickJson || !tickJson.success || !tickJson.data) {
						throw new Error((tickJson && tickJson.data && tickJson.data.message) ? tickJson.data.message : 'Selected bulk image sync tick failed.');
					}

					var data = tickJson.data;
					showPersistentOverlayForBulkAction('images', normalizeProgress(data.progress));

					if (data.done) {
						setBulkActionState(null);
						if (data.redirectUrl) {
							window.location.assign(data.redirectUrl);
							return;
						}

						hideOverlay();
						return;
					}

					window.setTimeout(tick, 50);
				}).catch(function (error) {
					setBulkActionState(null);
					hideOverlay();
					window.alert(error && error.message ? error.message : 'Selected bulk image sync failed.');
				});
			};

			window.setTimeout(tick, 50);
		}).catch(function (error) {
			setBulkActionState(null);
			hideOverlay();
			window.alert(error && error.message ? error.message : 'Could not start selected bulk image sync.');
		});
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

		if (canRunSelectedBulkImageSyncAjax(form, bulkActionContext)) {
			event.preventDefault();
			runSelectedBulkImageSyncAjaxLoop(form, bulkActionContext);
			return;
		}

		if (canRunBulkImageSyncAjax(form)) {
			event.preventDefault();
			runBulkImageSyncAjaxLoop(form);
			return;
		}

		var bulkActionType = detectBulkActionFromSubmit(form, bulkActionContext);
		if (bulkActionType) {
			setBulkActionState(bulkActionType);
			showPersistentOverlayForBulkAction(
				bulkActionType,
				'images' === bulkActionType && bulkActionContext && bulkActionContext.selectedCount > 0
					? { handled: 0, processed: 0, total: bulkActionContext.selectedCount, wcSku: '', shopifyItemId: '', indeterminate: false }
					: { handled: 0, processed: 0, total: 0, wcSku: '', shopifyItemId: '', indeterminate: true }
			);
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
	var progressMeta = getProgressMetaFromQuery();
	var progressAction = progressMeta.action === 'stock' ? 'stock' : (progressMeta.action === 'images' ? 'images' : '');
	if (isBulkActionContinuation() || activeBulkAction) {
		showPersistentOverlayForBulkAction(progressAction || (activeBulkAction ? activeBulkAction.type : 'images'), progressMeta);
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

