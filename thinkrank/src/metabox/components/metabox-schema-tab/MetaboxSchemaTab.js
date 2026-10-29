/**
 * MetaboxSchemaTab — Shell Component
 *
 * State management and layout shell for the metabox schema tab.
 * Delegates rendering to sub-section components and API calls to api.js.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';

// Sub-section components
import SchemaTypeSection from './SchemaTypeSection';
import ImportSection from './ImportSection';
import SavedSchemasSection from './SavedSchemasSection';
import DeploymentSection from './DeploymentSection';
import PreviewSection from './PreviewSection';
import singleActiveReducer from './activationReducer';

// API functions
import {
	loadDeployedSchemas,
	loadSchemaFormData,
	saveSchemaFormData,
	saveSchemaFormDataOnUnload,
	saveAdditionalSchemas,
	generateSchema,
	deploySchema,
	importSchemaFromUrl,
} from './api';

/**
 * Fallback Upsell Components for Free Version
 */
const RawCodeUpsell = () => (
	<div className="thinkrank-field" style={{ marginTop: '15px' }}>
		<div className="schema-status-notice" style={{ marginTop: '5px' }}>
			<span className="dashicons dashicons-lock"></span>
			<p>
				{__('Raw Code import is a Pro feature. ', 'thinkrank')}
				<a
					href="https://thinkrank.ai/#pricing"
					target="_blank"
					rel="noopener noreferrer"
				>
					{__('Upgrade to ThinkRank Pro', 'thinkrank')}
				</a>
				{__(' to paste and save raw JSON-LD schema code.', 'thinkrank')}
			</p>
		</div>
	</div>
);

const FileUploadUpsell = () => (
	<div className="thinkrank-field" style={{ marginTop: '15px' }}>
		<div className="schema-status-notice" style={{ marginTop: '5px' }}>
			<span className="dashicons dashicons-lock"></span>
			<p>
				{__('File Upload import is a Pro feature. ', 'thinkrank')}
				<a
					href="https://thinkrank.ai/#pricing"
					target="_blank"
					rel="noopener noreferrer"
				>
					{__('Upgrade to ThinkRank Pro', 'thinkrank')}
				</a>
				{__(' to upload and import JSON-LD schema files.', 'thinkrank')}
			</p>
		</div>
	</div>
);

/**
 * MetaboxSchemaTab Component
 * @param root0
 * @param root0.postId
 * @param root0.postTitle
 * @param root0.contentPreview
 * @param root0.formData
 * @param root0.showNotice
 */
const MetaboxSchemaTab = ({
	postId,
	postTitle,
	contentPreview,
	formData,
	showNotice,
}) => {
	// Schema state. A product's type list has no Article, so starting there
	// left the select showing Product while Generate sent Article, and an
	// untouched product never reached the WooCommerce Product fill (#1011).
	const [selectedSchemaType, setSelectedSchemaType] = useState(() =>
		window.thinkrankMetabox?.postType === 'product' ? 'Product' : 'Article'
	);
	const [generatedSchemas, setGeneratedSchemas] = useState(null);
	const [deployedSchemas, setDeployedSchemas] = useState(null);
	const [isGenerating, setIsGenerating] = useState(false);
	const [isDeploying, setIsDeploying] = useState(false);
	const [isLoading, setIsLoading] = useState(true);
	const [isRefreshing, setIsRefreshing] = useState(false);

	// Schema form data state
	const [schemaFormData, setSchemaFormData] = useState({});
	// Only debounce-save once the user has actually touched a field. Without
	// this the initial load would immediately write the data back it just read.
	const schemaDirtyRef = useRef(false);
	const [schemaBackups, setSchemaBackups] = useState({});

	// Import state
	const [importSource, setImportSource] = useState('URL / Online Page');
	const [importUrl, setImportUrl] = useState('');
	const [importedData, setImportedData] = useState(null);
	const [isImporting, setIsImporting] = useState(false);
	const [availableSchemas, setAvailableSchemas] = useState([]);
	const [selectedImportIndex, setSelectedImportIndex] = useState(0);

	// Multi-schema state
	const [savedSchemas, setSavedSchemas] = useState([]);
	const [editingSchemaIndex, setEditingSchemaIndex] = useState(null);

	// Config from localized data
	const currentPostType = window.thinkrankMetabox?.postType || 'post';
	const postRestBase =
		window.thinkrankMetabox?.postRestBase ||
		(currentPostType === 'page' ? 'pages' : 'posts');

	// Schema types for posts
	const postSchemaTypes = [
		{ label: __('Article', 'thinkrank'), value: 'Article' },
		{ label: __('Blog Posting', 'thinkrank'), value: 'BlogPosting' },
		{
			label: __('Technical Article', 'thinkrank'),
			value: 'TechnicalArticle',
		},
		{ label: __('News Article', 'thinkrank'), value: 'NewsArticle' },
		{
			label: __('Scholarly Article', 'thinkrank'),
			value: 'ScholarlyArticle',
		},
		{ label: __('Report', 'thinkrank'), value: 'Report' },
		{ label: __('How-To Guide', 'thinkrank'), value: 'HowTo' },
		{ label: __('FAQ Page', 'thinkrank'), value: 'FAQPage' },
		{ label: __('Review', 'thinkrank'), value: 'Review' },
		{ label: __('Video', 'thinkrank'), value: 'VideoObject' },
	];

	// Schema types for pages.
	//
	// The WebPage family is listed first: it is what an ordinary page usually
	// is, and until now none of it was selectable even though the output side
	// has handled it all along (#624). Only the four with a real branch in
	// Global_SEO_Schema_Output::generate_schema() are offered — ItemPage,
	// QAPage and CollectionPage are in that class's allowlist but have no
	// branch, so they fall to the `default` arm and publish plain WebPage.
	// Offering a type that silently emits a different one is worse than not
	// offering it.
	const pageSchemaTypes = [
		{ label: __('Web Page', 'thinkrank'), value: 'WebPage' },
		{ label: __('About Page', 'thinkrank'), value: 'AboutPage' },
		{ label: __('Contact Page', 'thinkrank'), value: 'ContactPage' },
		{ label: __('Profile Page', 'thinkrank'), value: 'ProfilePage' },
		{ label: __('Article', 'thinkrank'), value: 'Article' },
		{ label: __('How-To Guide', 'thinkrank'), value: 'HowTo' },
		{ label: __('FAQ Page', 'thinkrank'), value: 'FAQPage' },
		{ label: __('Event', 'thinkrank'), value: 'Event' },
		{
			label: __('Software Application', 'thinkrank'),
			value: 'SoftwareApplication',
		},
		{ label: __('Product', 'thinkrank'), value: 'Product' },
		{ label: __('Review', 'thinkrank'), value: 'Review' },
		{ label: __('Video', 'thinkrank'), value: 'VideoObject' },
	];

	// A two-way page/everything-else branch handed WooCommerce products the post
	// list, which does not contain Product (#472).
	const productSchemaTypes = [
		{ label: __('Product', 'thinkrank'), value: 'Product' },
		{ label: __('Review', 'thinkrank'), value: 'Review' },
		{ label: __('FAQ Page', 'thinkrank'), value: 'FAQPage' },
		{ label: __('Video', 'thinkrank'), value: 'VideoObject' },
	];

	let schemaTypes = postSchemaTypes;
	if (currentPostType === 'page') {
		schemaTypes = pageSchemaTypes;
	} else if (currentPostType === 'product') {
		schemaTypes = productSchemaTypes;
	}

	// Import methods. Raw Code and File Upload are ThinkRank Pro features: free
	// ships only their teasers, and Pro replaces both entries through the
	// `thinkrank.schemaTab.importMethods` filter with its own implementations.
	const defaultImportMethods = [
		{
			id: 'URL / Online Page',
			label: __('URL / Online Page', 'thinkrank'),
			component: 'URL',
		},
		{
			id: 'Raw Code',
			label: __('Raw Code (Pro)', 'thinkrank'),
			component: RawCodeUpsell,
		},
		{
			id: 'File Upload',
			label: __('File Upload (Pro)', 'thinkrank'),
			component: FileUploadUpsell,
		},
	];

	const importMethods = applyFilters(
		'thinkrank.schemaTab.importMethods',
		defaultImportMethods
	);
	const activeImportMethod =
		importMethods.find((m) => m.id === importSource) || importMethods[0];

	/**
	 * Build API context
	 */
	const getApiContext = () => ({
		postId,
		postTitle,
		contentPreview,
		formData,
		showNotice,
		currentPostType,
		postRestBase,
		schemaTypes,
		selectedSchemaType,
		schemaFormData,
		generatedSchemas,
		deployedSchemas,
		savedSchemas,
		importUrl,
		setSelectedSchemaType,
		setGeneratedSchemas,
		setDeployedSchemas,
		setIsGenerating,
		setIsDeploying,
		setIsLoading,
		setIsRefreshing,
		setSchemaFormData,
		setSavedSchemas,
		setIsImporting,
		setImportedData,
		setAvailableSchemas,
		setSelectedImportIndex,
	});

	/**
	 * Handle schema type change with warning and backup
	 * @param newSchemaType
	 */
	const handleSchemaTypeChange = (newSchemaType) => {
		if (newSchemaType === selectedSchemaType) return;

		const hasCurrentData = Object.keys(schemaFormData).length > 0;

		if (hasCurrentData) {
			const confirmMessage = __(
				'Switching schema type will replace your current configuration. Your current data will be backed up in case you want to switch back. Continue?',
				'thinkrank'
			);
			if (!window.confirm(confirmMessage)) {
				const selectElement =
					document.getElementById('schema-type-select');
				if (selectElement) selectElement.value = selectedSchemaType;
				return;
			}

			setSchemaBackups((prev) => ({
				...prev,
				[selectedSchemaType]: schemaFormData,
			}));
		}

		const backupData = schemaBackups[newSchemaType] || {};
		setSelectedSchemaType(newSchemaType);
		setSchemaFormData(backupData);
		saveSchemaFormData(getApiContext(), backupData, newSchemaType);

		if (Object.keys(backupData).length > 0) {
			showNotice?.(
				__(
					`Switched to ${newSchemaType} schema. Previous configuration restored from backup.`,
					'thinkrank'
				),
				'success'
			);
		} else {
			showNotice?.(
				__(`Switched to ${newSchemaType} schema.`, 'thinkrank'),
				'success'
			);
		}
	};

	const handleSchemaFormChange = (field, value) => {
		schemaDirtyRef.current = true;
		setSchemaFormData((prev) => ({ ...prev, [field]: value }));
	};

	/**
	 * Toggle schema activation.
	 *
	 * Free keeps one saved schema active at a time (singleActiveReducer). ThinkRank
	 * Pro can replace the rule through `thinkrank.schemaTab.activationReducer`.
	 * @param index
	 */
	const toggleSchemaActivation = (index) => {
		if (index < 0 || index >= savedSchemas.length) return;
		const reduce = applyFilters(
			'thinkrank.schemaTab.activationReducer',
			singleActiveReducer
		);
		const { schemas: newSchemas, message } = reduce(savedSchemas, index);
		setSavedSchemas(newSchemas);
		saveAdditionalSchemas(getApiContext(), newSchemas);
		showNotice?.(message, 'success');
	};

	/**
	 * Get deployment status for UI logic
	 */
	const getDeploymentStatus = () => {
		const hasActiveSavedSchemas = savedSchemas.some((s) => s._isActive);
		if (!generatedSchemas && !deployedSchemas && !hasActiveSavedSchemas)
			return 'not_generated';
		if (!generatedSchemas && !deployedSchemas && hasActiveSavedSchemas)
			return 'ready_to_deploy';
		if (generatedSchemas && !deployedSchemas) return 'ready_to_deploy';
		if (deployedSchemas && !generatedSchemas) return 'deployed';
		if (generatedSchemas && deployedSchemas) return 'ready_to_redeploy';
		return 'not_generated';
	};

	/**
	 * Get preview schemas (merged)
	 */
	const getPreviewSchemas = () => {
		let base = {};

		if (generatedSchemas?.generated_schemas) {
			base = { ...generatedSchemas.generated_schemas };
		} else if (deployedSchemas) {
			// /schema/deployed wraps each entry as {data, method, type}.
			// Returning that verbatim rendered the storage envelope instead of
			// the JSON-LD after any page reload (#472).
			base = Object.entries(deployedSchemas).reduce(
				(acc, [type, entry]) => {
					acc[type] = entry?.data ?? entry;
					return acc;
				},
				{}
			);
		}

		// Deploy merges active saved/imported schemas, so the preview has to as
		// well or it under-reports what will actually ship (#472).
		savedSchemas.forEach((schema, index) => {
			if (!schema?._isActive) {
				return;
			}

			const primaryType = Array.isArray(schema['@type'])
				? schema['@type'][0]
				: schema['@type'];

			if (!primaryType) {
				return;
			}

			const clean = { ...schema };
			delete clean._isActive;

			let key = primaryType;
			if (base[key]) {
				key = `${primaryType}-${index}`;
			}
			base[key] = clean;
		});

		return base;
	};

	// Load initial data
	useEffect(() => {
		const ctx = getApiContext();
		loadDeployedSchemas(ctx, true);
		loadSchemaFormData(ctx);
	}, [postId]);

	// Persist schema edits shortly after they stop, so leaving the page is never
	// the thing that saves them. This used to rely entirely on `beforeunload`,
	// where the browser tears the document down without waiting for the request
	// — so edits were routinely lost on navigation.
	useEffect(() => {
		if (Object.keys(schemaFormData).length === 0) return undefined;
		// Skip the save triggered by the initial load populating state.
		if (!schemaDirtyRef.current) return undefined;

		const timer = setTimeout(() => {
			saveSchemaFormData(
				getApiContext(),
				schemaFormData,
				selectedSchemaType
			);
		}, 1500);

		return () => clearTimeout(timer);
	}, [schemaFormData, selectedSchemaType]);

	// Last-resort flush on post submit / page unload, for edits made inside the
	// debounce window above. Uses a keepalive request so it can outlive the
	// document — an ordinary async call started here does not.
	useEffect(() => {
		const flush = () => {
			if (Object.keys(schemaFormData).length > 0) {
				saveSchemaFormDataOnUnload(
					getApiContext(),
					schemaFormData,
					selectedSchemaType
				);
			}
		};

		window.addEventListener('beforeunload', flush);

		const handleSubmit = (e) => {
			if (e.target.id === 'post') {
				flush();
			}
		};
		document.addEventListener('submit', handleSubmit);

		return () => {
			window.removeEventListener('beforeunload', flush);
			document.removeEventListener('submit', handleSubmit);
		};
	}, [schemaFormData, selectedSchemaType]);

	const ctx = getApiContext();
	const deploymentStatus = getDeploymentStatus();
	const finalPreviewSchemas = getPreviewSchemas();

	if (isLoading) {
		return (
			<div className="thinkrank-metabox-schema-tab">
				<div className="tr-flex tr-items-center tr-gap-2 tr-p-4 tr-text-xs tr-text-tertiary">
					<span className="tr-w-3 tr-h-3 tr-border-2 tr-border-primary tr-border-t-transparent tr-rounded-full tr-animate-spin tr-shrink-0" />
					{__('Loading schema data…', 'thinkrank')}
				</div>
			</div>
		);
	}

	return (
		<div
			className={`thinkrank-metabox-schema-tab ${isRefreshing ? 'is-refreshing' : ''}`}
		>
			{/* Refresh indicator */}
			{isRefreshing && (
				<div className="tr-flex tr-items-center tr-gap-2 tr-px-4 tr-py-2 tr-mb-2 tr-rounded tr-bg-white tr-border tr-border-secondary tr-text-xs tr-text-tertiary">
					<span className="tr-w-3 tr-h-3 tr-border-2 tr-border-primary tr-border-t-transparent tr-rounded-full tr-animate-spin tr-shrink-0" />
					{__('Updating schema data…', 'thinkrank')}
				</div>
			)}

			{/* Schema Type + Import — side by side, equal height */}
			<div className="tr-flex tr-flex-col md:tr-flex-row tr-gap-4 md:tr-items-stretch">
				<div className="tr-flex tr-flex-1 tr-min-w-0 tr-w-full">
					{/* Schema Type Selection */}
					<SchemaTypeSection
						selectedSchemaType={selectedSchemaType}
						schemaTypes={schemaTypes}
						schemaFormData={schemaFormData}
						isGenerating={isGenerating}
						onSchemaTypeChange={handleSchemaTypeChange}
						onSchemaFormChange={handleSchemaFormChange}
						onGenerateSchema={() => generateSchema(ctx)}
					/>
				</div>

				<div className="tr-flex tr-flex-1 tr-min-w-0 tr-w-full">
					{/* Import Schema */}
					<ImportSection
						importSource={importSource}
						setImportSource={setImportSource}
						importUrl={importUrl}
						setImportUrl={setImportUrl}
						importedData={importedData}
						setImportedData={setImportedData}
						availableSchemas={availableSchemas}
						setAvailableSchemas={setAvailableSchemas}
						selectedImportIndex={selectedImportIndex}
						setSelectedImportIndex={setSelectedImportIndex}
						isImporting={isImporting}
						importMethods={importMethods}
						activeImportMethod={activeImportMethod}
						savedSchemas={savedSchemas}
						setSavedSchemas={setSavedSchemas}
						saveAdditionalSchemas={(schemas) =>
							saveAdditionalSchemas(ctx, schemas)
						}
						editingSchemaIndex={editingSchemaIndex}
						setEditingSchemaIndex={setEditingSchemaIndex}
						showNotice={showNotice}
						setIsImporting={setIsImporting}
						postId={postId}
						onImportFromUrl={() => importSchemaFromUrl(ctx)}
					/>
				</div>
			</div>

			{/* Saved Schemas */}
			<SavedSchemasSection
				savedSchemas={savedSchemas}
				editingSchemaIndex={editingSchemaIndex}
				onEdit={(index) => {
					setImportedData(savedSchemas[index]);
					setEditingSchemaIndex(index);
					setTimeout(() => {
						const editor = document.querySelector(
							'.imported-schema-editor'
						);
						if (editor)
							editor.scrollIntoView({ behavior: 'smooth' });
					}, 100);
				}}
				onToggleActivation={toggleSchemaActivation}
				onDelete={(index) => {
					if (
						window.confirm(
							__(
								'Are you sure you want to delete this schema?',
								'thinkrank'
							)
						)
					) {
						const newSchemas = savedSchemas.filter(
							(_, i) => i !== index
						);
						setSavedSchemas(newSchemas);
						saveAdditionalSchemas(ctx, newSchemas);
						if (editingSchemaIndex === index) {
							setEditingSchemaIndex(null);
							setImportedData(null);
						} else if (
							editingSchemaIndex !== null &&
							index < editingSchemaIndex
						) {
							// Deleting a lower index shifts the array, so the
							// stored index pointed at the wrong entry and
							// "Update Saved Schema" overwrote it (#472).
							setEditingSchemaIndex(editingSchemaIndex - 1);
						}
					}
				}}
			/>

			{/* Deployment Status */}
			<DeploymentSection
				deploymentStatus={deploymentStatus}
				isGenerating={isGenerating}
				isDeploying={isDeploying}
				onGenerateSchema={() => generateSchema(ctx)}
				onDeploySchema={() => deploySchema(ctx)}
			/>

			{/* Schema Preview */}
			<PreviewSection
				finalPreviewSchemas={finalPreviewSchemas}
				savedSchemas={savedSchemas}
				formData={formData}
				postTitle={postTitle}
				contentPreview={contentPreview}
			/>
		</div>
	);
};

export default MetaboxSchemaTab;
