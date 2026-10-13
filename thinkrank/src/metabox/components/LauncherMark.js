/**
 * ThinkRank launcher mark: the logo with a live SEO score badge.
 *
 * The block editor's pinned launcher has always shown a score alongside the
 * logo; the page-builder launchers (Elementor, Oxygen, Divi) render the bare
 * logo, which is why #257 asked for the score in the Bricks toolbar.
 *
 * The score reaches this component two ways, because a launcher usually lives
 * in a different React tree from the drawer that computes the score:
 *  - the initial value localized as `thinkrankMetabox.seoScore`, or the last
 *    broadcast value parked on `window.thinkrankLatestScore`;
 *  - the `thinkrank:score-updated` window event MetaboxApp fires whenever a
 *    score is recalculated, so the badge follows an in-builder re-analysis
 *    without a reload.
 *
 * Styling is inline rather than class-based so it survives a page builder's
 * own CSS reset, which is the environment this is mounted into.
 *
 * @package
 * @since 2.2.1
 */

import { useState, useEffect } from '@wordpress/element';
import ThinkRankLogo from './ThinkRankLogo';
import { scoreBadgeStyle } from '@utils/scoreScheme';

/**
 * Coerce a broadcast score to a rounded 0-100, or null when there isn't one.
 *
 * @param {*} value Raw score.
 * @return {number|null} Normalized score, or null when unusable.
 */
export const normalizeScore = (value) => {
	const num = Number(value);
	return Number.isFinite(num) && num > 0 ? Math.round(num) : null;
};

/**
 * The most recent score this page knows about.
 *
 * @return {number|null} Normalized score, or null before any analysis.
 */
export const readLatestScore = () =>
	normalizeScore(
		typeof window !== 'undefined'
			? (window.thinkrankLatestScore ?? window.thinkrankMetabox?.seoScore)
			: null
	);

/**
 * Logo plus score badge, kept in sync with the drawer.
 *
 * @param {Object} props      Component props.
 * @param {number} props.size Logo size in px.
 * @return {JSX.Element} The launcher mark.
 */
export default function LauncherMark({ size = 22 }) {
	const [score, setScore] = useState(readLatestScore);

	useEffect(() => {
		// Re-read once after mount: the drawer may have broadcast before this
		// (separate) tree attached its listener.
		setScore(readLatestScore());

		const onScore = (event) =>
			setScore(normalizeScore(event.detail?.score));
		window.addEventListener('thinkrank:score-updated', onScore);
		return () =>
			window.removeEventListener('thinkrank:score-updated', onScore);
	}, []);

	// Always show a badge. Before a post has been analyzed the score is null,
	// so fall back to 0 rather than making the mark jump when the first score
	// arrives. Matches the block editor's pinned launcher.
	const displayScore = score ?? 0;

	return (
		<span
			style={{
				position: 'relative',
				display: 'inline-flex',
				lineHeight: 0,
			}}
		>
			<ThinkRankLogo size={size} />
			<span
				aria-hidden="true"
				style={{
					...scoreBadgeStyle(displayScore),
					position: 'absolute',
					right: '-6px',
					bottom: '-5px',
					minWidth: '15px',
					height: '15px',
					padding: '0 3px',
					borderRadius: '999px',
					font: '600 9px/15px system-ui, -apple-system, sans-serif',
					textAlign: 'center',
					boxSizing: 'border-box',
					pointerEvents: 'none',
				}}
			>
				{displayScore}
			</span>
		</span>
	);
}
