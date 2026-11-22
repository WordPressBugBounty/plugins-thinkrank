/**
 * Regression Guard in the editor: what this page serves to search engines,
 * and what changed since the last check (#720).
 *
 * The check loads the published page the way a logged-out visitor does, so it
 * reflects everything that shapes the output — theme, builder, other plugins —
 * not only what ThinkRank stores. A draft cannot be loaded that way; the server
 * says so, and the message is shown as it comes.
 */

import { __ } from '@wordpress/i18n';

import Button from '../../admin/components/ui/Button';
import { SkeletonText } from '../../admin/components/ui/Skeleton';
import HeadCheckResult from '../../admin/components/regression-guard/HeadCheckResult';
import useHeadCheck from '../../admin/components/regression-guard/useHeadCheck';
import PanelCard from './ui/PanelCard';
import { IconCode } from './ui/icons';

const HeadCheckPanel = ({ postId }) => {
	const { view, loading, checking, error, check } = useHeadCheck(postId);

	return (
		<PanelCard
			icon={<IconCode />}
			title={__('What this page serves', 'thinkrank')}
			subtitle={__(
				'Check the live page before and after an update to see whether anything changed.',
				'thinkrank'
			)}
			action={
				<Button
					variant="secondary"
					size="sm"
					loading={checking}
					disabled={loading}
					onClick={check}
				>
					{__('Check now', 'thinkrank')}
				</Button>
			}
			bodyClassName="tr-flex tr-flex-col tr-gap-3"
		>
			{loading ? (
				<SkeletonText lines={2} />
			) : (
				<>
					{error && (
						<p className="tr-text-xs tr-text-secondary tr-m-0">
							{error}
						</p>
					)}
					<HeadCheckResult view={view} />
				</>
			)}
		</PanelCard>
	);
};

export default HeadCheckPanel;
