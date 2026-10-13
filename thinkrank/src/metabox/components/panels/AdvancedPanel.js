/**
 * Advanced panel — canonical URL, redirect, pillar content, and robots meta.
 */

import { __ } from '@wordpress/i18n';
import Input from '../../../admin/components/ui/Input';
import Select from '../../../admin/components/ui/Select';
import Toggle from '../../../admin/components/ui/Toggle';
import PanelCard from '../ui/PanelCard';
import Field from '../ui/Field';
import { IconSliders } from '../ui/icons';
import PillarContentSuggestions from '../PillarContentSuggestions';
import MetaboxRobotsTab from '../MetaboxRobotsTab';

const REDIRECT_TYPE_LABELS = {
	301: __('301 (Permanent)', 'thinkrank'),
	302: __('302 (Temporary)', 'thinkrank'),
	307: __('307 (Temporary, keeps method)', 'thinkrank'),
};

const AdvancedPanel = ({
	formData,
	onFieldChange,
	onRobotsChange,
	linkSuggestionsEnabled,
	postId,
	redirectSupported = false,
	redirectTypes = [301, 302, 307],
}) => (
	<div className="tr-flex tr-flex-col tr-gap-4">
		<PanelCard
			icon={<IconSliders />}
			title={__('Advanced', 'thinkrank')}
			subtitle={__('Canonical URL and content settings.', 'thinkrank')}
			bodyClassName="tr-flex tr-flex-col tr-gap-5"
		>
			<Field
				label={__('Canonical URL', 'thinkrank')}
				htmlFor="thinkrank_canonical_url"
				help={__(
					'Override the canonical URL for this post. Leave empty to use the default permalink.',
					'thinkrank'
				)}
			>
				<Input
					id="thinkrank_canonical_url"
					type="url"
					value={formData.thinkrank_canonical_url}
					onChange={(e) =>
						onFieldChange('thinkrank_canonical_url', e.target.value)
					}
					placeholder={__(
						'https://example.com/canonical-url',
						'thinkrank'
					)}
				/>
			</Field>

			<Field
				label={__('Redirect this post to', 'thinkrank')}
				htmlFor="thinkrank_redirect_url"
				help={
					redirectSupported
						? __(
								'Visitors who open this post are sent here instead. Leave empty for no redirect.',
								'thinkrank'
							)
						: __(
								'Available with ThinkRank Pro. The redirect is stored as a rule in Redirections, where you can edit it later.',
								'thinkrank'
							)
				}
			>
				{/* Not type="url": a site path such as /new-page/ is a valid
				    destination, and the browser would flag it as invalid. */}
				<Input
					id="thinkrank_redirect_url"
					type="text"
					inputMode="url"
					disabled={!redirectSupported}
					value={formData.thinkrank_redirect_url}
					onChange={(e) =>
						onFieldChange('thinkrank_redirect_url', e.target.value)
					}
					placeholder={__(
						'https://example.com/replacement-page',
						'thinkrank'
					)}
				/>
			</Field>

			{/* The status code only means anything once there is a destination,
			    and an empty field is the common state, so it stays out of the
			    way until the editor has actually set a redirect. */}
			{redirectSupported && !!formData.thinkrank_redirect_url && (
				<Field
					label={__('Redirect type', 'thinkrank')}
					htmlFor="thinkrank_redirect_type"
					help={__(
						'Use 301 when the move is permanent. Search engines pass ranking signals on to the destination.',
						'thinkrank'
					)}
				>
					<Select
						id="thinkrank_redirect_type"
						value={String(formData.thinkrank_redirect_type || 301)}
						onChange={(e) =>
							onFieldChange(
								'thinkrank_redirect_type',
								Number(e.target.value)
							)
						}
						options={redirectTypes.map((code) => ({
							value: String(code),
							label: REDIRECT_TYPE_LABELS[code] || String(code),
						}))}
					/>
				</Field>
			)}

			<div className="tr-flex tr-flex-col tr-gap-3 tr-pt-4 tr-border-t tr-border-secondary">
				<p className="tr-text-xs tr-text-tertiary tr-m-0">
					{__(
						'These control this site only. Search engines are handled separately, under Robots Meta below.',
						'thinkrank'
					)}
				</p>
				<Toggle
					id="thinkrank_exclude_from_search"
					checked={!!formData.thinkrank_exclude_from_search}
					onChange={(value) =>
						onFieldChange('thinkrank_exclude_from_search', value)
					}
					label={__(
						"Hide from this site's search results",
						'thinkrank'
					)}
					description={__(
						'Visitors searching your site will not see this post. It stays published and reachable at its own address.',
						'thinkrank'
					)}
				/>
				<Toggle
					id="thinkrank_exclude_from_archives"
					checked={!!formData.thinkrank_exclude_from_archives}
					onChange={(value) =>
						onFieldChange('thinkrank_exclude_from_archives', value)
					}
					label={__('Hide from archive pages', 'thinkrank')}
					description={__(
						'Keeps this post off category, tag, author and date listings, and off the blog home.',
						'thinkrank'
					)}
				/>
			</div>

			{linkSuggestionsEnabled && (
				<div className="tr-flex tr-flex-col tr-gap-3 tr-pt-4 tr-border-t tr-border-secondary">
					<Toggle
						checked={!!formData.thinkrank_pillar_content}
						onChange={(value) =>
							onFieldChange('thinkrank_pillar_content', value)
						}
						label={__('This post is Pillar Content', 'thinkrank')}
						description={__(
							'Mark cornerstone content to power internal link suggestions.',
							'thinkrank'
						)}
					/>
					<PillarContentSuggestions postId={postId} />
				</div>
			)}
		</PanelCard>

		{/* MetaboxRobotsTab renders its own card. */}
		<MetaboxRobotsTab
			robotsEnabled={formData.thinkrank_robots_meta_enabled}
			robotsMeta={formData.thinkrank_robots_meta}
			advancedRobotsMeta={formData.thinkrank_advanced_robots_meta}
			onChange={onRobotsChange}
		/>
	</div>
);

export default AdvancedPanel;
