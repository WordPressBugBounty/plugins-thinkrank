/**
 * Search traffic chart.
 *
 * The same react-chartjs-2 <Line> chart used on Analytics › Dashboard
 * (see src/admin/components/essential-seo/analytics/DashboardSection.js):
 * Clicks / Impressions on the left axis, Avg. CTR / Avg. Position on the right,
 * same colors, tension, and dual-axis options. Fed by the search-daily rows.
 *
 * Concrete hex colors are used (not the --tr-* CSS vars the SPA relies on)
 * because those tokens are not present on the wp-admin Dashboard screen.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Line } from 'react-chartjs-2';
import {
	Chart as ChartJS,
	CategoryScale,
	LinearScale,
	PointElement,
	LineElement,
	Title,
	Tooltip,
	Legend,
	Filler,
} from 'chart.js';

ChartJS.register(
	CategoryScale,
	LinearScale,
	PointElement,
	LineElement,
	Title,
	Tooltip,
	Legend,
	Filler
);

const COLORS = {
	clicks: '#4794ff',
	impressions: '#6f52ff',
	ctr: '#bd52ff',
	position: '#ff8026',
};

const GRID_COLOR = '#e9ebf1';
const TICK_COLOR = '#7c828d';

/**
 * Shared dataset styling for every line.
 *
 * @param {string} label  Dataset label.
 * @param {Array}  data   Data points.
 * @param {string} color  Line color.
 * @param {string} axisId Target axis id.
 * @return {Object} Chart.js dataset.
 */
function dataset(label, data, color, axisId) {
	return {
		label,
		data,
		borderColor: color,
		backgroundColor: 'transparent',
		borderWidth: 2,
		tension: 0.4,
		fill: false,
		pointRadius: 0,
		pointHoverRadius: 5,
		pointHoverBackgroundColor: color,
		pointHoverBorderColor: '#ffffff',
		pointHoverBorderWidth: 2,
		yAxisID: axisId,
	};
}

export default function SearchTrafficChart({ rows }) {
	if (!Array.isArray(rows) || rows.length === 0) {
		return null;
	}

	const labels = rows.map((r) => {
		const d = new Date(`${r.date}T00:00:00`);
		if (Number.isNaN(d.getTime())) {
			return r.date;
		}
		return d.toLocaleDateString('en-US', {
			month: 'short',
			day: 'numeric',
		});
	});

	const chartData = {
		labels,
		datasets: [
			dataset(
				__('Clicks', 'thinkrank'),
				rows.map((r) => r.clicks),
				COLORS.clicks,
				'yLeft'
			),
			dataset(
				__('Impressions', 'thinkrank'),
				rows.map((r) => r.impressions),
				COLORS.impressions,
				'yLeft'
			),
			dataset(
				__('Avg. CTR (%)', 'thinkrank'),
				rows.map((r) => r.ctr),
				COLORS.ctr,
				'yRight'
			),
			dataset(
				__('Avg. Position', 'thinkrank'),
				rows.map((r) => r.position),
				COLORS.position,
				'yRight'
			),
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
					padding: 14,
					font: { size: 12 },
					color: TICK_COLOR,
				},
			},
			tooltip: {
				enabled: true,
			},
		},
		scales: {
			x: {
				grid: { color: GRID_COLOR, drawBorder: false },
				ticks: {
					color: TICK_COLOR,
					font: { size: 11 },
					maxTicksLimit: 7,
					maxRotation: 0,
				},
				border: { display: false },
			},
			yLeft: {
				position: 'left',
				grid: { color: GRID_COLOR, drawBorder: false },
				ticks: {
					color: TICK_COLOR,
					font: { size: 11 },
					callback: (v) =>
						v >= 1000 ? `${(v / 1000).toFixed(0)}k` : v,
				},
				border: { display: false },
			},
			yRight: {
				position: 'right',
				grid: { display: false },
				ticks: {
					color: TICK_COLOR,
					font: { size: 11 },
					callback: (v) => `${v}`,
				},
				border: { display: false },
			},
		},
	};

	return (
		<div className="thinkrank-dashboard-widget__chart">
			<Line data={chartData} options={chartOptions} />
		</div>
	);
}
