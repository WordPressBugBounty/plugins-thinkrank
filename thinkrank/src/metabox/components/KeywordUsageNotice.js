/**
 * KeywordUsageNotice
 *
 * Warns when a focus keyword is already used as the focus keyword on other
 * posts (Rank Math's "You have already used this Focus Keyword" status).
 * Self-contained: debounced-fetches /thinkrank/v1/focus-keyword-usage for the
 * current keywords and renders a per-keyword warning with a link to the first
 * conflicting post. Reads the post id from the localized metabox config.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const KeywordUsageNotice = ({ keywords = [] }) => {
	const [usage, setUsage] = useState([]);
	const timerRef = useRef(null);
	const postId = window.thinkrankMetabox?.postId;

	const normalized = (Array.isArray(keywords) ? keywords : [])
		.map((k) => String(k).trim())
		.filter(Boolean);

	// Stable dependency: the lowercased keyword set. Avoids refetching when the
	// array identity changes but the values don't.
	const signature = normalized.join('|').toLowerCase();

	useEffect(() => {
		if (!postId || normalized.length === 0) {
			setUsage([]);
			return undefined;
		}

		if (timerRef.current) {
			clearTimeout(timerRef.current);
		}

		timerRef.current = setTimeout(() => {
			apiFetch({
				path:
					'/thinkrank/v1/focus-keyword-usage?post_id=' +
					encodeURIComponent(postId) +
					'&keywords=' +
					encodeURIComponent(JSON.stringify(normalized)),
			})
				.then((res) => {
					if (res?.success && Array.isArray(res.keywords)) {
						setUsage(res.keywords.filter((k) => k.count > 0));
					} else {
						setUsage([]);
					}
				})
				.catch(() => setUsage([]));
		}, 600);

		return () => {
			if (timerRef.current) {
				clearTimeout(timerRef.current);
			}
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [signature, postId]);

	if (usage.length === 0) {
		return null;
	}

	return (
		<div className="tr-flex tr-flex-col tr-gap-1.5 tr-mt-2">
			{usage.map((item) => {
				const firstPost = item.posts?.[0];
				const linkLabel =
					item.count > 1
						? sprintf(
								/* translators: %d: number of other posts */
								__('used on %d posts', 'thinkrank'),
								item.count
							)
						: __('view post', 'thinkrank');

				return (
					<p
						key={item.keyword}
						className="tr-flex tr-items-start tr-gap-1.5 tr-text-xs tr-text-secondary tr-m-0"
					>
						<span
							aria-hidden="true"
							style={{ color: '#d63638', lineHeight: '1.4' }}
						>
							&#9888;
						</span>
						<span>
							{sprintf(
								/* translators: %s: focus keyword */
								__(
									'You have already used the focus keyword “%s”.',
									'thinkrank'
								),
								item.keyword
							)}{' '}
							{firstPost?.edit && (
								<a
									href={firstPost.edit}
									target="_blank"
									rel="noopener noreferrer"
								>
									{linkLabel}
								</a>
							)}
						</span>
					</p>
				);
			})}
		</div>
	);
};

export default KeywordUsageNotice;
