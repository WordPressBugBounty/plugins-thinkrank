/**
 * Schema Preview Section
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';

const PreviewSection = ({
	finalPreviewSchemas,
	savedSchemas,
	formData,
	postTitle,
	contentPreview,
}) => {
	const hasPreviewSchemas = Object.keys(finalPreviewSchemas).length > 0;

	if (!hasPreviewSchemas) return null;

	return (
		<div className="tr-bg-white tr-rounded-lg tr-border tr-border-secondary tr-mb-4">
			<div className="tr-px-4 tr-py-3 tr-border-b tr-border-secondary">
				<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
					{__('Schema Preview', 'thinkrank')}
				</h4>
				<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
					{__(
						'Preview of the generated schema markup in JSON-LD format.',
						'thinkrank'
					)}
				</p>
			</div>
			<div className="tr-p-4 tr-flex tr-flex-col tr-gap-4">
				{/* Schema type badges */}
				<div className="schema-types-generated">
					<h5>{__('Schema Types:', 'thinkrank')}</h5>
					<div className="schema-type-badges">
						{Object.keys(finalPreviewSchemas).map((schemaType) => (
							<span
								key={schemaType}
								className="schema-type-badge"
							>
								{schemaType}
							</span>
						))}
					</div>
				</div>

				{/* JSON-LD preview */}
				<div className="schema-code-preview">
					<h5>{__('JSON-LD Code:', 'thinkrank')}</h5>
					{Object.entries(finalPreviewSchemas).map(
						([schemaType, schemaData]) => (
							<div key={schemaType} className="schema-code-block">
								<h6
									style={{
										display: 'flex',
										alignItems: 'center',
										gap: '6px',
										margin: '0 0 8px 0',
										fontSize: '13px',
										fontWeight: 600,
									}}
								>
									{schemaType} Schema
									{savedSchemas.some(
										(s) =>
											s._isActive &&
											s['@type'] === schemaType
									) && (
										<span
											className="schema-type-badge saved-schema-badge"
											style={{
												backgroundColor: '#e5e5e5',
												color: '#333',
												fontSize: '10px',
												padding: '2px 6px',
											}}
										>
											{__('Custom', 'thinkrank')}
										</span>
									)}
								</h6>
								<pre className="schema-json-preview">
									{JSON.stringify(schemaData, null, 2)}
								</pre>
							</div>
						)
					)}
				</div>

				{/* Data sources */}
				<div className="schema-data-source">
					<h5>{__('Data Sources Used:', 'thinkrank')}</h5>
					<ul>
						{formData?.thinkrank_seo_title && (
							<li>
								{__(
									'✓ SEO Metadata title (AI-optimized)',
									'thinkrank'
								)}
							</li>
						)}
						{formData?.thinkrank_meta_description && (
							<li>
								{__(
									'✓ SEO Metadata description (AI-optimized)',
									'thinkrank'
								)}
							</li>
						)}
						{formData?.thinkrank_focus_keyword && (
							<li>{__('✓ Focus keyword', 'thinkrank')}</li>
						)}
						{postTitle && !formData?.thinkrank_seo_title && (
							<li>
								{__('✓ Post title (fallback)', 'thinkrank')}
							</li>
						)}
						{contentPreview && (
							<li>{__('✓ Post content', 'thinkrank')}</li>
						)}
					</ul>
					<p className="description">
						{__(
							'Schema markup uses SEO Metadata from the SEO Analysis tab. Use "Generate with AI" to optimize title and description.',
							'thinkrank'
						)}
					</p>
				</div>
			</div>
		</div>
	);
};

export default PreviewSection;
