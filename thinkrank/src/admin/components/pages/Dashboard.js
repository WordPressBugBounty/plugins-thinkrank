import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import logger from '../../../shared/utils/logger';

import { SkeletonCard } from '../ui';
import WelcomeCard from '../dashboard/WelcomeCard';
import SiteHealthAudit from '../dashboard/SiteHealthAudit';
import RecentPostsHealth from '../dashboard/RecentPostsHealth';
import ProSlot from '../dashboard/ProSlot';
import QuickAccess from '../dashboard/QuickAccess';
import SocialCard from '../dashboard/SocialCard';

// Refetch on tab focus only when the data is older than this — settings
// rarely change from another tab, and unconditional refetching made every
// alt-tab back to the dashboard fire two requests.
const REFRESH_STALE_MS = 5 * 60 * 1000;

export default function Dashboard() {
	const [siteDescription, setSiteDescription] = useState(
		window.thinkrankAdmin?.siteDescription || ''
	);
	const [isLoading, setIsLoading] = useState(true);
	const isMountedRef = useRef(true);

	const load = useCallback(async () => {
		try {
			// Only the site description is read here. This used to fetch
			// /thinkrank/v1/settings alongside it to compute an `apiConfigured`
			// flag for SiteHealthAudit — which destructures just
			// { onRefresh, compact } and has never read it. So the call bought
			// nothing, and it 403'd for every delegated role without
			// `thinkrank_settings`: sharing a Promise.all with no per-promise
			// catch, it took this successful sibling down with it and left the
			// description unsynced (#574). The dashboard opens on
			// `thinkrank_access`, so those roles all land here.
			const wpSettings = await apiFetch({
				path: '/wp/v2/settings',
				method: 'GET',
			}).catch(() => null);
			if (!isMountedRef.current) return;
			if (wpSettings && typeof wpSettings.description === 'string') {
				setSiteDescription(wpSettings.description);
				// Keep the localized global in sync so other components that
				// read from window.thinkrankAdmin.siteDescription stay accurate.
				if (window.thinkrankAdmin) {
					window.thinkrankAdmin.siteDescription =
						wpSettings.description;
				}
			}
		} catch (err) {
			logger.error('Dashboard: failed to load data', err);
		} finally {
			if (isMountedRef.current) setIsLoading(false);
		}
	}, []);

	const lastLoadedAtRef = useRef(0);

	useEffect(() => {
		isMountedRef.current = true;
		lastLoadedAtRef.current = Date.now();
		load();

		const onVisible = () => {
			if (document.hidden) return;
			if (Date.now() - lastLoadedAtRef.current < REFRESH_STALE_MS) return;
			lastLoadedAtRef.current = Date.now();
			load();
		};
		document.addEventListener('visibilitychange', onVisible);
		return () => {
			isMountedRef.current = false;
			document.removeEventListener('visibilitychange', onVisible);
		};
	}, [load]);

	if (isLoading) {
		return (
			<div
				className="tr-grid tr-gap-6"
				style={{ gridTemplateColumns: 'minmax(0, 1fr)' }}
			>
				<SkeletonCard lines={3} />
				<SkeletonCard lines={4} />
				<SkeletonCard lines={4} />
			</div>
		);
	}

	return (
		<div
			className="tr-grid tr-gap-6"
			style={{
				gridTemplateColumns: 'minmax(0, 1fr) minmax(300px, 360px)',
			}}
		>
			{/* ── Main column ──────────────────────────── */}
			<div className="tr-flex tr-flex-col tr-gap-6 tr-min-w-0">
				<WelcomeCard />
				<SiteHealthAudit
					siteDescription={siteDescription}
					onRefresh={load}
				/>
				<RecentPostsHealth />
			</div>

			{/* ── Sidebar ──────────────────────────────── */}
			<div className="tr-flex tr-flex-col tr-gap-6 tr-min-w-0">
				<ProSlot />
				<QuickAccess />
				<SocialCard />
			</div>
		</div>
	);
}
