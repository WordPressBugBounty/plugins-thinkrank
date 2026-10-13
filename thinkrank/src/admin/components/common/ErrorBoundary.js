/**
 * Error Boundary Component
 *
 * Catches and handles React errors gracefully
 *
 * @package
 * @since 1.0.0
 */

import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Button } from '@wordpress/components';
import logger from '../../../shared/utils/logger';

/**
 * Error Boundary Component
 */
class ErrorBoundary extends Component {
	constructor(props) {
		super(props);
		this.state = { hasError: false, error: null, errorInfo: null };
	}

	static getDerivedStateFromError(error) {
		// Update state so the next render will show the fallback UI
		return { hasError: true };
	}

	componentDidCatch(error, errorInfo) {
		// Log error details
		this.setState({
			error,
			errorInfo,
		});

		// Log to console for debugging
		logger.error(
			'ThinkRank Error Boundary caught an error:',
			error,
			errorInfo
		);
	}

	handleReload = () => {
		// Reload the page to recover from error
		window.location.reload();
	};

	render() {
		if (this.state.hasError) {
			return (
				<div className="thinkrank-error-boundary">
					<Notice status="error" isDismissible={false}>
						<h3>{__('Something went wrong', 'thinkrank')}</h3>
						<p>
							{__(
								'ThinkRank encountered an unexpected error. Please try reloading the page.',
								'thinkrank'
							)}
						</p>

						<div className="error-actions">
							<Button isPrimary onClick={this.handleReload}>
								{__('Reload Page', 'thinkrank')}
							</Button>
						</div>

						{process.env.NODE_ENV === 'development' && (
							<details className="error-details">
								<summary>
									{__(
										'Error Details (Development)',
										'thinkrank'
									)}
								</summary>
								<pre>
									{this.state.error &&
										this.state.error.toString()}
									<br />
									{this.state.errorInfo.componentStack}
								</pre>
							</details>
						)}
					</Notice>
				</div>
			);
		}

		return this.props.children;
	}
}

export default ErrorBoundary;
