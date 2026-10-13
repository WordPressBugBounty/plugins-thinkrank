/**
 * Performance Chart Component
 *
 * Professional Chart.js implementation for Core Web Vitals and performance metrics
 *
 * @package
 * @since 1.0.0
 */

import { Line } from 'react-chartjs-2';

// Chart.js is loaded separately via charts.js entry point
// Components are registered globally by the charts bundle

/**
 * Performance Chart Component
 * @param root0
 * @param root0.title
 * @param root0.data
 * @param root0.unit
 * @param root0.thresholds
 * @param root0.height
 * @param root0.showArea
 * @param root0.color
 */
const PerformanceChart = ({
	title,
	data,
	unit = '',
	thresholds = {},
	height = 300,
	showArea = true,
	color = '#0073aa',
}) => {
	// Prepare chart data
	const chartData = {
		labels: data.map((_, index) => {
			const daysAgo = data.length - 1 - index;
			const date = new Date();
			date.setDate(date.getDate() - daysAgo);
			return date.toLocaleDateString('en-US', {
				month: 'short',
				day: 'numeric',
			});
		}),
		datasets: [
			{
				label: title,
				data: data.map((point) => point.value),
				borderColor: color,
				backgroundColor: showArea ? `${color}20` : 'transparent',
				borderWidth: 2,
				fill: showArea,
				tension: 0.4,
				pointBackgroundColor: color,
				pointBorderColor: '#ffffff',
				pointBorderWidth: 2,
				pointRadius: 4,
				pointHoverRadius: 6,
				pointHoverBackgroundColor: color,
				pointHoverBorderColor: '#ffffff',
				pointHoverBorderWidth: 2,
			},
		],
	};

	// Chart options
	const options = {
		responsive: true,
		maintainAspectRatio: false,
		plugins: {
			legend: {
				display: false,
			},
			title: {
				display: true,
				text: title,
				font: {
					size: 16,
					weight: '600',
				},
				color: 'var(--tr-text-heading)',
				padding: {
					bottom: 20,
				},
			},
			tooltip: {
				backgroundColor: '#ffffff',
				titleColor: '#1e1e1e',
				bodyColor: '#1e1e1e',
				borderColor: '#e0e0e0',
				borderWidth: 1,
				cornerRadius: 8,
				displayColors: false,
				callbacks: {
					label(context) {
						const value = context.parsed.y;
						const formattedValue =
							unit === 's'
								? `${value.toFixed(2)}s`
								: unit === 'ms'
									? `${Math.round(value)}ms`
									: value.toFixed(3);

						return `${title}: ${formattedValue}`;
					},
				},
			},
		},
		scales: {
			x: {
				grid: {
					color: '#f0f0f0',
					borderColor: '#e0e0e0',
				},
				ticks: {
					color: '#666666',
					font: {
						size: 12,
					},
				},
			},
			y: {
				beginAtZero: true,
				grid: {
					color: '#f0f0f0',
					borderColor: '#e0e0e0',
				},
				ticks: {
					color: '#666666',
					font: {
						size: 12,
					},
					callback(value) {
						if (unit === 's') {
							return `${value.toFixed(1)}s`;
						} else if (unit === 'ms') {
							return `${Math.round(value)}ms`;
						}
						return value.toFixed(2);
					},
				},
			},
		},
		interaction: {
			intersect: false,
			mode: 'index',
		},
		elements: {
			point: {
				hoverRadius: 8,
			},
		},
	};

	// Add threshold lines if provided
	if (thresholds.good || thresholds.needs_improvement) {
		options.plugins.annotation = {
			annotations: {},
		};

		if (thresholds.good) {
			options.plugins.annotation.annotations.goodThreshold = {
				type: 'line',
				yMin: thresholds.good,
				yMax: thresholds.good,
				borderColor: '#34a853',
				borderWidth: 2,
				borderDash: [5, 5],
				label: {
					content: 'Good',
					enabled: true,
					position: 'end',
				},
			};
		}

		if (thresholds.needs_improvement) {
			options.plugins.annotation.annotations.improvementThreshold = {
				type: 'line',
				yMin: thresholds.needs_improvement,
				yMax: thresholds.needs_improvement,
				borderColor: '#fbbc04',
				borderWidth: 2,
				borderDash: [5, 5],
				label: {
					content: 'Needs Improvement',
					enabled: true,
					position: 'end',
				},
			};
		}
	}

	return (
		<div style={{ height: `${height}px`, width: '100%' }}>
			<Line data={chartData} options={options} />
		</div>
	);
};

export default PerformanceChart;
