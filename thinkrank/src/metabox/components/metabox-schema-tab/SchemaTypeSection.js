/**
 * Schema Type Selection Section
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';

const getSchemaFormComponent = (schemaType) => {
	switch (schemaType) {
		case 'HowTo':
			return require('../../../admin/components/essential-seo/schema/forms/SchemaHowToForm')
				.default;
		case 'FAQPage':
			return require('../../../admin/components/essential-seo/schema/forms/SchemaFAQForm')
				.default;
		case 'Event':
			return require('../../../admin/components/essential-seo/schema/forms/SchemaEventForm')
				.default;
		case 'SoftwareApplication':
			return require('../../../admin/components/essential-seo/schema/forms/SchemaSoftwareForm')
				.default;
		case 'Product':
			return require('../../../admin/components/essential-seo/schema/forms/SchemaProductForm')
				.default;
		case 'Review':
			return require('../../../admin/components/essential-seo/schema/forms/SchemaReviewForm')
				.default;
		case 'VideoObject':
			return require('../../../admin/components/essential-seo/schema/forms/SchemaVideoObjectForm')
				.default;
		default:
			return null;
	}
};

const SchemaTypeSection = ({
	selectedSchemaType,
	schemaTypes,
	schemaFormData,
	isGenerating,
	onSchemaTypeChange,
	onSchemaFormChange,
	onGenerateSchema,
}) => {
	const FormComponent = getSchemaFormComponent(selectedSchemaType);

	return (
		<div className="tr-w-full tr-bg-white tr-rounded-lg tr-border tr-border-secondary">
			<div className="tr-px-4 tr-py-3 tr-border-b tr-border-secondary">
				<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
					{__('Schema Type', 'thinkrank')}
				</h4>
				<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
					{__(
						'Select the type of structured data markup for this content.',
						'thinkrank'
					)}
				</p>
			</div>
			<div className="tr-p-4 tr-flex tr-flex-col tr-gap-3">
				<div>
					<label
						htmlFor="schema-type-select"
						className="tr-block tr-text-xs tr-font-medium tr-text-secondary tr-mb-1"
					>
						{__('Schema Type', 'thinkrank')}
					</label>
					<select
						id="schema-type-select"
						value={selectedSchemaType}
						onChange={(e) => onSchemaTypeChange(e.target.value)}
						className="tr-w-full tr-h-8 tr-rounded tr-border tr-border-primary tr-bg-white tr-text-xs tr-px-2 focus:tr-outline-none"
					>
						{schemaTypes.map((type) => (
							<option key={type.value} value={type.value}>
								{type.label}
							</option>
						))}
					</select>
					<p className="tr-text-[11px] tr-text-tertiary tr-m-0 tr-mt-1">
						{__(
							'Article is recommended for most blog posts and pages.',
							'thinkrank'
						)}
					</p>
				</div>

				{FormComponent && (
					<div className="schema-form-container">
						<FormComponent
							settings={{ ...schemaFormData, enabled: true }}
							onSettingChange={onSchemaFormChange}
							isLoading={isGenerating}
						/>
					</div>
				)}

				<div className="schema-actions">
					<button
						type="button"
						onClick={onGenerateSchema}
						disabled={isGenerating}
						className="tr-inline-flex tr-items-center tr-gap-1.5 tr-h-8 tr-px-3 tr-rounded tr-text-xs tr-font-medium tr-text-white tr-bg-brand hover:tr-bg-brand disabled:tr-opacity-50 disabled:tr-cursor-not-allowed tr-transition-colors tr-cursor-pointer tr-border-0"
					>
						{isGenerating && (
							<span className="tr-w-3 tr-h-3 tr-border-2 tr-border-white tr-border-t-transparent tr-rounded-full tr-animate-spin tr-shrink-0" />
						)}
						{isGenerating
							? __('Generating…', 'thinkrank')
							: __('Generate Schema', 'thinkrank')}
					</button>
				</div>
			</div>
		</div>
	);
};

export default SchemaTypeSection;
