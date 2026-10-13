/**
 * Setup Wizard container.
 *
 * Owns navigation state and per-step data, renders the stepper, the active step
 * and the footer. Functional steps save into existing endpoints on continue.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useCallback, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Stepper from './components/Stepper';
import StepLayout from './components/StepLayout';
import StepFooter from './components/StepFooter';

import Step1Start from './steps/Step1Start';
import Step2Migration from './steps/Step2Migration';
import Step3SiteSetup from './steps/Step3SiteSetup';
import StepMcpServer from './steps/StepMcpServer';
import Step4Analytics from './steps/Step4Analytics';
import Step5Ecosystem from './steps/Step5Ecosystem';
import StepHub from './steps/StepHub';
import Step7Ready from './steps/Step7Ready';

import {
	saveStep,
	getMigratedSiteData,
	saveSiteIdentity,
	saveSitemap,
	saveUiMode,
	installPlugins,
	recordStartStep,
} from './api';
import logger from '../../shared/utils/logger';

/**
 * Step definitions — the single source of truth for the stepper and navigation.
 * `customFooter` steps render their own actions (the final "Ready" screen).
 */
const STEPS = [
	{
		slug: 'start',
		label: __('Start', 'thinkrank'),
		title: __('Welcome to ThinkRank', 'thinkrank'),
		subtitle: __(
			'Set up your site’s SEO foundation in under 3 minutes.',
			'thinkrank'
		),
		customFooter: true,
	},
	{
		slug: 'migration',
		label: __('Migration', 'thinkrank'),
		title: __('Import from an Existing Plugin', 'thinkrank'),
		subtitle: __(
			'We detected SEO data from your existing plugins. Import it in one click.',
			'thinkrank'
		),
		primary: __('Continue', 'thinkrank'),
		skip: true,
		back: false,
	},
	{
		slug: 'site-setup',
		label: __('Site Setup', 'thinkrank'),
		title: __('Site Identity & Sitemap', 'thinkrank'),
		subtitle: __(
			'How search engines understand and represent your site.',
			'thinkrank'
		),
		primary: __('Save & Continue', 'thinkrank'),
		skip: false,
		back: false,
	},
	{
		slug: 'mcp',
		label: __('MCP Server', 'thinkrank'),
		title: __('Connect the MCP Server', 'thinkrank'),
		subtitle: __(
			'Let AI assistants like Claude and ChatGPT read and improve your SEO through a secure, permission-scoped connection.',
			'thinkrank'
		),
		primary: __('Continue', 'thinkrank'),
		skip: true,
		back: false,
	},
	{
		slug: 'analytics',
		label: __('Analytics', 'thinkrank'),
		title: __('Connect analytics', 'thinkrank'),
		subtitle: __(
			'See how your site performs in search right inside WordPress.',
			'thinkrank'
		),
		primary: __('Continue', 'thinkrank'),
		skip: true,
		back: false,
	},
	{
		slug: 'ecosystem',
		label: __('Ecosystem', 'thinkrank'),
		title: __('Recommended plugins', 'thinkrank'),
		subtitle: __(
			'Optional tools to extend your site functionality. Install in one click.',
			'thinkrank'
		),
		primary: __('Continue', 'thinkrank'),
		skip: true,
		back: false,
	},
	{
		slug: 'hub',
		label: __('ThinkRank Hub', 'thinkrank'),
		title: __('Connect to ThinkRank Hub', 'thinkrank'),
		subtitle: __(
			'Follow this site’s SEO beside your other sites, and reach every site from one AI connection.',
			'thinkrank'
		),
		primary: __('Continue', 'thinkrank'),
		skip: true,
		back: true,
	},
	{
		slug: 'ready',
		label: __('Ready', 'thinkrank'),
		badge: __('Setup complete', 'thinkrank'),
		title: __(
			'Your site is now optimized. Let’s start growing traffic. 🚀',
			'thinkrank'
		),
		subtitle: __(
			'ThinkRank is tracking your performance and applying SEO best practices across your site.',
			'thinkrank'
		),
		customFooter: true,
	},
];

const DEFAULT_SITE_DATA = {
	// A site going through setup is usually a new owner, so the menu starts
	// with what they need; the wizard asks, and the header switch changes it.
	ui_mode: 'simple',
	identity_type: 'blog',
	represents: 'person',
	site_name: '',
	alternate_name: '',
	default_social_image: '',
	logo_url: '',
	sitemap_enabled: true,
	sitemap_include_images: true,
	include_posts: true,
	include_pages: true,
	include_taxonomies: false,
};

export default function SetupWizard({ config }) {
	const total = STEPS.length;

	// Once "Allow & Continue" has been chosen on the Start step, the
	// Start step (index 1) is permanently dismissed — the earliest reachable
	// step becomes Migration (index 2). This mirrors the server-side guard so a
	// refresh, direct URL hit, or the stepper can never return to Start.
	// "Skip" leaves this false so the user can come back
	// to Start and opt in later.
	const [started, setStarted] = useState(!!config.started);
	const minStep = started ? 2 : 1;

	// Steps are tracked by their stable 1-based index into STEPS. The Migration
	// step (index 2) is hidden entirely when there's nothing to migrate; the
	// stepper then renumbers the *visible* steps so the displayed sequence stays
	// gap-free (e.g. Site Setup shows as "2"). Availability comes from the server
	// so the numbering is correct on first paint.
	const hasMigration = !!config.migrationAvailable;
	const visibleSteps = STEPS.map((s, i) => ({ ...s, index: i + 1 })).filter(
		(s) => s.slug !== 'migration' || hasMigration
	);
	const visibleIndices = visibleSteps.map((s) => s.index);

	// Clamp a target index into the allowed range and onto the nearest visible
	// step (preferring forward), so navigation never lands on a hidden step.
	const clampVisible = useCallback(
		(idx) => {
			const clamped = Math.min(Math.max(idx, minStep), total);
			if (visibleIndices.includes(clamped)) {
				return clamped;
			}
			const forward = visibleIndices.find((i) => i >= clamped);
			if (forward !== undefined) {
				return forward;
			}
			return [...visibleIndices].reverse().find((i) => i <= clamped);
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[minStep, total, hasMigration]
	);

	const [current, setCurrent] = useState(() =>
		clampVisible(parseInt(config.currentStep, 10) || 1)
	); // 1-based index into STEPS
	const [siteData, setSiteData] = useState({
		...DEFAULT_SITE_DATA,
		site_name: config.siteName || '',
	});
	// Plugins already installed AND active on the site. These start checked and
	// stay checked — the checkbox reflects real activation status.
	const activeEcosystem = Array.isArray(config.activeEcosystem)
		? config.activeEcosystem
		: [];
	const [ecosystem, setEcosystem] = useState(activeEcosystem); // selected slugs — active ones pre-checked
	const [completed, setCompleted] = useState([]); // slugs finished via Continue/Save
	const [googleConnected, setGoogleConnected] = useState(
		!!config.googleConnected
	);
	const [isSaving, setIsSaving] = useState(false);
	const [error, setError] = useState('');
	// A long-running step (the Migration import) reports itself busy so the
	// footer and stepper lock — the user can't skip, go back, jump steps, or
	// Continue until it finishes or fails.
	const [stepBusy, setStepBusy] = useState(false);

	// Clear the lock whenever the active step changes (defensive: a busy step is
	// never left while still running, but this guarantees no stale lock).
	useEffect(() => {
		setStepBusy(false);
	}, [current]);

	const step = STEPS[current - 1];

	const persistStep = useCallback((next) => {
		saveStep(next).catch((e) =>
			logger.error('Failed to persist wizard step', e)
		);
	}, []);

	const goTo = useCallback(
		(next) => {
			const target = clampVisible(next);
			setError('');
			setCurrent(target);
			persistStep(target);
		},
		[clampVisible, persistStep]
	);

	/**
	 * Run the active step's save (functional steps only). Returns true on success.
	 */
	const runSave = useCallback(async () => {
		if (step.slug === 'site-setup') {
			await saveSiteIdentity({
				site_name: siteData.site_name,
				alternate_name: siteData.alternate_name,
				logo_url: siteData.logo_url,
				default_social_image: siteData.default_social_image,
				identity_type: siteData.identity_type,
				represents: siteData.represents,
			});
			await saveSitemap({
				enabled: !!siteData.sitemap_enabled,
				include_images: !!siteData.sitemap_include_images,
				include_posts: !!siteData.include_posts,
				include_pages: !!siteData.include_pages,
				include_categories: !!siteData.include_taxonomies,
				include_tags: !!siteData.include_taxonomies,
			});
			// A preference, not a setting: a failure here must not hold up
			// the wizard, and the header switch can set it later.
			try {
				await saveUiMode(siteData.ui_mode);
			} catch (e) {
				logger.error('Failed to save the menu mode', e);
			}
			return true;
		}

		if (step.slug === 'ecosystem') {
			// Best-effort: install the selected plugins but never block the
			// wizard if one (or all) fail — the outcome is logged, not surfaced.
			if (ecosystem.length) {
				try {
					await installPlugins(ecosystem);
				} catch (e) {
					logger.error('Ecosystem plugin install failed', e);
				}
			}
			return true;
		}

		return true;
	}, [step.slug, siteData, ecosystem]);

	/**
	 * Pull the freshly migrated values back into the Site Setup step so it shows
	 * the imported data instead of defaults. The endpoint only returns keys that
	 * actually carry a migrated value, so unmapped fields stay untouched.
	 * Best-effort: a failed read leaves the existing form values as-is.
	 */
	const loadMigratedSiteData = useCallback(async () => {
		try {
			const res = await getMigratedSiteData();
			const migrated = res?.settings || {};

			if (!Object.keys(migrated).length) {
				return;
			}

			setSiteData((prev) => ({ ...prev, ...migrated }));
		} catch (e) {
			logger.error('Failed to load migrated site data', e);
		}
	}, []);

	const toggleEcosystem = useCallback((slug, on) => {
		setEcosystem((prev) =>
			on ? [...new Set([...prev, slug])] : prev.filter((s) => s !== slug)
		);
	}, []);

	const handlePrimary = useCallback(async () => {
		if (stepBusy) {
			return;
		}
		setIsSaving(true);
		setError('');
		try {
			await runSave();
			setCompleted((prev) =>
				prev.includes(step.slug) ? prev : [...prev, step.slug]
			);
			goTo(current + 1);
		} catch (e) {
			logger.error('Setup Wizard save failed', e);
			setError(
				e?.message ||
					__(
						'Something went wrong while saving. Please try again.',
						'thinkrank'
					)
			);
		} finally {
			setIsSaving(false);
		}
	}, [runSave, goTo, current, step.slug, stepBusy]);

	/**
	 * The Start step's two consent buttons. Usage tracking is enabled only by
	 * "Allow & Continue", and only then is the Start step permanently
	 * dismissed: lock navigation immediately so the stepper can't jump back to
	 * Start this session; recordStartStep persists the flag server-side for
	 * future visits. "Skip" leaves Start reachable, so the
	 * user can come back and opt in later.
	 */
	const handleStart = useCallback(
		async (consent) => {
			setIsSaving(true);
			setError('');
			if (consent) {
				setStarted(true);
			}
			try {
				await recordStartStep(consent);
			} catch (e) {
				logger.error('Failed to record the Start step', e);
			}
			setCompleted((prev) =>
				prev.includes('start') ? prev : [...prev, 'start']
			);
			goTo(current + 1);
			setIsSaving(false);
		},
		[goTo, current]
	);

	const handleSkip = useCallback(() => {
		if (stepBusy) {
			return;
		}
		setCompleted((prev) => prev.filter((s) => s !== step.slug));
		goTo(current + 1);
	}, [goTo, current, step.slug, stepBusy]);
	const handleBack = useCallback(() => {
		if (stepBusy) {
			return;
		}
		goTo(current - 1);
	}, [goTo, current, stepBusy]);

	const StepComponent = {
		start: Step1Start,
		migration: Step2Migration,
		'site-setup': Step3SiteSetup,
		mcp: StepMcpServer,
		analytics: Step4Analytics,
		ecosystem: Step5Ecosystem,
		hub: StepHub,
		ready: Step7Ready,
	}[step.slug];

	const stepProps = {
		config,
		siteData,
		onSiteDataChange: (patch) =>
			setSiteData((prev) => ({ ...prev, ...patch })),
		selected: ecosystem,
		activePlugins: activeEcosystem,
		onToggle: toggleEcosystem,
		onMigrationComplete: loadMigratedSiteData,
		onBusyChange: setStepBusy,
		completed,
		googleConnected,
		onGoogleConnect: () => setGoogleConnected(true),
		onNext: handlePrimary,
		onSkip: handleSkip,
		onBack: handleBack,
		isSaving,
		onStart: handleStart,
	};

	return (
		<div className="tr-wizard">
			<div className="tr-wizard__panel">
				<div className="tr-wizard__stepper-card">
					<Stepper
						steps={visibleSteps}
						current={current}
						minStep={minStep}
						locked={stepBusy}
						onStepClick={goTo}
					/>
				</div>

				<StepLayout
					badge={step.badge}
					title={step.title}
					subtitle={step.subtitle}
					footer={
						!step.customFooter && (
							<StepFooter
								primaryLabel={step.primary}
								onPrimary={handlePrimary}
								showSkip={!!step.skip}
								onSkip={handleSkip}
								showBack={!!step.back}
								onBack={handleBack}
								isSaving={isSaving}
								locked={stepBusy}
							/>
						)
					}
				>
					<StepComponent
						{...stepProps}
						dashboardUrl={config.completeUrl}
						sitemapUrl={config.sitemapUrl}
					/>

					{error && (
						<div className="tr-wizard__error" role="alert">
							{error}
						</div>
					)}
				</StepLayout>
			</div>
		</div>
	);
}
