/**
 * Error Boundary Component for ThinkRank Metabox
 *
 * Catches JavaScript errors in child components and displays
 * a fallback UI instead of crashing the entire metabox.
 *
 * @package
 * @since 1.0.0
 */

import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Error Boundary Class Component
 *
 * React error boundaries must be class components to catch errors
 * during rendering, in lifecycle methods, and in constructors.
 */
class ErrorBoundary extends Component {
	constructor(props) {
		super(props);
		this.state = {
			hasError: false,
			error: null,
			errorInfo: null,
		};
	}

	/**
	 * Update state when an error is caught
	 *
	 * @param {Error} error The error that was thrown
	 * @return {Object} New state object
	 */
	static getDerivedStateFromError(error) {
		return {
			hasError: true,
			error,
		};
	}

	/**
	 * Log error details and notify parent component
	 *
	 * @param {Error}  error     The error that was thrown
	 * @param {Object} errorInfo Additional error information
	 */
	componentDidCatch(error, errorInfo) {
		this.setState({
			error,
			errorInfo,
		});

		// Notify parent component if callback provided
		if (this.props.onError) {
			this.props.onError(error, errorInfo);
		}

		// In development, you might want to log to external service
		// In production, avoid logging sensitive information
		if (process.env.NODE_ENV === 'development') {
			// Development logging only - removed in production builds
		}
	}

	/**
	 * Reset error boundary state
	 */
	resetErrorBoundary = () => {
		this.setState({
			hasError: false,
			error: null,
			errorInfo: null,
		});
	};

	/**
	 * Render error UI or children
	 */
	render() {
		if (this.state.hasError) {
			// Custom error UI
			return (
				<div className="thinkrank-error-boundary">
					<div className="notice notice-error">
						<h4>{__('Something went wrong', 'thinkrank')}</h4>
						<p>
							{this.props.fallbackMessage ||
								__(
									'This component encountered an error and could not be displayed.',
									'thinkrank'
								)}
						</p>
						<div className="error-actions">
							<button
								type="button"
								className="button button-secondary"
								onClick={this.resetErrorBoundary}
							>
								{__('Try Again', 'thinkrank')}
							</button>
							<button
								type="button"
								className="button button-secondary"
								onClick={() => window.location.reload()}
							>
								{__('Refresh Page', 'thinkrank')}
							</button>
						</div>
					</div>

					{/* Development error details */}
					{process.env.NODE_ENV === 'development' &&
						this.state.error && (
							<details
								className="error-details"
								style={{ marginTop: '10px' }}
							>
								<summary>
									{__(
										'Error Details (Development Only)',
										'thinkrank'
									)}
								</summary>
								<pre
									style={{
										background: '#f1f1f1',
										padding: '10px',
										fontSize: '12px',
										overflow: 'auto',
										maxHeight: '200px',
									}}
								>
									{this.state.error.toString()}
									{this.state.errorInfo &&
										this.state.errorInfo.componentStack}
								</pre>
							</details>
						)}
				</div>
			);
		}

		return this.props.children;
	}
}

/**
 * Higher-order component to wrap components with error boundary
 *
 * @param {React.Component} WrappedComponent Component to wrap
 * @param {Object}          options          Error boundary options
 * @return {React.Component} Component wrapped with error boundary
 */
export const withErrorBoundary = (WrappedComponent, options = {}) => {
	const WithErrorBoundaryComponent = (props) => (
		<ErrorBoundary
			fallbackMessage={options.fallbackMessage}
			onError={options.onError}
		>
			<WrappedComponent {...props} />
		</ErrorBoundary>
	);

	WithErrorBoundaryComponent.displayName = `withErrorBoundary(${WrappedComponent.displayName || WrappedComponent.name})`;

	return WithErrorBoundaryComponent;
};

export default ErrorBoundary;
