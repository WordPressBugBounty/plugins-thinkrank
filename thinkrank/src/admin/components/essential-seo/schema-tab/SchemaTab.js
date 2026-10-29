/**
 * Schema Tab Component — Shell
 *
 * Slim state-management shell that delegates rendering to existing
 * sub-components in ../schema/ and business logic to ./api.js and ./validation.js.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SkeletonCard } from '../../ui';
import { Button, Flex, FlexItem } from '@wordpress/components';

// Import shared components
import SettingsCard from '../../common/SettingsCard';
import OptimizationButton from '../../common/OptimizationButton';

// Import existing sub-components (unchanged in ../schema/)
import SchemaSettings from '../schema/SchemaSettings';
import SchemaGeneration from '../schema/SchemaGeneration';
import SchemaDeployment from '../schema/SchemaDeployment';
import SchemaOrganizationForm from '../schema/forms/SchemaOrganizationForm';
import SchemaWebsiteForm from '../schema/forms/SchemaWebsiteForm';
import SchemaLocalBusinessForm from '../schema/forms/SchemaLocalBusinessForm';
import SchemaPersonForm from '../schema/forms/SchemaPersonForm';
import SchemaPreview from '../schema/SchemaPreview';
import ProGatedTab from '../../pro/ProGatedTab';
import ProTeaser from '../../pro/ProTeaser';
import {
	CustomSchemaIllustration,
	FieldMappingIllustration,
} from '../../pro/pro-teaser-illustrations';

// Import modular logic
import {
	loadSettings,
	saveSettings,
	loadSchemaTypes,
	loadDeployedSchemas,
	testWithGoogleRichResults,
	generateSchema,
	deploySchema,
	generatePreview,
	syncFromSiteIdentity,
	syncFromLocalSEO,
} from './api';

/**
 * Schema Tab Component
 * @param root0
 * @param root0.activeSubSection
 * @param root0.onNavigate
 */
const SchemaTab = ({ activeSubSection = 'schema-settings', onNavigate }) => {
	// Component mount tracking for memory leak prevention
	const isMountedRef = useRef(true);
	const abortControllerRef = useRef(null);

	// MEMORY OPTIMIZATION: Consolidated loading states
	const [loadingStates, setLoadingStates] = useState({
		isLoading: true,
		isSaving: false,
		isOptimizing: false,
		isValidating: false,
		isGenerating: false,
		isDeploying: false,
		isSyncing: false,
		isPreviewing: false,
	});

	const [settings, setSettings] = useState({});
	// The settings as last loaded or saved. Generate and Deploy read this,
	// not the form, so both work from the snapshot the server holds (#1007).
	const [savedSettings, setSavedSettings] = useState(null);
	const [hasChanges, setHasChanges] = useState(false);
	// Counts edits to the form. A save clears hasChanges only while this is
	// still what it was when the request left, so an edit made during the
	// save stays unsaved (and keeps Generate and Deploy held).
	const settingsRevisionRef = useRef(0);
	const [notice, setNotice] = useState(null);

	// MEMORY OPTIMIZATION: Consolidated schema data states
	const [schemaData, setSchemaData] = useState({
		schemaTypes: [],
		generatedSchemas: null,
		deployedSchemas: null,
		validationResults: null,
		schemaPreview: null,
		performanceData: null,
	});

	/**
	 * Safe setState helper to prevent memory leaks
	 * @param setter
	 * @param value
	 */
	const safeSetState = (setter, value) => {
		if (isMountedRef.current) {
			setter(value);
		}
	};

	/**
	 * Safe loading state updater
	 * @param key
	 * @param value
	 */
	const updateLoadingState = (key, value) => {
		if (isMountedRef.current) {
			setLoadingStates((prev) => ({ ...prev, [key]: value }));
		}
	};

	/**
	 * Helper to update schema data safely
	 * @param key
	 * @param value
	 */
	const updateSchemaData = (key, value) => {
		if (isMountedRef.current) {
			setSchemaData((prev) => ({ ...prev, [key]: value }));
		}
	};

	// Extract individual states for backward compatibility
	const {
		isLoading,
		isSaving,
		isValidating,
		isDeploying,
		isSyncing,
		isPreviewing,
		// Tracked by the generate flow but never read, so the button showed no
		// busy state and repeated clicks fired concurrent requests (#472).
		isGenerating,
	} = loadingStates;
	const {
		generatedSchemas,
		deployedSchemas,
		validationResults,
		schemaPreview,
	} = schemaData;

	/**
	 * Mark the form as edited since the last load or save.
	 *
	 * @param {boolean} changed True for an edit, false once saved.
	 */
	const markChanged = (changed) => {
		if (changed) {
			settingsRevisionRef.current += 1;
		}
		safeSetState(setHasChanges, changed);
	};

	/**
	 * Build the API context object passed to all api.js functions
	 */
	const getApiContext = () => ({
		settings,
		savedSettings,
		setSettings,
		setSavedSettings,
		setHasChanges: markChanged,
		settingsRevisionRef,
		setNotice,
		updateLoadingState,
		safeSetState,
		updateSchemaData,
		isMountedRef,
		abortControllerRef,
		generatedSchemas,
		deployedSchemas,
		validationResults,
	});

	/**
	 * Handle setting changes
	 * @param key
	 * @param value
	 */
	const handleSettingChange = (key, value) => {
		safeSetState(setSettings, (prev) => ({ ...prev, [key]: value }));
		markChanged(true);
	};

	/**
	 * Handle schema type toggle
	 * @param schemaType
	 * @param enabled
	 */
	const handleSchemaTypeToggle = (schemaType, enabled) => {
		const currentTypes = settings.enabled_schema_types || [];
		let newTypes;

		if (enabled) {
			newTypes = [...currentTypes, schemaType];
		} else {
			newTypes = currentTypes.filter((type) => type !== schemaType);
		}

		handleSettingChange('enabled_schema_types', newTypes);
	};

	// Load initial data
	useEffect(() => {
		const ctx = getApiContext();
		loadSettings(ctx);
		loadSchemaTypes(ctx);
		loadDeployedSchemas(ctx);
	}, []);

	// Component cleanup
	useEffect(() => {
		return () => {
			isMountedRef.current = false;
			if (abortControllerRef.current) {
				abortControllerRef.current.abort();
			}
			setNotice(null);
		};
	}, []);

	// Clear validation results when switching away from schema sections
	useEffect(() => {
		const formValidationTabs = [
			'organization',
			'website',
			'person',
			'schema-validation',
		];
		if (!formValidationTabs.includes(activeSubSection)) {
			if (isMountedRef.current) {
				updateSchemaData('validationResults', null);
			}
		}
	}, [activeSubSection]);

	/**
	 * Get optimization button configuration based on active sub-section
	 */
	const getOptimizationButtonConfig = () => {
		const ctx = getApiContext();
		const buttons = [];

		// The master switch makes the site schema forms read-only, and Sync
		// writes straight into them — it filled disabled fields and re-enabled
		// Save, so the "stays read-only until you enable schema markup" notice
		// was bypassable from the header.
		const schemaDisabled = !settings.enabled;

		switch (activeSubSection) {
			case 'website':
				buttons.push(
					<OptimizationButton
						key="sync-website"
						type="rule"
						label={__('Sync from Site Identity', 'thinkrank')}
						onClick={() => syncFromSiteIdentity(ctx, 'website')}
						isBusy={isSyncing}
						disabled={schemaDisabled}
					/>
				);
				break;

			case 'organization':
				buttons.push(
					<OptimizationButton
						key="sync-organization"
						type="rule"
						label={__('Sync from Site Identity', 'thinkrank')}
						onClick={() =>
							syncFromSiteIdentity(ctx, 'organization')
						}
						isBusy={isSyncing}
						disabled={schemaDisabled}
					/>
				);
				break;

			// The Deployment screen owns its own Deploy / Regenerate actions in
			// SchemaDeployment, where they can be disabled while validation is
			// failing. A duplicate header button here offered "Deploy Schema"
			// regardless of deployment_ready, so it is intentionally absent.
		}

		// Rich Results test button for schema sections
		if (['organization', 'website', 'person'].includes(activeSubSection)) {
			buttons.push(
				<OptimizationButton
					key="rich-results-test"
					type="test"
					label={__('Test Rich Results', 'thinkrank')}
					onClick={() => testWithGoogleRichResults(getApiContext())}
					isBusy={isValidating}
				/>
			);
		}

		return buttons;
	};

	if (isLoading) {
		return (
			<div className="thinkrank-loading">
				<SkeletonCard lines={3} />
			</div>
		);
	}

	const sectionMap = {
		'schema-settings': 'settings',
		'schema-types': 'types',
		organization: 'organization',
		website: 'website',
		person: 'person',
		'local-business': 'local-business',
		'custom-schema': 'custom-schema',
		'field-mapping': 'field-mapping',
		deployment: 'deployment',
	};

	const tabName = sectionMap[activeSubSection] || 'settings';

	// Two subsections are Pro panels that own their own REST route and their own
	// Save button. The shared footer save below tracks `hasChanges` on the free
	// schema settings, which those panels never touch — so on them it rendered
	// permanently greyed out, a second save control that could never be clicked
	// and did not apply to anything on screen.
	const SELF_SAVING_SECTIONS = ['custom-schema', 'field-mapping'];
	const usesSharedSave = !SELF_SAVING_SECTIONS.includes(tabName);

	return (
		<div className="thinkrank-schema-tab">
			<SettingsCard
				title={__('Site Schema Manager', 'thinkrank')}
				optimizationButtons={getOptimizationButtonConfig()}
				notice={notice}
				onNoticeRemove={() => setNotice(null)}
			>
				{/* Content based on active subsection */}
				{(() => {
					switch (tabName) {
						case 'settings':
							return (
								<SchemaSettings
									settings={settings}
									onSettingChange={handleSettingChange}
									isLoading={isLoading}
								/>
							);

						case 'types':
							return (
								<SchemaGeneration
									settings={settings}
									onSettingChange={handleSettingChange}
									isLoading={isLoading}
								/>
							);

						case 'organization':
							return (
								<SchemaOrganizationForm
									settings={settings}
									onSettingChange={handleSettingChange}
									isLoading={isLoading}
									onNavigate={onNavigate}
								/>
							);

						case 'website':
							return (
								<SchemaWebsiteForm
									settings={settings}
									onSettingChange={handleSettingChange}
									isLoading={isLoading}
									onNavigate={onNavigate}
								/>
							);

						case 'person':
							return (
								<SchemaPersonForm
									settings={settings}
									onSettingChange={handleSettingChange}
									isLoading={isLoading}
									onNavigate={onNavigate}
								/>
							);

						case 'local-business':
							return (
								<SchemaLocalBusinessForm
									settings={settings}
									onSettingChange={handleSettingChange}
									isLoading={isLoading}
									onNavigate={onNavigate}
								/>
							);

						case 'custom-schema':
							return (
								<ProGatedTab
									filter="thinkrank.schema.customSchemaTab"
									teaser={
										<ProTeaser
											illustration={
												<CustomSchemaIllustration />
											}
											title={__(
												'Custom Schema & Display Conditions',
												'thinkrank'
											)}
											description={__(
												'Go beyond the built-in schema types. Build any structured data you need and control exactly where it appears across your site.',
												'thinkrank'
											)}
											features={[
												__(
													'Create custom JSON-LD schema for any content type',
													'thinkrank'
												),
												__(
													'Target it with display conditions (post type, taxonomy, single page)',
													'thinkrank'
												),
												__(
													'Add rich results like Recipe, FAQ, or JobPosting with ease',
													'thinkrank'
												),
											]}
										/>
									}
								/>
							);

						case 'field-mapping':
							return (
								<ProGatedTab
									filter="thinkrank.schema.fieldMappingTab"
									teaser={
										<ProTeaser
											illustration={
												<FieldMappingIllustration />
											}
											title={__(
												'Custom Fields to Schema',
												'thinkrank'
											)}
											description={__(
												'Bind a post type to a schema type and fill its properties straight from your custom fields no JSON-LD to write, and no schema to maintain post by post.',
												'thinkrank'
											)}
											features={[
												__(
													'Map ACF, SCF, Meta Box, Pods and ACPT fields onto schema properties',
													'thinkrank'
												),
												__(
													'Bind any post type to Product, Event, Recipe, Course, JobPosting and more',
													'thinkrank'
												),
												__(
													'Preview the generated schema against a real post before it goes live',
													'thinkrank'
												),
											]}
										/>
									}
								/>
							);

						case 'deployment':
							return (
								<>
									<SchemaDeployment
										generatedSchemas={generatedSchemas}
										deployedSchemas={deployedSchemas}
										isDeploying={isDeploying}
										onDeploySchema={() =>
											deploySchema(getApiContext())
										}
										onGenerateSchema={() =>
											generateSchema(getApiContext())
										}
										isGenerating={isGenerating}
										settings={settings}
										onSettingChange={handleSettingChange}
										hasUnsavedChanges={hasChanges}
										onSaveSettings={() =>
											saveSettings(getApiContext())
										}
										isSaving={isSaving}
									/>
									{/* Belongs to Deployment only. It used to
									    render outside this switch, so the whole
									    generated payload was repeated under
									    every schema screen and buried the form
									    the user had opened. */}
									<SchemaPreview
										generatedSchemas={generatedSchemas}
										schemaPreview={schemaPreview}
										isGeneratingPreview={isPreviewing}
										onGeneratePreview={() =>
											generatePreview(getApiContext())
										}
									/>
								</>
							);

						default:
							return null;
					}
				})()}

				{/* Save Button — only for the subsections it actually saves. */}
				{usesSharedSave && (
					<Flex justify="flex-end" className="thinkrank-mt-lg">
						<FlexItem>
							<Button
								variant="primary"
								onClick={() => saveSettings(getApiContext())}
								isBusy={isSaving}
								disabled={!hasChanges || isSaving}
							>
								{isSaving
									? __('Saving…', 'thinkrank')
									: __('Save Settings', 'thinkrank')}
							</Button>
						</FlexItem>
					</Flex>
				)}
			</SettingsCard>
		</div>
	);
};

export default SchemaTab;
