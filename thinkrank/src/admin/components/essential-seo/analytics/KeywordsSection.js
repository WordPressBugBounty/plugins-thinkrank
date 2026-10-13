/**
 * Keywords Section Component
 *
 * Detailed keyword performance data from Google Search Console.
 * Extracted from AnalyticsTab for maintainability.
 *
 * @package
 * @since 1.11.0
 */

import { __ } from '@wordpress/i18n';
import { Flex, __experimentalText as Text } from '@wordpress/components';
import KeywordsTab from '../KeywordsTab';
import { dateRangeOptions } from './utils';

/**
 * KeywordsSection Component
 * @param root0
 * @param root0.dateRange
 * @param root0.setDateRange
 * @param root0.dashboardData
 */
const KeywordsSection = ({ dateRange, setDateRange, dashboardData }) => (
	<div className="thinkrank-space-y-6">
		<Flex justify="space-between" align="center">
			<div>
				<Text variant="muted">
					{__(
						'Detailed keyword performance data from Google Search Console.',
						'thinkrank'
					)}
				</Text>
			</div>
			<div
				style={{
					position: 'relative',
					display: 'inline-flex',
					alignItems: 'center',
				}}
			>
				<select
					value={dateRange}
					onChange={(e) => setDateRange(e.target.value)}
					style={{
						appearance: 'none',
						WebkitAppearance: 'none',
						MozAppearance: 'none',
						background: '#fff',
						border: '1px solid #4451ff',
						borderRadius: '6px',
						padding: '8px 36px 8px 14px',
						fontSize: '14px',
						fontWeight: '600',
						color: 'var(--tr-accent)',
						cursor: 'pointer',
						outline: 'none',
						lineHeight: '1.4',
						boxShadow: 'none',
					}}
				>
					{dateRangeOptions.map((opt) => (
						<option key={opt.value} value={opt.value}>
							{opt.label}
						</option>
					))}
				</select>
				<span
					style={{
						position: 'absolute',
						right: '10px',
						top: '50%',
						transform: 'translateY(-50%)',
						pointerEvents: 'none',
						display: 'flex',
						alignItems: 'center',
					}}
				>
					<svg
						width="14"
						height="14"
						viewBox="0 0 14 14"
						fill="none"
						xmlns="http://www.w3.org/2000/svg"
					>
						<path
							d="M3 5L7 9L11 5"
							stroke="#4451ff"
							strokeWidth="1.5"
							strokeLinecap="round"
							strokeLinejoin="round"
						/>
					</svg>
				</span>
			</div>
		</Flex>

		{dashboardData && dashboardData.search_performance && (
			<KeywordsTab data={dashboardData.search_performance} />
		)}
	</div>
);

export default KeywordsSection;
