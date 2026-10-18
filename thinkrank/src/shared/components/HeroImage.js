/**
 * ThinkRank hero image
 *
 * The animated hero (Remotion render, 760x540 animated WebP, ~360 KB): an
 * assistant asks for SEO fixes, the request travels over MCP, and the SEO
 * score climbs. It loops forever, so visitors who ask their system to reduce
 * motion get a still of the finished scene instead (WCAG 2.2.2).
 *
 * Shared by the dashboard welcome card and the setup wizard's first step.
 *
 * @since 2.17.0
 */
import heroAnimation from '../assets/thinkrank-hero.webp';
import heroStill from '../assets/thinkrank-hero-still.webp';

export const REDUCED_MOTION_MEDIA = '(prefers-reduced-motion: reduce)';

/**
 * @param {Object} props
 * @param {string} [props.pictureClassName] Class for the <picture> wrapper.
 * @param {Object} [props.pictureStyle]     Inline style for the wrapper.
 * @return {JSX.Element} The hero, animated unless reduced motion is on.
 */
export default function HeroImage({
	pictureClassName,
	pictureStyle,
	...imgProps
}) {
	return (
		<picture className={pictureClassName} style={pictureStyle}>
			<source media={REDUCED_MOTION_MEDIA} srcSet={heroStill} />
			<img src={heroAnimation} alt="" {...imgProps} />
		</picture>
	);
}
