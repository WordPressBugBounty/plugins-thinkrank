/**
 * ThinkRank Hub mark: one centre joined to the sites around it.
 *
 * Shared by the Hub card and the header chip so the two always match.
 *
 * @package
 * @since 2.15.0
 */

const HubIcon = ({ size = 22 }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<circle cx="12" cy="12" r="3" />
		<circle cx="4.5" cy="4.5" r="2" />
		<circle cx="19.5" cy="4.5" r="2" />
		<circle cx="4.5" cy="19.5" r="2" />
		<circle cx="19.5" cy="19.5" r="2" />
		<path d="M9.9 9.9 5.9 5.9M14.1 9.9l4-4M9.9 14.1l-4 4M14.1 14.1l4 4" />
	</svg>
);

export default HubIcon;
