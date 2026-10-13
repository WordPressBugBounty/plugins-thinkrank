/**
 * Open AI Tools on the ThinkRank Hub tab, for the header chip.
 *
 * @package
 * @since 2.15.0
 */

import { navigate, getPageFromUrl } from './router';
import { TAB_EVENT } from './hubStatus';

/**
 * Open AI Tools on the ThinkRank Hub tab. From another page the router
 * mounts AI Tools on `?tab=hub`; on AI Tools itself the URL changes in place,
 * so the page is told to switch tabs.
 */
export const openHubTab = () => {
	const alreadyThere = getPageFromUrl() === 'ai-tools';
	navigate('ai-tools', { tab: 'hub' });
	if (alreadyThere) {
		window.dispatchEvent(new CustomEvent(TAB_EVENT, { detail: 'hub' }));
	}
};
