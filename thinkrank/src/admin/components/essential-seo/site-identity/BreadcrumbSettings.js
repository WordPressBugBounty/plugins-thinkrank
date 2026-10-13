/**
 * Breadcrumb Settings Component
 *
 * Extracted from SiteIdentityTab.js - handles breadcrumb configuration and settings
 * Maintains exact functionality and structure from the original 'breadcrumbs' case
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Input, Select, Toggle, Container } from '../../ui';
import SectionHeader from '../../common/SectionHeader';
import FormField from '../../common/FormField';
import BreadcrumbPreview from '../../common/BreadcrumbPreview';

/**
 * Get breadcrumb type options
 */
const getBreadcrumbTypeOptions = () => [
	{ label: __('Hierarchical', 'thinkrank'), value: 'hierarchical' },
	{ label: __('Taxonomy-based', 'thinkrank'), value: 'taxonomy' },
	{ label: __('URL Path', 'thinkrank'), value: 'path' },
	{ label: __('Custom', 'thinkrank'), value: 'custom' },
];

/**
 * Breadcrumb Settings Component
 * @param root0
 * @param root0.settings
 * @param root0.handleSettingChange
 * @param root0.onPersistSetting    Autosave one key immediately (feature switches).
 * @param root0.isPersistingSetting True while such an autosave is in flight.
 */
const BreadcrumbSettings = ({
	settings,
	handleSettingChange,
	onPersistSetting,
	isPersistingSetting = false,
}) => {
	const disabled = !settings.enabled;

	/**
	 * Breadcrumbs on/off is a feature switch, so flipping it is the save. The
	 * parent owns the settings object and the autosave; fall back to the plain
	 * (Save-button) path if it wasn't given one.
	 * @param {boolean} value New breadcrumbs_enabled value.
	 */
	const toggleBreadcrumbs = (value) => {
		if (!onPersistSetting) {
			handleSettingChange('breadcrumbs_enabled', value);
			return;
		}

		onPersistSetting(
			'breadcrumbs_enabled',
			value,
			value
				? __('Breadcrumbs are on.', 'thinkrank')
				: __('Breadcrumbs are off.', 'thinkrank')
		);
	};

	return (
		<Container direction="column" gap={4}>
			<Card padding={false}>
				<SectionHeader
					title={__('Breadcrumb Settings', 'thinkrank')}
					description={__(
						'Configure how breadcrumb navigation appears on your site.',
						'thinkrank'
					)}
					withBorder={settings.breadcrumbs_enabled}
					trailing={
						<Toggle
							label={
								isPersistingSetting
									? __('Saving…', 'thinkrank')
									: __('Enable breadcrumbs', 'thinkrank')
							}
							checked={settings.breadcrumbs_enabled}
							onChange={toggleBreadcrumbs}
							disabled={disabled || isPersistingSetting}
						/>
					}
				/>

				{settings.breadcrumbs_enabled && (
					<Container
						direction="column"
						gap={6}
						className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
					>
						<FormField
							id="tr-bc-type"
							label={__('Breadcrumb Type', 'thinkrank')}
							disabled={disabled}
						>
							<Select
								id="tr-bc-type"
								value={settings.breadcrumb_type}
								options={getBreadcrumbTypeOptions()}
								onChange={(e) =>
									handleSettingChange(
										'breadcrumb_type',
										e.target.value
									)
								}
								disabled={disabled}
							/>
						</FormField>

						<FormField
							id="tr-bc-home-text"
							label={__('Home Text', 'thinkrank')}
							description={__(
								'Text for the home breadcrumb link',
								'thinkrank'
							)}
							disabled={disabled}
							className="tr-pt-6"
						>
							<Input
								id="tr-bc-home-text"
								value={settings.breadcrumb_home_text}
								onChange={(e) =>
									handleSettingChange(
										'breadcrumb_home_text',
										e.target.value
									)
								}
								placeholder={__('e.g., Home', 'thinkrank')}
								disabled={disabled}
							/>
						</FormField>

						<FormField
							id="tr-bc-separator"
							label={__('Breadcrumb Separator', 'thinkrank')}
							description={__(
								'Character used to separate breadcrumb items',
								'thinkrank'
							)}
							disabled={disabled}
							className="tr-pt-6"
						>
							<Input
								id="tr-bc-separator"
								value={settings.breadcrumb_separator}
								onChange={(e) =>
									handleSettingChange(
										'breadcrumb_separator',
										e.target.value
									)
								}
								placeholder="/"
								disabled={disabled}
							/>
						</FormField>

						<FormField
							id="tr-bc-prefix"
							label={__('Breadcrumb Prefix', 'thinkrank')}
							description={__(
								'Text to show before breadcrumbs (e.g., "You are here:")',
								'thinkrank'
							)}
							disabled={disabled}
							className="tr-pt-6"
						>
							<Input
								id="tr-bc-prefix"
								value={settings.breadcrumb_prefix}
								onChange={(e) =>
									handleSettingChange(
										'breadcrumb_prefix',
										e.target.value
									)
								}
								placeholder={__(
									'e.g., You are here:',
									'thinkrank'
								)}
								disabled={disabled}
							/>
						</FormField>

						<div className="tr-pt-6">
							<Toggle
								label={__('Show Current Page', 'thinkrank')}
								checked={settings.show_current_page}
								onChange={(val) =>
									handleSettingChange(
										'show_current_page',
										val
									)
								}
								description={__(
									'Include the current page in breadcrumbs',
									'thinkrank'
								)}
								disabled={disabled}
							/>
						</div>

						<div className="tr-pt-6">
							<Toggle
								label={__(
									'Use SEO Titles in Breadcrumbs',
									'thinkrank'
								)}
								checked={!!settings.breadcrumb_use_seo_title}
								onChange={(val) =>
									handleSettingChange(
										'breadcrumb_use_seo_title',
										val
									)
								}
								description={__(
									'Label each crumb with the SEO title of the post or term when one is set, so the trail matches the search snippet. Falls back to the normal title.',
									'thinkrank'
								)}
								disabled={disabled}
							/>
						</div>
					</Container>
				)}
			</Card>

			{settings.breadcrumbs_enabled && (
				<BreadcrumbPreview
					className="thinkrank-mt-sm"
					breadcrumbSettings={{
						breadcrumbs_enabled: settings.breadcrumbs_enabled,
						breadcrumb_type: settings.breadcrumb_type,
						breadcrumb_home_text: settings.breadcrumb_home_text,
						breadcrumb_separator: settings.breadcrumb_separator,
						breadcrumb_prefix: settings.breadcrumb_prefix,
						show_current_page: settings.show_current_page,
					}}
					disabled={!settings.enabled}
				/>
			)}
		</Container>
	);
};

export default BreadcrumbSettings;
