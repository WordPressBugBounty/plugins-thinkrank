/**
 * ThinkRank SEO Quick Edit modal (post list).
 *
 * Opens from the pencil button in the ThinkRank SEO Overview column and lets
 * editors change the SEO title, meta description and focus keywords without
 * leaving edit.php. On save the server re-renders the column cell (score
 * included) and the row updates in place.
 *
 * Vanilla JS on purpose: edit.php ships no React runtime, and the only other
 * ThinkRank script there (focus-keyword-inline-edit) is similarly standalone.
 * All field values are written through textContent/value — never innerHTML —
 * so stored meta can't inject markup into the admin. The one innerHTML sink is
 * the column cell swap, whose markup is rendered server-side by the same
 * escaping code that rendered the cell on page load.
 *
 * @package
 * @since 1.28.0
 */

(function () {
	'use strict';

	const config = window.thinkrankSeoQuickEdit;

	if (!config) {
		return;
	}

	const i18n = config.i18n || {};
	let openState = null; // { postId, cell, trigger, overlay, els }

	/**
	 * Tiny element factory: el('div', 'class-a class-b', textContent)
	 *
	 * @param {string} tag       Element tag name.
	 * @param {string} className Class attribute value.
	 * @param {string} [text]    Text content (set via textContent, never HTML).
	 * @return {HTMLElement} The created element.
	 */
	function el(tag, className, text) {
		const node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null && text !== '') {
			node.textContent = text;
		}
		return node;
	}

	// ------------------------------------------------------------------ AJAX

	function post(action, data) {
		const body = new window.FormData();
		body.append('action', action);
		body.append('nonce', config.nonce);
		Object.keys(data).forEach(function (key) {
			body.append(key, data[key]);
		});

		return window
			.fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			})
			.then(function (response) {
				return response.json();
			});
	}

	// ----------------------------------------------------------------- Modal

	function close() {
		if (!openState) {
			return;
		}
		document.removeEventListener('keydown', onKeydown);
		openState.overlay.remove();
		if (openState.trigger && document.body.contains(openState.trigger)) {
			openState.trigger.focus();
		}
		openState = null;
	}

	function onKeydown(event) {
		if (event.key === 'Escape') {
			close();
		}
	}

	function buildSkeleton(postTitle) {
		const overlay = el('div', 'thinkrank-qe-overlay');
		const modal = el('div', 'thinkrank-qe-modal');
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-modal', 'true');
		modal.setAttribute('aria-label', i18n.modalTitle);

		// Header
		const header = el('div', 'thinkrank-qe-header');
		const heading = el('div', 'thinkrank-qe-heading');
		heading.appendChild(el('h2', 'thinkrank-qe-title', i18n.modalTitle));
		if (postTitle) {
			heading.appendChild(el('p', 'thinkrank-qe-post-title', postTitle));
		}
		const closeBtn = el('button', 'thinkrank-qe-close');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', i18n.close);
		closeBtn.innerHTML =
			'<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
		closeBtn.addEventListener('click', close);
		header.appendChild(heading);
		header.appendChild(closeBtn);

		const body = el('div', 'thinkrank-qe-body');
		const footer = el('div', 'thinkrank-qe-footer');

		modal.appendChild(header);
		modal.appendChild(body);
		modal.appendChild(footer);
		overlay.appendChild(modal);

		overlay.addEventListener('mousedown', function (event) {
			if (event.target === overlay) {
				close();
			}
		});

		return {
			overlay,
			modal,
			body,
			footer,
			heading,
		};
	}

	function buildCounter(input, max) {
		const counter = el('span', 'thinkrank-qe-counter');

		function update() {
			const length = input.value.length;
			counter.textContent = length + '/' + max;
			counter.classList.toggle('is-over', length > max);
		}

		input.addEventListener('input', update);
		update();

		return counter;
	}

	/**
	 * One labeled field row: label + counter on top, control, helper line(s).
	 *
	 * @param {Object} options Field options (id, label, value, placeholder,
	 *                         textarea, maxLength, counterMax, helpers).
	 * @return {{wrap: HTMLElement, control: HTMLElement}} Row wrapper + control.
	 */
	function buildField(options) {
		const wrap = el('div', 'thinkrank-qe-field');
		const top = el('div', 'thinkrank-qe-field-top');
		const label = el('label', 'thinkrank-qe-label', options.label);
		label.setAttribute('for', options.id);
		top.appendChild(label);

		let control;
		if (options.textarea) {
			control = document.createElement('textarea');
			control.rows = 3;
		} else {
			control = document.createElement('input');
			control.type = 'text';
		}
		control.id = options.id;
		control.className = 'thinkrank-qe-input';
		control.value = options.value || '';
		if (options.placeholder) {
			control.placeholder = options.placeholder;
		}
		if (options.maxLength) {
			control.maxLength = options.maxLength;
		}

		if (options.counterMax) {
			top.appendChild(buildCounter(control, options.counterMax));
		}

		wrap.appendChild(top);
		wrap.appendChild(control);

		(options.helpers || []).forEach(function (helper) {
			if (helper) {
				wrap.appendChild(el('p', 'thinkrank-qe-help', helper));
			}
		});

		return { wrap, control };
	}

	function renderForm(state, data) {
		const body = state.els.body;
		const footer = state.els.footer;
		body.textContent = '';
		footer.textContent = '';

		if (
			data.post_title &&
			!state.els.heading.querySelector('.thinkrank-qe-post-title')
		) {
			state.els.heading.appendChild(
				el('p', 'thinkrank-qe-post-title', data.post_title)
			);
		}

		const title = buildField({
			id: 'thinkrank-qe-seo-title',
			label: i18n.seoTitle,
			value: data.seo_title,
			// The effective (pattern-resolved) value reads as a placeholder, so
			// an empty field means "inheriting" rather than "blank".
			placeholder: data.effective_title,
			maxLength: 80,
			counterMax: config.titleMax,
			helpers: [
				i18n.seoTitleHelp,
				data.seo_title === '' ? i18n.inheritedHint : '',
			],
		});

		const description = buildField({
			id: 'thinkrank-qe-meta-description',
			label: i18n.metaDescription,
			value: data.meta_description,
			placeholder: data.effective_description,
			textarea: true,
			maxLength: 320,
			counterMax: config.descriptionMax,
			helpers: [
				i18n.metaDescriptionHelp,
				data.meta_description === '' ? i18n.inheritedHint : '',
			],
		});

		const keywords = buildField({
			id: 'thinkrank-qe-focus-keywords',
			label: i18n.focusKeywords,
			value: (data.focus_keywords || []).join(', '),
			helpers: [i18n.focusKeywordsHelp],
		});

		body.appendChild(title.wrap);
		body.appendChild(description.wrap);
		body.appendChild(keywords.wrap);

		const error = el('p', 'thinkrank-qe-error');
		error.hidden = true;
		body.appendChild(error);

		// Footer: full-editor escape hatch left, Cancel/Save right.
		if (data.edit_link) {
			const editLink = el('a', 'thinkrank-qe-edit-link', i18n.editFull);
			editLink.href = data.edit_link;
			footer.appendChild(editLink);
		}

		const actions = el('div', 'thinkrank-qe-actions');
		const cancel = el(
			'button',
			'thinkrank-qe-btn thinkrank-qe-btn-ghost',
			i18n.cancel
		);
		cancel.type = 'button';
		cancel.addEventListener('click', close);

		const save = el(
			'button',
			'thinkrank-qe-btn thinkrank-qe-btn-primary',
			i18n.save
		);
		save.type = 'button';
		save.addEventListener('click', function () {
			save.disabled = true;
			save.textContent = i18n.saving;
			error.hidden = true;

			post(config.actionSave, {
				post_id: state.postId,
				seo_title: title.control.value,
				meta_description: description.control.value,
				focus_keywords: keywords.control.value,
			})
				.then(function (response) {
					if (!response || !response.success) {
						throw new Error(
							(response &&
								response.data &&
								response.data.message) ||
								''
						);
					}
					// Swap the freshly rendered cell (score included) into the row.
					if (response.data.column_html && state.cell) {
						state.cell.innerHTML = response.data.column_html;
					}
					close();
				})
				.catch(function (err) {
					save.disabled = false;
					save.textContent = i18n.save;
					error.textContent = (err && err.message) || i18n.saveError;
					error.hidden = false;
				});
		});

		actions.appendChild(cancel);
		actions.appendChild(save);
		footer.appendChild(actions);

		title.control.focus();
		title.control.setSelectionRange(
			title.control.value.length,
			title.control.value.length
		);
	}

	/**
	 * Build the modal's field data from the trigger's embedded data attributes.
	 *
	 * The column render embeds everything the modal needs, so opening costs no
	 * admin-ajax round trip (a full WordPress bootstrap) — the form renders in
	 * the same click task. After a save the cell markup is replaced server-side,
	 * which re-embeds fresh values.
	 *
	 * @param {HTMLElement} trigger The clicked trigger button.
	 * @param {HTMLElement} cell    The column cell containing the trigger.
	 * @return {Object} Field data in the same shape the get endpoint returns.
	 */
	function dataFromTrigger(trigger, cell) {
		const d = trigger.dataset;

		// Keyword freshness: the chip's inline editor may have changed the
		// PRIMARY keyword since the page rendered (it never touches the
		// secondaries), so rebuild the list as [current chip primary, ...rest].
		let keywords = (d.focusKeywords || '')
			.split(',')
			.map(function (s) {
				return s.trim();
			})
			.filter(Boolean);

		const chip =
			cell && cell.querySelector('.thinkrank-focus-keyword-display');
		if (chip && chip.dataset.keyword !== undefined) {
			const primary = chip.dataset.keyword.trim();
			const rest = keywords.slice(1);
			keywords = primary ? [primary].concat(rest) : rest;
		}

		return {
			post_title: d.postTitle || '',
			seo_title: d.seoTitle || '',
			meta_description: d.metaDescription || '',
			effective_title: d.effectiveTitle || '',
			effective_description: d.effectiveDescription || '',
			focus_keywords: keywords,
			edit_link: d.editLink || '',
		};
	}

	function open(trigger) {
		if (openState) {
			close();
		}

		const postId = trigger.getAttribute('data-post-id');
		const cell = trigger.closest('.column-thinkrank_seo_overview');
		const els = buildSkeleton('');

		openState = {
			postId,
			cell,
			trigger,
			overlay: els.overlay,
			els,
		};

		document.body.appendChild(els.overlay);
		document.addEventListener('keydown', onKeydown);

		// Fast path: render synchronously from the data embedded in the cell.
		if (trigger.dataset.effectiveTitle !== undefined) {
			renderForm(openState, dataFromTrigger(trigger, cell));
			return;
		}

		// Fallback (markup without embedded data, e.g. a cached page from an
		// older build): fetch the fields over admin-ajax.
		els.body.appendChild(el('div', 'thinkrank-qe-loading'));

		post(config.actionGet, { post_id: postId })
			.then(function (response) {
				if (!openState || openState.postId !== postId) {
					return; // Modal was closed (or reopened for another post) mid-flight.
				}
				if (!response || !response.success) {
					throw new Error(
						(response && response.data && response.data.message) ||
							''
					);
				}
				renderForm(openState, response.data);
			})
			.catch(function (err) {
				if (!openState || openState.postId !== postId) {
					return;
				}
				openState.els.body.textContent = '';
				const error = el(
					'p',
					'thinkrank-qe-error',
					(err && err.message) || i18n.loadError
				);
				openState.els.body.appendChild(error);
			});
	}

	document.addEventListener('click', function (event) {
		const trigger = event.target.closest('.thinkrank-qe-trigger');
		if (trigger) {
			event.preventDefault();
			open(trigger);
		}
	});
})();
