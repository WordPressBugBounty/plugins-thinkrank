/**
 * ThinkRank HowTo block.
 *
 * An inline step-by-step guide block (type steps directly, Rank Math style)
 * that renders an ordered step list — each step with an optional image — and
 * emits HowTo JSON-LD on the front end (the schema is added server-side via a
 * render_block filter, so it is not stripped by KSES on save). Static save;
 * lean General/Style inspector tabs.
 */

import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import edit from './edit';
import save from './save';
import './style.scss';

registerBlockType('thinkrank/howto', {
	apiVersion: 3,
	title: __('HowTo (ThinkRank)', 'thinkrank'),
	description: __(
		'Add step-by-step instructions with HowTo schema for rich results.',
		'thinkrank'
	),
	category: 'widgets',
	icon: 'editor-ol',
	keywords: [
		__('how to', 'thinkrank'),
		__('steps', 'thinkrank'),
		__('instructions', 'thinkrank'),
		__('schema', 'thinkrank'),
	],
	supports: {
		html: false,
		anchor: true,
	},
	attributes: {
		heading: { type: 'string', default: '' },
		headingTag: { type: 'string', default: 'h2' },
		description: { type: 'string', default: '' },
		steps: {
			type: 'array',
			default: [
				{ title: '', text: '', imageId: 0, imageUrl: '', imageAlt: '' },
			],
		},
		totalDays: { type: 'number', default: 0 },
		totalHours: { type: 'number', default: 0 },
		totalMinutes: { type: 'number', default: 0 },
		showNumbers: { type: 'boolean', default: true },
		outputSchema: { type: 'boolean', default: true },
		stepTitleColor: { type: 'string' },
		stepTextColor: { type: 'string' },
		stepTitleFontSize: { type: 'number', default: 17 },
		stepSpacing: { type: 'number', default: 12 },
		stepBackground: { type: 'string' },
		stepBorderColor: { type: 'string' },
		stepBorderRadius: { type: 'number', default: 6 },
	},
	edit,
	save,
});
