/**
 * ThinkRank Metabox Robots Tab Component
 *
 * Per-post robots meta + advanced robots overrides.
 *
 * @package
 * @since 1.9.1
 */

import { __ } from '@wordpress/i18n';
import {
	ToggleControl,
	CheckboxControl,
	TextControl,
	Flex,
	FlexItem,
	Notice,
} from '@wordpress/components';
import RobotMetaSettings from '../../admin/components/essential-seo/RobotMetaSettings';
import Select from '../../admin/components/ui/Select';

const DEFAULT_ROBOTS = {
	index: true,
	noindex: false,
	nofollow: false,
	noarchive: false,
	noimageindex: false,
	nosnippet: false,
};

const DEFAULT_ADVANCED = {
	snippet_enabled: true,
	max_snippet: -1,
	video_preview_enabled: true,
	max_video_preview: -1,
	image_preview_enabled: true,
	max_image_preview: 'large',
};

const MetaboxRobotsTab = ({
	robotsMeta,
	advancedRobotsMeta,
	robotsEnabled,
	onChange,
}) => {
	const robots = { ...DEFAULT_ROBOTS, ...(robotsMeta || {}) };
	const advanced = { ...DEFAULT_ADVANCED, ...(advancedRobotsMeta || {}) };

	const setRobots = (next) => onChange({ robotsMeta: next });
	const setAdvanced = (patch) =>
		onChange({ advancedRobotsMeta: { ...advanced, ...patch } });
	const setEnabled = (val) => onChange({ robotsEnabled: val });

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			<div className="tr-bg-white tr-rounded-lg tr-border tr-border-secondary">
				<div className="tr-px-4 tr-py-3 tr-border-b tr-border-secondary">
					<Flex align="flex-start" justify="space-between">
						<FlexItem style={{ flex: 1, paddingRight: '20px' }}>
							<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
								{__('Robots Meta Override', 'thinkrank')}
							</h4>
							<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-1">
								{__(
									'Override the global and post-type robots meta directives for this single post. When disabled, defaults apply.',
									'thinkrank'
								)}
							</p>
						</FlexItem>
						<FlexItem>
							<ToggleControl
								checked={!!robotsEnabled}
								onChange={setEnabled}
								__nextHasNoMarginBottom
							/>
						</FlexItem>
					</Flex>
				</div>

				{robotsEnabled && (
					<div className="tr-p-4">
						<RobotMetaSettings
							values={robots}
							onChange={setRobots}
						/>

						<div
							className="thinkrank-advanced-robots-meta"
							style={{
								marginTop: '24px',
								paddingTop: '20px',
								borderTop: '1px solid #eee',
							}}
						>
							<h4 style={{ marginTop: 0, marginBottom: '16px' }}>
								{__('Advanced Robots Meta', 'thinkrank')}
							</h4>

							<div
								style={{
									display: 'grid',
									gridTemplateColumns: 'repeat(3, 1fr)',
									gap: '20px',
								}}
							>
								<div>
									<CheckboxControl
										label={__('Snippet', 'thinkrank')}
										checked={advanced.snippet_enabled}
										onChange={(val) =>
											setAdvanced({
												snippet_enabled: val,
											})
										}
										__nextHasNoMarginBottom
									/>
									<TextControl
										type="number"
										value={advanced.max_snippet}
										onChange={(val) =>
											setAdvanced({
												max_snippet: parseInt(val, 10),
											})
										}
										disabled={!advanced.snippet_enabled}
										help={__(
											'Snippet length (-1 for unlimited)',
											'thinkrank'
										)}
									/>
								</div>

								<div>
									<CheckboxControl
										label={__('Video Preview', 'thinkrank')}
										checked={advanced.video_preview_enabled}
										onChange={(val) =>
											setAdvanced({
												video_preview_enabled: val,
											})
										}
										__nextHasNoMarginBottom
									/>
									<TextControl
										type="number"
										value={advanced.max_video_preview}
										onChange={(val) =>
											setAdvanced({
												max_video_preview: parseInt(
													val,
													10
												),
											})
										}
										disabled={
											!advanced.video_preview_enabled
										}
										help={__(
											'Max video preview seconds (-1 for unlimited).',
											'thinkrank'
										)}
									/>
								</div>

								<div>
									<CheckboxControl
										label={__('Image Preview', 'thinkrank')}
										checked={advanced.image_preview_enabled}
										onChange={(val) =>
											setAdvanced({
												image_preview_enabled: val,
											})
										}
										__nextHasNoMarginBottom
									/>
									{/* The checkbox above is the visible label; the
									select keeps its own for a screen reader. */}
									<Select
										id="thinkrank-metabox-max-image-preview"
										label={__(
											'Max image preview',
											'thinkrank'
										)}
										hideLabelFromVision
										value={
											advanced.max_image_preview ||
											'large'
										}
										options={[
											{
												label: __('None', 'thinkrank'),
												value: 'none',
											},
											{
												label: __(
													'Standard',
													'thinkrank'
												),
												value: 'standard',
											},
											{
												label: __('Large', 'thinkrank'),
												value: 'large',
											},
										]}
										onChange={(e) =>
											setAdvanced({
												max_image_preview:
													e.target.value,
											})
										}
										disabled={
											!advanced.image_preview_enabled
										}
										description={__(
											'Max image preview size.',
											'thinkrank'
										)}
									/>
								</div>
							</div>
						</div>

						{robots.noindex && (
							<Notice
								status="warning"
								isDismissible={false}
								style={{ marginTop: '16px' }}
							>
								{__(
									'This post will not be indexed by search engines.',
									'thinkrank'
								)}
							</Notice>
						)}
					</div>
				)}
			</div>
		</div>
	);
};

export default MetaboxRobotsTab;
