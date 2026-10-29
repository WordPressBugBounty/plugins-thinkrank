/**
 * Follow Bricks' instant navigation.
 *
 * Since Bricks 2.4, switching to another page or template inside the builder
 * does not reload the window. Bricks fetches the new document over
 * `bricks_get_partial_builder_data`, swaps its state in place and records the
 * switch with `history.pushState({ postId })` (`replaceState` for Back and
 * Forward). It fires no DOM event of its own.
 *
 * The panel was mounted once from the config localized for the document open
 * at page load, so after a switch it kept showing that document's SEO data,
 * and Save wrote it back to that document (#971).
 *
 * This wraps both history methods. Bricks calls them only after the new
 * document has loaded, so a switch the user cancels at Bricks' unsaved-changes
 * prompt is never picked up. On a new `postId` the caller is told to leave the
 * old document at once, so nothing can be saved against it while the new
 * config loads, then handed the new config when it arrives. If it fails to
 * arrive, the caller gets a retry for that document.
 *
 * @package
 * @since 2.15.1
 */

/**
 * AJAX action that returns the panel config for a document.
 */
export const PANEL_DATA_ACTION = 'thinkrank_bricks_panel_data';

/**
 * The post ID a history state names, or 0.
 *
 * @param {*} state History state Bricks pushed.
 * @return {number} Post ID.
 */
export function statePostId(state) {
	const id = Number(state && state.postId);

	return Number.isInteger(id) && id > 0 ? id : 0;
}

/**
 * Fetch the panel config for a document.
 *
 * @param {number} postId Document Bricks switched to.
 * @param {Object} config Current config (for ajaxUrl and nonce).
 * @return {Promise<Object>} The new config.
 */
export async function fetchPanelData(postId, config) {
	const body = new URLSearchParams();
	body.append('action', PANEL_DATA_ACTION);
	body.append('nonce', config.nonce || '');
	body.append('post_id', String(postId));

	const response = await window.fetch(
		config.ajaxUrl || '/wp-admin/admin-ajax.php',
		{
			method: 'POST',
			credentials: 'same-origin',
			body,
		}
	);
	const json = await response.json();

	if (!json || !json.success || !json.data) {
		throw new Error(
			'ThinkRank: could not load the SEO panel for this document.'
		);
	}

	return json.data;
}

/**
 * Start following instant navigation.
 *
 * @param {Object}   options
 * @param {Function} options.getConfig Returns the current config.
 * @param {Function} options.onLeave   Called with the new post ID as soon as
 *                                     the document changes.
 * @param {Function} options.onArrive  Called with the new config once loaded.
 * @param {Function} [options.onLoad]  Called with the post ID each time its
 *                                     config starts loading, retries included.
 * @param {Function} [options.onError] Called with the post ID and a retry
 *                                     function when its config failed to load.
 * @param {Function} [options.load]    Loader, for tests. Defaults to fetchPanelData.
 * @param {Object}   [options.win]     Window, for tests.
 * @return {Function} Stops following and restores the history methods.
 */
export function followInstantNavigation({
	getConfig,
	onLeave,
	onArrive,
	onLoad = () => {},
	onError = () => {},
	load = fetchPanelData,
	win = window,
}) {
	const history = win.history;
	const original = {
		pushState: history.pushState,
		replaceState: history.replaceState,
	};
	let sequence = 0;
	// The document Bricks is on, which is not the config's post while a
	// switch is loading: A, then B, then back to A before B's config arrives
	// must still end on A.
	let current = Number((getConfig() || {}).postId) || 0;
	// Whether the current document's config failed to load. The old panel
	// stays hidden, so without a way to load it again a dropped request
	// would leave this document with no SEO panel until a reload.
	let failed = false;

	const loadCurrent = () => {
		const postId = current;
		sequence += 1;
		const mine = sequence;
		failed = false;

		onLoad(postId);

		load(postId, getConfig() || {}).then(
			(data) => {
				// A newer switch started while this one loaded: its answer wins.
				if (mine === sequence) {
					onArrive(data);
				}
			},
			(error) => {
				if (mine !== sequence) {
					return;
				}

				// Keep the old document's panel hidden: showing it again is
				// exactly the bug this guards against. Offer a retry instead,
				// good only while this document is still the one open.
				failed = true;
				onError(
					postId,
					() => {
						if (failed && current === postId) {
							loadCurrent();
						}
					},
					error
				);
			}
		);
	};

	const changed = (state) => {
		const postId = statePostId(state);

		if (!postId) {
			return;
		}

		if (postId === current) {
			// Bricks recorded the open document again: if its config never
			// arrived, that is a second chance to load it.
			if (failed) {
				loadCurrent();
			}
			return;
		}

		current = postId;
		onLeave(postId);
		loadCurrent();
	};

	['pushState', 'replaceState'].forEach((method) => {
		history[method] = function (state, ...rest) {
			const result = original[method].call(this, state, ...rest);
			try {
				changed(state);
			} catch (e) {
				// Never break Bricks' own navigation.
			}
			return result;
		};
	});

	// No popstate listener: on Back/Forward Bricks loads the document first
	// and then calls replaceState, which is caught above. Reacting to popstate
	// itself would act before Bricks has switched, or when its unsaved-changes
	// prompt keeps the user where they are.

	return () => {
		history.pushState = original.pushState;
		history.replaceState = original.replaceState;
	};
}
