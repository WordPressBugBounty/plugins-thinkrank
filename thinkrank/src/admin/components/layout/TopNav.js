import { __ } from '@wordpress/i18n';
import classnames from 'classnames';
import NavSearch from './NavSearch';
import {
	navigate,
	getPageUrl,
	canAccess,
	isPlainLeftClick,
} from '../../utils/router';
import { prefetchPage } from '../../utils/prefetch';
import { useHubConnected } from '../../utils/hubStatus';
import { openHubTab } from '../../utils/hubTab';
import HubIcon from '../common/HubIcon';

const ThinkRankLogo = () => (
	<svg
		width="29"
		height="29"
		viewBox="0 0 29 29"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		xmlnsXlink="http://www.w3.org/1999/xlink"
		aria-hidden="true"
	>
		<g filter="url(#filter0_ii_1426_17776)">
			<g clipPath="url(#clip0_1426_17776)">
				<g filter="url(#filter1_i_1426_17776)">
					<path
						d="M0.436523 6.61665C0.436523 3.15762 3.24063 0.353516 6.69966 0.353516H22.1734C25.6324 0.353516 28.4365 3.15762 28.4365 6.61665V22.0904C28.4365 25.5494 25.6324 28.3535 22.1734 28.3535H6.69966C3.24063 28.3535 0.436523 25.5494 0.436523 22.0904V6.61665Z"
						fill="url(#paint0_linear_1426_17776)"
					/>
				</g>
				<g filter="url(#filter2_f_1426_17776)">
					<path
						d="M7.5174 21.5838C3.89577 20.9253 2.76064 20.6108 -0.0576172 22.8811V30.3506H29.5615L29.1584 8.37305C28.9585 8.37314 22.824 10.9411 19.833 13.9C16.0579 18.7189 13.4633 22.6649 7.5174 21.5838Z"
						fill="url(#paint1_linear_1426_17776)"
					/>
				</g>
				<g filter="url(#filter3_dii_1426_17776)">
					<path
						d="M29.1618 8.11914C29.1618 8.11914 29.1622 8.11903 29.3906 8.71865C29.6058 9.28347 29.6182 9.31627 29.6189 9.31817C29.6189 9.31817 29.6185 9.3185 29.6182 9.31862C29.6176 9.31886 29.6166 9.31924 29.6153 9.31976C29.6124 9.32086 29.6078 9.32246 29.6018 9.32477C29.5898 9.32942 29.5714 9.33652 29.5471 9.34596C29.4986 9.36487 29.4265 9.39344 29.3332 9.43073C29.1465 9.50532 28.8754 9.61538 28.5404 9.75703C27.8699 10.0405 26.9452 10.449 25.9291 10.9483C23.8797 11.9554 21.5331 13.2968 20.1165 14.6951C19.2976 15.5641 18.5831 16.4248 17.898 17.2522C17.2153 18.0766 16.555 18.8765 15.8671 19.588C14.477 21.0257 12.9316 22.1479 10.6959 22.53C9.60585 22.7163 8.53683 22.6112 7.52835 22.4516C6.48364 22.2863 5.5662 22.0772 4.59619 21.989C3.82718 21.9191 3.16045 21.9788 2.48024 22.211C1.79208 22.4458 1.05578 22.8689 0.16582 23.5725L-0.629883 22.5658C0.329559 21.8073 1.19422 21.2939 2.06576 20.9965C2.94522 20.6963 3.79701 20.6277 4.7124 20.7109C5.73306 20.8037 6.79732 21.0365 7.7291 21.184C8.69711 21.3372 9.59965 21.4156 10.4799 21.2651C12.3522 20.9451 13.6648 20.0196 14.9444 18.6962C15.5914 18.027 16.2189 17.2678 16.9095 16.4337C17.5953 15.6055 18.3371 14.7115 19.1918 13.8053L19.1994 13.7973L19.2073 13.7893C20.7824 12.2312 23.2976 10.8117 25.3631 9.79668C26.4061 9.28415 27.3538 8.86556 28.0407 8.5751C28.3843 8.4298 28.6633 8.31639 28.8569 8.239C28.9537 8.20031 29.0292 8.17052 29.0809 8.15036C29.1068 8.14028 29.1268 8.13259 29.1404 8.12734C29.1472 8.12472 29.1525 8.12281 29.1561 8.12142C29.1579 8.12073 29.1592 8.11998 29.1602 8.1196C29.1607 8.11941 29.1613 8.11925 29.1616 8.11914H29.1618Z"
						fill="url(#paint2_linear_1426_17776)"
					/>
				</g>
				<g filter="url(#filter4_dii_1426_17776)">
					<path
						d="M22.3437 13.4975C22.3437 14.5285 21.508 15.3642 20.477 15.3642C19.4461 15.3642 18.6104 14.5285 18.6104 13.4975C18.6104 12.4666 19.4461 11.6309 20.477 11.6309C21.508 11.6309 22.3437 12.4666 22.3437 13.4975Z"
						fill="url(#paint3_radial_1426_17776)"
					/>
					<path
						d="M20.4766 11.2734C21.7049 11.2734 22.7009 12.2688 22.7012 13.4971C22.7012 14.7256 21.7051 15.7217 20.4766 15.7217C19.2482 15.7214 18.2529 14.7254 18.2529 13.4971C18.2532 12.2689 19.2484 11.2737 20.4766 11.2734Z"
						stroke="white"
						strokeWidth="0.715555"
					/>
				</g>
				<g filter="url(#filter5_d_1426_17776)">
					<path
						d="M15.6201 6.78776C15.9391 6.88104 15.9391 7.33291 15.6201 7.42619L13.5873 8.02069C13.4784 8.05254 13.3933 8.13768 13.3614 8.24656L12.7669 10.2794C12.6736 10.5984 12.2218 10.5984 12.1285 10.2794L11.534 8.24656C11.5022 8.13768 11.417 8.05254 11.3081 8.02069L9.27529 7.42619C8.95631 7.33291 8.9563 6.88104 9.27528 6.78776L11.3081 6.19326C11.417 6.16141 11.5022 6.07627 11.534 5.96739L12.1285 3.93455C12.2218 3.61557 12.6736 3.61557 12.7669 3.93455L13.3614 5.96739C13.3933 6.07627 13.4784 6.16141 13.5873 6.19326L15.6201 6.78776Z"
						fill="url(#paint4_linear_1426_17776)"
					/>
				</g>
				<g filter="url(#filter6_d_1426_17776)">
					<path
						d="M11.6747 13.8907C11.8298 13.9361 11.8298 14.1557 11.6747 14.201L10.4245 14.5667C10.3716 14.5822 10.3302 14.6235 10.3147 14.6765L9.94909 15.9267C9.90375 16.0817 9.68413 16.0817 9.63879 15.9267L9.27317 14.6765C9.25769 14.6235 9.21631 14.5822 9.16339 14.5667L7.91315 14.201C7.75811 14.1557 7.75811 13.9361 7.91315 13.8907L9.16339 13.5251C9.21631 13.5096 9.25769 13.4683 9.27317 13.4153L9.63879 12.1651C9.68413 12.0101 9.90375 12.0101 9.94909 12.1651L10.3147 13.4153C10.3302 13.4683 10.3716 13.5096 10.4245 13.5251L11.6747 13.8907Z"
						fill="url(#paint5_linear_1426_17776)"
					/>
				</g>
			</g>
			<rect
				x="0.353516"
				y="0.353516"
				width="28"
				height="28"
				rx="7.76216"
				stroke="#B6CBFF"
				strokeWidth="0.707321"
			/>
		</g>
		<defs>
			<filter
				id="filter0_ii_1426_17776"
				x="0"
				y="0"
				width="29.3727"
				height="29.3727"
				filterUnits="userSpaceOnUse"
				colorInterpolationFilters="sRGB"
			>
				<feFlood floodOpacity="0" result="BackgroundImageFix" />
				<feBlend
					mode="normal"
					in="SourceGraphic"
					in2="BackgroundImageFix"
					result="shape"
				/>
				<feColorMatrix
					in="SourceAlpha"
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
					result="hardAlpha"
				/>
				<feOffset dx="0.665934" />
				<feGaussianBlur stdDeviation="0.332839" />
				<feComposite
					in2="hardAlpha"
					operator="arithmetic"
					k2="-1"
					k3="1"
				/>
				<feColorMatrix
					type="matrix"
					values="0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 0 0 0 0.4 0"
				/>
				<feBlend
					mode="normal"
					in2="shape"
					result="effect1_innerShadow_1426_17776"
				/>
				<feColorMatrix
					in="SourceAlpha"
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
					result="hardAlpha"
				/>
				<feOffset dy="0.666153" />
				<feGaussianBlur stdDeviation="0.332839" />
				<feComposite
					in2="hardAlpha"
					operator="arithmetic"
					k2="-1"
					k3="1"
				/>
				<feColorMatrix
					type="matrix"
					values="0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 0 0 0 0.4 0"
				/>
				<feBlend
					mode="normal"
					in2="effect1_innerShadow_1426_17776"
					result="effect2_innerShadow_1426_17776"
				/>
			</filter>
			<filter
				id="filter1_i_1426_17776"
				x="0.436523"
				y="0.353516"
				width="28"
				height="28.6657"
				filterUnits="userSpaceOnUse"
				colorInterpolationFilters="sRGB"
			>
				<feFlood floodOpacity="0" result="BackgroundImageFix" />
				<feBlend
					mode="normal"
					in="SourceGraphic"
					in2="BackgroundImageFix"
					result="shape"
				/>
				<feColorMatrix
					in="SourceAlpha"
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
					result="hardAlpha"
				/>
				<feOffset dy="0.666153" />
				<feGaussianBlur stdDeviation="0.332839" />
				<feComposite
					in2="hardAlpha"
					operator="arithmetic"
					k2="-1"
					k3="1"
				/>
				<feColorMatrix
					type="matrix"
					values="0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 0 0 0 0.4 0"
				/>
				<feBlend
					mode="normal"
					in2="shape"
					result="effect1_innerShadow_1426_17776"
				/>
			</filter>
			<filter
				id="filter2_f_1426_17776"
				x="-0.810117"
				y="7.62055"
				width="31.1241"
				height="23.4835"
				filterUnits="userSpaceOnUse"
				colorInterpolationFilters="sRGB"
			>
				<feFlood floodOpacity="0" result="BackgroundImageFix" />
				<feBlend
					mode="normal"
					in="SourceGraphic"
					in2="BackgroundImageFix"
					result="shape"
				/>
				<feGaussianBlur
					stdDeviation="0.37625"
					result="effect1_foregroundBlur_1426_17776"
				/>
			</filter>
			<filter
				id="filter3_dii_1426_17776"
				x="-3.13822"
				y="6.23789"
				width="35.2657"
				height="20.4698"
				filterUnits="userSpaceOnUse"
				colorInterpolationFilters="sRGB"
			>
				<feFlood floodOpacity="0" result="BackgroundImageFix" />
				<feColorMatrix
					in="SourceAlpha"
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
					result="hardAlpha"
				/>
				<feOffset dy="0.627083" />
				<feGaussianBlur stdDeviation="1.25417" />
				<feComposite in2="hardAlpha" operator="out" />
				<feColorMatrix
					type="matrix"
					values="0 0 0 0 0.315797 0 0 0 0 0 0 0 0 0 0.371526 0 0 0 0.25 0"
				/>
				<feBlend
					mode="normal"
					in2="BackgroundImageFix"
					result="effect1_dropShadow_1426_17776"
				/>
				<feBlend
					mode="normal"
					in="SourceGraphic"
					in2="effect1_dropShadow_1426_17776"
					result="shape"
				/>
				<feColorMatrix
					in="SourceAlpha"
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
					result="hardAlpha"
				/>
				<feOffset dy="0.125417" />
				<feGaussianBlur stdDeviation="0.0627083" />
				<feComposite
					in2="hardAlpha"
					operator="arithmetic"
					k2="-1"
					k3="1"
				/>
				<feColorMatrix
					type="matrix"
					values="0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 0 0 0 0.7 0"
				/>
				<feBlend
					mode="normal"
					in2="shape"
					result="effect2_innerShadow_1426_17776"
				/>
				<feColorMatrix
					in="SourceAlpha"
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
					result="hardAlpha"
				/>
				<feOffset dy="-0.125417" />
				<feGaussianBlur stdDeviation="0.37625" />
				<feComposite
					in2="hardAlpha"
					operator="arithmetic"
					k2="-1"
					k3="1"
				/>
				<feColorMatrix
					type="matrix"
					values="0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 0 0 0 0.5 0"
				/>
				<feBlend
					mode="normal"
					in2="effect2_innerShadow_1426_17776"
					result="effect3_innerShadow_1426_17776"
				/>
			</filter>
			<filter
				id="filter4_dii_1426_17776"
				x="13.9265"
				y="8.93202"
				width="13.101"
				height="13.1001"
				filterUnits="userSpaceOnUse"
				colorInterpolationFilters="sRGB"
			>
				<feFlood floodOpacity="0" result="BackgroundImageFix" />
				<feColorMatrix
					in="SourceAlpha"
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
					result="hardAlpha"
				/>
				<feOffset dy="1.984" />
				<feGaussianBlur stdDeviation="1.984" />
				<feComposite in2="hardAlpha" operator="out" />
				<feColorMatrix
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0.0528337 0 0 0 0 0.619328 0 0 0 0.3 0"
				/>
				<feBlend
					mode="normal"
					in2="BackgroundImageFix"
					result="effect1_dropShadow_1426_17776"
				/>
				<feBlend
					mode="normal"
					in="SourceGraphic"
					in2="effect1_dropShadow_1426_17776"
					result="shape"
				/>
				<feColorMatrix
					in="SourceAlpha"
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
					result="hardAlpha"
				/>
				<feOffset dy="0.238518" />
				<feGaussianBlur stdDeviation="0.238518" />
				<feComposite
					in2="hardAlpha"
					operator="arithmetic"
					k2="-1"
					k3="1"
				/>
				<feColorMatrix
					type="matrix"
					values="0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 0 0 0 0.4 0"
				/>
				<feBlend
					mode="normal"
					in2="shape"
					result="effect2_innerShadow_1426_17776"
				/>
				<feColorMatrix
					in="SourceAlpha"
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
					result="hardAlpha"
				/>
				<feOffset dy="0.238518" />
				<feComposite
					in2="hardAlpha"
					operator="arithmetic"
					k2="-1"
					k3="1"
				/>
				<feColorMatrix
					type="matrix"
					values="0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 0 0 0 0.05 0"
				/>
				<feBlend
					mode="normal"
					in2="effect2_innerShadow_1426_17776"
					result="effect3_innerShadow_1426_17776"
				/>
			</filter>
			<filter
				id="filter5_d_1426_17776"
				x="6.94974"
				y="2.65211"
				width="10.996"
				height="10.997"
				filterUnits="userSpaceOnUse"
				colorInterpolationFilters="sRGB"
			>
				<feFlood floodOpacity="0" result="BackgroundImageFix" />
				<feColorMatrix
					in="SourceAlpha"
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
					result="hardAlpha"
				/>
				<feOffset dy="1.0432" />
				<feGaussianBlur stdDeviation="1.0432" />
				<feComposite in2="hardAlpha" operator="out" />
				<feColorMatrix
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0.15 0"
				/>
				<feBlend
					mode="normal"
					in2="BackgroundImageFix"
					result="effect1_dropShadow_1426_17776"
				/>
				<feBlend
					mode="normal"
					in="SourceGraphic"
					in2="effect1_dropShadow_1426_17776"
					result="shape"
				/>
			</filter>
			<filter
				id="filter6_d_1426_17776"
				x="5.71048"
				y="11.0056"
				width="8.16694"
				height="8.16694"
				filterUnits="userSpaceOnUse"
				colorInterpolationFilters="sRGB"
			>
				<feFlood floodOpacity="0" result="BackgroundImageFix" />
				<feColorMatrix
					in="SourceAlpha"
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
					result="hardAlpha"
				/>
				<feOffset dy="1.0432" />
				<feGaussianBlur stdDeviation="1.0432" />
				<feComposite in2="hardAlpha" operator="out" />
				<feColorMatrix
					type="matrix"
					values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0.15 0"
				/>
				<feBlend
					mode="normal"
					in2="BackgroundImageFix"
					result="effect1_dropShadow_1426_17776"
				/>
				<feBlend
					mode="normal"
					in="SourceGraphic"
					in2="effect1_dropShadow_1426_17776"
					result="shape"
				/>
			</filter>
			<linearGradient
				id="paint0_linear_1426_17776"
				x1="14.4365"
				y1="0.353516"
				x2="14.4365"
				y2="28.3535"
				gradientUnits="userSpaceOnUse"
			>
				<stop stopColor="#5A57FF" />
				<stop offset="1" stopColor="#5B98FF" />
			</linearGradient>
			<linearGradient
				id="paint1_linear_1426_17776"
				x1="18.3943"
				y1="15.3016"
				x2="18.3943"
				y2="40.2595"
				gradientUnits="userSpaceOnUse"
			>
				<stop offset="0.0831429" stopColor="#4B73FF" />
				<stop offset="0.359704" stopColor="#4B73FF" stopOpacity="0" />
				<stop offset="0.7" stopColor="#4B73FF" stopOpacity="0" />
			</linearGradient>
			<linearGradient
				id="paint2_linear_1426_17776"
				x1="29.2845"
				y1="8.77232"
				x2="0.650625"
				y2="22.9504"
				gradientUnits="userSpaceOnUse"
			>
				<stop stopColor="#BDC1FF" />
				<stop offset="0.420828" stopColor="#D6E8FF" />
				<stop offset="1" stopColor="#7391FE" />
			</linearGradient>
			<radialGradient
				id="paint3_radial_1426_17776"
				cx="0"
				cy="0"
				r="1"
				gradientUnits="userSpaceOnUse"
				gradientTransform="translate(22.1572 11.6007) rotate(134.421) scale(4.39003)"
			>
				<stop offset="0.321315" stopColor="#BAFEFF" />
				<stop offset="1" stopColor="#011FFF" />
			</radialGradient>
			<linearGradient
				id="paint4_linear_1426_17776"
				x1="9.87102"
				y1="1.28287"
				x2="14.9766"
				y2="13.0695"
				gradientUnits="userSpaceOnUse"
			>
				<stop stopColor="#FFE6FC" />
				<stop offset="0.350962" stopColor="#FFE6FC" />
				<stop offset="1" stopColor="#AA00F2" />
			</linearGradient>
			<linearGradient
				id="paint5_linear_1426_17776"
				x1="8.28563"
				y1="10.6367"
				x2="11.2743"
				y2="17.5362"
				gradientUnits="userSpaceOnUse"
			>
				<stop stopColor="#FFE6FC" />
				<stop offset="0.350962" stopColor="#FFE6FC" />
				<stop offset="1" stopColor="#AA00F2" />
			</linearGradient>
			<clipPath id="clip0_1426_17776">
				<rect
					x="0.353516"
					y="0.353516"
					width="28"
					height="28"
					rx="7.76216"
					fill="white"
				/>
			</clipPath>
		</defs>
	</svg>
);

const IconDocs = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
		<path d="M14 2v6h6" />
		<path d="M8 13h8M8 17h8M8 9h2" />
	</svg>
);

const IconCalendar = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<rect x="3" y="4.5" width="18" height="16" rx="2.5" />
		<path d="M3 9h18M8 3v3M16 3v3" />
		<path d="M8.5 14.5l2.2 2.2 4.3-4.6" />
	</svg>
);

const IconUpgrade = () => (
	<svg
		width="12"
		height="12"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<path d="M12 2L2 7l10 5 10-5-10-5z" />
		<path d="M2 17l10 5 10-5" />
		<path d="M2 12l10 5 10-5" />
	</svg>
);

const NAV_ITEMS = [
	{ id: 'dashboard', label: __('Dashboard', 'thinkrank') },
	{ id: 'essential-seo', label: __('Essential SEO', 'thinkrank') },
	{ id: 'ai-tools', label: __('AI Tools', 'thinkrank') },
	{ id: 'analytics', label: __('Usages', 'thinkrank') },
	{ id: 'settings', label: __('Settings', 'thinkrank') },
];

/**
 * The ThinkRank Hub chip: always shown to administrators, connected or not,
 * and opening AI Tools on the ThinkRank Hub tab.
 */
const HubChip = () => {
	const connected = useHubConnected();
	const label = connected
		? __('Hub connected', 'thinkrank')
		: __('Connect Hub', 'thinkrank');

	return (
		<a
			href={getPageUrl('ai-tools', { tab: 'hub' })}
			onClick={(event) => {
				if (isPlainLeftClick(event)) {
					event.preventDefault();
					openHubTab();
				}
			}}
			className={classnames(
				'tr-hidden md:tr-inline-flex tr-items-center tr-gap-1.5 tr-px-2.5 tr-py-1.5 tr-rounded-md tr-text-xs tr-font-semibold tr-no-underline tr-transition-colors',
				connected
					? 'tr-bg-success-bg tr-text-success hover:tr-brightness-95'
					: 'tr-bg-white tr-text-secondary hover:tr-bg-line-2'
			)}
			title={
				connected
					? __('This site is connected to ThinkRank Hub', 'thinkrank')
					: __('Connect this site to ThinkRank Hub', 'thinkrank')
			}
		>
			<HubIcon size={14} />
			{label}
			{connected && (
				<span
					className="tr-w-1.5 tr-h-1.5 tr-rounded-full tr-bg-success"
					aria-hidden="true"
				/>
			)}
		</a>
	);
};

export default function TopNav({ currentPage }) {
	const isPro = window.thinkrankAdmin?.isPro;
	const version = window.thinkrankAdmin?.version || '1.0.0';
	// The Hub card, and so the chip, is for administrators only.
	const showHub = !!window.thinkrankAdmin?.isAdmin && canAccess('ai-tools');

	return (
		<nav
			className="tr-flex tr-items-center tr-h-14 tr-shrink-0 tr-bg-white tr-border-b tr-border-secondary tr-px-4 tr-gap-3"
			style={{ boxShadow: '0 1px 3px 0 rgba(0,0,0,0.04)' }}
			aria-label={__('ThinkRank navigation', 'thinkrank')}
		>
			{/* Brand */}
			<div className="tr-flex tr-items-center tr-gap-2 tr-shrink-0">
				<span className="tr-flex tr-items-center tr-justify-center tr-shrink-0">
					<ThinkRankLogo />
				</span>
				<span
					className="tr-hidden md:tr-block tr-text-sm tr-font-bold tr-text-primary"
					style={{ letterSpacing: '-0.01em' }}
				>
					ThinkRank
				</span>
				{isPro && (
					<span className="tr-inline-flex tr-items-center tr-px-1.5 tr-py-0.5 tr-rounded tr-bg-brand-bg tr-text-brand tr-text-[10px] tr-font-semibold tr-leading-none">
						PRO
					</span>
				)}
			</div>

			{/* Divider */}
			<div
				className="tr-w-px tr-h-5 tr-bg-line-2 tr-shrink-0"
				aria-hidden="true"
			/>

			{/* Nav links */}
			<ul className="tr-flex tr-items-stretch tr-gap-0.5 tr-list-none tr-m-0 tr-p-0 tr-flex-1 tr-h-full">
				{NAV_ITEMS.filter((item) => canAccess(item.id)).map((item) => {
					const isActive = currentPage === item.id;
					const prefetch = () => {
						if (!isActive) {
							prefetchPage(item.id);
						}
					};
					return (
						<li key={item.id} className="tr-m-0 tr-p-0 tr-flex">
							<a
								href={getPageUrl(item.id)}
								onMouseEnter={prefetch}
								onFocus={prefetch}
								onClick={(event) => {
									// SPA navigation on plain left-clicks; the real href
									// keeps middle-click / new-tab / copy-link working.
									if (isPlainLeftClick(event)) {
										event.preventDefault();
										navigate(item.id);
									}
								}}
								className={classnames(
									'tr-relative tr-inline-flex tr-items-center tr-h-full tr-px-2.5 md:tr-px-3',
									'tr-text-xs md:tr-text-sm tr-font-medium tr-no-underline tr-whitespace-nowrap tr-outline-none',
									'tr-transition-colors tr-duration-150',
									isActive
										? 'tr-text-primary'
										: 'tr-text-tertiary hover:tr-text-secondary'
								)}
								aria-current={isActive ? 'page' : undefined}
							>
								{item.label}
								{isActive && (
									<span
										className="tr-absolute tr-left-2 tr-right-2 tr-h-0.5 tr-bg-brand"
										style={{ bottom: '-1px' }}
										aria-hidden="true"
									/>
								)}
							</a>
						</li>
					);
				})}
			</ul>

			{/* Search */}
			<div className="tr-shrink-0">
				<NavSearch currentPage={currentPage} />
			</div>

			{/* Right utilities */}
			<div className="tr-flex tr-items-center tr-gap-1.5 tr-shrink-0 tr-pl-3 tr-border-l tr-border-secondary">
				{showHub && <HubChip />}

				{/* Book a free onboarding call */}
				<a
					href="https://calendly.com/thinkrank/onboarding"
					target="_blank"
					rel="noopener noreferrer"
					className="tr-hidden md:tr-inline-flex tr-items-center tr-gap-1.5 tr-px-2.5 tr-py-1.5 tr-rounded-md tr-border tr-border-primary tr-bg-white tr-text-secondary tr-text-xs tr-font-semibold tr-no-underline tr-transition-colors hover:tr-bg-line-2"
					title={__(
						'Book a free 30-minute onboarding call',
						'thinkrank'
					)}
				>
					<IconCalendar />
					{__('Book a Free Call', 'thinkrank')}
				</a>

				{/* Help */}
				<a
					href="https://thinkrank.ai/docs"
					target="_blank"
					rel="noopener noreferrer"
					className="tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-rounded-md tr-text-tertiary hover:tr-text-secondary hover:tr-bg-white tr-no-underline tr-transition-colors"
					title={__('Documentation', 'thinkrank')}
					aria-label={__('Documentation', 'thinkrank')}
				>
					<IconDocs />
				</a>

				{!isPro && (
					<a
						href="https://thinkrank.ai/#pricing"
						target="_blank"
						rel="noopener noreferrer"
						className="tr-hidden lg:tr-inline-flex tr-items-center tr-gap-1.5 tr-px-2.5 tr-py-1.5 tr-rounded-md tr-bg-[linear-gradient(90deg,#4c4cff_58%,#3535b0_118%)] tr-text-white tr-text-xs tr-font-semibold tr-no-underline tr-transition-all hover:tr-brightness-110"
						style={{ boxShadow: '0 1px 4px rgba(68,81,255,0.3)' }}
					>
						<IconUpgrade />
						{__('Upgrade', 'thinkrank')}
					</a>
				)}

				<span className="tr-hidden xl:tr-block tr-text-[11px] tr-text-tertiary tr-pl-1">
					v{version}
				</span>
			</div>
		</nav>
	);
}
