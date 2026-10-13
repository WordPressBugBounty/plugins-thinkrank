/**
 * ThinkRank deactivation feedback dialog (Plugins screen).
 *
 * Loaded only for a site that agreed to share usage data
 * (Plugin_Usage_Tracker::should_ask_on_deactivation()). Clicking ThinkRank's
 * Deactivate link opens the dialog Plugin_Usage_Tracker::deactivate_reasons_form()
 * printed; every path out of it that is not Cancel ends on the original
 * deactivation URL, so asking never stands between the user and deactivating.
 *
 * No build step and no jQuery: the file ships as written from static/.
 */
(function () {
	'use strict';

	/** How long a submit may take before we deactivate anyway. */
	const SUBMIT_TIMEOUT_MS = 4000;

	/** How long the "could not be sent" message shows before deactivating. */
	const ERROR_PAUSE_MS = 1200;

	function navigate(config, url) {
		if (typeof config.navigate === 'function') {
			config.navigate(url);
			return;
		}
		window.location.assign(url);
	}

	function findDeactivateLink(plugin) {
		const rows = document.querySelectorAll('tr[data-plugin]');
		for (let i = 0; i < rows.length; i++) {
			if (rows[i].getAttribute('data-plugin') === plugin) {
				return rows[i].querySelector('.deactivate a');
			}
		}
		return null;
	}

	function init() {
		const config = window.thinkrankDeactivation;
		const dialog = document.getElementById('thinkrank-deactivation');
		if (!config || !config.plugin || !dialog) {
			return;
		}

		const link = findDeactivateLink(config.plugin);
		if (!link) {
			return;
		}

		const form = dialog.querySelector('form');
		const submit = dialog.querySelector('[data-thinkrank-action="submit"]');
		const spinner = dialog.querySelector('.spinner');
		const error = dialog.querySelector('.thinkrank-deactivation__error');
		const radios = dialog.querySelectorAll(
			'input[type="radio"][name="reason"]'
		);
		const details = dialog.querySelectorAll('[data-thinkrank-reason]');
		const deactivateUrl = link.href;
		let busy = false;

		function selectedReason() {
			const checked = dialog.querySelector(
				'input[type="radio"][name="reason"]:checked'
			);
			return checked ? checked.value : '';
		}

		function showDetailFor(reason) {
			for (let i = 0; i < details.length; i++) {
				const panel = details[i];
				const active =
					panel.getAttribute('data-thinkrank-reason') === reason;
				const field = panel.querySelector('textarea, input');
				panel.hidden = !active;
				if (field) {
					// Disabled while hidden, so only the chosen reason's text is sent.
					field.disabled = !active;
				}
			}
		}

		function detailText() {
			const reason = selectedReason();
			for (let i = 0; i < details.length; i++) {
				if (
					details[i].getAttribute('data-thinkrank-reason') === reason
				) {
					const field = details[i].querySelector('textarea, input');
					return field ? field.value.trim() : '';
				}
			}
			return '';
		}

		function setBusy(value) {
			busy = value;
			dialog.setAttribute('aria-busy', value ? 'true' : 'false');
			if (spinner) {
				spinner.classList.toggle('is-active', value);
			}
			const buttons = dialog.querySelectorAll('button');
			for (let i = 0; i < buttons.length; i++) {
				buttons[i].disabled =
					value || (buttons[i] === submit && !selectedReason());
			}
		}

		function reset() {
			form.reset();
			showDetailFor('');
			submit.disabled = true;
			if (error) {
				error.hidden = true;
			}
			setBusy(false);
		}

		function open() {
			reset();
			if (typeof dialog.showModal === 'function') {
				dialog.showModal();
			} else {
				dialog.setAttribute('open', '');
			}
			if (radios.length) {
				radios[0].focus();
			}
		}

		function close() {
			if (busy) {
				return;
			}
			if (typeof dialog.close === 'function') {
				dialog.close();
			} else {
				dialog.removeAttribute('open');
			}
			link.focus();
		}

		function send() {
			const body = new window.FormData();
			body.append('action', config.action);
			body.append('security', config.nonce);
			body.append('reason', selectedReason());
			body.append('details', detailText());

			const controller =
				typeof window.AbortController === 'function'
					? new window.AbortController()
					: null;
			const timer = window.setTimeout(function () {
				if (controller) {
					controller.abort();
				}
			}, SUBMIT_TIMEOUT_MS);

			return window
				.fetch(config.ajaxUrl, {
					method: 'POST',
					body,
					credentials: 'same-origin',
					signal: controller ? controller.signal : undefined,
				})
				.then(function (response) {
					return response.ok;
				})
				.catch(function () {
					return false;
				})
				.then(function (ok) {
					window.clearTimeout(timer);
					return ok;
				});
		}

		link.addEventListener('click', function (event) {
			// Ctrl/Cmd-click and the like keep their usual meaning.
			if (
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.altKey
			) {
				return;
			}
			event.preventDefault();
			open();
		});

		for (let i = 0; i < radios.length; i++) {
			radios[i].addEventListener('change', function (event) {
				showDetailFor(event.target.value);
				submit.disabled = false;
			});
		}

		dialog.addEventListener('click', function (event) {
			const action = event.target.closest
				? event.target.closest('[data-thinkrank-action]')
				: null;

			if (
				action &&
				action.getAttribute('data-thinkrank-action') === 'cancel'
			) {
				event.preventDefault();
				close();
				return;
			}
			if (
				action &&
				action.getAttribute('data-thinkrank-action') === 'skip'
			) {
				event.preventDefault();
				if (!busy) {
					setBusy(true);
					navigate(config, deactivateUrl);
				}
				return;
			}
			// A click on the backdrop lands on the dialog element itself.
			if (event.target === dialog) {
				close();
			}
		});

		// Escape: the browser closes a modal dialog itself; keep it open while sending.
		dialog.addEventListener('cancel', function (event) {
			if (busy) {
				event.preventDefault();
				return;
			}
			link.focus();
		});

		form.addEventListener('submit', function (event) {
			// method="dialog" would close the dialog; the request decides when.
			event.preventDefault();
			if (busy || !selectedReason()) {
				return;
			}
			setBusy(true);
			send().then(function (ok) {
				if (ok || !error) {
					navigate(config, deactivateUrl);
					return;
				}
				error.hidden = false;
				window.setTimeout(function () {
					navigate(config, deactivateUrl);
				}, ERROR_PAUSE_MS);
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
