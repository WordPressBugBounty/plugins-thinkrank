/**
 * Chart.js Entry Point
 *
 * Separate bundle for Chart.js library to optimize loading
 * Only loaded when performance charts are needed
 *
 * @package
 * @since 1.0.0
 */

// Import and register Chart.js components
import {
	Chart as ChartJS,
	CategoryScale,
	LinearScale,
	PointElement,
	LineElement,
	Title,
	Tooltip,
	Filler,
} from 'chart.js';

// Register Chart.js components globally
ChartJS.register(
	CategoryScale,
	LinearScale,
	PointElement,
	LineElement,
	Title,
	Tooltip,
	Filler
);

// Export Chart.js for use by other components
export { ChartJS };

// Make Chart.js available globally for WordPress compatibility
window.ThinkRankChartJS = ChartJS;
