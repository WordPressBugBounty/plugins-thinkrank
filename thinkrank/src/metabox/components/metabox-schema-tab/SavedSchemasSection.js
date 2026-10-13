/**
 * Saved Schemas List Section
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';

const SavedSchemasSection = ({
	savedSchemas,
	editingSchemaIndex,
	onEdit,
	onToggleActivation,
	onDelete,
}) => {
	if (savedSchemas.length === 0) return null;

	const btnSm =
		'tr-inline-flex tr-items-center tr-h-7 tr-px-2 tr-rounded tr-border tr-border-primary tr-bg-white tr-text-[11px] tr-font-medium tr-text-secondary hover:tr-bg-white tr-cursor-pointer';
	const btnSmActive =
		'tr-inline-flex tr-items-center tr-h-7 tr-px-2 tr-rounded tr-border-0 tr-text-[11px] tr-font-medium tr-text-white tr-bg-brand hover:tr-bg-brand tr-cursor-pointer';
	const btnSmDanger =
		'tr-inline-flex tr-items-center tr-h-7 tr-px-2 tr-rounded tr-border tr-border-danger tr-bg-white tr-text-[11px] tr-font-medium tr-text-danger hover:tr-bg-danger-bg tr-cursor-pointer';

	return (
		<div className="tr-mt-4 tr-bg-white tr-rounded-lg tr-border tr-border-secondary tr-mb-4">
			<div className="tr-px-4 tr-py-3 tr-border-b tr-border-secondary">
				<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
					{__('Saved Schemas', 'thinkrank')}
				</h4>
				<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
					{__(
						'Manage your additionally imported schemas.',
						'thinkrank'
					)}
				</p>
			</div>
			<div className="tr-divide-y tr-divide-line-2">
				{savedSchemas.map((schema, index) => (
					<div
						key={index}
						className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-px-4 tr-py-3"
						style={{
							backgroundColor:
								editingSchemaIndex === index
									? '#f8fafc'
									: 'transparent',
						}}
					>
						<div className="tr-min-w-0">
							<span className="tr-text-xs tr-font-semibold tr-text-primary">
								{schema['@type'] ||
									__('Unknown Type', 'thinkrank')}
							</span>
							{schema.name && (
								<span className="tr-text-xs tr-text-tertiary tr-ml-1.5">
									· {schema.name}
								</span>
							)}
						</div>
						<div className="tr-flex tr-items-center tr-gap-1.5 tr-shrink-0">
							<button
								type="button"
								onClick={() => onEdit(index)}
								className={btnSm}
							>
								{__('Edit', 'thinkrank')}
							</button>
							<button
								type="button"
								onClick={() => onToggleActivation(index)}
								className={
									schema._isActive ? btnSmActive : btnSm
								}
							>
								{schema._isActive
									? __('Deactivate', 'thinkrank')
									: __('Activate', 'thinkrank')}
							</button>
							<button
								type="button"
								onClick={() => onDelete(index)}
								className={btnSmDanger}
							>
								{__('Delete', 'thinkrank')}
							</button>
						</div>
					</div>
				))}
			</div>
		</div>
	);
};

export default SavedSchemasSection;
