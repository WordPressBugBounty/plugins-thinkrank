/**
 * Schema Settings Component
 *
 * Landing screen for the Site Schema Manager — general schema settings.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { ToggleControl } from '@wordpress/components';

import { Select } from '../../ui';
import { getValidationLevelOptions as getSharedValidationLevelOptions } from '../../../config/schema-settings-config';
import AdvancedOnly from '../../common/AdvancedOnly';

const SchemaIcon = () => (
	<svg
		width="22"
		height="22"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M8 6L3 12L8 18"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
		<path
			d="M16 6L21 12L16 18"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
		<path
			d="M14 4L10 20"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

const SettingRow = ({ title, description, control, last }) => (
	<div
		style={{
			display: 'flex',
			alignItems: 'flex-start',
			justifyContent: 'space-between',
			gap: '24px',
			padding: '20px 0',
			borderBottom: last ? 'none' : '1px solid var(--tr-border)',
		}}
	>
		<div style={{ flex: 1, minWidth: 0 }}>
			<div
				style={{
					fontSize: '14px',
					fontWeight: 600,
					color: 'var(--tr-text-heading)',
					marginBottom: '4px',
				}}
			>
				{title}
			</div>
			<div
				style={{
					fontSize: '13px',
					color: 'var(--tr-text-body)',
					lineHeight: 1.5,
					maxWidth: '560px',
				}}
			>
				{description}
			</div>
		</div>
		<div
			style={{
				flexShrink: 0,
				minWidth: '180px',
				display: 'flex',
				justifyContent: 'flex-end',
			}}
		>
			{control}
		</div>
	</div>
);

const SchemaSettings = ({ settings, onSettingChange }) => {
	return (
		<div className="tab-content">
			{/* ── Settings card ────────────────────────────────────── */}
			<div
				style={{
					border: '1px solid var(--tr-border)',
					borderRadius: '12px',
					background: '#fff',
					overflow: 'hidden',
				}}
			>
				{/* ── Card header ──────────────────────────────────── */}
				<div
					style={{
						display: 'flex',
						alignItems: 'flex-start',
						gap: '16px',
						padding: '20px 24px',
						borderBottom: '1px solid var(--tr-border)',
					}}
				>
					<div
						style={{
							width: '44px',
							height: '44px',
							borderRadius: '10px',
							background: '#eef0fd',
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'center',
							color: '#4f46e5',
							flexShrink: 0,
						}}
					>
						<SchemaIcon />
					</div>
					<div style={{ minWidth: 0 }}>
						<h2
							style={{
								margin: 0,
								fontSize: '16px',
								fontWeight: 600,
								color: 'var(--tr-text-heading)',
							}}
						>
							{__('General Schema Settings', 'thinkrank')}
						</h2>
						<p
							style={{
								margin: '4px 0 0',
								fontSize: '13px',
								color: 'var(--tr-text-body)',
								lineHeight: 1.5,
							}}
						>
							{__(
								'Control how ThinkRank generates and validates structured data across your site. These options apply to every site-level schema you enable.',
								'thinkrank'
							)}
						</p>
					</div>
				</div>

				{/* ── Settings rows ────────────────────────────────── */}
				<div style={{ padding: '4px 24px' }}>
					<SettingRow
						title={__('Enable Schema Markup', 'thinkrank')}
						description={__(
							'Master switch for site-level structured data. While this is off, the Organization, Website and Person forms stay read-only and no schema is output.',
							'thinkrank'
						)}
						control={
							<ToggleControl
								// The visible title lives in an unassociated
								// sibling div, so without this the control is
								// announced as an unlabeled checkbox (#472).
								aria-label={__(
									'Enable Schema Markup',
									'thinkrank'
								)}
								checked={!!settings.enabled}
								onChange={(value) =>
									onSettingChange('enabled', value)
								}
								__nextHasNoMarginBottom={true}
							/>
						}
					/>

					<SettingRow
						title={__('Knowledge Graph', 'thinkrank')}
						description={__(
							'Optimize markup so Google can build a Knowledge Graph entry for your site recommended for brand and entity visibility in search.',
							'thinkrank'
						)}
						control={
							<ToggleControl
								aria-label={__('Knowledge Graph', 'thinkrank')}
								checked={!!settings.knowledge_graph}
								onChange={(value) =>
									onSettingChange('knowledge_graph', value)
								}
								__nextHasNoMarginBottom={true}
							/>
						}
					/>

					<SettingRow
						title={__('Auto-Generate Schema', 'thinkrank')}
						description={__(
							'Automatically build schema markup from your content so you do not have to maintain it by hand as posts and pages change.',
							'thinkrank'
						)}
						control={
							<ToggleControl
								aria-label={__(
									'Auto-Generate Schema',
									'thinkrank'
								)}
								checked={!!settings.auto_generate_schema}
								onChange={(value) =>
									onSettingChange(
										'auto_generate_schema',
										value
									)
								}
								__nextHasNoMarginBottom={true}
							/>
						}
					/>

					<SettingRow
						title={__(
							'Publish FAQ schema from page-builder accordions',
							'thinkrank'
						)}
						description={__(
							'Read the accordions on Oxygen and Breakdance pages as FAQ content and publish FAQPage schema from them. Those builders have no ThinkRank FAQ module, so this is the only way their questions reach search engines. Leave it off if your accordions hold something other than questions and answers, such as specifications or a changelog: marking those up as an FAQ misdescribes the page.',
							'thinkrank'
						)}
						control={
							<ToggleControl
								aria-label={__(
									'Publish FAQ schema from page-builder accordions',
									'thinkrank'
								)}
								checked={!!settings.enable_accordion_faq_schema}
								onChange={(value) =>
									onSettingChange(
										'enable_accordion_faq_schema',
										value
									)
								}
								__nextHasNoMarginBottom={true}
							/>
						}
					/>

					<AdvancedOnly
						label={__('Validation level', 'thinkrank')}
						className="tr-my-3"
					>
						<SettingRow
							last
							title={__('Validation Level', 'thinkrank')}
							description={__(
								'How strictly ThinkRank validates the generated markup before it ships. Stricter levels catch more issues but may flag valid edge cases.',
								'thinkrank'
							)}
							control={
								<Select
									id="tr-schema-validation-level"
									className="tr-w-[200px]"
									aria-label={__(
										'Validation Level',
										'thinkrank'
									)}
									value={
										settings.validation_level || 'moderate'
									}
									options={getSharedValidationLevelOptions()}
									onChange={(e) =>
										onSettingChange(
											'validation_level',
											e.target.value
										)
									}
								/>
							}
						/>
					</AdvancedOnly>
				</div>
			</div>
		</div>
	);
};

export default SchemaSettings;
