/**
 * Metric card icons.
 *
 * White line glyphs sized for the 36×36 colored tile on the Clicks /
 * Impressions cards — mirrors the icons used on Analytics › Dashboard.
 *
 * @package
 * @since 1.0.0
 */

/**
 * Click / cursor icon (Total Clicks card).
 *
 * @return {JSX.Element} SVG icon.
 */
export function ClicksIcon() {
	return (
		<svg
			width="20"
			height="20"
			viewBox="0 0 20 20"
			fill="none"
			aria-hidden="true"
			xmlns="http://www.w3.org/2000/svg"
		>
			<path
				d="M9 2.5C9 1.948 9.448 1.5 10 1.5C10.552 1.5 11 1.948 11 2.5V9"
				stroke="white"
				strokeWidth="1.5"
				strokeLinecap="round"
			/>
			<path
				d="M11 8V6C11 5.448 11.448 5 12 5C12.552 5 13 5.448 13 6V9"
				stroke="white"
				strokeWidth="1.5"
				strokeLinecap="round"
			/>
			<path
				d="M13 8V6.5C13 5.948 13.448 5.5 14 5.5C14.552 5.5 15 5.948 15 6.5V10.5"
				stroke="white"
				strokeWidth="1.5"
				strokeLinecap="round"
			/>
			<path
				d="M7 5C7 4.448 7.448 4 8 4C8.552 4 9 4.448 9 5V9"
				stroke="white"
				strokeWidth="1.5"
				strokeLinecap="round"
			/>
			<path
				d="M7 9.5C7 9.5 5.5 9.5 5.5 11.5C5.5 12.828 6 14 7 15C7.833 15.833 9 16.5 10.5 16.5H12C13.657 16.5 15 15.157 15 13.5V10.5"
				stroke="white"
				strokeWidth="1.5"
				strokeLinecap="round"
				strokeLinejoin="round"
			/>
		</svg>
	);
}

/**
 * Ascending bar-chart icon (Total Impressions card).
 *
 * @return {JSX.Element} SVG icon.
 */
export function ImpressionsIcon() {
	return (
		<svg
			width="20"
			height="20"
			viewBox="0 0 20 20"
			fill="none"
			aria-hidden="true"
			xmlns="http://www.w3.org/2000/svg"
		>
			<path
				d="M2.5 2.5V17.5H17.5"
				stroke="white"
				strokeWidth="1.5"
				strokeLinecap="round"
				strokeLinejoin="round"
			/>
			<path
				d="M6.5 14V15.5"
				stroke="white"
				strokeWidth="1.5"
				strokeLinecap="round"
			/>
			<path
				d="M9.5 12V15.5"
				stroke="white"
				strokeWidth="1.5"
				strokeLinecap="round"
			/>
			<path
				d="M12.5 9.5V15.5"
				stroke="white"
				strokeWidth="1.5"
				strokeLinecap="round"
			/>
			<path
				d="M15.5 6.5V15.5"
				stroke="white"
				strokeWidth="1.5"
				strokeLinecap="round"
			/>
			<path
				d="M2.5 10.5C6.5 10.5 6 6.5 9.5 6.5C13 6.5 12.5 10.5 17.5 8"
				stroke="white"
				strokeWidth="1.5"
				strokeLinecap="round"
				strokeLinejoin="round"
				strokeOpacity="0.55"
			/>
		</svg>
	);
}
