/**
 * Schema VideoObject Form Component
 *
 * Captures the fields Google requires for VideoObject rich results
 * (name, description, thumbnail, upload date) plus the recommended
 * content/embed URLs and duration.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Input, Textarea } from '../../../ui';
import MediaPicker from '../../../common/MediaPicker';

const IconVideo = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polygon points="23 7 16 12 23 17 23 7" />
		<rect x="1" y="5" width="15" height="14" rx="2" ry="2" />
	</svg>
);

const IconLink = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
		<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
	</svg>
);

const SchemaVideoObjectForm = ({ settings, onSettingChange, isLoading }) => {
	const disabled = !settings.enabled;

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{/* ── Video Details ─────────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconVideo />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Video Details', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Required information for video rich results',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('Video Title', 'thinkrank')}
						value={settings.video_name || ''}
						onChange={(e) =>
							onSettingChange('video_name', e.target.value)
						}
						description={__(
							'Title of the video. Leave empty to use the post title.',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Textarea
						label={__('Description', 'thinkrank')}
						value={settings.video_description || ''}
						onChange={(e) =>
							onSettingChange('video_description', e.target.value)
						}
						description={__(
							'Short description of the video for search engines.',
							'thinkrank'
						)}
						rows={3}
						disabled={disabled}
					/>
					<MediaPicker
						label={__('Thumbnail Image', 'thinkrank')}
						value={settings.video_thumbnail}
						onChange={(value) =>
							onSettingChange('video_thumbnail', value)
						}
						help={__(
							'Video thumbnail (recommended: 1280×720 px). Falls back to the featured image.',
							'thinkrank'
						)}
						allowedTypes={['image']}
						disabled={disabled}
					/>
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Upload Date', 'thinkrank')}
							type="datetime-local"
							value={settings.video_upload_date || ''}
							onChange={(e) =>
								onSettingChange(
									'video_upload_date',
									e.target.value
								)
							}
							description={__(
								'When the video was published. Defaults to the post date.',
								'thinkrank'
							)}
							disabled={disabled}
						/>
						<Input
							label={__('Duration', 'thinkrank')}
							value={settings.video_duration || ''}
							onChange={(e) =>
								onSettingChange(
									'video_duration',
									e.target.value
								)
							}
							description={__(
								'ISO 8601 format, e.g. PT1M33S for 1 min 33 sec.',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</div>
				</div>
			</Card>

			{/* ── Playback URLs ─────────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconLink />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Playback URLs', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Provide at least one so the video is playable in search results',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('Content URL', 'thinkrank')}
						type="url"
						value={settings.video_content_url || ''}
						onChange={(e) =>
							onSettingChange('video_content_url', e.target.value)
						}
						description={__(
							'Direct link to the video file (.mp4, etc.).',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Input
						label={__('Embed URL', 'thinkrank')}
						type="url"
						value={settings.video_embed_url || ''}
						onChange={(e) =>
							onSettingChange('video_embed_url', e.target.value)
						}
						description={__(
							'Player embed URL (e.g. a YouTube/Vimeo embed link).',
							'thinkrank'
						)}
						disabled={disabled}
					/>
				</div>
			</Card>
		</div>
	);
};

export default SchemaVideoObjectForm;
