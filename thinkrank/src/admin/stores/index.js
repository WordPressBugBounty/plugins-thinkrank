/**
 * Data Stores Registration
 *
 * Registers all WordPress data stores for ThinkRank
 *
 * @package
 * @since 1.0.0
 */

import { register } from '@wordpress/data';
import coreStore from './core';

import settingsStore from './settings';
import metadataStore from './metadata';
import usageAnalyticsStore from './usageAnalytics';
import emailReportStore from './emailReport';

// Register all stores
register(coreStore);

register(settingsStore);
register(metadataStore);
register(usageAnalyticsStore);
register(emailReportStore);

// Export store names for easy access
export const STORE_NAMES = {
	CORE: 'thinkrank/core',

	SETTINGS: 'thinkrank/settings',
	METADATA: 'thinkrank/metadata',
	USAGE_ANALYTICS: 'thinkrank/analytics',
	EMAIL_REPORT: 'thinkrank/email-report',
};

export default STORE_NAMES;
