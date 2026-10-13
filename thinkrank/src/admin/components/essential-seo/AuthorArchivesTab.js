/**
 * Author Archives Tab Component
 *
 * Manages Author Archives settings.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	SkeletonCard,
	Card,
	Input,
	Toggle,
	Button,
	Alert,
	Container,
} from '../ui';

import SettingsCard from '../common/SettingsCard';
import SectionHeader from '../common/SectionHeader';
import FormField from '../common/FormField';
import notify from '../../utils/notify';
import logger from '../../../shared/utils/logger';

/**
 * Author Archives Tab Component
 */
const AuthorArchivesTab = () => {
	const [isEnabled, setIsEnabled] = useState(true);
	const [showInSearchResults, setShowInSearchResults] = useState(true);
	const [showEmptyArchives, setShowEmptyArchives] = useState(false);
	const [seoTitle, setSeoTitle] = useState('');
	const [seoDescription, setSeoDescription] = useState('');
	const [isLoading, setIsLoading] = useState(true);
	const [isSaving, setIsSaving] = useState(false);
	const [isTogglingFeature, setIsTogglingFeature] = useState(false);
	const [hasChanges, setHasChanges] = useState(false);
	const [notice, setNotice] = useState(null);

	const seoTitleVars = [
		{ label: __('+ Author Name', 'thinkrank'), value: '%author_name%' },
		{ label: __('+ Site Title', 'thinkrank'), value: '%site_title%' },
		{ label: __('+ Separator', 'thinkrank'), value: '%separator%' },
		{ label: __('+ Page', 'thinkrank'), value: '%page%' },
	];

	const metaDescVars = [
		{ label: __('+ Author Name', 'thinkrank'), value: '%author_name%' },
		{ label: __('+ Site Title', 'thinkrank'), value: '%site_title%' },
	];

	// Load settings on mount
	useEffect(() => {
		loadSettings();
	}, []);

	const loadSettings = async () => {
		setIsLoading(true);
		try {
			const response = await fetch(
				`${window.thinkrankAdmin.apiUrl}author-archives/settings`,
				{
					headers: {
						'X-WP-Nonce': window.thinkrankAdmin.restNonce,
					},
				}
			);

			if (response.ok) {
				const data = await response.json();
				if (data.success && data.data) {
					setIsEnabled(data.data.enabled);
					setShowInSearchResults(
						data.data.show_in_search_results !== false
					); // Default to true if undefined
					setShowEmptyArchives(
						data.data.show_empty_archives === true
					); // Default to false if undefined
					// Use ?? rather than ||: the API already resolves the
					// default for an unset option, so an empty string here is
					// a template the user deliberately cleared and must stay
					// cleared (#316).
					setSeoTitle(data.data.title ?? '');
					setSeoDescription(data.data.meta_description ?? '');
				}
			}
		} catch (error) {
			logger.error('Error loading author archives settings:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to load settings. Please refresh the page.',
					'thinkrank'
				),
			});
		} finally {
			setIsLoading(false);
		}
	};

	/**
	 * Every field's current value, with optional overrides applied on top.
	 *
	 * The endpoint takes the whole settings object, so even a single-field
	 * autosave has to send the rest alongside it.
	 * @param {Object} overrides Values to apply over the current state.
	 * @return {Object} The settings payload.
	 */
	const collectSettings = (overrides = {}) => ({
		enabled: isEnabled,
		show_in_search_results: showInSearchResults,
		show_empty_archives: showEmptyArchives,
		title: seoTitle,
		meta_description: seoDescription,
		...overrides,
	});

	/**
	 * POST the settings envelope, throwing on any failure so the Save button and
	 * the enable-toggle autosave handle errors the same way.
	 * @param {Object} settings Settings payload to persist.
	 */
	const postSettings = async (settings) => {
		const response = await fetch(
			`${window.thinkrankAdmin.apiUrl}author-archives/settings`,
			{
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.thinkrankAdmin.restNonce,
				},
				body: JSON.stringify({ settings }),
			}
		);

		if (!response.ok) {
			throw new Error('Failed to save settings');
		}

		const data = await response.json();
		if (!data.success) {
			throw new Error(data.message || 'Failed to save settings');
		}
	};

	const saveSettings = async () => {
		setIsSaving(true);
		setNotice(null);

		try {
			await postSettings(collectSettings());

			setNotice({
				status: 'success',
				message: __(
					'Author Archives settings saved successfully!',
					'thinkrank'
				),
			});
			setHasChanges(false);
		} catch (error) {
			setNotice({
				status: 'error',
				message: __(
					'Failed to save settings. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsSaving(false);
		}
	};

	const insertVariable = (targetValue, setTargetValue, variableValue) => {
		let currentVal = targetValue || '';
		if (currentVal.length > 0 && !currentVal.endsWith(' ')) {
			currentVal += ' ';
		}
		setTargetValue(currentVal + variableValue);
		setHasChanges(true);
	};

	const renderVariableTags = (targetValue, setTargetValue, vars) => (
		<span
			className="thinkrank-variable-tags-container"
			style={{ display: 'block', marginTop: '10px' }}
		>
			<span style={{ marginRight: '5px', fontWeight: 600 }}>
				{__('Use variables:', 'thinkrank')}
			</span>
			{vars.map((variable, index) => (
				<button
					key={index}
					type="button"
					onClick={() =>
						insertVariable(
							targetValue,
							setTargetValue,
							variable.value
						)
					}
					className="thinkrank-variable-tag"
					title={__('Click to insert', 'thinkrank')}
				>
					{variable.label}
				</button>
			))}
		</span>
	);

	/**
	 * Enable/disable author archives.
	 *
	 * House rule: the feature switch IS the save. This component keeps each
	 * field in its own state rather than one settings object, so the shared
	 * useAutosaveSettings hook doesn't fit — the same behaviour is hand-rolled
	 * here: set optimistically, persist, roll back to the previous value and
	 * toast on failure. It deliberately does not touch `hasChanges`; the Save
	 * button stays for the templates and the other toggles.
	 * @param {boolean} value New enabled state.
	 */
	const handleToggleChange = async (value) => {
		const previous = isEnabled;

		setIsEnabled(value);
		setIsTogglingFeature(true);

		try {
			await postSettings(collectSettings({ enabled: value }));

			notify.success(
				value
					? __('Author archives are on.', 'thinkrank')
					: __('Author archives are off.', 'thinkrank')
			);
		} catch (error) {
			logger.error('ThinkRank: autosave failed', error);
			setIsEnabled(previous);
			notify.error(__('Couldn’t save that change.', 'thinkrank'));
		} finally {
			setIsTogglingFeature(false);
		}
	};

	if (isLoading) {
		return (
			<Card padding={false} className="tr-mb-4">
				<div className="tr-p-6 tr-flex tr-items-center tr-gap-2">
					<SkeletonCard lines={3} />
				</div>
			</Card>
		);
	}

	return (
		<div className="thinkrank-author-archives-tab">
			{notice && (
				<Alert
					variant={notice.status === 'success' ? 'success' : 'error'}
					onDismiss={() => setNotice(null)}
					className="tr-mb-4"
				>
					{notice.message}
				</Alert>
			)}

			<SettingsCard
				title={__('Author Archives', 'thinkrank')}
				notice={null}
				onNoticeRemove={() => setNotice(null)}
			>
				<Container direction="column" gap={4}>
					{/* Site Appearance */}
					<Card padding={false}>
						<SectionHeader
							title={__('Site appearance', 'thinkrank')}
							description={__(
								'Determine how your author archives should look in search engines.',
								'thinkrank'
							)}
							// Disabled, the header is the card's only child, so
							// its divider would land 1px above the card's own
							// border and read as a doubled line.
							withBorder={isEnabled}
							trailing={
								<Toggle
									label={
										isTogglingFeature
											? __('Saving…', 'thinkrank')
											: __(
													'Enable author archives',
													'thinkrank'
												)
									}
									checked={isEnabled}
									onChange={handleToggleChange}
									disabled={isTogglingFeature}
								/>
							}
						/>
						{isEnabled && (
							<Container
								direction="column"
								gap={6}
								className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
							>
								<Toggle
									label={__(
										'Show author archives in search results',
										'thinkrank'
									)}
									checked={showInSearchResults}
									onChange={(val) => {
										setShowInSearchResults(val);
										setHasChanges(true);
									}}
									description={
										showInSearchResults
											? __(
													'Author archives are indexable.',
													'thinkrank'
												)
											: __(
													'Author archives are set to noindex.',
													'thinkrank'
												)
									}
								/>
								{showInSearchResults && (
									<div className="tr-pt-6">
										<Toggle
											label={__(
												'Show author archives without posts in search results',
												'thinkrank'
											)}
											checked={showEmptyArchives}
											onChange={(val) => {
												setShowEmptyArchives(val);
												setHasChanges(true);
											}}
											description={
												showEmptyArchives
													? __(
															'Empty author archives are indexable.',
															'thinkrank'
														)
													: __(
															'Empty author archives are set to noindex.',
															'thinkrank'
														)
											}
										/>
									</div>
								)}
							</Container>
						)}
					</Card>

					{/* SEO Title */}
					{isEnabled && (
						<Card padding={false}>
							<SectionHeader
								title={__('SEO title', 'thinkrank')}
								description={__(
									'Template used for the SEO title of author archive pages.',
									'thinkrank'
								)}
							/>
							<Container
								direction="column"
								gap={6}
								className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
							>
								<FormField
									id="tr-aa-seo-title"
									label={__('Title template', 'thinkrank')}
									help={__(
										'Leave empty to use the default WordPress title.',
										'thinkrank'
									)}
								>
									<Input
										id="tr-aa-seo-title"
										value={seoTitle}
										onChange={(e) => {
											setSeoTitle(e.target.value);
											setHasChanges(true);
										}}
										placeholder={__(
											'Insert variable',
											'thinkrank'
										)}
									/>
									{renderVariableTags(
										seoTitle,
										setSeoTitle,
										seoTitleVars
									)}
								</FormField>
							</Container>
						</Card>
					)}

					{/* Meta Description */}
					{isEnabled && (
						<Card padding={false}>
							<SectionHeader
								title={__('Meta description', 'thinkrank')}
								description={__(
									'Template used for the meta description of author archive pages.',
									'thinkrank'
								)}
							/>
							<Container
								direction="column"
								gap={6}
								className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
							>
								<FormField
									id="tr-aa-meta-desc"
									label={__(
										'Description template',
										'thinkrank'
									)}
									help={__(
										'Leave empty to omit the meta description on author archives.',
										'thinkrank'
									)}
								>
									<Input
										id="tr-aa-meta-desc"
										value={seoDescription}
										onChange={(e) => {
											setSeoDescription(e.target.value);
											setHasChanges(true);
										}}
										placeholder={__(
											'Insert variable',
											'thinkrank'
										)}
									/>
									{renderVariableTags(
										seoDescription,
										setSeoDescription,
										metaDescVars
									)}
								</FormField>
							</Container>
						</Card>
					)}
				</Container>

				{/* Save Button */}
				<div className="tr-flex tr-justify-end tr-mt-6">
					<Button
						variant="primary"
						size="md"
						onClick={saveSettings}
						loading={isSaving}
						disabled={!hasChanges || isSaving}
					>
						{__('Save Changes', 'thinkrank')}
					</Button>
				</div>
			</SettingsCard>
		</div>
	);
};

export default AuthorArchivesTab;
