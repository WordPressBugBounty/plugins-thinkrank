import { useState, useEffect, useCallback } from '@wordpress/element';

import SidebarNavigation, {
	canSeeSection,
	getNavigationStructure,
} from '../essential-seo/SidebarNavigation';
import ContentArea from '../essential-seo/ContentArea';
import { Alert } from '../ui';

const DEFAULT_SECTION = 'site-identity';
const DEFAULT_ITEM = 'basic-info';

/**
 * Resolve a section/item the current role is actually allowed to see.
 *
 * The requested section (default or from the URL) may be one this role has no
 * capability for — in that case fall back to the first accessible section so a
 * user never lands on a section the Role Manager hides.
 *
 * @param {string} sectionId Requested section id.
 * @param {string} itemId    Requested item id.
 * @return {[string, string]} Allowed [sectionId, itemId].
 */
const resolveAccessibleSection = (sectionId, itemId) => {
	if (canSeeSection(sectionId)) {
		return [sectionId, itemId];
	}
	const firstAllowed = getNavigationStructure().find((section) =>
		canSeeSection(section.id)
	);
	if (firstAllowed) {
		const firstItem = firstAllowed.items?.[0]?.id || null;
		return [firstAllowed.id, firstItem];
	}
	// No accessible sections — leave the request as-is; the REST gate still protects data.
	return [sectionId, itemId];
};

/**
 * Update the URL's nav_section / nav_item params without adding a history entry.
 * Also clears the one-shot OAuth callback param so subsequent reloads don't
 * keep re-redirecting to Google Services.
 * @param sectionId
 * @param itemId
 */
const writeNavToUrl = (sectionId, itemId) => {
	try {
		const url = new URL(window.location.href);
		url.searchParams.set('nav_section', sectionId);
		url.searchParams.set('nav_item', itemId);
		url.searchParams.delete('type');
		window.history.replaceState({}, '', url.toString());
	} catch (e) {
		// URL API failure shouldn't break navigation
	}
};

const EssentialSEO = () => {
	const [initialSection, initialItem] = resolveAccessibleSection(
		DEFAULT_SECTION,
		DEFAULT_ITEM
	);
	const [activeSection, setActiveSection] = useState(initialSection);
	const [activeItem, setActiveItem] = useState(initialItem);
	const [error, setError] = useState(null);

	// Restore section/item from the URL on mount and normalize OAuth callback param
	useEffect(() => {
		try {
			const params = new URLSearchParams(window.location.search);
			let section = DEFAULT_SECTION;
			let item = DEFAULT_ITEM;

			if (params.get('type') === 'google_analytics') {
				section = 'integrations';
				item = 'google-services';
			} else if (params.get('nav_section') && params.get('nav_item')) {
				section = params.get('nav_section');
				item = params.get('nav_item');
			}

			// Never land on a section this role can't access (e.g. a stale URL
			// or the default) — fall back to the first accessible section.
			[section, item] = resolveAccessibleSection(section, item);

			setActiveSection(section);
			setActiveItem(item);

			// Normalize the URL so reloads always carry nav_section/nav_item and not transient OAuth params
			writeNavToUrl(section, item);
		} catch (e) {
			setError(e.message);
		}
	}, []);

	const handleNavigate = useCallback((sectionId, itemId) => {
		setActiveSection(sectionId);
		setActiveItem(itemId);
		writeNavToUrl(sectionId, itemId);
	}, []);

	// Listen for search navigation events dispatched by NavSearch in TopNav.
	useEffect(() => {
		const handler = (e) => {
			const { section, item } = e.detail || {};
			if (section && item) {
				handleNavigate(section, item);
			}
		};
		window.addEventListener('thinkrank:nav-search', handler);
		return () =>
			window.removeEventListener('thinkrank:nav-search', handler);
	}, [handleNavigate]);

	return (
		<div className="tr-flex tr-flex-col md:tr-flex-row tr-gap-4 lg:tr-gap-6 tr-items-start">
			{/* Settings sidebar.
			    The outer column stretches to the row height (md:tr-self-stretch)
			    and the sticky lives on an inner wrapper pinned with top:0. A
			    sticky element gets shifted down by its `top` inset at rest
			    whenever its containing block is taller than itself — which happens
			    on content-heavy tabs — so any non-zero top (the old tr-top-4)
			    knocked the sidebar ~16px out of line with the content breadcrumb,
			    intermittently depending on the tab's height. top:0 keeps the
			    sidebar top aligned with the breadcrumb at rest while still pinning
			    on scroll where the sidebar is shorter than the viewport. */}
			<div className="tr-w-full md:tr-w-44 lg:tr-w-56 md:tr-shrink-0 md:tr-self-stretch">
				<div className="md:tr-sticky md:tr-top-0">
					<SidebarNavigation
						activeSection={activeSection}
						activeItem={activeItem}
						onNavigate={handleNavigate}
					/>
				</div>
			</div>

			{/* Content area.
			    `tr-w-full` matters on the stacked (mobile) layout only. The row
			    is `tr-items-start`, so below `md` — where it is a column — this
			    child is sized to fit-content on the cross axis and grows to the
			    widest thing any tab renders, pushing the page wider than the
			    viewport. `tr-min-w-0` cannot prevent that: it bounds the
			    min-content floor, while a `flex-start` cross axis sizes to
			    max-content. Claiming the full width pins the column to the
			    viewport so each tab's own responsive behaviour can take effect.
			    Same pattern as the sidebar above and ContentStructure's canvas. */}
			<div className="tr-flex-1 tr-min-w-0 tr-w-full">
				{error && (
					<Alert variant="error" className="tr-mb-4">
						{error}
					</Alert>
				)}
				<ContentArea
					activeSection={activeSection}
					activeItem={activeItem}
					onNavigate={handleNavigate}
				/>
			</div>
		</div>
	);
};

export default EssentialSEO;
