/**
 * ThinkRank Table of Contents block.
 *
 * Builds a linked table of contents from the post's heading blocks (Rank Math
 * style). The editor keeps the list in sync with the document and assigns
 * missing anchors to headings (persisted on the heading blocks themselves, so
 * the links work on the front end with no JavaScript). Static save.
 */

import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import edit from './edit';
import save from './save';
import './style.scss';

registerBlockType('thinkrank/toc', {
	apiVersion: 3,
	title: __('Table of Contents (ThinkRank)', 'thinkrank'),
	description: __(
		'A linked table of contents generated from your headings.',
		'thinkrank'
	),
	category: 'widgets',
	icon: 'list-view',
	keywords: [
		__('table of contents', 'thinkrank'),
		__('toc', 'thinkrank'),
		__('index', 'thinkrank'),
		__('anchor', 'thinkrank'),
	],
	supports: {
		html: false,
		anchor: true,
	},
	attributes: {
		heading: { type: 'string', default: 'Table of Contents' },
		headingTag: { type: 'string', default: 'h2' },
		// Derived from the document's heading blocks; kept in sync by edit().
		headings: {
			type: 'array',
			default: [],
		},
		maxLevel: { type: 'number', default: 3 },
		listStyle: { type: 'string', default: 'disc' },
		outputSchema: { type: 'boolean', default: true },
		linkColor: { type: 'string' },
		itemSpacing: { type: 'number', default: 6 },
	},
	edit,
	save,
});
