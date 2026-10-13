/**
 * Setup Section Component
 *
 * OAuth setup & search console configuration section.
 * Extracted from AnalyticsTab for maintainability.
 *
 * @package
 * @since 1.11.0
 */

import { __ } from '@wordpress/i18n';
import {
	Card,
	CardBody,
	CardHeader,
	Button,
	__experimentalText as Text,
} from '@wordpress/components';

/**
 * SetupSection Component
 *
 * @param {Object}   props
 * @param {Function} props.onNavigate Navigation callback
 */
const SetupSection = ({ onNavigate }) => (
	<div className="thinkrank-space-y-6">
		<Text variant="muted">
			{__(
				'Configure Google API connections for SEO analytics data collection.',
				'thinkrank'
			)}
		</Text>

		{/* Setup Instructions */}
		<Card
			size="small"
			className="thinkrank-mt-md thinkrank-mb-lg thinkrank-border thinkrank-border-light thinkrank-rounded-lg"
		>
			<CardHeader className="thinkrank-border-b thinkrank-border-light thinkrank-bg-gray-100">
				<h3>{__('Setup Instructions', 'thinkrank')}</h3>
			</CardHeader>
			<CardBody className="thinkrank-p-lg">
				<div className="thinkrank-space-y-md">
					<div>
						<h4 className="thinkrank-mb-1 thinkrank-text-primary">
							{__('1. Configure API Keys', 'thinkrank')}
						</h4>
						<Text variant="muted">
							{__(
								'Add your Google Analytics, Search Console, and PageSpeed API keys in the ',
								'thinkrank'
							)}
							<Button
								variant="link"
								onClick={() => {
									// Navigate to Integrations section
									if (onNavigate) {
										onNavigate(
											'integrations',
											'google-services'
										);
									}
								}}
								className="thinkrank-p-0 thinkrank-underline thinkrank-h-auto"
							>
								{__('Integrations tab', 'thinkrank')}
							</Button>
							{__('.', 'thinkrank')}
						</Text>
					</div>
					<div>
						<h4 className="thinkrank-mb-1 thinkrank-text-primary">
							{__('2. Test Connections', 'thinkrank')}
						</h4>
						<Text variant="muted">
							{__(
								'Use the "Test Connections" button to verify your API configuration.',
								'thinkrank'
							)}
						</Text>
					</div>
					<div>
						<h4 className="thinkrank-mb-1 thinkrank-text-primary">
							{__('3. Enable Analytics', 'thinkrank')}
						</h4>
						<Text variant="muted">
							{__(
								'Toggle the "Enable SEO Analytics" switch above to start collecting data.',
								'thinkrank'
							)}
						</Text>
					</div>
				</div>
			</CardBody>
		</Card>
	</div>
);

export default SetupSection;
