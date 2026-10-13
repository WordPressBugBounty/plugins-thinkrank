/**
 * Dashboard Section Component
 *
 * Main analytics dashboard with metric cards, charts, trends, and data visualization.
 * Extracted from AnalyticsTab for maintainability.
 *
 * @package
 * @since 1.11.0
 */

import { __ } from '@wordpress/i18n';
import { SkeletonCard } from '../../ui';
import ProTeaser from '../../pro/ProTeaser';
import ProLicenseNotice from '../../pro/ProLicenseNotice';
import { isProUnlicensed } from '../../pro/proPlan';
import {
	GoogleAnalyticsIllustration,
	SearchQueriesIllustration,
	TopContentIllustration,
	TrafficOverviewIllustration,
} from '../../pro/pro-teaser-illustrations';
import { Flex, __experimentalText as Text } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { Bar, Line, Doughnut } from 'react-chartjs-2';
import {
	renderSearchChangeBadge,
	renderPercentChangeBadge,
	getSearchConsoleUrl,
	getSearchConsoleInsightsUrl,
	getGoogleAnalyticsReportUrl,
	hasValidDashboardData,
	dateRangeOptions,
	getSearchPerformanceTotals,
} from './utils';

/**
 * DashboardSection Component
 * @param root0
 * @param root0.settings
 * @param root0.dateRange
 * @param root0.setDateRange
 * @param root0.dashboardData
 * @param root0.searchDailyData
 * @param root0.previousPeriodData
 * @param root0.countryData
 * @param root0.brandedData
 * @param root0.countryCardTab
 * @param root0.setCountryCardTab
 * @param root0.trafficTrends
 * @param root0.isRefreshing
 */
const DashboardSection = ({
	settings,
	dateRange,
	setDateRange,
	dashboardData,
	searchDailyData,
	previousPeriodData,
	countryData,
	brandedData,
	countryCardTab,
	setCountryCardTab,
	trafficTrends,
	isRefreshing,
}) => {
	const totals = getSearchPerformanceTotals(searchDailyData);

	const kwPosTooltipPlugin = {
		id: 'kwPosTooltip',
		afterDraw(chart) {
			const { ctx } = chart;
			const activeEls = chart.getActiveElements();
			if (!activeEls.length) return;

			const el = activeEls[0].element;
			const barX = el.x;
			const barTopY = el.y;
			const value = chart.data.datasets[0].data[activeEls[0].index];
			const text = Number(value).toLocaleString();

			const circleR = 8;
			const dotR = 3;
			const gap = 3;
			const arrowH = 6;
			const arrowW = 7;
			const padX = 10;
			const bh = 28;
			const br = 7;

			ctx.save();

			ctx.font = '600 12px Inter, sans-serif';
			const bw = ctx.measureText(text).width + padX * 2;

			// Clamp tooltip x to chart area
			const { left, right } = chart.chartArea;
			let tx = barX - bw / 2;
			if (tx < left) tx = left;
			if (tx + bw > right) tx = right - bw;

			const arrowTipY = barTopY - circleR - gap;
			const ty = arrowTipY - arrowH - bh;

			// Tooltip box (manual roundRect for compatibility)
			ctx.beginPath();
			ctx.moveTo(tx + br, ty);
			ctx.lineTo(tx + bw - br, ty);
			ctx.quadraticCurveTo(tx + bw, ty, tx + bw, ty + br);
			ctx.lineTo(tx + bw, ty + bh - br);
			ctx.quadraticCurveTo(tx + bw, ty + bh, tx + bw - br, ty + bh);
			ctx.lineTo(tx + br, ty + bh);
			ctx.quadraticCurveTo(tx, ty + bh, tx, ty + bh - br);
			ctx.lineTo(tx, ty + br);
			ctx.quadraticCurveTo(tx, ty, tx + br, ty);
			ctx.closePath();
			ctx.fillStyle = '#1d1d1d';
			ctx.shadowColor = 'rgba(17,17,17,0.12)';
			ctx.shadowBlur = 8;
			ctx.shadowOffsetY = 2;
			ctx.fill();

			// Downward arrow
			ctx.shadowColor = 'transparent';
			ctx.shadowBlur = 0;
			ctx.shadowOffsetY = 0;
			ctx.beginPath();
			ctx.moveTo(barX - arrowW, ty + bh);
			ctx.lineTo(barX + arrowW, ty + bh);
			ctx.lineTo(barX, arrowTipY);
			ctx.closePath();
			ctx.fillStyle = '#1d1d1d';
			ctx.fill();

			// Tooltip text
			ctx.font = '600 12px Inter, sans-serif';
			ctx.fillStyle = 'white';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText(text, tx + bw / 2, ty + bh / 2);

			// White circle (dot marker on bar top)
			ctx.beginPath();
			ctx.arc(barX, barTopY, circleR, 0, Math.PI * 2);
			ctx.fillStyle = 'white';
			ctx.shadowColor = 'rgba(0,0,0,0.15)';
			ctx.shadowBlur = 6;
			ctx.fill();
			ctx.shadowColor = 'transparent';
			ctx.shadowBlur = 0;

			// Black center dot
			ctx.beginPath();
			ctx.arc(barX, barTopY, dotR, 0, Math.PI * 2);
			ctx.fillStyle = '#1d1d1d';
			ctx.fill();

			ctx.restore();
		},
	};

	return (
		<div className="thinkrank-space-y-6">
			<Flex justify="space-between" align="center">
				<div>
					<Text variant="muted">
						{__(
							'Track your SEO performance with AI-powered insights, real trends, and data that helps you improve rankings.',
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

			{/* Dashboard Data */}
			{dashboardData && hasValidDashboardData(dashboardData) && (
				<div className="thinkrank-space-y-6 thinkrank-mt-6">
					{/* Traffic Overview — GA4 data: real card when present, else Pro teaser (GA4 is Pro-connected) */}
					{(() => {
						const hasTraffic =
							dashboardData.traffic &&
							Object.keys(dashboardData.traffic).length > 0;
						if (hasTraffic)
							return (
								<div
									style={{
										border: '1px solid var(--tr-border-card)',
										borderRadius: '8px',
										display: 'flex',
										flexDirection: 'column',
										marginBottom: '24px',
										overflow: 'hidden',
									}}
								>
									{/* Section header */}
									<div
										style={{
											padding: '16px 20px',
											borderBottom:
												'1px solid var(--tr-border-light)',
											display: 'flex',
											alignItems: 'center',
											gap: '8px',
										}}
									>
										<span
											style={{
												fontWeight: 600,
												fontSize: '16px',
												color: 'var(--tr-text-heading)',
											}}
										>
											{__(
												'Traffic Overview',
												'thinkrank'
											)}
										</span>
									</div>

									{/* Body — metric cards */}
									<div style={{ padding: '16px 20px 20px' }}>
										<div
											style={{
												display: 'flex',
												gap: '16px',
												flexWrap: 'wrap',
											}}
										>
											{(() => {
												const t = dashboardData.traffic;
												const trends =
													trafficTrends || {};

												// Icon glyphs (20×20). Stroke uses currentColor so the
												// badge's brand text colour tints the icon.
												const ICONS = {
													sessions: (
														<svg
															width="20"
															height="20"
															viewBox="0 0 20 20"
															fill="none"
															xmlns="http://www.w3.org/2000/svg"
														>
															<path
																d="M2 10.5H5.5L7.5 5L11 15L13 8.5H18"
																stroke="currentColor"
																strokeWidth="1.5"
																strokeLinecap="round"
																strokeLinejoin="round"
															/>
														</svg>
													),
													pageviews: (
														<svg
															width="20"
															height="20"
															viewBox="0 0 20 20"
															fill="none"
															xmlns="http://www.w3.org/2000/svg"
														>
															<path
																d="M2 10C2 10 5 4 10 4C15 4 18 10 18 10C18 10 15 16 10 16C5 16 2 10 2 10Z"
																stroke="currentColor"
																strokeWidth="1.5"
																strokeLinecap="round"
																strokeLinejoin="round"
															/>
															<circle
																cx="10"
																cy="10"
																r="2.5"
																stroke="currentColor"
																strokeWidth="1.5"
															/>
														</svg>
													),
													active_users: (
														<svg
															width="20"
															height="20"
															viewBox="0 0 20 20"
															fill="none"
															xmlns="http://www.w3.org/2000/svg"
														>
															<circle
																cx="7.5"
																cy="6.5"
																r="2.5"
																stroke="currentColor"
																strokeWidth="1.5"
															/>
															<path
																d="M3 15.5C3 12.9 5 11 7.5 11C10 11 12 12.9 12 15.5"
																stroke="currentColor"
																strokeWidth="1.5"
																strokeLinecap="round"
															/>
															<path
																d="M13 4.3C14.3 4.7 15.2 5.9 15.2 7.3C15.2 8.7 14.3 9.9 13 10.3"
																stroke="currentColor"
																strokeWidth="1.5"
																strokeLinecap="round"
															/>
															<path
																d="M14 15.5C14 13.4 12.8 11.9 11 11.2"
																stroke="currentColor"
																strokeWidth="1.5"
																strokeLinecap="round"
															/>
														</svg>
													),
													bounce_rate: (
														<svg
															width="20"
															height="20"
															viewBox="0 0 20 20"
															fill="none"
															xmlns="http://www.w3.org/2000/svg"
														>
															<path
																d="M4 5V15H16"
																stroke="currentColor"
																strokeWidth="1.5"
																strokeLinecap="round"
																strokeLinejoin="round"
															/>
															<path
																d="M7 11L10 8L12 10L15.5 6.5"
																stroke="currentColor"
																strokeWidth="1.5"
																strokeLinecap="round"
																strokeLinejoin="round"
															/>
															<path
																d="M12.5 6.5H15.5V9.5"
																stroke="currentColor"
																strokeWidth="1.5"
																strokeLinecap="round"
																strokeLinejoin="round"
															/>
														</svg>
													),
												};

												const cards = [
													{
														key: 'sessions',
														label: __(
															'Sessions',
															'thinkrank'
														),
														value: Number(
															t.sessions || 0
														).toLocaleString(),
														trend: trends.sessions,
													},
													{
														key: 'pageviews',
														label: __(
															'Pageviews',
															'thinkrank'
														),
														value: Number(
															t.pageviews || 0
														).toLocaleString(),
														trend: trends.pageviews,
													},
													{
														key: 'active_users',
														label: __(
															'Active Users',
															'thinkrank'
														),
														value: Number(
															t.active_users || 0
														).toLocaleString(),
														trend: trends.active_users,
													},
													{
														key: 'bounce_rate',
														label: __(
															'Bounce Rate',
															'thinkrank'
														),
														value: `${(Number(t.bounce_rate || 0) * 100).toFixed(1)}%`,
														trend: trends.bounce_rate,
														lowerIsBetter: true,
													},
												];

												return cards.map((c) => (
													<div
														key={c.key}
														style={{
															background: '#fff',
															border: '1px solid var(--tr-border-light)',
															borderRadius: '8px',
															padding: '16px',
															flex: '1 0 0',
															minWidth: '160px',
															display: 'flex',
															flexDirection:
																'column',
															gap: '20px',
															overflow: 'hidden',
															position:
																'relative',
														}}
													>
														<div
															style={{
																display: 'flex',
																gap: '12px',
																alignItems:
																	'center',
															}}
														>
															<div
																style={{
																	background:
																		'var(--tr-accent-bg)',
																	color: 'var(--tr-accent)',
																	borderRadius:
																		'8px',
																	width: '36px',
																	height: '36px',
																	flexShrink: 0,
																	display:
																		'flex',
																	alignItems:
																		'center',
																	justifyContent:
																		'center',
																}}
															>
																{ICONS[c.key]}
															</div>
															<span
																style={{
																	fontSize:
																		'16px',
																	color: 'var(--tr-text-body)',
																	lineHeight: 1.2,
																}}
															>
																{c.label}
															</span>
														</div>
														<div
															style={{
																display: 'flex',
																alignItems:
																	'flex-end',
																justifyContent:
																	'space-between',
															}}
														>
															<span
																style={{
																	fontWeight: 600,
																	fontSize:
																		'24px',
																	lineHeight: 1.2,
																	color: 'var(--tr-text-heading)',
																}}
															>
																{c.value}
															</span>
															{c.trend &&
																typeof c.trend
																	.percentage_change ===
																	'number' && (
																	<div
																		style={{
																			paddingBottom:
																				'4px',
																		}}
																	>
																		{renderPercentChangeBadge(
																			c
																				.trend
																				.percentage_change,
																			c.lowerIsBetter
																		)}
																	</div>
																)}
														</div>
													</div>
												));
											})()}
										</div>
									</div>
								</div>
							);
						// Pro is installed but unlicensed: name the missing licence
						// rather than selling Pro to someone who already has it.
						if (!window.thinkrankAdmin?.isPro && isProUnlicensed())
							return (
								<div style={{ marginBottom: '24px' }}>
									<ProLicenseNotice
										feature={__(
											'Traffic Overview',
											'thinkrank'
										)}
									/>
								</div>
							);
						if (!window.thinkrankAdmin?.isPro)
							return (
								<div style={{ marginBottom: '24px' }}>
									<ProTeaser
										illustration={
											<TrafficOverviewIllustration />
										}
										title={__(
											'Traffic Overview',
											'thinkrank'
										)}
										description={__(
											'See how much traffic your site really gets. Connect Google Analytics 4 to track sessions, pageviews, active users, and bounce rate right inside ThinkRank.',
											'thinkrank'
										)}
										features={[
											__(
												'Sessions, pageviews, and active users at a glance',
												'thinkrank'
											),
											__(
												'Bounce rate with period-over-period trends',
												'thinkrank'
											),
											__(
												'GA4 traffic data alongside your search performance',
												'thinkrank'
											),
										]}
									/>
								</div>
							);
						return null;
					})()}

					{/* Search Performance (GSC Data) — Figma design */}
					{dashboardData.search_performance && (
						<div
							style={{
								border: '1px solid var(--tr-border-card)',
								borderRadius: '8px',
								display: 'flex',
								flexDirection: 'column',
								marginBottom: '24px',
								overflow: 'hidden',
							}}
						>
							{/* Section header */}
							<div
								style={{
									padding: '16px 20px',
									borderBottom:
										'1px solid var(--tr-border-light)',
									display: 'flex',
									alignItems: 'center',
									gap: '8px',
								}}
							>
								<span
									style={{
										fontWeight: 600,
										fontSize: '16px',
										color: 'var(--tr-text-heading)',
									}}
								>
									{__('Search Performance', 'thinkrank')}
								</span>
								<a
									href={getSearchConsoleUrl(
										settings.search_console_property,
										dateRange
									)}
									target="_blank"
									rel="noopener noreferrer"
									style={{
										background: 'var(--tr-accent-bg)',
										padding: '4px 8px',
										borderRadius: '10px',
										fontWeight: 500,
										fontSize: '14px',
										color: 'var(--tr-accent)',
										display: 'inline-flex',
										alignItems: 'center',
										gap: '4px',
										textDecoration: 'none',
									}}
								>
									{__('Search Console', 'thinkrank')}
									<svg
										width="14"
										height="14"
										viewBox="0 0 14 14"
										fill="none"
										xmlns="http://www.w3.org/2000/svg"
									>
										<path
											d="M3 11L11 3M11 3H5M11 3V9"
											stroke="#4451ff"
											strokeWidth="1.5"
											strokeLinecap="round"
											strokeLinejoin="round"
										/>
									</svg>
								</a>
							</div>

							{/* Body — metric cards + trend chart */}
							<div
								style={{
									padding: '16px 20px 20px',
									display: 'flex',
									flexDirection: 'column',
									gap: '16px',
								}}
							>
								{/* 4 metric cards */}
								<div
									style={{
										display: 'flex',
										gap: '16px',
										flexWrap: 'wrap',
									}}
								>
									{/* Total Clicks */}
									{(() => {
										const clicks = totals.clicks;
										return (
											<div
												style={{
													background: '#fff',
													border: '1px solid var(--tr-border-light)',
													borderRadius: '8px',
													padding: '16px',
													flex: '1 0 0',
													minWidth: '160px',
													display: 'flex',
													flexDirection: 'column',
													gap: '20px',
													overflow: 'hidden',
													position: 'relative',
												}}
											>
												<div
													style={{
														display: 'flex',
														gap: '12px',
														alignItems: 'center',
													}}
												>
													<div
														style={{
															background:
																'#4794ff',
															borderRadius: '8px',
															width: '36px',
															height: '36px',
															flexShrink: 0,
															display: 'flex',
															alignItems:
																'center',
															justifyContent:
																'center',
														}}
													>
														{/* Hand cursor pointer — matches Figma node 887:15304 */}
														<svg
															width="20"
															height="20"
															viewBox="0 0 20 20"
															fill="none"
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
													</div>
													<span
														style={{
															fontSize: '16px',
															color: 'var(--tr-text-body)',
															lineHeight: 1.2,
														}}
													>
														{__(
															'Total Clicks',
															'thinkrank'
														)}
													</span>
												</div>
												<div
													style={{
														display: 'flex',
														alignItems: 'flex-end',
														justifyContent:
															'space-between',
													}}
												>
													<span
														style={{
															fontWeight: 600,
															fontSize: '24px',
															lineHeight: 1.2,
															color: 'var(--tr-text-heading)',
														}}
													>
														{Number(
															clicks
														).toLocaleString()}
													</span>
													<div
														style={{
															paddingBottom:
																'4px',
														}}
													>
														{renderSearchChangeBadge(
															clicks,
															previousPeriodData?.clicks
														)}
													</div>
												</div>
											</div>
										);
									})()}

									{/* Total Impressions */}
									{(() => {
										const impressions = totals.impressions;
										return (
											<div
												style={{
													background: '#fff',
													border: '1px solid var(--tr-border-light)',
													borderRadius: '8px',
													padding: '16px',
													flex: '1 0 0',
													minWidth: '160px',
													display: 'flex',
													flexDirection: 'column',
													gap: '20px',
													overflow: 'hidden',
													position: 'relative',
												}}
											>
												<div
													style={{
														display: 'flex',
														gap: '12px',
														alignItems: 'center',
													}}
												>
													<div
														style={{
															background:
																'#6f52ff',
															borderRadius: '8px',
															width: '36px',
															height: '36px',
															flexShrink: 0,
															display: 'flex',
															alignItems:
																'center',
															justifyContent:
																'center',
														}}
													>
														{/* Ascending bar chart — matches Figma node 887:15356 */}

														<svg
															width="36"
															height="36"
															viewBox="0 0 36 36"
															fill="none"
															xmlns="http://www.w3.org/2000/svg"
														>
															<path
																d="M0 8C0 3.58172 3.58172 0 8 0H28C32.4183 0 36 3.58172 36 8V28C36 32.4183 32.4183 36 28 36H8C3.58172 36 0 32.4183 0 28V8Z"
																fill="#6F52FF"
															/>
															<g clipPath="url(#clip0_887_15356)">
																<path
																	d="M10.5 10.5V25.5H25.5"
																	stroke="white"
																	strokeWidth="1.5"
																	strokeLinecap="round"
																	strokeLinejoin="round"
																/>
																<path
																	d="M24.668 23V25.5"
																	stroke="white"
																	strokeWidth="1.5"
																	strokeLinecap="round"
																	strokeLinejoin="round"
																/>
																<path
																	d="M21.332 21.334V25.5007"
																	stroke="white"
																	strokeWidth="1.5"
																	strokeLinecap="round"
																	strokeLinejoin="round"
																/>
																<path
																	d="M18 18.834V25.5007"
																	stroke="white"
																	strokeWidth="1.5"
																	strokeLinecap="round"
																	strokeLinejoin="round"
																/>
																<path
																	d="M14.668 21.334V25.5007"
																	stroke="white"
																	strokeWidth="1.5"
																	strokeLinecap="round"
																	strokeLinejoin="round"
																/>
																<path
																	d="M10.5 17.1667C15.5 17.1667 14.6667 13 18 13C21.3333 13 20.5 17.1667 25.5 17.1667"
																	stroke="white"
																	strokeWidth="1.5"
																	strokeLinecap="round"
																	strokeLinejoin="round"
																/>
															</g>
															<defs>
																<clipPath id="clip0_887_15356">
																	<rect
																		width="20"
																		height="20"
																		fill="white"
																		transform="translate(8 8)"
																	/>
																</clipPath>
															</defs>
														</svg>
													</div>
													<span
														style={{
															fontSize: '16px',
															color: 'var(--tr-text-body)',
															lineHeight: 1.2,
														}}
													>
														{__(
															'Total Impressions',
															'thinkrank'
														)}
													</span>
												</div>
												<div
													style={{
														display: 'flex',
														alignItems: 'flex-end',
														justifyContent:
															'space-between',
													}}
												>
													<span
														style={{
															fontWeight: 600,
															fontSize: '24px',
															lineHeight: 1.2,
															color: 'var(--tr-text-heading)',
														}}
													>
														{Number(impressions) >=
														1000
															? `${(Number(impressions) / 1000).toFixed(1)}K`
															: Number(
																	impressions
																).toLocaleString()}
													</span>
													<div
														style={{
															paddingBottom:
																'4px',
														}}
													>
														{renderSearchChangeBadge(
															impressions,
															previousPeriodData?.impressions
														)}
													</div>
												</div>
											</div>
										);
									})()}

									{/* Average CTR */}
									{(() => {
										const ctr = totals.ctr;
										return (
											<div
												style={{
													background: '#fff',
													border: '1px solid var(--tr-border-light)',
													borderRadius: '8px',
													padding: '16px',
													flex: '1 0 0',
													minWidth: '160px',
													display: 'flex',
													flexDirection: 'column',
													gap: '20px',
													overflow: 'hidden',
													position: 'relative',
												}}
											>
												<div
													style={{
														display: 'flex',
														gap: '12px',
														alignItems: 'center',
													}}
												>
													<div
														style={{
															background:
																'#bd52ff',
															borderRadius: '8px',
															width: '36px',
															height: '36px',
															flexShrink: 0,
															display: 'flex',
															alignItems:
																'center',
															justifyContent:
																'center',
														}}
													>
														{/* Keyboard icon — matches Figma node 887:15408 */}
														<svg
															width="20"
															height="20"
															viewBox="0 0 20 20"
															fill="none"
															xmlns="http://www.w3.org/2000/svg"
														>
															<rect
																x="2"
																y="4.5"
																width="16"
																height="11"
																rx="2"
																stroke="white"
																strokeWidth="1.5"
															/>
															<rect
																x="4"
																y="7"
																width="1.75"
																height="1.75"
																rx="0.5"
																fill="white"
															/>
															<rect
																x="7"
																y="7"
																width="1.75"
																height="1.75"
																rx="0.5"
																fill="white"
															/>
															<rect
																x="10"
																y="7"
																width="1.75"
																height="1.75"
																rx="0.5"
																fill="white"
															/>
															<rect
																x="13"
																y="7"
																width="1.75"
																height="1.75"
																rx="0.5"
																fill="white"
															/>
															<rect
																x="4"
																y="10.25"
																width="1.75"
																height="1.75"
																rx="0.5"
																fill="white"
															/>
															<rect
																x="7"
																y="10.25"
																width="1.75"
																height="1.75"
																rx="0.5"
																fill="white"
															/>
															<rect
																x="10"
																y="10.25"
																width="1.75"
																height="1.75"
																rx="0.5"
																fill="white"
															/>
															<rect
																x="13"
																y="10.25"
																width="1.75"
																height="1.75"
																rx="0.5"
																fill="white"
															/>
															<rect
																x="5.5"
																y="13.5"
																width="9"
																height="1.5"
																rx="0.5"
																fill="white"
															/>
														</svg>
													</div>
													<span
														style={{
															fontSize: '16px',
															color: 'var(--tr-text-body)',
															lineHeight: 1.2,
														}}
													>
														{__(
															'Avg. CTR',
															'thinkrank'
														)}
													</span>
												</div>
												<div
													style={{
														display: 'flex',
														alignItems: 'flex-end',
														justifyContent:
															'space-between',
													}}
												>
													<span
														style={{
															fontWeight: 600,
															fontSize: '24px',
															lineHeight: 1.2,
															color: 'var(--tr-text-heading)',
														}}
													>
														{Number(ctr).toFixed(2)}
														%
													</span>
													<div
														style={{
															paddingBottom:
																'4px',
														}}
													>
														{renderSearchChangeBadge(
															ctr,
															previousPeriodData?.ctr
														)}
													</div>
												</div>
											</div>
										);
									})()}

									{/* Average Position */}
									{(() => {
										const position = totals.position;
										return (
											<div
												style={{
													background: '#fff',
													border: '1px solid var(--tr-border-light)',
													borderRadius: '8px',
													padding: '16px',
													flex: '1 0 0',
													minWidth: '160px',
													display: 'flex',
													flexDirection: 'column',
													gap: '20px',
													overflow: 'hidden',
													position: 'relative',
												}}
											>
												<div
													style={{
														display: 'flex',
														gap: '12px',
														alignItems: 'center',
													}}
												>
													<div
														style={{
															background:
																'#ff8026',
															borderRadius: '8px',
															width: '36px',
															height: '36px',
															flexShrink: 0,
															display: 'flex',
															alignItems:
																'center',
															justifyContent:
																'center',
														}}
													>
														{/* Ranking / sort bars icon — matches Figma node 887:15469 */}

														<svg
															width="36"
															height="36"
															viewBox="0 0 36 36"
															fill="none"
															xmlns="http://www.w3.org/2000/svg"
														>
															<path
																d="M0 8C0 3.58172 3.58172 0 8 0H28C32.4183 0 36 3.58172 36 8V28C36 32.4183 32.4183 36 28 36H8C3.58172 36 0 32.4183 0 28V8Z"
																fill="#FF8026"
															/>
															<g clipPath="url(#clip0_887_15469)">
																<path
																	d="M18 25.4993V22.166"
																	stroke="white"
																	strokeWidth="1.5"
																	strokeLinecap="round"
																	strokeLinejoin="round"
																/>
																<path
																	d="M18 18.8333V15.5"
																	stroke="white"
																	strokeWidth="1.5"
																	strokeLinecap="round"
																	strokeLinejoin="round"
																/>
																<path
																	d="M18 12.1667V10.5"
																	stroke="white"
																	strokeWidth="1.5"
																	strokeLinecap="round"
																	strokeLinejoin="round"
																/>
																<path
																	d="M16.332 25.5H19.6654"
																	stroke="white"
																	strokeWidth="1.5"
																	strokeLinecap="round"
																	strokeLinejoin="round"
																/>
																<path
																	d="M14.668 12.166V15.4993H23.8346L25.5013 13.8327L23.8346 12.166H14.668Z"
																	stroke="white"
																	strokeWidth="1.5"
																	strokeLinecap="round"
																	strokeLinejoin="round"
																/>
																<path
																	d="M19.6654 18.834V22.1673H12.9987L11.332 20.5007L12.9987 18.834H19.6654Z"
																	stroke="white"
																	strokeWidth="1.5"
																	strokeLinecap="round"
																	strokeLinejoin="round"
																/>
															</g>
															<defs>
																<clipPath id="clip0_887_15469">
																	<rect
																		width="20"
																		height="20"
																		fill="white"
																		transform="translate(8 8)"
																	/>
																</clipPath>
															</defs>
														</svg>
													</div>
													<span
														style={{
															fontSize: '16px',
															color: 'var(--tr-text-body)',
															lineHeight: 1.2,
														}}
													>
														{__(
															'Average Position',
															'thinkrank'
														)}
													</span>
												</div>
												<div
													style={{
														display: 'flex',
														alignItems: 'flex-end',
														justifyContent:
															'space-between',
													}}
												>
													<span
														style={{
															fontWeight: 600,
															fontSize: '24px',
															lineHeight: 1.2,
															color: 'var(--tr-text-heading)',
														}}
													>
														{Number(
															position
														).toFixed(1)}
													</span>
													<div
														style={{
															paddingBottom:
																'4px',
														}}
													>
														{renderSearchChangeBadge(
															position,
															previousPeriodData?.position,
															true
														)}
													</div>
												</div>
											</div>
										);
									})()}
								</div>

								{/* Multi-line trend chart */}
								{searchDailyData &&
									searchDailyData.rows &&
									searchDailyData.rows.length > 0 &&
									(() => {
										const rows = searchDailyData.rows;

										// Dot colors matching the metric card icon backgrounds
										const COLORS = {
											clicks: '#4794ff',
											impressions: '#6f52ff',
											ctr: '#bd52ff',
											position: '#ff8026',
										};

										const labels = rows.map((r) => {
											const d = new Date(
												r.date + 'T00:00:00'
											);
											return d.toLocaleDateString(
												'en-US',
												{
													month: 'short',
													day: 'numeric',
												}
											);
										});

										// Full date labels for the tooltip footer
										const fullLabels = rows.map((r) => {
											const d = new Date(
												r.date + 'T00:00:00'
											);
											return d.toLocaleDateString(
												'en-US',
												{
													month: 'long',
													day: 'numeric',
													year: 'numeric',
												}
											);
										});

										/**
										 * Custom external HTML tooltip — matches the Figma "Bubble" design:
										 * white card, colored dots, dark text values, date footer.
										 * @param context
										 */
										const externalTooltipHandler = (
											context
										) => {
											const { chart, tooltip } = context;

											let tooltipEl =
												chart.canvas.parentNode.querySelector(
													'.tr-chart-tooltip'
												);
											if (!tooltipEl) {
												tooltipEl =
													document.createElement(
														'div'
													);
												tooltipEl.className =
													'tr-chart-tooltip';
												Object.assign(tooltipEl.style, {
													position: 'absolute',
													pointerEvents: 'none',
													background: '#ffffff',
													border: '1px solid #e9ebf1',
													borderRadius: '8px',
													boxShadow:
														'0px 4px 12px rgba(17,17,17,0.10), 0px 1px 3px rgba(17,17,17,0.08)',
													padding: '12px',
													minWidth: '150px',
													transition:
														'opacity 0.15s ease',
													zIndex: '100',
													fontFamily:
														'Inter, -apple-system, sans-serif',
												});
												chart.canvas.parentNode.appendChild(
													tooltipEl
												);
											}

											// Hide when not active
											if (tooltip.opacity === 0) {
												tooltipEl.style.opacity = '0';
												return;
											}

											const dataIndex =
												tooltip.dataPoints?.[0]
													?.dataIndex;
											const dateLabel =
												fullLabels[dataIndex] ?? '';

											const metricRows = [
												{
													color: COLORS.clicks,
													value:
														rows[
															dataIndex
														]?.clicks?.toLocaleString() ??
														'',
												},
												{
													color: COLORS.impressions,
													value:
														rows[
															dataIndex
														]?.impressions?.toLocaleString() ??
														'',
												},
												{
													color: COLORS.ctr,
													value: `${(rows[dataIndex]?.ctr ?? 0).toFixed(2)}%`,
												},
												{
													color: COLORS.position,
													value: `${(rows[dataIndex]?.position ?? 0).toFixed(1)}`,
												},
											];

											tooltipEl.innerHTML = `
                                        <div style="display:flex;flex-direction:column;gap:7px;margin-bottom:10px;">
                                            ${metricRows
												.map(
													({ color, value }) => `
                                                <div style="display:flex;align-items:center;gap:8px;">
                                                    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${color};flex-shrink:0;"></span>
                                                    <span style="font-size:14px;font-weight:500;color:#1d1d1d;line-height:20px;white-space:nowrap;">${value}</span>
                                                </div>
                                            `
												)
												.join('')}
                                        </div>
                                        <div style="border-top:1px solid #e9ebf1;padding-top:8px;">
                                            <span style="font-size:14px;color:#5a5c5e;line-height:20px;white-space:nowrap;">${dateLabel}</span>
                                        </div>
                                    `;

											// Position relative to chart canvas
											const {
												offsetLeft: posX,
												offsetTop: posY,
											} = chart.canvas;
											const tooltipWidth =
												tooltipEl.offsetWidth || 160;
											const tooltipHeight =
												tooltipEl.offsetHeight || 130;
											const canvasWidth =
												chart.canvas.offsetWidth;

											let left =
												posX + tooltip.caretX + 12;
											const top =
												posY +
												tooltip.caretY -
												tooltipHeight / 2;

											// Flip left if overflowing right edge
											if (
												left + tooltipWidth >
												posX + canvasWidth
											) {
												left =
													posX +
													tooltip.caretX -
													tooltipWidth -
													12;
											}

											tooltipEl.style.opacity = '1';
											tooltipEl.style.left = `${left}px`;
											tooltipEl.style.top = `${top}px`;
										};

										const chartData = {
											labels,
											datasets: [
												{
													label: __(
														'Clicks',
														'thinkrank'
													),
													data: rows.map(
														(r) => r.clicks
													),
													borderColor: COLORS.clicks,
													backgroundColor:
														'rgba(71, 148, 255, 0.08)',
													borderWidth: 2,
													tension: 0.4,
													fill: false,
													pointRadius: 0,
													pointHoverRadius: 5,
													pointHoverBackgroundColor:
														COLORS.clicks,
													pointHoverBorderColor:
														'#ffffff',
													pointHoverBorderWidth: 2,
													yAxisID: 'yLeft',
												},
												{
													label: __(
														'Impressions',
														'thinkrank'
													),
													data: rows.map(
														(r) => r.impressions
													),
													borderColor:
														COLORS.impressions,
													backgroundColor:
														'rgba(111, 82, 255, 0.08)',
													borderWidth: 2,
													tension: 0.4,
													fill: false,
													pointRadius: 0,
													pointHoverRadius: 5,
													pointHoverBackgroundColor:
														COLORS.impressions,
													pointHoverBorderColor:
														'#ffffff',
													pointHoverBorderWidth: 2,
													yAxisID: 'yLeft',
												},
												{
													label: __(
														'Avg. CTR (%)',
														'thinkrank'
													),
													data: rows.map(
														(r) => r.ctr
													),
													borderColor: COLORS.ctr,
													backgroundColor:
														'rgba(189, 82, 255, 0.08)',
													borderWidth: 2,
													tension: 0.4,
													fill: false,
													pointRadius: 0,
													pointHoverRadius: 5,
													pointHoverBackgroundColor:
														COLORS.ctr,
													pointHoverBorderColor:
														'#ffffff',
													pointHoverBorderWidth: 2,
													yAxisID: 'yRight',
												},
												{
													label: __(
														'Avg. Position',
														'thinkrank'
													),
													data: rows.map(
														(r) => r.position
													),
													borderColor:
														COLORS.position,
													backgroundColor:
														'rgba(255, 128, 38, 0.08)',
													borderWidth: 2,
													tension: 0.4,
													fill: false,
													pointRadius: 0,
													pointHoverRadius: 5,
													pointHoverBackgroundColor:
														COLORS.position,
													pointHoverBorderColor:
														'#ffffff',
													pointHoverBorderWidth: 2,
													yAxisID: 'yRight',
												},
											],
										};

										const chartOptions = {
											responsive: true,
											maintainAspectRatio: false,
											interaction: {
												mode: 'index',
												intersect: false,
											},
											plugins: {
												legend: {
													display: true,
													position: 'bottom',
													labels: {
														usePointStyle: true,
														pointStyle: 'circle',
														padding: 20,
														font: {
															size: 13,
															family: 'Inter, sans-serif',
														},
														color: 'var(--tr-text-body)',
													},
												},
												tooltip: {
													enabled: false,
													external:
														externalTooltipHandler,
												},
											},
											scales: {
												x: {
													grid: {
														color: '#e9ebf1',
														drawBorder: false,
													},
													ticks: {
														color: 'var(--tr-text-subtle)',
														font: { size: 11 },
														maxTicksLimit: 10,
														maxRotation: 0,
													},
													border: { display: false },
												},
												yLeft: {
													position: 'left',
													grid: {
														color: '#e9ebf1',
														drawBorder: false,
													},
													ticks: {
														color: 'var(--tr-text-subtle)',
														font: { size: 11 },
														callback: (v) =>
															v >= 1000
																? `${(v / 1000).toFixed(0)}k`
																: v,
													},
													border: { display: false },
												},
												yRight: {
													position: 'right',
													grid: { display: false },
													ticks: {
														color: 'var(--tr-text-subtle)',
														font: { size: 11 },
														callback: (v) => `${v}`,
													},
													border: { display: false },
												},
											},
										};

										return (
											<div
												style={{
													height: '320px',
													marginTop: '8px',
													position: 'relative',
													width: '100%',
													overflow: 'hidden',
												}}
											>
												<Line
													data={chartData}
													options={chartOptions}
												/>
											</div>
										);
									})()}
							</div>
						</div>
					)}

					{/* GA4 Users Card — Pro Feature (Total / New / Returning Users + Avg. Engagement Time) */}
					{(() => {
						// Ask the plan BEFORE the filter. Pro registers its filters even when
						// unlicensed (so the License screen stays reachable, #512), so a
						// filter-first gate always took the Pro branch and rendered a panel
						// pointed at a route load_components() never registered (#533).
						const ga4Users = !window.thinkrankAdmin?.isPro
							? null
							: applyFilters(
									'thinkrank.analytics.ga4UsersCard',
									null,
									dateRange
								);
						const gaReportUrl =
							getGoogleAnalyticsReportUrl(settings);

						const cardWrapper = (children, hasProContent) => (
							<div
								style={{
									border: '1px solid var(--tr-border-card)',
									borderRadius: '8px',
									overflow: 'hidden',
									marginBottom: '24px',
								}}
							>
								<div
									style={{
										borderBottom:
											'1px solid var(--tr-border-card)',
										padding: '14px 16px',
										display: 'flex',
										alignItems: 'center',
										gap: '8px',
									}}
								>
									<span
										style={{
											fontWeight: 600,
											fontSize: '16px',
											color: 'var(--tr-text-heading)',
										}}
									>
										{__('Google Analytics', 'thinkrank')}
									</span>
									{hasProContent && gaReportUrl ? (
										<a
											href={gaReportUrl}
											target="_blank"
											rel="noopener noreferrer"
											style={{
												background:
													'var(--tr-accent-bg)',
												padding: '4px 8px',
												borderRadius: '10px',
												fontWeight: 500,
												fontSize: '13px',
												color: 'var(--tr-accent)',
												display: 'inline-flex',
												alignItems: 'center',
												gap: '4px',
												textDecoration: 'none',
											}}
										>
											{__(
												'Google Analytics',
												'thinkrank'
											)}
											<svg
												width="13"
												height="13"
												viewBox="0 0 14 14"
												fill="none"
												xmlns="http://www.w3.org/2000/svg"
											>
												<path
													d="M3 11L11 3M11 3H5M11 3V9"
													stroke="#4451ff"
													strokeWidth="1.5"
													strokeLinecap="round"
													strokeLinejoin="round"
												/>
											</svg>
										</a>
									) : (
										<span
											style={{
												background:
													'var(--tr-accent-bg)',
												padding: '4px 8px',
												borderRadius: '10px',
												fontWeight: 500,
												fontSize: '13px',
												color: 'var(--tr-accent)',
												display: 'inline-flex',
												alignItems: 'center',
												gap: '4px',
												cursor: hasProContent
													? 'default'
													: 'pointer',
											}}
										>
											{__(
												'Google Analytics',
												'thinkrank'
											)}
											<svg
												width="13"
												height="13"
												viewBox="0 0 14 14"
												fill="none"
												xmlns="http://www.w3.org/2000/svg"
											>
												<path
													d="M3 11L11 3M11 3H5M11 3V9"
													stroke="#4451ff"
													strokeWidth="1.5"
													strokeLinecap="round"
													strokeLinejoin="round"
												/>
											</svg>
										</span>
									)}
								</div>
								{children}
							</div>
						);

						if (ga4Users) {
							return cardWrapper(ga4Users, true);
						}

						// Pro is here, the licence is not — say so rather than
						// upselling a plugin the site already has (#534/#536).
						if (isProUnlicensed()) {
							return (
								<div style={{ marginBottom: '24px' }}>
									<ProLicenseNotice
										feature={__('Queries', 'thinkrank')}
									/>
								</div>
							);
						}

						// Pro is here, the licence is not — say so rather than
						// upselling a plugin the site already has (#534/#536).
						if (isProUnlicensed()) {
							return (
								<div style={{ marginBottom: '24px' }}>
									<ProLicenseNotice
										feature={__('Top Content', 'thinkrank')}
									/>
								</div>
							);
						}

						// Free version — shared Pro teaser (no misleading mock data)
						return (
							<div style={{ marginBottom: '24px' }}>
								<ProTeaser
									illustration={
										<GoogleAnalyticsIllustration />
									}
									title={__('Google Analytics', 'thinkrank')}
									description={__(
										'See who is visiting and how engaged they are. Connect Google Analytics 4 to bring total, new, and returning users plus engagement time right beside your search data.',
										'thinkrank'
									)}
									features={[
										__(
											'Total, new, and returning users at a glance',
											'thinkrank'
										),
										__(
											'Average engagement time and user trends over time',
											'thinkrank'
										),
										__(
											'GA4 metrics side by side with your Search Console data',
											'thinkrank'
										),
									]}
								/>
							</div>
						);
					})()}

					{/* Keyword Positions + Tabs — side-by-side */}
					{dashboardData.search_performance && (
						<div
							style={{
								display: 'flex',
								gap: '16px',
								marginBottom: '24px',
								alignItems: 'stretch',
							}}
						>
							{/* LEFT: Keyword Positions bar chart */}
							{dashboardData.search_performance
								.position_distribution && (
								<div
									style={{
										flex: '1 1 0',
										border: '1px solid var(--tr-border-light)',
										borderRadius: '8px',
										overflow: 'hidden',
										minWidth: 0,
										display: 'flex',
										flexDirection: 'column',
									}}
								>
									<div
										style={{
											borderBottom:
												'1px solid var(--tr-border-card)',
											padding: '16px',
											display: 'flex',
											alignItems: 'center',
											gap: '8px',
										}}
									>
										<span
											style={{
												fontWeight: 600,
												fontSize: '16px',
												color: 'var(--tr-text-heading)',
											}}
										>
											{__(
												'Keyword positions',
												'thinkrank'
											)}
										</span>
										<a
											href={getSearchConsoleUrl(
												settings.search_console_property,
												dateRange
											)}
											target="_blank"
											rel="noopener noreferrer"
											style={{
												background:
													'var(--tr-accent-bg)',
												padding: '4px 8px',
												borderRadius: '10px',
												fontWeight: 500,
												fontSize: '14px',
												color: 'var(--tr-accent)',
												display: 'inline-flex',
												alignItems: 'center',
												gap: '4px',
												textDecoration: 'none',
											}}
										>
											{__('Search Console', 'thinkrank')}
											<svg
												width="14"
												height="14"
												viewBox="0 0 14 14"
												fill="none"
												xmlns="http://www.w3.org/2000/svg"
											>
												<path
													d="M3 11L11 3M11 3H5M11 3V9"
													stroke="#4451ff"
													strokeWidth="1.5"
													strokeLinecap="round"
													strokeLinejoin="round"
												/>
											</svg>
										</a>
									</div>
									<div
										style={{
											flex: 1,
											padding: '16px 16px 16px',
											minHeight: '340px',
										}}
									>
										<Bar
											plugins={[kwPosTooltipPlugin]}
											data={{
												labels: [
													__('Top3', 'thinkrank'),
													__('4-10', 'thinkrank'),
													__('11-50', 'thinkrank'),
													__('51-100', 'thinkrank'),
												],
												datasets: [
													{
														label: __(
															'Keywords',
															'thinkrank'
														),
														data: [
															dashboardData
																.search_performance
																.position_distribution
																.top_3,
															dashboardData
																.search_performance
																.position_distribution[
																'4_10'
															],
															dashboardData
																.search_performance
																.position_distribution[
																'10_50'
															],
															dashboardData
																.search_performance
																.position_distribution[
																'51_100'
															],
														],
														backgroundColor: [
															'#4451ff',
															'#757eff',
															'#a8aeff',
															'#d1d4ff',
														],
														borderColor: [
															'#4451ff',
															'#757eff',
															'#a8aeff',
															'#d1d4ff',
														],
														borderWidth: 0,
														borderRadius: {
															topLeft: 8,
															topRight: 8,
														},
														borderSkipped: false,
													},
												],
											}}
											options={{
												responsive: true,
												maintainAspectRatio: false,
												interaction: {
													mode: 'index',
													intersect: false,
												},
												layout: {
													padding: { top: 60 },
												},
												plugins: {
													legend: { display: false },
													tooltip: { enabled: false },
												},
												scales: {
													x: {
														grid: {
															display: false,
														},
														border: {
															display: false,
														},
														ticks: {
															color: 'var(--tr-text-subtle)',
															font: {
																size: 12,
																family: 'Inter, sans-serif',
															},
														},
													},
													y: {
														beginAtZero: true,
														border: {
															display: false,
														},
														grid: {
															color: '#e9ebf1',
															drawTicks: false,
														},
														ticks: {
															color: 'var(--tr-text-subtle)',
															font: {
																size: 12,
																family: 'Inter, sans-serif',
															},
															padding: 8,
															callback: (v) =>
																v >= 1000000
																	? (
																			v /
																			1000000
																		).toFixed(
																			0
																		) + 'M'
																	: v >= 1000
																		? (
																				v /
																				1000
																			).toFixed(
																				0
																			) +
																			'k'
																		: v,
														},
													},
												},
											}}
										/>
									</div>
								</div>
							)}

							{/* RIGHT: Traffic Channels / Top Countries / Branded tabs */}
							{(() => {
								const COUNTRY_NAMES = {
									usa: 'United States',
									gbr: 'United Kingdom',
									can: 'Canada',
									aus: 'Australia',
									ind: 'India',
									deu: 'Germany',
									fra: 'France',
									bra: 'Brazil',
									jpn: 'Japan',
									chn: 'China',
									kor: 'South Korea',
									mex: 'Mexico',
									esp: 'Spain',
									ita: 'Italy',
									nld: 'Netherlands',
									pol: 'Poland',
									rus: 'Russia',
									tur: 'Turkey',
									idn: 'Indonesia',
									pak: 'Pakistan',
									bgd: 'Bangladesh',
									phl: 'Philippines',
									vnm: 'Vietnam',
									tha: 'Thailand',
									mys: 'Malaysia',
									sgp: 'Singapore',
									nzl: 'New Zealand',
									zaf: 'South Africa',
									nga: 'Nigeria',
									ken: 'Kenya',
									egy: 'Egypt',
									arg: 'Argentina',
									chl: 'Chile',
									col: 'Colombia',
									per: 'Peru',
									prt: 'Portugal',
									swe: 'Sweden',
									nor: 'Norway',
									dnk: 'Denmark',
									fin: 'Finland',
									aut: 'Austria',
									che: 'Switzerland',
									bel: 'Belgium',
									cze: 'Czech Republic',
									hun: 'Hungary',
									rou: 'Romania',
									grc: 'Greece',
									ukr: 'Ukraine',
									isr: 'Israel',
									are: 'UAE',
									sau: 'Saudi Arabia',
									lka: 'Sri Lanka',
									npl: 'Nepal',
									mmr: 'Myanmar',
									khm: 'Cambodia',
									fji: 'Fiji',
									cri: 'Costa Rica',
									ecu: 'Ecuador',
									hkg: 'Hong Kong',
									twn: 'Taiwan',
								};
								const toName = (code) =>
									COUNTRY_NAMES[code] || code.toUpperCase();
								const BRAND_COLORS = [
									'#4451ff',
									'#757eff',
									'#a8aeff',
									'#d1d4ff',
								];
								const getColor = (i) =>
									BRAND_COLORS[i % BRAND_COLORS.length];

								const countries = (
									countryData?.countries ?? []
								).slice(0, 4);

								const tabStyle = (active) => ({
									padding: '14px 0',
									fontSize: '13px',
									fontWeight: active ? 600 : 400,
									color: active ? '#4451ff' : '#6b7280',
									borderBottom: active
										? '2px solid #4451ff'
										: '2px solid transparent',
									cursor: 'pointer',
									background: 'none',
									border: 'none',
									outline: 'none',
									whiteSpace: 'nowrap',
									flexShrink: 0,
								});

								const chipSvg = (
									<svg
										width="14"
										height="14"
										viewBox="0 0 14 14"
										fill="none"
										xmlns="http://www.w3.org/2000/svg"
									>
										<path
											d="M3 11L11 3M11 3H5M11 3V9"
											stroke="#4451ff"
											strokeWidth="1.5"
											strokeLinecap="round"
											strokeLinejoin="round"
										/>
									</svg>
								);
								const chip = (label, href) =>
									href ? (
										<a
											href={href}
											target="_blank"
											rel="noopener noreferrer"
											style={{
												background:
													'var(--tr-accent-bg)',
												padding: '4px 8px',
												borderRadius: '10px',
												fontWeight: 500,
												fontSize: '14px',
												color: 'var(--tr-accent)',
												display: 'inline-flex',
												alignItems: 'center',
												gap: '4px',
												textDecoration: 'none',
											}}
										>
											{label}
											{chipSvg}
										</a>
									) : (
										<span
											style={{
												background:
													'var(--tr-accent-bg)',
												padding: '4px 8px',
												borderRadius: '10px',
												fontWeight: 500,
												fontSize: '14px',
												color: 'var(--tr-accent)',
												display: 'inline-flex',
												alignItems: 'center',
												gap: '4px',
											}}
										>
											{label}
											{chipSvg}
										</span>
									);

								const donutSection = (
									labels,
									data,
									colors,
									tooltipFn
								) => (
									<div
										style={{
											width: '200px',
											height: '200px',
											margin: '0 auto 20px',
										}}
									>
										<Doughnut
											data={{
												labels,
												datasets: [
													{
														data,
														backgroundColor: colors,
														borderColor:
															'transparent',
														borderWidth: 0,
														borderRadius: 10,
														spacing: 5,
													},
												],
											}}
											options={{
												cutout: '66%',
												responsive: true,
												maintainAspectRatio: false,
												plugins: {
													legend: { display: false },
													tooltip: {
														backgroundColor: '#fff',
														borderColor: '#e2e6f1',
														borderWidth: 1,
														bodyColor: '#1d1d1d',
														padding: {
															x: 14,
															y: 8,
														},
														cornerRadius: 10,
														displayColors: false,
														callbacks: {
															title: () => '',
															label: tooltipFn,
														},
													},
												},
											}}
										/>
									</div>
								);

								// Plan first — see the note on the other Pro slots above.
								const proTrafficChannels = window.thinkrankAdmin
									?.isPro
									? applyFilters(
											'thinkrank.analytics.trafficChannelSection',
											false,
											dateRange
										)
									: false;

								return (
									<div
										style={{
											flex: '1 1 0',
											border: '1px solid var(--tr-border-light)',
											borderRadius: '8px',
											overflow: 'hidden',
											minWidth: 0,
											display: 'flex',
											flexDirection: 'column',
										}}
									>
										{/* Tab bar — 3 tabs */}
										<div
											style={{
												borderBottom:
													'1px solid var(--tr-border-light)',
												padding: '0 14px',
												display: 'flex',
												gap: '14px',
												overflowX: 'auto',
												flexWrap: 'nowrap',
											}}
										>
											{[
												{
													id: 'traffic-channels',
													label: proTrafficChannels ? (
														__(
															'Traffic Channels',
															'thinkrank'
														)
													) : (
														<span
															style={{
																display:
																	'inline-flex',
																alignItems:
																	'center',
																gap: '5px',
															}}
														>
															{__(
																'Traffic Channels',
																'thinkrank'
															)}
															<span
																style={{
																	background:
																		'#fff3ec',
																	color: '#f97316',
																	padding:
																		'1px 5px',
																	borderRadius:
																		'8px',
																	fontWeight: 600,
																	fontSize:
																		'10px',
																	display:
																		'inline-flex',
																	alignItems:
																		'center',
																	gap: '2px',
																}}
															>
																<svg
																	width="9"
																	height="7"
																	viewBox="0 0 18 15"
																	fill="#f97316"
																	xmlns="http://www.w3.org/2000/svg"
																>
																	<path
																		d="M1 14H17M2.5 14L1 6.5L5.5 10L9 1L12.5 10L17 6.5L15.5 14H2.5Z"
																		stroke="#f97316"
																		strokeWidth="1.5"
																		strokeLinecap="round"
																		strokeLinejoin="round"
																	/>
																</svg>
																PRO
															</span>
														</span>
													),
												},
												{
													id: 'top-countries',
													label: __(
														'Top Countries',
														'thinkrank'
													),
												},
												{
													id: 'branded',
													label: __(
														'Branded vs Non-Branded',
														'thinkrank'
													),
												},
											].map(({ id, label }) => (
												<button
													key={id}
													style={tabStyle(
														countryCardTab === id
													)}
													onClick={() =>
														setCountryCardTab(id)
													}
												>
													{label}
												</button>
											))}
										</div>

										{countryCardTab ===
											'traffic-channels' &&
											proTrafficChannels && (
												<div
													style={{
														padding:
															'16px 20px 20px',
														flex: 1,
													}}
												>
													<div
														style={{
															display: 'flex',
															justifyContent:
																'flex-end',
															marginBottom:
																'12px',
														}}
													>
														{chip(
															__(
																'Google Analytics',
																'thinkrank'
															),
															getGoogleAnalyticsReportUrl(
																settings
															)
														)}
													</div>
													{proTrafficChannels}
												</div>
											)}
										{countryCardTab ===
											'traffic-channels' &&
											!proTrafficChannels && (
												<div
													style={{
														flex: 1,
														display: 'flex',
														flexDirection: 'column',
														alignItems: 'center',
														justifyContent:
															'center',
														textAlign: 'center',
														padding: '32px 24px',
														gap: '14px',
													}}
												>
													<div
														style={{
															width: '64px',
															height: '64px',
															borderRadius:
																'14px',
															background:
																'var(--tr-accent-bg)',
															display: 'flex',
															alignItems:
																'center',
															justifyContent:
																'center',
														}}
													>
														{/* Segmented donut — channels split */}
														<svg
															width="34"
															height="34"
															viewBox="0 0 36 36"
															fill="none"
															xmlns="http://www.w3.org/2000/svg"
														>
															<circle
																cx="18"
																cy="18"
																r="13"
																stroke="#c3c8ff"
																strokeWidth="6"
															/>
															<path
																d="M18 5a13 13 0 0 1 11.3 6.5"
																stroke="#4451ff"
																strokeWidth="6"
																strokeLinecap="round"
															/>
															<path
																d="M29.3 11.5a13 13 0 0 1 -3.6 17.6"
																stroke="#7b82ff"
																strokeWidth="6"
																strokeLinecap="round"
															/>
														</svg>
													</div>
													<div
														style={{
															display: 'flex',
															alignItems:
																'center',
															gap: '8px',
														}}
													>
														<span
															style={{
																fontWeight: 700,
																fontSize:
																	'16px',
																color: 'var(--tr-text-heading)',
															}}
														>
															{__(
																'Traffic Channels',
																'thinkrank'
															)}
														</span>
														<span
															style={{
																background:
																	'var(--tr-accent-bg)',
																color: 'var(--tr-accent)',
																padding:
																	'2px 8px',
																borderRadius:
																	'8px',
																fontSize:
																	'12px',
																fontWeight: 600,
															}}
														>
															{__(
																'Pro',
																'thinkrank'
															)}
														</span>
													</div>
													<p
														style={{
															margin: 0,
															fontSize: '13px',
															color: 'var(--tr-text-body)',
															maxWidth: '320px',
															lineHeight: 1.5,
														}}
													>
														{__(
															'See where your visitors come from (organic, direct, referral, social, and more) with Google Analytics 4.',
															'thinkrank'
														)}
													</p>
													<a
														href="https://thinkrank.ai/#pricing"
														target="_blank"
														rel="noreferrer"
														style={{
															marginTop: '4px',
															background:
																'var(--tr-accent)',
															color: '#fff',
															borderRadius: '8px',
															padding: '9px 18px',
															fontSize: '14px',
															fontWeight: 600,
															textDecoration:
																'none',
															display:
																'inline-block',
														}}
													>
														{__(
															'Upgrade Now',
															'thinkrank'
														)}
													</a>
												</div>
											)}

										{/* Top Countries */}
										{countryCardTab === 'top-countries' && (
											<div
												style={{
													padding: '16px 20px 20px',
													flex: 1,
												}}
											>
												<div
													style={{
														display: 'flex',
														justifyContent:
															'flex-end',
														marginBottom: '12px',
													}}
												>
													{chip(
														__(
															'Search Console',
															'thinkrank'
														),
														getSearchConsoleInsightsUrl(
															settings.search_console_property
														)
													)}
												</div>
												{countries.length === 0 ? (
													<div
														style={{
															textAlign: 'center',
															padding: '40px 0',
															color: 'var(--tr-text-subtle)',
															fontSize: '14px',
														}}
													>
														{__(
															'No country data available for this period.',
															'thinkrank'
														)}
													</div>
												) : (
													<>
														{donutSection(
															countries.map((c) =>
																toName(
																	c.country
																)
															),
															countries.map(
																(c) =>
																	c.percentage
															),
															countries.map(
																(_, i) =>
																	getColor(i)
															),
															(ctx) =>
																`${ctx.label} ${ctx.parsed}%`
														)}
														<div
															style={{
																display: 'flex',
																flexDirection:
																	'column',
																gap: '14px',
															}}
														>
															{countries.map(
																(c, i) => (
																	<div
																		key={
																			c.country
																		}
																		className="tr-flex-row--between"
																	>
																		<div className="tr-flex-row">
																			<span
																				style={{
																					width: '10px',
																					height: '10px',
																					borderRadius:
																						'50%',
																					background:
																						getColor(
																							i
																						),
																					flexShrink: 0,
																					display:
																						'inline-block',
																				}}
																			/>
																			<span
																				style={{
																					fontSize:
																						'14px',
																					color: 'var(--tr-text-heading)',
																				}}
																			>
																				{toName(
																					c.country
																				)}
																			</span>
																		</div>
																		<span
																			style={{
																				fontSize:
																					'14px',
																				fontWeight: 600,
																				color: 'var(--tr-text-heading)',
																			}}
																		>
																			{
																				c.percentage
																			}
																			%
																		</span>
																	</div>
																)
															)}
														</div>
													</>
												)}
											</div>
										)}

										{/* Branded vs Non Branded */}
										{countryCardTab === 'branded' &&
											(() => {
												const computeTrend = (
													current,
													previous
												) => {
													if (
														!previous ||
														previous === 0
													)
														return null;
													const delta =
														((current - previous) /
															Math.abs(
																previous
															)) *
														100;
													if (Math.abs(delta) < 0.5)
														return null;
													return {
														direction:
															delta > 0
																? 'up'
																: 'down',
														value: Math.abs(
															delta
														).toFixed(1),
													};
												};

												const segments = brandedData
													? [
															{
																label: __(
																	'Branded',
																	'thinkrank'
																),
																percentage:
																	brandedData
																		.branded
																		?.percentage ??
																	0,
																clicks:
																	brandedData
																		.branded
																		?.clicks ??
																	0,
																trend: computeTrend(
																	brandedData
																		.branded
																		?.percentage ??
																		0,
																	brandedData
																		.previous
																		?.branded
																		?.percentage ??
																		0
																),
															},
															{
																label: __(
																	'Non-Branded',
																	'thinkrank'
																),
																percentage:
																	brandedData
																		.non_branded
																		?.percentage ??
																	0,
																clicks:
																	brandedData
																		.non_branded
																		?.clicks ??
																	0,
																trend: computeTrend(
																	brandedData
																		.non_branded
																		?.percentage ??
																		0,
																	brandedData
																		.previous
																		?.non_branded
																		?.percentage ??
																		0
																),
															},
														]
													: null;

												const brandedColors = [
													'#4451ff',
													'#a8aeff',
												];
												const trendArrow = (trend) => {
													if (!trend) return null;
													const isUp =
														trend.direction ===
														'up';
													const color = isUp
														? '#027a48'
														: '#921c13';
													return (
														<span
															style={{
																fontSize:
																	'12px',
																fontWeight: 600,
																color,
																display:
																	'inline-flex',
																alignItems:
																	'center',
																gap: '2px',
															}}
														>
															{isUp ? (
																<svg
																	width="10"
																	height="10"
																	viewBox="0 0 10 10"
																	fill="none"
																>
																	<path
																		d="M2 8L8 2M8 2H3M8 2V7"
																		stroke="#027a48"
																		strokeWidth="1.5"
																		strokeLinecap="round"
																		strokeLinejoin="round"
																	/>
																</svg>
															) : (
																<svg
																	width="10"
																	height="10"
																	viewBox="0 0 10 10"
																	fill="none"
																>
																	<path
																		d="M2 2L8 8M8 8H3M8 8V3"
																		stroke="#921c13"
																		strokeWidth="1.5"
																		strokeLinecap="round"
																		strokeLinejoin="round"
																	/>
																</svg>
															)}
															{trend.value}%
														</span>
													);
												};

												return (
													<div
														style={{
															padding:
																'16px 20px 20px',
															flex: 1,
														}}
													>
														<div
															style={{
																display: 'flex',
																justifyContent:
																	'flex-end',
																marginBottom:
																	'12px',
															}}
														>
															{chip(
																__(
																	'Search Console',
																	'thinkrank'
																),
																getSearchConsoleInsightsUrl(
																	settings.search_console_property
																)
															)}
														</div>

														{!segments ? (
															<div
																style={{
																	textAlign:
																		'center',
																	padding:
																		'40px 0',
																	color: 'var(--tr-text-subtle)',
																	fontSize:
																		'14px',
																}}
															>
																{__(
																	'No data available for this period.',
																	'thinkrank'
																)}
															</div>
														) : (
															<>
																{donutSection(
																	segments.map(
																		(s) =>
																			s.label
																	),
																	segments.map(
																		(s) =>
																			s.percentage
																	),
																	brandedColors,
																	(ctx) =>
																		`${ctx.label} ${ctx.parsed}%`
																)}

																{/* Brand terms hint */}
																{brandedData
																	?.brand_terms
																	?.length >
																	0 && (
																	<p
																		style={{
																			fontSize:
																				'11px',
																			color: 'var(--tr-text-subtle)',
																			marginBottom:
																				'12px',
																			textAlign:
																				'center',
																		}}
																	>
																		{__(
																			'Brand keyword:',
																			'thinkrank'
																		)}{' '}
																		<em>
																			{brandedData.brand_terms.join(
																				', '
																			)}
																		</em>
																	</p>
																)}

																<div
																	style={{
																		display:
																			'flex',
																		flexDirection:
																			'column',
																		gap: '14px',
																	}}
																>
																	{segments.map(
																		(
																			s,
																			i
																		) => (
																			<div
																				key={
																					s.label
																				}
																				className="tr-flex-row--between"
																			>
																				<div className="tr-flex-row">
																					<span
																						style={{
																							width: '10px',
																							height: '10px',
																							borderRadius:
																								'50%',
																							background:
																								brandedColors[
																									i
																								],
																							flexShrink: 0,
																							display:
																								'inline-block',
																						}}
																					/>
																					<span
																						style={{
																							fontSize:
																								'14px',
																							color: 'var(--tr-text-heading)',
																						}}
																					>
																						{
																							s.label
																						}
																					</span>
																				</div>
																				<div
																					style={{
																						display:
																							'flex',
																						alignItems:
																							'center',
																						gap: '6px',
																					}}
																				>
																					<span
																						style={{
																							fontSize:
																								'14px',
																							fontWeight: 600,
																							color: 'var(--tr-text-heading)',
																						}}
																					>
																						{
																							s.percentage
																						}

																						%
																					</span>
																					{trendArrow(
																						s.trend
																					)}
																				</div>
																			</div>
																		)
																	)}
																</div>
															</>
														)}
													</div>
												);
											})()}
									</div>
								);
							})()}
						</div>
					)}

					{/* Top Queries — Pro Feature (Search Console query comparison table) */}
					{(() => {
						const insightsUrl = getSearchConsoleInsightsUrl(
							settings.search_console_property
						);
						// Ask the plan BEFORE the filter. Pro registers its filters even when
						// unlicensed (so the License screen stays reachable, #512), so a
						// filter-first gate always took the Pro branch and rendered a panel
						// pointed at a route load_components() never registered (#533).
						const topQueries = !window.thinkrankAdmin?.isPro
							? null
							: applyFilters(
									'thinkrank.analytics.topQueriesCard',
									null,
									dateRange,
									insightsUrl
								);

						if (topQueries) {
							return (
								<div style={{ marginBottom: '24px' }}>
									{topQueries}
								</div>
							);
						}

						// Free version — shared Pro teaser (no misleading mock data)
						return (
							<div style={{ marginBottom: '24px' }}>
								<ProTeaser
									illustration={<SearchQueriesIllustration />}
									title={__('Queries', 'thinkrank')}
									description={__(
										'Discover exactly what people search to find you. Track your top Search Console queries and see which are winning or losing positions over time.',
										'thinkrank'
									)}
									features={[
										__(
											'Your best-performing queries ranked by clicks and impressions',
											'thinkrank'
										),
										__(
											'Winning vs. losing filters to spot momentum shifts',
											'thinkrank'
										),
										__(
											'Position, CTR, and change tracked for every query',
											'thinkrank'
										),
									]}
								/>
							</div>
						);
					})()}

					{/* Top Content — Pro Feature (Search Console + GA4 merged table) */}
					{(() => {
						const insightsUrl = getSearchConsoleInsightsUrl(
							settings.search_console_property
						);
						// Ask the plan BEFORE the filter. Pro registers its filters even when
						// unlicensed (so the License screen stays reachable, #512), so a
						// filter-first gate always took the Pro branch and rendered a panel
						// pointed at a route load_components() never registered (#533).
						const topContent = !window.thinkrankAdmin?.isPro
							? null
							: applyFilters(
									'thinkrank.analytics.topContentCard',
									null,
									dateRange,
									insightsUrl
								);
						if (topContent)
							return (
								<div style={{ marginBottom: '24px' }}>
									{topContent}
								</div>
							);
						// Free version — shared Pro teaser (no misleading mock data)
						return (
							<div style={{ marginBottom: '24px' }}>
								<ProTeaser
									illustration={<TopContentIllustration />}
									title={__('Top Content', 'thinkrank')}
									description={__(
										'Know which pages actually drive results. Top Content merges Search Console and GA4 so you can see clicks, impressions, and users for every page in one table.',
										'thinkrank'
									)}
									features={[
										__(
											'Search Console and GA4 metrics merged per page',
											'thinkrank'
										),
										__(
											'Position, clicks, impressions, CTR, and GA4 users side by side',
											'thinkrank'
										),
										__(
											'Spot your highest-impact pages at a glance',
											'thinkrank'
										),
									]}
								/>
							</div>
						);
					})()}
					{isRefreshing && (
						<div className="thinkrank-flex thinkrank-justify-center thinkrank-p-lg">
							<SkeletonCard lines={3} />
						</div>
					)}
				</div>
			)}
		</div>
	);
};

export default DashboardSection;
