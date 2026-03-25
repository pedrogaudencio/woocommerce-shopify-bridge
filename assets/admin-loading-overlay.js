(function () {
	'use strict';

	var overlayVisible = false;
	var hideTimer = null;

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

	function showOverlay() {
		var overlay = ensureOverlay();
		var messageNode = overlay.querySelector('.swb-loading-message');
		var message = (window.swbLoadingOverlay && window.swbLoadingOverlay.message) || 'Working... please wait.';

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

	function hideOverlay() {
		var overlay = document.getElementById('swb-loading-overlay');
		if (overlay) {
			overlay.classList.remove('is-active');
		}
		document.body.classList.remove('swb-loading-active');
		overlayVisible = false;
	}

	function markBusy(trigger, event) {
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
		showOverlay();
	}

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest('[data-swb-long-action="1"]');
		if (!trigger) {
			return;
		}

		markBusy(trigger, event);
	});

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!(form instanceof HTMLFormElement)) {
			return;
		}

		if (form.getAttribute('data-swb-long-action') === '1') {
			showOverlay();
		}
	});

	window.addEventListener('pageshow', hideOverlay);
	window.addEventListener('focus', function () {
		if (overlayVisible) {
			window.setTimeout(hideOverlay, 200);
		}
	});
})();

