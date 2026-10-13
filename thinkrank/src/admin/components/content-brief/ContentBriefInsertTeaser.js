/**
 * Content Brief → "Insert into post" upsell teaser (free default).
 *
 * Rendered as the default value of the `thinkrank.contentBrief.resultActions`
 * slot, so free users still see the "Insert into post" action — with a Pro
 * badge. When the Pro plugin is active it overrides this slot with the real,
 * working button. Clicking here opens the shared ProTeaser upsell in a modal.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Modal } from '../ui';
import ProTeaser from '../pro/ProTeaser';
import { TopContentIllustration } from '../pro/pro-teaser-illustrations';

const PlusIcon = () => (
	<svg
		className="tr-w-3.5 tr-h-3.5"
		fill="none"
		stroke="currentColor"
		viewBox="0 0 24 24"
	>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M12 4v16m8-8H4"
		/>
	</svg>
);

const ContentBriefInsertTeaser = () => {
	const [isOpen, setIsOpen] = useState(false);

	return (
		<>
			<Button
				variant="primary"
				size="sm"
				onClick={() => setIsOpen(true)}
				title={__(
					'Insert this brief into a draft post (Pro)',
					'thinkrank'
				)}
			>
				<PlusIcon />
				{__('Insert into post', 'thinkrank')}
				<span className="tr-ml-1 tr-inline-flex tr-items-center tr-px-1.5 tr-py-0.5 tr-rounded tr-text-[10px] tr-font-bold tr-uppercase tr-tracking-wide tr-bg-white/25 tr-text-white">
					{__('Pro', 'thinkrank')}
				</span>
			</Button>

			<Modal
				isOpen={isOpen}
				onClose={() => setIsOpen(false)}
				title={__('Insert into Post', 'thinkrank')}
				size="xl"
			>
				<ProTeaser
					illustration={<TopContentIllustration />}
					title={__('Insert Brief into a Post', 'thinkrank')}
					description={__(
						'Turn your content brief into a ready-to-edit draft in one click. ThinkRank Pro creates a draft post from the brief’s outline and fills in the SEO title, meta description, and focus keyword for you.',
						'thinkrank'
					)}
					features={[
						__(
							'Create a draft post from your brief’s outline with one click',
							'thinkrank'
						),
						__(
							'Auto-fill the SEO title, meta description, and focus keyword',
							'thinkrank'
						),
						__(
							'Skip the copy-paste and jump straight to writing',
							'thinkrank'
						),
					]}
				/>
			</Modal>
		</>
	);
};

export default ContentBriefInsertTeaser;
