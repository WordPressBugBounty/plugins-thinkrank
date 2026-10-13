/**
 * Schema Disabled Notice
 *
 * Site-level schema forms disable every field while the master
 * "Enable Schema Markup" switch is off. Without this banner that state is
 * silent — the fields simply refuse input with no reason given — so each form
 * renders it above its cards and links straight to the switch.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Alert, Button } from '../../ui';

const SchemaDisabledNotice = ({ onNavigate }) => (
	<Alert variant="warning" title={__('Schema markup is off', 'thinkrank')}>
		<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-3">
			<span>
				{__(
					'These fields stay read-only until you enable schema markup in Schema Settings.',
					'thinkrank'
				)}
			</span>
			{typeof onNavigate === 'function' && (
				<Button
					variant="secondary"
					size="sm"
					onClick={() => onNavigate('schema', 'schema-settings')}
				>
					{__('Go to Schema Settings', 'thinkrank')}
				</Button>
			)}
		</div>
	</Alert>
);

export default SchemaDisabledNotice;
