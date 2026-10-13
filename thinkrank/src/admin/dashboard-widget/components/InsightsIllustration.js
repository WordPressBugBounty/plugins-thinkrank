/**
 * Search Insights illustration.
 *
 * A lightweight, self-contained flat illustration (a dashboard being inspected
 * with a magnifying glass) for the widget's empty state. Uses the ThinkRank
 * brand color and adapts to the surrounding card background.
 *
 * @package
 * @since 1.0.0
 */

export default function InsightsIllustration() {
	return (
		<svg
			className="thinkrank-dashboard-widget__illustration-svg"
			viewBox="0 0 320 180"
			role="img"
			aria-hidden="true"
			focusable="false"
			xmlns="http://www.w3.org/2000/svg"
		>
			{/* Screen / dashboard */}
			<rect
				x="86"
				y="34"
				width="148"
				height="104"
				rx="8"
				fill="#ffffff"
				stroke="#C7CBE6"
				strokeWidth="2"
			/>
			{/* Chart bars */}
			<rect x="104" y="96" width="12" height="26" rx="2" fill="#D5D9F5" />
			<rect x="122" y="82" width="12" height="40" rx="2" fill="#B9BFF0" />
			<rect x="140" y="70" width="12" height="52" rx="2" fill="#4451FF" />
			{/* Text lines */}
			<rect x="104" y="50" width="52" height="6" rx="3" fill="#E3E6F7" />
			<rect x="104" y="62" width="34" height="6" rx="3" fill="#EDEFFb" />
			{/* Donut */}
			<circle
				cx="196"
				cy="74"
				r="20"
				fill="none"
				stroke="#E3E6F7"
				strokeWidth="8"
			/>
			<path
				d="M196 54 a20 20 0 0 1 17.3 30"
				fill="none"
				stroke="#4451FF"
				strokeWidth="8"
				strokeLinecap="round"
			/>
			<rect x="176" y="104" width="40" height="6" rx="3" fill="#E3E6F7" />
			<rect x="176" y="116" width="28" height="6" rx="3" fill="#EDEFFb" />
			{/* Magnifying glass */}
			<circle
				cx="150"
				cy="78"
				r="30"
				fill="#4451FF"
				fillOpacity="0.08"
				stroke="#4451FF"
				strokeWidth="4"
			/>
			<line
				x1="171"
				y1="99"
				x2="188"
				y2="116"
				stroke="#4451FF"
				strokeWidth="6"
				strokeLinecap="round"
			/>
			{/* Floating tags */}
			<rect
				x="46"
				y="52"
				width="34"
				height="24"
				rx="4"
				fill="#ffffff"
				stroke="#C7CBE6"
				strokeWidth="2"
			/>
			<rect x="52" y="59" width="22" height="4" rx="2" fill="#E3E6F7" />
			<rect x="52" y="66" width="14" height="4" rx="2" fill="#EDEFFb" />
			<rect
				x="240"
				y="60"
				width="34"
				height="24"
				rx="4"
				fill="#ffffff"
				stroke="#C7CBE6"
				strokeWidth="2"
			/>
			<rect x="246" y="67" width="22" height="4" rx="2" fill="#E3E6F7" />
			<rect x="246" y="74" width="14" height="4" rx="2" fill="#EDEFFb" />
		</svg>
	);
}
