/**
 * Confirmation Dialog Component
 *
 * Reusable modal for export/migrate/cleanup/delete confirmations.
 *
 * @package
 * @since 2.0.0
 */

import { __ } from '@wordpress/i18n';
import { Modal, Button } from '../ui';

/**
 * Confirmation Dialog
 *
 * Uses the house `ui/Modal` (rendered inside the app's `.tr-root`) rather than
 * the WordPress `Modal`, which portals to `document.body` outside the app's
 * styled root and renders visually out of step with the rest of the admin.
 *
 * @param {Object}             props               Component props
 * @param {string}             props.title         Dialog title
 * @param {string|JSX.Element} props.message       Dialog message
 * @param {string}             props.confirmLabel  Confirm button label
 * @param {boolean}            props.isDestructive Whether the action is destructive
 * @param {Function}           props.onConfirm     Confirm callback
 * @param {Function}           props.onCancel      Cancel callback
 */
const ConfirmationDialog = ({
	title,
	message,
	confirmLabel,
	isDestructive = false,
	onConfirm,
	onCancel,
}) => {
	return (
		<Modal
			isOpen
			onClose={onCancel}
			title={title}
			size="md"
			footer={
				<>
					<Button variant="secondary" onClick={onCancel}>
						{__('Cancel', 'thinkrank')}
					</Button>
					<Button
						variant={isDestructive ? 'danger' : 'primary'}
						onClick={onConfirm}
					>
						{confirmLabel}
					</Button>
				</>
			}
		>
			<div className="thinkrank-confirmation-dialog__content tr-text-sm tr-text-secondary [&_p]:tr-mt-0 [&_p]:tr-mb-3 [&_p:last-child]:tr-mb-0 [&_strong]:tr-text-primary [&_strong]:tr-font-semibold">
				{typeof message === 'string' ? (
					<p className="tr-m-0">{message}</p>
				) : (
					message
				)}
			</div>
		</Modal>
	);
};

/**
 * Pre-built dialog configurations
 * @param pluginName
 */
export const getExportDialog = (pluginName) => ({
	title: __('Export SEO data from ', 'thinkrank') + pluginName + '?',
	message: __(
		'This will read metadata from your database and store a snapshot in your WordPress options table. No data will be modified or deleted.',
		'thinkrank'
	),
	confirmLabel: __('Start Export', 'thinkrank'),
	isDestructive: false,
});

export const getMigrateDialog = () => ({
	title: __('Migrate snapshot to ThinkRank?', 'thinkrank'),
	message: (
		<div>
			<p>
				{__(
					'This writes ThinkRank SEO settings and metadata from the exported snapshot. It is safe to run more than once.',
					'thinkrank'
				)}
			</p>
			<p>
				{__(
					'Running the migration again may overwrite existing ThinkRank settings and metadata where applicable ThinkRank fields still at their defaults are filled from the snapshot, while values you have already customized are preserved.',
					'thinkrank'
				)}
			</p>
			<p>
				<strong>
					{__(
						'We recommend creating a database backup before proceeding.',
						'thinkrank'
					)}
				</strong>
			</p>
		</div>
	),
	confirmLabel: __('Start Migration', 'thinkrank'),
	isDestructive: false,
});

export const getCleanupDialog = (pluginName) => ({
	title:
		__('Remove ', 'thinkrank') +
		pluginName +
		__(' data from database?', 'thinkrank'),
	message: (
		<div>
			<p>
				{__('This will permanently delete all ', 'thinkrank') +
					pluginName +
					__(
						' meta keys from your posts and terms. This cannot be undone.',
						'thinkrank'
					)}
			</p>
			<p>
				{__(
					'Only proceed if you have verified that ThinkRank is working correctly with the imported data.',
					'thinkrank'
				)}
			</p>
		</div>
	),
	confirmLabel:
		__('Delete ', 'thinkrank') + pluginName + __(' Data', 'thinkrank'),
	isDestructive: true,
});

/**
 * Shown when the server blocked cleanup (HTTP 409) because the snapshot still
 * holds data ThinkRank has not applied yet. Confirming retries with force=true.
 *
 * @param {string}   pluginName Source plugin name.
 * @param {string}   message    Server-provided explanation.
 * @param {Object[]} preserved  Preserved-but-unapplied buckets ({label, count}).
 */
export const getForceCleanupDialog = (pluginName, message, preserved = []) => ({
	title: __('Some data is not applied yet', 'thinkrank'),
	message: (
		<div>
			<p>{message}</p>
			{preserved.length > 0 && (
				<ul style={{ listStyleType: 'disc', paddingLeft: '20px' }}>
					{preserved.map((bucket, index) => (
						<li key={index}>
							{bucket.label}
							{bucket.count > 1 ? ` (${bucket.count})` : ''}
						</li>
					))}
				</ul>
			)}
			<p>
				<strong>
					{__(
						"This data stays preserved in the snapshot, but the source plugin's copy will be permanently removed.",
						'thinkrank'
					)}
				</strong>
			</p>
		</div>
	),
	confirmLabel:
		__('Delete ', 'thinkrank') +
		pluginName +
		__(' Data Anyway', 'thinkrank'),
	isDestructive: true,
});

export const getDeleteSnapshotDialog = (pluginName, extendedData = []) => ({
	title:
		__('Delete ', 'thinkrank') + pluginName + __(' snapshot?', 'thinkrank'),
	message: (
		<div>
			{extendedData.length > 0 && (
				<>
					<p>
						{__(
							'This snapshot contains data that ThinkRank does not yet support:',
							'thinkrank'
						)}
					</p>
					<ul style={{ listStyleType: 'disc', paddingLeft: '20px' }}>
						{extendedData.map((item, index) => (
							<li key={index}>{item}</li>
						))}
					</ul>
				</>
			)}
			<p>
				<strong>
					{__(
						'Deleting this snapshot means this data cannot be migrated in the future unless you reinstall the source plugin and re-export.',
						'thinkrank'
					)}
				</strong>
			</p>
		</div>
	),
	confirmLabel: __('Delete Snapshot', 'thinkrank'),
	isDestructive: true,
});

export default ConfirmationDialog;
