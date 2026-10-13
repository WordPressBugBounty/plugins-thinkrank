/**
 * Metadata Generator Tab Component
 *
 * Wrapper component for MetadataGenerator in AI Tools page
 *
 * @package
 * @since 1.0.0
 */

import { useState } from '@wordpress/element';
import MetadataGenerator, { MetadataPreview } from '../ai/MetadataGenerator';
import SerpPreview from '../ai/SerpPreview';

/**
 * Metadata Generator Tab Component
 *
 * This component replicates the same layout and functionality as the AIToolsTab
 * from Dashboard.js, providing MetadataGenerator with SerpPreview in a two-column layout
 */
const MetadataGeneratorTab = () => {
	const [generatedMetadata, setGeneratedMetadata] = useState(null);

	const handleMetadataGenerated = (metadata) => {
		setGeneratedMetadata(metadata);
	};

	return (
		<div className="tr-grid tr-grid-cols-1 lg:tr-grid-cols-5 tr-gap-6">
			<div className="tr-min-w-0 lg:tr-col-span-3">
				<MetadataGenerator
					onMetadataGenerated={handleMetadataGenerated}
					initialContent=""
				/>
			</div>
			<div className="tr-min-w-0 lg:tr-col-span-2 tr-flex tr-flex-col tr-gap-6">
				<SerpPreview
					title={generatedMetadata?.title || ''}
					description={generatedMetadata?.description || ''}
					url={window.location.origin}
				/>
				{generatedMetadata && (
					<MetadataPreview
						metadata={generatedMetadata}
						allowApply={true}
					/>
				)}
			</div>
		</div>
	);
};

export default MetadataGeneratorTab;
