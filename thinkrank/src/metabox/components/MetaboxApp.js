/**
 * ThinkRank Metabox React Component
 *
 * Main React component for the SEO metabox with tabbed interface.
 * Phase 1: Basic tab structure with SEO Analysis as default tab.
 *
 * @package
 * @since 1.0.0
 */

import {
	useState,
	useEffect,
	useRef,
	memo,
	useCallback,
	useMemo,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { cleanForSlug } from '@wordpress/url';
import apiFetch from '@wordpress/api-fetch';
import classnames from 'classnames';
import linkifyMessage from '../../shared/utils/linkifyMessage';
import { resolveEditorContent } from '../../admin/components/seo/utils/contentAnalysis';
import {
	beginScoreRequest,
	fieldsKeyOf,
	getSessionScore,
	keywordsKeyOf,
	requestLiveScore,
} from '../../admin/components/seo/liveScore';
import { persistSeoFields } from '../utils/persistSeoFields';

// Import child components
import MetaboxSchemaTab from './metabox-schema-tab';
import SocialTab from './SocialTab';
import ErrorBoundary from './ErrorBoundary';
import MetaboxDrawer from './MetaboxDrawer';

// Focused tab panels
import GeneralPanel from './panels/GeneralPanel';
import AnalysisPanel from './panels/AnalysisPanel';
import AdvancedPanel from './panels/AdvancedPanel';
import { withoutServerOwnedFields } from '../utils/draftFields';
import useCustomFieldVariables from '../utils/useCustomFieldVariables';

// Nav icons
import {
	IconDocument,
	IconGauge,
	IconCode,
	IconShare,
	IconSliders,
} from './ui/icons';

/**
 * Guards for the post-load SEO meta refresh (see #329). The panel polls for
 * values written after the editor loaded (Auto AI on publish, bulk
 * optimization, imports), so those requests must never overlap each other and
 * must not re-fire on every focus change.
 *
 * The minimum gap sits below the poll interval so the normal cadence is
 * untouched; it only collapses bursts.
 */
const SEO_REFRESH_MIN_GAP_MS = 3000;
const SEO_REFRESH_TIMEOUT_MS = 60000;
const SEO_REFRESH_INTERVAL_MS = 4000;
const SEO_REFRESH_MAX_TICKS = 10;
// A skipped re-check (one already in flight, or inside the min gap) must not
// lose a pending signal, so it retries — a bounded number of times.
const SEO_REFRESH_MAX_RETRIES = 3;

/**
 * Read the localized refresh config, falling back to the defaults above.
 *
 * Site owners switch the refresh off with the `thinkrank_metabox_seo_refresh`
 * PHP filter. Because the poll itself is client-side, that decision has to be
 * localized into the bundle and read here — a PHP-side filter alone could not
 * stop it (#329).
 *
 * @return {{enabled: boolean, intervalMs: number, maxTicks: number}} Config.
 */
const getSeoRefreshConfig = () => {
	const raw = window.thinkrankMetabox?.seoRefresh || {};
	const interval = Number(raw.intervalMs);
	const maxTicks = Number(raw.maxTicks);
	return {
		enabled: raw.enabled !== false,
		intervalMs: interval > 0 ? interval : SEO_REFRESH_INTERVAL_MS,
		maxTicks:
			Number.isFinite(maxTicks) && maxTicks >= 0
				? maxTicks
				: SEO_REFRESH_MAX_TICKS,
	};
};

/**
 * Call `onSaved` after each completed (non-autosave) post save.
 *
 * Publishing is what queues Auto AI, so a save is the moment the panel should
 * re-check whether a write is now pending. The Classic Editor reloads the page
 * on save and re-mounts, so it needs nothing here; this covers the editors
 * that save in place.
 *
 * @param {Function} onSaved Called once per completed save.
 * @return {Function} Unsubscribe.
 */
const subscribeToPostSaves = (onSaved) => {
	const cleanups = [];

	const dataApi = window.wp?.data;
	if (dataApi?.select?.('core/editor')) {
		try {
			let wasSaving = false;
			cleanups.push(
				dataApi.subscribe(() => {
					const editor = dataApi.select('core/editor');
					if (!editor) return;
					const saving =
						!!editor.isSavingPost?.() &&
						!editor.isAutosavingPost?.();
					if (wasSaving && !saving) {
						onSaved();
					}
					wasSaving = saving;
				})
			);
		} catch (e) {
			// Editor store unavailable — the other integrations below cover it.
		}
	}

	// Elementor saves through its own pipeline, never touching core/editor.
	try {
		const saver = window.elementor?.saver;
		if (saver?.on && saver?.off) {
			const handler = () => onSaved();
			saver.on('after:save', handler);
			cleanups.push(() => saver.off('after:save', handler));
		}
	} catch (e) {
		// Elementor absent or a different version — not fatal.
	}

	return () => cleanups.forEach((fn) => fn());
};

/**
 * Cheap 32-bit string fingerprint, used to key cached analysis responses on
 * every scored input without carrying the full values around.
 *
 * @param {string} value Value to fingerprint.
 * @return {number} Signed 32-bit hash (0 for an empty value).
 */
const hashString = (value) => {
	let hash = 0;
	const str = String(value || '');
	for (let i = 0; i < str.length; i++) {
		hash = (hash * 31 + str.charCodeAt(i)) | 0;
	}
	return hash;
};

/**
 * Main Metabox Component
 * @param root0
 * @param root0.postId
 * @param root0.existingMetadata
 * @param root0.contentPreview
 * @param root0.contentFull
 * @param root0.strings
 * @param root0.postTitle
 * @param root0.homeUrl
 * @param root0.postModified
 * @param root0.linkSuggestionsEnabled
 * @param root0.saveHandler
 * @param root0.contentResolver
 * @param root0.hideLauncher
 * @param root0.showPanelCard
 */
const MetaboxApp = ({
	postId,
	existingMetadata = {},
	contentPreview = '',
	contentFull = '',
	strings = {},
	postTitle: initialPostTitle = '',
	homeUrl = '',
	postModified = '',
	linkSuggestionsEnabled = true,
	// Optional overrides used when the metabox is hosted outside the classic /
	// block editor (e.g. the Elementor editor):
	// - saveHandler(formData): persists via a custom path (returns a Promise);
	//   when omitted, Save falls back to the editor's own save flow.
	// - contentResolver(): returns the post's plain-text content for AI/analysis
	//   when the standard block/classic/TinyMCE sources aren't present.
	saveHandler = null,
	contentResolver = null,
	// When true, no launcher button is rendered — the host opens the drawer via
	// the `thinkrank:toggle-seo-drawer` window event (e.g. Elementor's top bar).
	hideLauncher = false,
	// When true, render a visible card in the normal document flow. The drawer
	// itself is fixed-position, so without this the "ThinkRank SEO" metabox
	// renders as an empty box and toggling it in Screen Options / the block
	// editor's Preferences → Panels list appears to do nothing. Only the
	// metabox entry point sets this; hosts that mount into their own node
	// (Elementor, Divi, Oxygen) leave it off.
	showPanelCard = false,
}) => {
	// Parse JSON-encoded robots payloads coming from PHP hidden fields
	const parseJsonField = (value, fallback) => {
		if (!value) return fallback;
		if (typeof value === 'object') return value;
		try {
			const parsed = JSON.parse(value);
			return parsed && typeof parsed === 'object' ? parsed : fallback;
		} catch (e) {
			return fallback;
		}
	};

	const metaboxConfig =
		typeof window !== 'undefined' ? window.thinkrankMetabox || {} : {};
	// Focus keywords the input accepts; matches Focus_Keywords::MAX in PHP. An
	// extension that stores more (ThinkRank Pro) raises it; 0 = no cap.
	const focusKeywordsMax = applyFilters(
		'thinkrank.metabox.focusKeywordsMax',
		5
	);
	// Resolved Global/Bulk SEO patterns, shown as previews when a field is empty.
	const patternPreviews = metaboxConfig.patternPreviews || {};
	// Token => value map for live preview of custom patterns typed in fields.
	const basePatternVariables = metaboxConfig.patternVariables || {};
	const focusKeywordsCap = focusKeywordsMax > 0 ? focusKeywordsMax : Infinity;

	// Parse the focus keywords initial value (JSON-encoded array from the PHP
	// hidden field), falling back to the single legacy keyword.
	const parseFocusKeywords = () => {
		const raw = existingMetadata.focus_keywords;
		let list = [];
		if (Array.isArray(raw)) {
			list = raw;
		} else if (typeof raw === 'string' && raw) {
			try {
				const parsed = JSON.parse(raw);
				if (Array.isArray(parsed)) list = parsed;
			} catch (e) {
				list = [];
			}
		}
		if (!list.length && existingMetadata.focus_keyword) {
			list = [existingMetadata.focus_keyword];
		}
		return list
			.map((k) => String(k).trim())
			.filter(Boolean)
			.slice(0, focusKeywordsCap);
	};

	const initialFocusKeywords = parseFocusKeywords();

	// Form state - preserving exact field names from jQuery version
	const [formData, setFormData] = useState({
		thinkrank_seo_title: existingMetadata.title || '',
		thinkrank_meta_description: existingMetadata.description || '',
		// Primary keyword (first) kept for back-compat consumers (AI, SERP).
		thinkrank_focus_keyword: initialFocusKeywords[0] || '',
		// Full focus keyword list (up to 5).
		thinkrank_focus_keywords: initialFocusKeywords,
		thinkrank_canonical_url: existingMetadata.canonical_url || '',
		// Destination for the per-post redirect. Not post meta — the server
		// reads and writes it through ThinkRank Pro's redirections table — so
		// it is always empty when Pro is inactive.
		thinkrank_redirect_url: existingMetadata.redirect_url || '',
		thinkrank_redirect_type: Number(existingMetadata.redirect_type) || 301,
		// Post slug (post_name) — editable via the metabox Permalink field.
		thinkrank_post_slug: metaboxConfig.postSlug || '',
		thinkrank_content_type: 'blog_post',
		thinkrank_tone: 'professional',
		thinkrank_pillar_content:
			existingMetadata.pillar_content === '1' ||
			existingMetadata.pillar_content === true,
		thinkrank_exclude_from_search:
			existingMetadata.exclude_from_search === '1' ||
			existingMetadata.exclude_from_search === 1 ||
			existingMetadata.exclude_from_search === true,
		thinkrank_exclude_from_archives:
			existingMetadata.exclude_from_archives === '1' ||
			existingMetadata.exclude_from_archives === 1 ||
			existingMetadata.exclude_from_archives === true,
		thinkrank_robots_meta_enabled:
			existingMetadata.robots_meta_enabled === '1' ||
			existingMetadata.robots_meta_enabled === true,
		thinkrank_robots_meta: parseJsonField(existingMetadata.robots_meta, {}),
		thinkrank_advanced_robots_meta: parseJsonField(
			existingMetadata.advanced_robots_meta,
			{}
		),
		thinkrank_og_title: existingMetadata.og_title || '',
		thinkrank_og_description: existingMetadata.og_description || '',
		thinkrank_og_image: existingMetadata.og_image || '',
		thinkrank_twitter_title: existingMetadata.twitter_title || '',
		thinkrank_twitter_description:
			existingMetadata.twitter_description || '',
		thinkrank_twitter_image: existingMetadata.twitter_image || '',
	});

	// The page carries only the custom fields its saved fields name; a
	// %customfield(key)% typed since is looked up (#1277).
	const {
		vars: patternVariables,
		status: customFieldStatus,
		error: customFieldError,
		retry: retryCustomFields,
	} = useCustomFieldVariables(
		basePatternVariables,
		[
			formData.thinkrank_seo_title,
			formData.thinkrank_meta_description,
			formData.thinkrank_og_title,
			formData.thinkrank_og_description,
			formData.thinkrank_twitter_title,
			formData.thinkrank_twitter_description,
		],
		'post',
		postId
	);

	// Live post title — the mount-time prop goes stale as the user types, so
	// track edits from both the block editor store and the classic #title input.
	const [postTitle, setPostTitle] = useState(initialPostTitle);

	useEffect(() => {
		const cleanups = [];

		if (window.wp?.data?.select?.('core/editor')) {
			try {
				const unsubscribe = window.wp.data.subscribe(() => {
					const title = window.wp.data
						.select('core/editor')
						?.getEditedPostAttribute?.('title');
					if (typeof title === 'string') {
						setPostTitle(title);
					}
				});
				cleanups.push(unsubscribe);
			} catch (e) {
				// Editor store unavailable — classic editor listener below covers it
			}
		}

		const titleField = document.getElementById('title');
		if (titleField) {
			const onTitleInput = () => setPostTitle(titleField.value);
			titleField.addEventListener('input', onTitleInput);
			cleanups.push(() =>
				titleField.removeEventListener('input', onTitleInput)
			);
		}

		return () => cleanups.forEach((cleanup) => cleanup());
	}, []);

	// UI state
	const [isGenerating, setIsGenerating] = useState(false);
	const [isAnalyzing, setIsAnalyzing] = useState(false);
	const [showAnalysisResults, setShowAnalysisResults] = useState(false);
	const [showScoreBreakdown, setShowScoreBreakdown] = useState(false);
	const [notices, setNotices] = useState([]);
	const [analysisData, setAnalysisData] = useState(null);
	const [lastSaved, setLastSaved] = useState(null);

	// Simple cache for API responses
	const apiCache = useRef(new Map());

	// Refs for accessing DOM elements (needed for content extraction)
	const contentPreviewRef = useRef(null);

	const [activeTab, setActiveTab] = useState('general');

	// Drawer open/close state for the revamped logo-launcher UI.
	const [isOpen, setIsOpen] = useState(false);

	// Set if the pinned editor-header launcher fails to render, so the drawer
	// falls back to its own launcher — the button must never disappear.
	const [pinFailed, setPinFailed] = useState(false);

	// Current SEO score mirrored from the Analysis panel for the header ring.
	const [headerScore, setHeaderScore] = useState(null);

	// Saving state for the drawer-header Save button (block editor only; the
	// classic editor submits the page and reloads).
	const [isSaving, setIsSaving] = useState(false);

	const TABS = [
		{
			id: 'general',
			label: __('General', 'thinkrank'),
			icon: IconDocument,
		},
		{ id: 'analysis', label: __('Analysis', 'thinkrank'), icon: IconGauge },
		{ id: 'schema', label: __('Schema', 'thinkrank'), icon: IconCode },
		{ id: 'social', label: __('Social', 'thinkrank'), icon: IconShare },
		{
			id: 'advanced',
			label: __('Advanced', 'thinkrank'),
			icon: IconSliders,
		},
	];

	/**
	 * Handle form field changes
	 * @param fieldName
	 * @param value
	 */
	const handleFieldChange = (fieldName, value) => {
		setFormData((prev) => ({
			...prev,
			[fieldName]: value,
		}));
	};

	// --- Adopt out-of-band SEO meta writes without a manual reload ----------
	// Auto AI (on publish) and imports write the SEO title / meta description
	// straight to post meta AFTER this editor loaded. The drawer
	// reads its initial values once at mount, so those generated values would
	// otherwise stay invisible (and the SERP preview keeps showing the post-title
	// fallback) until a full page reload. Re-fetch the stored meta and adopt it —
	// but only for a field the user hasn't edited since our last sync, and never
	// replacing a real value with a blank.
	const syncedSeoRef = useRef({
		title: existingMetadata.title || '',
		description: existingMetadata.description || '',
	});
	const seoAdoptedRef = useRef(false);
	// Guards for refreshSeoFromServer below: one request at a time, and never
	// straight after the previous one. See #329.
	const seoRefreshInFlightRef = useRef(false);
	const lastSeoFetchAtRef = useRef(0);
	// The in-flight request's controller, so unmount can abort it.
	const seoAbortRef = useRef(null);

	/**
	 * Re-read the stored SEO meta and adopt anything a background writer filled.
	 *
	 * @return {Promise<?{pending: boolean}>} The server's pending state, or null
	 *   when the call was skipped (already in flight / inside the min gap) or
	 *   failed — callers must treat null as "no answer", not as "not pending".
	 */
	const refreshSeoFromServer = useCallback(async () => {
		if (!postId) return null;

		// One request at a time. Without this the 4s poll fires again while the
		// previous call is still open, so on a slow site the calls stack up
		// (10+ concurrent), saturate the PHP workers and make every response
		// slower still, which causes even more overlap. See #329.
		if (seoRefreshInFlightRef.current) return null;

		// Collapse bursts: a wake event landing moments after a fetch has
		// nothing new to learn.
		if (Date.now() - lastSeoFetchAtRef.current < SEO_REFRESH_MIN_GAP_MS) {
			return null;
		}

		seoRefreshInFlightRef.current = true;
		lastSeoFetchAtRef.current = Date.now();

		// A request that never settles must not strand the in-flight flag, or
		// the refresh would be dead for the rest of the session.
		const controller = new AbortController();
		seoAbortRef.current = controller;
		const timeoutId = setTimeout(
			() => controller.abort(),
			SEO_REFRESH_TIMEOUT_MS
		);

		let body;
		try {
			body = await apiFetch({
				path: `/thinkrank/v1/metadata/${postId}`,
				signal: controller.signal,
			});
		} catch (e) {
			return null; // Best-effort; a failed refresh must never disrupt the editor.
		} finally {
			clearTimeout(timeoutId);
			seoRefreshInFlightRef.current = false;
			if (seoAbortRef.current === controller) {
				seoAbortRef.current = null;
			}
		}
		const data = body && body.data ? body.data : body;
		if (!data) return null;

		const serverTitle = typeof data.title === 'string' ? data.title : '';
		const serverDesc =
			typeof data.description === 'string' ? data.description : '';

		setFormData((prev) => {
			const next = { ...prev };
			let changed = false;
			// Untouched-since-last-sync + differs + non-blank → a background
			// writer filled it after load; adopt it into the live form.
			if (
				serverTitle &&
				prev.thinkrank_seo_title === syncedSeoRef.current.title &&
				serverTitle !== prev.thinkrank_seo_title
			) {
				next.thinkrank_seo_title = serverTitle;
				syncedSeoRef.current.title = serverTitle;
				changed = true;
			}
			if (
				serverDesc &&
				prev.thinkrank_meta_description ===
					syncedSeoRef.current.description &&
				serverDesc !== prev.thinkrank_meta_description
			) {
				next.thinkrank_meta_description = serverDesc;
				syncedSeoRef.current.description = serverDesc;
				changed = true;
			}
			if (changed) {
				seoAdoptedRef.current = true;
			}
			return changed ? next : prev;
		});

		return { pending: !!data.pending };
	}, [postId]);

	useEffect(() => {
		if (!postId) {
			return undefined;
		}

		const config = getSeoRefreshConfig();
		if (!config.enabled) {
			return undefined;
		}

		let stopped = false;
		let interval = null;
		let ticks = 0;
		let onWake = null;
		let retryTimer = null;

		// Close the poll window: no timer, and crucially no listeners left
		// behind. Previously these survived until unmount, so every focus flip
		// for the rest of the session produced another request — and in
		// Elementor, where the panel and preview are separate frames, focus
		// flips constantly during normal editing (#329).
		const closeWindow = () => {
			if (interval) {
				clearInterval(interval);
				interval = null;
			}
			if (onWake) {
				window.removeEventListener('focus', onWake);
				document.removeEventListener('visibilitychange', onWake);
				onWake = null;
			}
		};

		// Open a bounded poll window. Only ever called once the server has said
		// a write is actually in flight, so the common case — nothing pending —
		// costs a single request and never opens this at all.
		const openWindow = () => {
			if (stopped || interval) return;

			ticks = 0;
			onWake = () => refreshSeoFromServer();
			window.addEventListener('focus', onWake);
			document.addEventListener('visibilitychange', onWake);

			interval = setInterval(async () => {
				ticks += 1;
				if (seoAdoptedRef.current || ticks > config.maxTicks) {
					closeWindow();
					return;
				}
				const result = await refreshSeoFromServer();
				if (stopped) return;
				// A null result means the call was skipped or failed, which says
				// nothing about pending state — keep waiting. Only a definite
				// "not pending any more" ends the window early.
				if (result && !result.pending) {
					closeWindow();
				}
			}, config.intervalMs);
		};

		// Waiting out a request that is already open costs nothing (no HTTP is
		// issued), so it is bounded generously — long enough to outlast the
		// abort timeout. That way a hung first request still ends in a real
		// answer instead of leaving the panel permanently unaware that a write
		// was pending (#329).
		const maxWaits =
			Math.ceil(SEO_REFRESH_TIMEOUT_MS / SEO_REFRESH_MIN_GAP_MS) + 2;

		// One re-check, opening the poll window only if something is pending.
		const check = async (attempt = 0, waits = 0) => {
			if (stopped) return;

			// Colliding with a request already in flight is not a failed
			// attempt — wait for it rather than spending a retry.
			if (seoRefreshInFlightRef.current) {
				if (waits < maxWaits) {
					retryTimer = setTimeout(
						() => check(attempt, waits + 1),
						SEO_REFRESH_MIN_GAP_MS
					);
				}
				return;
			}

			const result = await refreshSeoFromServer();
			if (stopped) return;

			if (!result) {
				// Skipped by the min gap, or the request failed — neither says
				// anything about pending state, so try again shortly.
				if (attempt < SEO_REFRESH_MAX_RETRIES) {
					retryTimer = setTimeout(
						() => check(attempt + 1, waits),
						SEO_REFRESH_MIN_GAP_MS
					);
				}
				return;
			}
			if (result.pending) {
				openWindow();
			}
		};

		// Immediate pass — also covers a value that landed between page render
		// and mount.
		check();

		// Publishing is what queues Auto AI, so re-check after each save: the
		// post that had nothing pending at mount may have something now.
		const unsubscribeSaves = subscribeToPostSaves(() => check());

		return () => {
			stopped = true;
			closeWindow();
			if (retryTimer) clearTimeout(retryTimer);
			unsubscribeSaves();
			// Don't leave a request hanging when the editor closes.
			if (seoAbortRef.current) {
				seoAbortRef.current.abort();
				seoAbortRef.current = null;
			}
		};
	}, [postId, refreshSeoFromServer]);

	/**
	 * Mirror the SEO score from the Analysis panel into the header ring.
	 */
	const handleScoreChange = useCallback((score) => {
		const num = Number(score);
		setHeaderScore(
			score === null || score === undefined || Number.isNaN(num)
				? null
				: num
		);
	}, []);

	/**
	 * Apply a robots-meta patch from the Advanced panel.
	 */
	const handleRobotsChange = useCallback((patch) => {
		setFormData((prev) => ({
			...prev,
			...(patch.robotsEnabled !== undefined && {
				thinkrank_robots_meta_enabled: patch.robotsEnabled,
			}),
			...(patch.robotsMeta !== undefined && {
				thinkrank_robots_meta: patch.robotsMeta,
			}),
			...(patch.advancedRobotsMeta !== undefined && {
				thinkrank_advanced_robots_meta: patch.advancedRobotsMeta,
			}),
		}));
	}, []);

	/**
	 * Handle focus keyword list changes. Keeps the legacy single-value field
	 * (thinkrank_focus_keyword) in sync with the primary (first) keyword.
	 * @param keywords
	 */
	const handleFocusKeywordsChange = (keywords) => {
		const list = keywords.slice(0, focusKeywordsCap);
		setFormData((prev) => ({
			...prev,
			thinkrank_focus_keywords: list,
			thinkrank_focus_keyword: list[0] || '',
		}));
	};

	// Holds the focus-keyword text the user has typed but not yet committed with
	// Enter/comma/blur. Kept in a ref (not state) so Save can read the latest
	// value without a stale closure and without triggering re-renders. See #230.
	const pendingFocusKeywordRef = useRef('');
	const handleFocusKeywordDraftChange = useCallback((value) => {
		pendingFocusKeywordRef.current = value || '';
	}, []);

	/**
	 * Return a copy of the form data with any uncommitted focus keyword folded
	 * into the keyword list, so clicking the drawer's Save button (without first
	 * pressing Enter) no longer silently drops the keyword. Returns the original
	 * object unchanged when there is nothing pending. See #230.
	 */
	const withPendingFocusKeyword = useCallback(
		(data) => {
			const pending = (pendingFocusKeywordRef.current || '')
				.trim()
				.replace(/,+$/, '')
				.trim();
			if (!pending) {
				return data;
			}
			const existing = data.thinkrank_focus_keywords || [];
			const isDupe = existing.some(
				(k) => k.toLowerCase() === pending.toLowerCase()
			);
			if (isDupe || existing.length >= focusKeywordsCap) {
				return data;
			}
			const list = [...existing, pending].slice(0, focusKeywordsCap);
			return {
				...data,
				thinkrank_focus_keywords: list,
				thinkrank_focus_keyword: list[0] || '',
			};
		},
		[focusKeywordsCap]
	);

	/**
	 * Mirror the focus keywords into the block editor's post entity. These meta
	 * keys are `show_in_rest`, so without this the editor store keeps a stale
	 * (often empty) copy and a later native "Update"/"Publish" PUTs it back over
	 * the value the drawer just saved. Keeping the entity in sync means the
	 * block editor persists the correct value itself and can't clobber ours.
	 * Best-effort: the admin-ajax write remains authoritative. See #230.
	 * @param data Form data whose focus keywords should be reflected.
	 */
	const syncFocusKeywordsToEditorEntity = useCallback((data) => {
		try {
			if (typeof wp === 'undefined' || !wp.data) {
				return;
			}
			const coreEditor = wp.data.select('core/editor');
			const coreStore = wp.data.dispatch('core');
			if (!coreEditor || !coreStore?.editEntityRecord) {
				return;
			}
			const postType = coreEditor.getCurrentPostType();
			const currentPostId = coreEditor.getCurrentPostId();
			if (!postType || !currentPostId) {
				return;
			}
			coreStore.editEntityRecord('postType', postType, currentPostId, {
				meta: {
					_thinkrank_focus_keywords:
						data.thinkrank_focus_keywords || [],
					_thinkrank_focus_keyword:
						data.thinkrank_focus_keyword || '',
				},
			});
		} catch (e) {
			// Non-fatal: the admin-ajax save below is the authoritative write.
		}
	}, []);

	/**
	 * Mirror an edited permalink slug into the block editor's post entity
	 * before its save, so the editor persists the slug itself and its own UI
	 * (URL panel, permalink preview, View link) reflects the change
	 * immediately. Without this the slug was written server-side behind the
	 * editor's back (metabox-loader → maybe_update_slug), the store kept the
	 * old post_name, and the editor kept displaying the old URL until a full
	 * reload — which reads as "the slug didn't change". Same pattern as the
	 * focus-keywords entity sync above.
	 * Best-effort: the server-side write remains as the fallback.
	 * @param data Form data whose slug should be reflected.
	 */
	const syncSlugToEditorEntity = useCallback((data) => {
		try {
			if (typeof wp === 'undefined' || !wp.data) {
				return;
			}
			const desired = cleanForSlug(
				String(data.thinkrank_post_slug || '').trim()
			);
			if (!desired) {
				// Blank means "keep the auto-generated slug" — never push it.
				return;
			}
			const coreEditor = wp.data.select('core/editor');
			const coreStore = wp.data.dispatch('core');
			if (!coreEditor || !coreStore?.editEntityRecord) {
				return;
			}
			const postType = coreEditor.getCurrentPostType();
			const currentPostId = coreEditor.getCurrentPostId();
			if (!postType || !currentPostId) {
				return;
			}
			if (coreEditor.getEditedPostAttribute('slug') === desired) {
				return;
			}
			coreStore.editEntityRecord('postType', postType, currentPostId, {
				slug: desired,
			});
		} catch (e) {
			// Non-fatal: the metabox-loader server write still lands the slug.
		}
	}, []);

	/**
	 * Show success notice
	 * @param message
	 */
	const showSuccess = (message) => {
		const notice = {
			id: Date.now(),
			type: 'success',
			message,
		};
		setNotices((prev) => [...prev, notice]);

		// Auto-remove after 5 seconds
		setTimeout(() => {
			setNotices((prev) => prev.filter((n) => n.id !== notice.id));
		}, 5000);
	};

	/**
	 * Show error notice
	 * @param message
	 */
	const showError = (message) => {
		const notice = {
			id: Date.now(),
			type: 'error',
			message,
		};
		setNotices((prev) => [...prev, notice]);

		// Auto-remove after 8 seconds
		setTimeout(() => {
			setNotices((prev) => prev.filter((n) => n.id !== notice.id));
		}, 8000);
	};

	/**
	 * Extract content from WordPress editor (same method as schema tab)
	 */
	const getPostContent = () => {
		let content = '';
		let htmlContent = '';

		// Host-provided resolver (e.g. Elementor reads its preview iframe). Use
		// it first; fall through to the standard sources when it yields nothing.
		if (typeof contentResolver === 'function') {
			const resolved = contentResolver();
			if (resolved) {
				return String(resolved).trim();
			}
		}

		// Try to get content from Block Editor first
		if (
			typeof wp !== 'undefined' &&
			wp.data &&
			wp.data.select('core/editor')
		) {
			try {
				const blockContent = wp.data
					.select('core/editor')
					.getEditedPostContent();
				if (blockContent) {
					htmlContent = blockContent;
				}
			} catch (e) {
				// Block editor not available, try classic editor
			}
		}

		// Fallback to TinyMCE (Classic Editor)
		if (!htmlContent && typeof tinymce !== 'undefined') {
			const editor = tinymce.get('content');
			if (editor && !editor.isHidden()) {
				htmlContent = editor.getContent();
			}
		}

		// Fallback to textarea
		if (!htmlContent) {
			const contentTextarea = document.getElementById('content');
			if (contentTextarea) {
				htmlContent = contentTextarea.value || '';
			}
		}

		// Builder markup hides its words in block-attribute JSON inside an HTML
		// comment, so reading textContent off it yields nothing. The resolver
		// only reads those attributes when the markup has no text of its own,
		// so a FAQ block on an ordinary post does not replace the article.
		if (htmlContent) {
			content = resolveEditorContent(htmlContent).text;
		}

		// Fallback to contentPreview if no editor content found
		if (!content && contentPreview) {
			content = contentPreview;
		}

		// Final fallback: try to get post title if no content
		if (!content) {
			const titleField = document.getElementById('title');
			if (titleField && titleField.value) {
				content = titleField.value;
			}
		}

		return content.trim();
	};

	/**
	 * Extract the raw editor HTML for server-side live analysis. Mirrors
	 * getPostContent's sources but keeps the markup (headings, links, images)
	 * that the score calculator inspects.
	 */
	const getPostContentHtml = () => {
		if (typeof contentResolver === 'function') {
			const resolved = contentResolver();
			if (resolved) {
				return String(resolved).trim();
			}
		}

		let htmlContent = '';

		if (
			typeof wp !== 'undefined' &&
			wp.data &&
			wp.data.select('core/editor')
		) {
			try {
				htmlContent =
					wp.data.select('core/editor').getEditedPostContent() || '';
			} catch (e) {
				// Block editor not available, try classic editor
			}
		}

		if (!htmlContent && typeof tinymce !== 'undefined') {
			const editor = tinymce.get('content');
			if (editor && !editor.isHidden()) {
				htmlContent = editor.getContent();
			}
		}

		if (!htmlContent) {
			htmlContent = document.getElementById('content')?.value || '';
		}

		// Builder markup (Divi 5 and friends) keeps its words in block
		// attribute JSON inside an HTML comment, so the scorer would read it
		// as zero words. Hand over the extracted text instead — but only when
		// the markup really has no text, or a single attribute-carrying block
		// would be all the scorer ever sees of the page.
		return resolveEditorContent(htmlContent).html.trim();
	};

	/**
	 * Handle AI generation
	 */
	const handleGenerateAI = useCallback(async () => {
		// Extract current post content
		const content = getPostContent();

		if (!content) {
			showError(
				'No content found to analyze. Please add some content to your post and try again.'
			);
			return;
		}

		// Minimum content length check
		if (content.length < 50) {
			showError(
				'Content is too short for AI analysis. Please add more content (at least 50 characters).'
			);
			return;
		}

		setIsGenerating(true);

		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/ai/generate-metadata',
				method: 'POST',
				data: {
					content,
					target_keyword: formData.thinkrank_focus_keyword,
					content_type: formData.thinkrank_content_type,
					tone: formData.thinkrank_tone,
				},
			});

			if (response.success) {
				// Update form data with AI-generated content
				setFormData((prev) => ({
					...prev,
					thinkrank_seo_title:
						response.data.title || prev.thinkrank_seo_title,
					thinkrank_meta_description:
						response.data.description ||
						prev.thinkrank_meta_description,
					thinkrank_focus_keyword:
						response.data.focus_keyword ||
						prev.thinkrank_focus_keyword,
				}));

				showSuccess('AI metadata generated successfully!');
			} else {
				showError(response.message || 'Failed to generate AI metadata');
			}
		} catch (error) {
			showError(error.message || 'Failed to generate AI metadata');
		} finally {
			setIsGenerating(false);
		}
	}, [
		formData.thinkrank_focus_keyword,
		formData.thinkrank_content_type,
		formData.thinkrank_tone,
		showError,
		showSuccess,
	]);

	/**
	 * Auto-save form data to local storage
	 */
	const autoSave = useCallback(() => {
		if (!postId) return;

		try {
			// Server-owned fields (focus keywords, the redirect) are left out:
			// they change without bumping post_modified, so the staleness check
			// below cannot catch a stale copy. See draftFields.js. Other
			// in-progress fields (title, description, …) still recover.
			const draftFormData = withoutServerOwnedFields(formData);

			const saveData = {
				formData: draftFormData,
				timestamp: Date.now(),
				postId,
			};
			localStorage.setItem(
				`thinkrank_metabox_${postId}`,
				JSON.stringify(saveData)
			);
			setLastSaved(new Date());
		} catch (error) {
			// Local storage failed - not critical
		}
	}, [formData, postId]);

	/**
	 * Save the SEO fields from the drawer header.
	 *
	 * The fields are written directly through the metabox AJAX route rather than
	 * riding along on the editor's save. Delegating to `savePost()` alone was
	 * unreliable: ThinkRank's fields live in this component's state, which
	 * Gutenberg's dirty-tracking knows nothing about, so a post with no content
	 * edits was never dirty and `savePost()` resolved without persisting
	 * anything — the drawer reported nothing and the SEO changes were silently
	 * lost.
	 *
	 * Ordering is deliberate. Several ThinkRank meta keys are `show_in_rest`, so
	 * the block editor's own save can carry stale copies of them; running our
	 * write *after* `savePost()` makes ThinkRank the last writer and keeps the
	 * drawer authoritative over its own fields. The classic editor reloads on
	 * submit, so there the write has to finish first.
	 */
	const handleSave = useCallback(async () => {
		// Fold in any focus keyword typed but not yet committed with Enter/comma,
		// so the Save button can't silently drop it (#230). Update state too so
		// the chip appears after saving.
		const dataToSave = withPendingFocusKeyword(formData);
		if (dataToSave !== formData) {
			setFormData(dataToSave);
			pendingFocusKeywordRef.current = '';
		}

		// Flush the in-progress local draft before saving.
		autoSave();
		setIsSaving(true);

		// Host-provided save path (e.g. Elementor/Divi/Oxygen, which have no
		// #post form to submit). Persists the fields and reports its own result.
		if (typeof saveHandler === 'function') {
			try {
				await saveHandler(dataToSave);
			} finally {
				setIsSaving(false);
			}
			return;
		}

		const editor =
			typeof wp !== 'undefined' && wp.data
				? wp.data.dispatch('core/editor')
				: null;
		const isBlockEditor = !!(
			editor && typeof editor.savePost === 'function'
		);

		try {
			// Block editor: let the editor persist post content first, then
			// write our fields last so they win.
			if (isBlockEditor) {
				// Sync focus keywords into the editor entity before its save so
				// the block editor persists them itself and a later native
				// "Update" can't PUT a stale copy back over ours (#230).
				syncFocusKeywordsToEditorEntity(dataToSave);
				// Slug rides the editor's own save too, so the URL panel and
				// permalink preview update the moment the save completes.
				syncSlugToEditorEntity(dataToSave);
				try {
					await editor.savePost();
				} catch (e) {
					// A failed/cancelled post save shouldn't block the SEO write.
				}
			}

			const result = await persistSeoFields(dataToSave);

			if (!result.success) {
				showError(result.message);
				return;
			}

			setLastSaved(new Date());

			// The slug WordPress actually stored can differ from what was typed
			// (sanitization, collision de-dupe). Reflect the final value in the
			// drawer so what the user sees is what the URL really is.
			if (isBlockEditor) {
				try {
					const finalSlug = wp.data
						.select('core/editor')
						.getCurrentPostAttribute('slug');
					if (
						finalSlug &&
						String(dataToSave.thinkrank_post_slug || '').trim() &&
						finalSlug !== dataToSave.thinkrank_post_slug
					) {
						setFormData((prev) => ({
							...prev,
							thinkrank_post_slug: finalSlug,
						}));
					}
				} catch (e) {
					// Display-only refinement; the save itself succeeded.
				}
			}

			// Classic editor: the fields are now stored, so submitting the form
			// persists the post itself. The page reloads, which is its own
			// confirmation — no notice needed (or possible).
			if (!isBlockEditor) {
				const submitBtn =
					document.getElementById('publish') ||
					document.getElementById('save-post');
				if (submitBtn) {
					submitBtn.click();
					return;
				}

				const form = document.getElementById('post');
				if (form) {
					form.submit();
					return;
				}
			}

			showSuccess(result.message);
		} finally {
			setIsSaving(false);
		}
	}, [
		autoSave,
		saveHandler,
		formData,
		showError,
		showSuccess,
		withPendingFocusKeyword,
		syncFocusKeywordsToEditorEntity,
		syncSlugToEditorEntity,
	]);

	/**
	 * Load saved data from local storage
	 */
	const loadSavedData = useCallback(() => {
		if (!postId) return;

		try {
			const saved = localStorage.getItem(`thinkrank_metabox_${postId}`);
			if (saved) {
				const saveData = JSON.parse(saved);
				// Only load if data is less than 24 hours old AND post hasn't been modified on server since
				const isRecent =
					Date.now() - saveData.timestamp < 24 * 60 * 60 * 1000;

				// If we have a post modified date from server, check if local storage is stale
				let isStale = false;
				if (postModified) {
					const serverModifiedTime = new Date(postModified).getTime();
					// If server modified time is NEWER than local storage save time, data is stale
					if (serverModifiedTime > saveData.timestamp) {
						isStale = true;
					}
				}

				if (isRecent && !isStale) {
					// Never restore server-owned fields from a draft (see
					// autoSave). Strip them here too, since a draft written
					// before this guard still carries them.
					const restoredFormData = withoutServerOwnedFields(
						saveData.formData
					);
					setFormData((prev) => ({ ...prev, ...restoredFormData }));
					setLastSaved(new Date(saveData.timestamp));
				}
			}
		} catch (error) {
			// Failed to load - not critical
		}
	}, [postId]);

	/**
	 * Simple API cache helper
	 */
	const getCachedResponse = useCallback((cacheKey) => {
		const cached = apiCache.current.get(cacheKey);
		if (cached && Date.now() - cached.timestamp < 5 * 60 * 1000) {
			// 5 minutes
			return cached.data;
		}
		return null;
	}, []);

	const setCachedResponse = useCallback((cacheKey, data) => {
		apiCache.current.set(cacheKey, {
			data,
			timestamp: Date.now(),
		});
	}, []);

	/**
	 * Handle content analysis
	 */
	const handleAnalyzeContent = useCallback(async () => {
		if (!postId) {
			showError('Post ID is required for content analysis');
			return;
		}

		// Read the live editor content so analysis reflects unsaved changes
		// (without it the endpoint falls back to the saved DB content).
		const liveContent = getPostContentHtml();
		const liveTitle = formData.thinkrank_seo_title || '';
		const liveDescription = formData.thinkrank_meta_description || '';

		// Fingerprint every scored input into the cache key so any edit busts
		// the cache. The title and description belong here as much as the body
		// does: they are scored factors, so keying on content alone served a
		// stale score for five minutes after applying a new title — which read
		// as the score changing on its own once the entry expired.
		const cacheKey = [
			'seo-analysis',
			postId,
			(formData.thinkrank_focus_keywords || []).join('|'),
			`${liveContent.length}:${hashString(liveContent)}`,
			hashString(liveTitle),
			hashString(liveDescription),
			hashString(formData.thinkrank_post_slug || ''),
		].join('-');
		const cachedResponse = getCachedResponse(cacheKey);

		if (cachedResponse) {
			setAnalysisData(cachedResponse);
			setShowAnalysisResults(true);
			if (cachedResponse.score_breakdown) {
				setShowScoreBreakdown(true);
			}
			showSuccess('SEO analysis loaded from cache!');
			return;
		}

		setIsAnalyzing(true);

		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/seo-score/calculate',
				method: 'POST',
				data: {
					post_id: postId,
					target_keyword: formData.thinkrank_focus_keyword,
					target_keywords: formData.thinkrank_focus_keywords,
					save_score: true,
					live_content: liveContent,
					// Score the fields as they stand in the editor, not as they
					// were last saved.
					live_title: liveTitle,
					live_description: liveDescription,
					// Same reason: score the permalink the user is looking at,
					// so the "keyword in slug" check reflects an unsaved edit.
					live_slug: formData.thinkrank_post_slug || '',
				},
			});

			if (response.success) {
				// Cache the response
				setCachedResponse(cacheKey, response.data);

				setAnalysisData(response.data);
				setShowAnalysisResults(true);
				if (response.data.score_breakdown) {
					setShowScoreBreakdown(true);
				}
				showSuccess('SEO analysis completed successfully!');
			} else {
				showError(response.message || 'Failed to analyze content');
			}
		} catch (error) {
			showError('Failed to calculate SEO score');
		} finally {
			setIsAnalyzing(false);
		}
	}, [
		postId,
		formData.thinkrank_focus_keyword,
		formData.thinkrank_focus_keywords,
		formData.thinkrank_seo_title,
		formData.thinkrank_meta_description,
		getCachedResponse,
		setCachedResponse,
		showError,
		showSuccess,
	]);

	/**
	 * Update SEO score display
	 * @param score
	 */
	const updateSEOScore = (score) => {
		// This will be handled in the analysis results component
		if (analysisData) {
			setAnalysisData((prev) => ({
				...prev,
				overall_score: score,
			}));
		}
	};

	// Load saved data on component mount
	useEffect(() => {
		loadSavedData();
	}, [loadSavedData]);

	// Auto-save form data when it changes (debounced)
	useEffect(() => {
		const timer = setTimeout(() => {
			autoSave();
		}, 2000); // Save 2 seconds after last change

		return () => clearTimeout(timer);
	}, [formData, autoSave]);

	// Classic Editor: mirror WordPress's own permalink field into our state.
	//
	// The server-side guard (thinkrank_post_slug_baseline) is what makes the
	// slug survive; this only stops our Permalink field showing a stale value
	// after the user edited WordPress's one. The block editor gets the same
	// reconciliation from syncSlugToEditorEntity() and the post-save refresh,
	// neither of which can run without wp.data — which is why Classic was
	// left uncovered (#441).
	//
	// Best-effort DOM coupling against core markup: every lookup is optional
	// and nothing here throws if the permalink box is absent (a post type
	// with no rewrite, a draft with no slug yet, a future core change).
	useEffect(() => {
		if (typeof wp !== 'undefined' && wp.data?.select?.('core/editor')) {
			return undefined;
		}

		// `#post_name` is the hidden field core's inline permalink editor
		// writes on OK; `#new-post-slug` is the visible input while editing.
		const hidden = document.getElementById('post_name');
		const visible = () => document.getElementById('new-post-slug');

		const adopt = (value) => {
			const slug = String(value || '').trim();
			if (!slug) {
				return;
			}
			setFormData((prev) =>
				prev.thinkrank_post_slug === slug
					? prev
					: { ...prev, thinkrank_post_slug: slug }
			);
		};

		// Core updates #post_name with .val(), which fires no event, so the
		// OK button is what we listen to. The input covers typing directly.
		const onClick = (event) => {
			if (!event.target?.closest?.('#edit-slug-buttons .save')) {
				return;
			}
			// Let core's handler write #post_name first.
			window.setTimeout(
				() => adopt(hidden?.value ?? visible()?.value),
				0
			);
		};

		const onInput = (event) => {
			if (event.target?.id === 'new-post-slug') {
				adopt(event.target.value);
			}
		};

		document.addEventListener('click', onClick, true);
		document.addEventListener('change', onInput, true);

		return () => {
			document.removeEventListener('click', onClick, true);
			document.removeEventListener('change', onInput, true);
		};
	}, []);

	// Save data before page unload
	useEffect(() => {
		const handleBeforeUnload = () => {
			autoSave();
		};

		window.addEventListener('beforeunload', handleBeforeUnload);
		return () =>
			window.removeEventListener('beforeunload', handleBeforeUnload);
	}, [autoSave]);

	// Allow a host outside this component (e.g. Elementor's top-bar launcher) to
	// open/close the drawer without rendering its own launcher button.
	useEffect(() => {
		const onToggle = () => setIsOpen((prev) => !prev);
		const onPinFailed = () => setPinFailed(true);
		// The pinned icon mounted (possibly after a pin-failed misfire on a slow
		// load) — drop the fallback launcher so only one trigger shows.
		const onPinRendered = () => setPinFailed(false);
		window.addEventListener('thinkrank:toggle-seo-drawer', onToggle);
		window.addEventListener('thinkrank:pin-failed', onPinFailed);
		window.addEventListener('thinkrank:pin-rendered', onPinRendered);
		return () => {
			window.removeEventListener('thinkrank:toggle-seo-drawer', onToggle);
			window.removeEventListener('thinkrank:pin-failed', onPinFailed);
			window.removeEventListener('thinkrank:pin-rendered', onPinRendered);
		};
	}, []);

	const showNotice = (message, type = 'info') => {
		if (type === 'success') showSuccess(message);
		else if (type === 'error') showError(message);
	};

	// Score for the launcher + persistent header ring. Prefer the live score
	// reported by the Analysis panel; fall back to the persisted score. 0/empty =
	// "not analyzed yet".
	//
	// The persisted score comes from the scores table (localized on page load) —
	// the same source the posts list column reads — so the badge matches it
	// immediately instead of waiting for the Analysis panel to mount and fetch.
	// The `thinkrank_seo_score` form field is kept only as a secondary source: it
	// is written solely by the AI analyze flow and is reset to 0 on every save.
	const persistedScore = Number(window.thinkrankMetabox?.seoScore);
	const savedScore = Number(existingMetadata.seo_score);
	let fallbackScore = null;
	if (Number.isFinite(persistedScore) && persistedScore > 0) {
		fallbackScore = persistedScore;
	} else if (Number.isFinite(savedScore) && savedScore > 0) {
		fallbackScore = savedScore;
	}
	const scoreForHeader = headerScore !== null ? headerScore : fallbackScore;

	// Broadcast the score so the pinned editor-header launcher (a separate React
	// tree registered via the plugin API) can mirror it as a badge, and stash it
	// on a global so a late-mounting launcher can read the initial value.
	useEffect(() => {
		window.thinkrankLatestScore = scoreForHeader;
		window.dispatchEvent(
			new CustomEvent('thinkrank:score-updated', {
				detail: { score: scoreForHeader },
			})
		);
	}, [scoreForHeader]);

	// Keep the header ring live on every tab. The Analysis panel re-scores a
	// title, description or keyword edit, but it only exists while its tab is
	// open, so the same edit made on the General tab left the ring (and the
	// launcher badge mirroring it) on the old number until the writer opened
	// Analysis. Away from that tab the drawer re-scores the edit itself, through
	// the request the panel makes, and the panel reads the result from the
	// shared session record when it mounts instead of scoring the text again.
	//
	// Only once a score exists, as on the Analysis tab, and never for the fields
	// as they were loaded: the score on screen already belongs to those, so
	// opening the editor costs no request.
	const liveTitle = formData.thinkrank_seo_title || '';
	const liveDescription = formData.thinkrank_meta_description || '';
	const liveKeywords = useMemo(
		() =>
			formData.thinkrank_focus_keywords?.length
				? formData.thinkrank_focus_keywords
				: [formData.thinkrank_focus_keyword].filter(Boolean),
		[formData.thinkrank_focus_keywords, formData.thinkrank_focus_keyword]
	);
	const liveFieldsKey = fieldsKeyOf(liveTitle, liveDescription);
	const liveKeywordsKey = keywordsKeyOf(liveKeywords);
	const loadedFieldsRef = useRef({
		key: liveFieldsKey,
		keywords: liveKeywordsKey,
	});
	const hasHeaderScore = scoreForHeader !== null;
	useEffect(() => {
		if (activeTab === 'analysis' || !postId || !hasHeaderScore) {
			return undefined;
		}
		const scored = getSessionScore(postId) || loadedFieldsRef.current;
		if (
			scored.key === liveFieldsKey &&
			scored.keywords === liveKeywordsKey
		) {
			return undefined;
		}
		const timer = setTimeout(async () => {
			const isSuperseded = beginScoreRequest(postId);
			try {
				const response = await requestLiveScore({
					postId,
					keywords: liveKeywords,
					seoTitle: liveTitle,
					metaDescription: liveDescription,
					saveScore: false,
					isSuperseded,
				});
				if (!isSuperseded() && response?.success) {
					handleScoreChange(response.data?.overall_score);
				}
			} catch (e) {
				// The ring keeps its last score; Analysis still recalculates.
			}
		}, 1500);
		return () => clearTimeout(timer);
	}, [
		activeTab,
		postId,
		hasHeaderScore,
		liveTitle,
		liveDescription,
		liveKeywords,
		liveFieldsKey,
		liveKeywordsKey,
		handleScoreChange,
	]);

	return (
		<>
			{/* In-flow card for the "ThinkRank SEO" metabox. The drawer below is
			    fixed-position, so without this the metabox body is empty and
			    toggling the panel in Screen Options looks broken. */}
			{showPanelCard && (
				<div className="tr-root tr-font-sans thinkrank-metabox-panel-card">
					<div className="thinkrank-metabox-panel-card-text">
						<strong>
							{__(
								'Optimize this content for search & AI',
								'thinkrank'
							)}
						</strong>
						<span>
							{__(
								'Titles, meta description, focus keywords, schema and social previews.',
								'thinkrank'
							)}
						</span>
					</div>
					<div className="thinkrank-metabox-panel-card-actions">
						{scoreForHeader !== null && (
							<span
								className="thinkrank-metabox-panel-card-score"
								title={__('Current SEO score', 'thinkrank')}
							>
								{scoreForHeader}
								<small>/100</small>
							</span>
						)}
						<button
							type="button"
							className="button button-primary"
							onClick={() => setIsOpen(true)}
						>
							{__('Open ThinkRank SEO', 'thinkrank')}
						</button>
					</div>
				</div>
			)}

			{/* ===== Revamped UI: floating logo launcher + bottom drawer ===== */}
			<MetaboxDrawer
				open={isOpen}
				onToggle={() => setIsOpen((prev) => !prev)}
				onClose={() => setIsOpen(false)}
				onSave={handleSave}
				isSaving={isSaving}
				score={scoreForHeader}
				hideLauncher={hideLauncher && !pinFailed}
			>
				<div className="tr-root tr-font-sans tr-flex tr-flex-col md:tr-flex-row tr-h-full tr-min-h-0">
					{/* Left vertical navigation — fixed (does not scroll with the
					    content); only the content column on the right scrolls. */}
					<nav className="tr-flex md:tr-flex-col tr-gap-1 tr-p-3 tr-overflow-x-auto md:tr-overflow-x-visible md:tr-w-56 md:tr-shrink-0 tr-border-b md:tr-border-b-0 md:tr-border-r tr-border-secondary">
						{TABS.map((tab) => {
							const Icon = tab.icon;
							return (
								<button
									key={tab.id}
									type="button"
									onClick={() => setActiveTab(tab.id)}
									className={classnames(
										'tr-flex tr-items-center tr-gap-2 tr-text-left tr-px-3 tr-py-2.5 tr-rounded-lg tr-text-sm tr-font-medium tr-whitespace-nowrap tr-transition-colors tr-border-0 tr-cursor-pointer',
										'focus:tr-outline-none',
										activeTab === tab.id
											? 'tr-bg-brand-bg tr-text-brand'
											: 'tr-bg-transparent tr-text-tertiary hover:tr-text-secondary hover:tr-bg-line-2'
									)}
								>
									<Icon className="tr-w-4 tr-h-4 tr-shrink-0" />
									{tab.label}
								</button>
							);
						})}
					</nav>

					{/* Content column — the only scrollable region */}
					<div
						className="tr-flex-1 tr-min-w-0 tr-min-h-0 tr-overflow-y-auto tr-p-5 tr-bg-[#F6F7FB]"
						style={{ scrollbarGutter: 'stable' }}
					>
						{/* Notices */}
						{notices.length > 0 && (
							<div className="tr-flex tr-flex-col tr-gap-2 tr-mb-4">
								{notices.map((notice) => (
									<div
										key={notice.id}
										className={classnames(
											'tr-flex tr-items-start tr-gap-2 tr-rounded tr-p-3 tr-text-sm',
											notice.type === 'success'
												? 'tr-bg-success-bg tr-text-success'
												: 'tr-bg-danger-bg tr-text-danger'
										)}
									>
										{linkifyMessage(notice.message)}
									</div>
								))}
							</div>
						)}

						{/* Tab content */}
						{activeTab === 'general' && (
							<ErrorBoundary
								fallbackMessage={__(
									'The General tab encountered an error.',
									'thinkrank'
								)}
							>
								<GeneralPanel
									formData={formData}
									onFieldChange={handleFieldChange}
									onFocusKeywordsChange={
										handleFocusKeywordsChange
									}
									onFocusKeywordDraftChange={
										handleFocusKeywordDraftChange
									}
									focusKeywordsMax={focusKeywordsMax}
									postTitle={postTitle}
									homeUrl={homeUrl}
									patternPreviews={patternPreviews}
									patternVariables={patternVariables}
									customFieldStatus={customFieldStatus}
									customFieldError={customFieldError}
									onRetryCustomFields={retryCustomFields}
								/>
							</ErrorBoundary>
						)}
						{activeTab === 'analysis' && (
							<ErrorBoundary
								fallbackMessage={__(
									'The Analysis tab encountered an error.',
									'thinkrank'
								)}
							>
								<AnalysisPanel
									postId={postId}
									formData={formData}
									onFieldChange={handleFieldChange}
									onGenerate={handleGenerateAI}
									isGenerating={isGenerating}
									onAnalyze={handleAnalyzeContent}
									isAnalyzing={isAnalyzing}
									strings={strings}
									scoreData={
										showAnalysisResults && analysisData
											? analysisData
											: null
									}
									postTitle={postTitle}
									contentPreview={contentPreview}
									contentFull={contentFull}
									onScoreChange={handleScoreChange}
								/>
							</ErrorBoundary>
						)}
						{activeTab === 'schema' && (
							<ErrorBoundary
								fallbackMessage={__(
									'The Schema tab encountered an error.',
									'thinkrank'
								)}
							>
								<MetaboxSchemaTab
									postId={postId}
									postTitle={postTitle}
									contentPreview={contentPreview}
									contentFull={contentFull}
									formData={formData}
									showNotice={showNotice}
								/>
							</ErrorBoundary>
						)}
						{activeTab === 'social' && (
							<ErrorBoundary
								fallbackMessage={__(
									'The Social tab encountered an error.',
									'thinkrank'
								)}
							>
								<SocialTab
									postId={postId}
									postTitle={postTitle}
									formData={formData}
									onChange={handleFieldChange}
									showNotice={showNotice}
									patternPreviews={patternPreviews}
									patternVariables={patternVariables}
									customFieldStatus={customFieldStatus}
									customFieldError={customFieldError}
									onRetryCustomFields={retryCustomFields}
								/>
							</ErrorBoundary>
						)}
						{activeTab === 'advanced' && (
							<ErrorBoundary
								fallbackMessage={__(
									'The Advanced tab encountered an error.',
									'thinkrank'
								)}
							>
								<AdvancedPanel
									formData={formData}
									onFieldChange={handleFieldChange}
									onRobotsChange={handleRobotsChange}
									linkSuggestionsEnabled={
										linkSuggestionsEnabled
									}
									postId={postId}
									redirectSupported={
										!!metaboxConfig.redirectSupported
									}
									redirectTypes={
										metaboxConfig.redirectTypes || [
											301, 302, 307,
										]
									}
								/>
							</ErrorBoundary>
						)}
					</div>
				</div>
			</MetaboxDrawer>

			{/* ===== Hidden WP form fields — MUST stay inside #post for save =====
			    Rendered directly into #thinkrank-metabox-container (in the post
			    form), NOT into the portaled drawer, so they submit on save. */}
			<input
				type="hidden"
				name="thinkrank_seo_title"
				value={formData.thinkrank_seo_title || ''}
			/>
			<input
				type="hidden"
				name="thinkrank_meta_description"
				value={formData.thinkrank_meta_description || ''}
			/>
			<input
				type="hidden"
				name="thinkrank_canonical_url"
				value={formData.thinkrank_canonical_url || ''}
			/>
			{/* Posted only when a provider can store it. On a site without Pro
			    the key is absent from $_POST entirely, which is what tells the
			    server "the redirect field was not submitted" rather than "the
			    editor cleared it" — otherwise one save with Pro deactivated
			    would wipe every redirect it had stored. */}
			{!!metaboxConfig.redirectSupported && (
				<>
					<input
						type="hidden"
						name="thinkrank_redirect_url"
						value={formData.thinkrank_redirect_url || ''}
					/>
					<input
						type="hidden"
						name="thinkrank_redirect_type"
						value={formData.thinkrank_redirect_type || 301}
					/>
				</>
			)}
			<input
				type="hidden"
				name="thinkrank_post_slug"
				value={formData.thinkrank_post_slug || ''}
			/>
			{/* What the field above was RENDERED with, never edited. The
			    server writes the slug only when the two differ — otherwise a
			    user who edited WordPress's own permalink field instead had
			    this untouched snapshot overwrite it on save (#441). */}
			<input
				type="hidden"
				name="thinkrank_post_slug_baseline"
				value={metaboxConfig.postSlug || ''}
			/>
			<input
				type="hidden"
				name="thinkrank_focus_keyword"
				value={formData.thinkrank_focus_keyword || ''}
			/>
			<input
				type="hidden"
				name="thinkrank_focus_keywords"
				value={JSON.stringify(formData.thinkrank_focus_keywords || [])}
			/>
			<input
				type="hidden"
				name="thinkrank_seo_score"
				value={analysisData?.overall_score || '0'}
			/>
			<input
				type="hidden"
				name="thinkrank_generated_at"
				value={existingMetadata.generated_at || ''}
			/>
			<input
				type="hidden"
				name="thinkrank_pillar_content"
				value={formData.thinkrank_pillar_content ? '1' : '0'}
			/>
			<input
				type="hidden"
				name="thinkrank_exclude_from_search"
				value={formData.thinkrank_exclude_from_search ? '1' : '0'}
			/>
			<input
				type="hidden"
				name="thinkrank_exclude_from_archives"
				value={formData.thinkrank_exclude_from_archives ? '1' : '0'}
			/>
			<input
				type="hidden"
				name="thinkrank_robots_meta_enabled"
				value={formData.thinkrank_robots_meta_enabled ? '1' : '0'}
			/>
			<input
				type="hidden"
				name="thinkrank_robots_meta"
				value={JSON.stringify(formData.thinkrank_robots_meta || {})}
			/>
			<input
				type="hidden"
				name="thinkrank_advanced_robots_meta"
				value={JSON.stringify(
					formData.thinkrank_advanced_robots_meta || {}
				)}
			/>
			<input
				type="hidden"
				name="thinkrank_og_title"
				value={formData.thinkrank_og_title || ''}
			/>
			<input
				type="hidden"
				name="thinkrank_og_description"
				value={formData.thinkrank_og_description || ''}
			/>
			<input
				type="hidden"
				name="thinkrank_og_image"
				value={formData.thinkrank_og_image || ''}
			/>
			<input
				type="hidden"
				name="thinkrank_twitter_title"
				value={formData.thinkrank_twitter_title || ''}
			/>
			<input
				type="hidden"
				name="thinkrank_twitter_description"
				value={formData.thinkrank_twitter_description || ''}
			/>
			<input
				type="hidden"
				name="thinkrank_twitter_image"
				value={formData.thinkrank_twitter_image || ''}
			/>
			<textarea
				ref={contentPreviewRef}
				id="thinkrank_content_preview"
				style={{ display: 'none' }}
				defaultValue={contentPreview}
			/>
		</>
	);
};

// Memoize the component to prevent unnecessary re-renders
export default memo(MetaboxApp);
