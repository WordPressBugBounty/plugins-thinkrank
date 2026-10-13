/**
 * ThinkRank FAQ block.
 *
 * An inline question/answer block (type Q&A directly, Rank Math style) that
 * renders a native <details> accordion and emits FAQPage JSON-LD on the front
 * end (the schema is added server-side via a render_block filter, so it is not
 * stripped by KSES on save). Static save; lean General/Style inspector tabs.
 */

import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import edit from './edit';
import save from './save';
import './style.scss';

registerBlockType('thinkrank/faq', {
	apiVersion: 3,
	title: __('FAQ (ThinkRank)', 'thinkrank'),
	description: __(
		'Add frequently asked questions as an accordion with FAQPage schema for rich results.',
		'thinkrank'
	),
	category: 'widgets',
	icon: 'editor-help',
	keywords: [
		__('faq', 'thinkrank'),
		__('questions', 'thinkrank'),
		__('accordion', 'thinkrank'),
		__('schema', 'thinkrank'),
	],
	supports: {
		html: false,
		anchor: true,
	},
	attributes: {
		heading: { type: 'string', default: '' },
		headingTag: { type: 'string', default: 'h2' },
		faqs: {
			type: 'array',
			default: [
				{
					question: '',
					answer: '',
					imageId: 0,
					imageUrl: '',
					imageAlt: '',
				},
			],
		},
		firstOpen: { type: 'boolean', default: true },
		outputSchema: { type: 'boolean', default: true },
		questionColor: { type: 'string' },
		questionBackground: { type: 'string' },
		answerColor: { type: 'string' },
		titleFontSize: { type: 'number', default: 17 },
		itemSpacing: { type: 'number', default: 8 },
		itemBackground: { type: 'string' },
		itemBorderColor: { type: 'string', default: '#e2e4e7' },
		itemBorderRadius: { type: 'number', default: 6 },
	},
	edit,
	save,
});
