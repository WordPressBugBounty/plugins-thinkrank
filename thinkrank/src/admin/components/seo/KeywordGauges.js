/**
 * One seven-segment gauge per focus keyword (#729): is this keyword in the
 * SEO title, the meta description, the URL, the first paragraph, a
 * subheading, the body, an image's alt text?
 *
 * The blended score above stays; the gauges say where each keyword is
 * missing. Each segment carries its own hint, and — in the block editor —
 * the ones that live in the content select the block where the keyword could
 * go.
 *
 * @package
 * @since 2.11.0
 */

import { __, sprintf } from '@wordpress/i18n';

export const PLACEMENTS = [
	{
		id: 'title',
		label: __('SEO title', 'thinkrank'),
		hint: __('Add it to the SEO title, near the start.', 'thinkrank'),
	},
	{
		id: 'meta_description',
		label: __('Meta description', 'thinkrank'),
		hint: __(
			'Work it into the meta description, ideally its first sentence.',
			'thinkrank'
		),
	},
	{
		id: 'slug',
		label: __('URL', 'thinkrank'),
		hint: __('Include it in the permalink.', 'thinkrank'),
	},
	{
		id: 'first_paragraph',
		label: __('First paragraph', 'thinkrank'),
		hint: __('Mention it in your opening paragraph.', 'thinkrank'),
		block: 'core/paragraph',
	},
	{
		id: 'subheading',
		label: __('Subheading', 'thinkrank'),
		hint: __('Add it to one H2 or H3 subheading.', 'thinkrank'),
		block: 'core/heading',
	},
	{
		id: 'content',
		label: __('Body', 'thinkrank'),
		hint: __('Use it in the body where it fits naturally.', 'thinkrank'),
		block: 'core/paragraph',
	},
	{
		id: 'image_alt',
		label: __('Image alt text', 'thinkrank'),
		hint: __(
			'If an image shows it, describe that image with it.',
			'thinkrank'
		),
		block: 'core/image',
	},
];

/**
 * Select the first block of a type in the block editor, which scrolls it
 * into view. No-op outside the block editor.
 *
 * @param {string} name Block name, e.g. core/heading.
 * @return {boolean} Whether a block was selected.
 */
export const selectFirstBlock = (name) => {
	const data = window.wp?.data;
	const editor = data?.select?.('core/block-editor');
	if (!editor?.getBlocks) {
		return false;
	}

	const find = (blocks) => {
		for (const block of blocks || []) {
			if (block.name === name) {
				return block;
			}
			const inner = find(block.innerBlocks);
			if (inner) {
				return inner;
			}
		}
		return null;
	};

	const block = find(editor.getBlocks());
	if (!block) {
		return false;
	}
	data.dispatch('core/block-editor').selectBlock(block.clientId);

	return true;
};

/**
 * What a segment says.
 *
 * @param {Object} placement Entry from PLACEMENTS.
 * @param {Object} state     { passed, where } from the API.
 * @return {string} Tooltip text.
 */
export const segmentText = (placement, state) => {
	if (!state?.passed) {
		return sprintf(
			/* translators: 1: where, e.g. "Subheading", 2: what to do. */
			__('Not in: %1$s · %2$s', 'thinkrank'),
			placement.label,
			placement.hint
		);
	}
	if (state.where) {
		return sprintf(
			/* translators: 1: where, e.g. "Subheading", 2: the matching text. */
			__('In: %1$s · “%2$s”', 'thinkrank'),
			placement.label,
			state.where
		);
	}

	/* translators: %s: where, e.g. "SEO title". */
	return sprintf(__('In: %s', 'thinkrank'), placement.label);
};

const KeywordGauge = ({ row }) => {
	const missing = PLACEMENTS.filter((p) => !row.placements?.[p.id]?.passed);
	const total = row.total || PLACEMENTS.length;
	const passed = total - missing.length;
	let tone = 'tr-text-warning';
	if (0 === missing.length) {
		tone = 'tr-text-success';
	} else if (passed <= 2) {
		tone = 'tr-text-danger';
	}

	return (
		<li className="tr-m-0">
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-2 tr-text-xs tr-mb-1">
				<span className="tr-font-medium tr-text-primary tr-truncate">
					{row.keyword}
				</span>
				<span className={`tr-font-semibold ${tone}`}>
					{sprintf(
						/* translators: 1: placements found, 2: all placements. */
						__('%1$d/%2$d', 'thinkrank'),
						passed,
						total
					)}
				</span>
			</div>
			<div
				className="tr-flex tr-gap-0.5"
				role="group"
				aria-label={sprintf(
					/* translators: %s: focus keyword. */
					__('Where “%s” appears', 'thinkrank'),
					row.keyword
				)}
			>
				{PLACEMENTS.map((placement) => {
					const state = row.placements?.[placement.id];
					const text = segmentText(placement, state);
					const segment = `tr-block tr-h-1.5 tr-flex-1 tr-rounded-sm ${
						state?.passed ? 'tr-bg-success' : 'tr-bg-line-2'
					}`;

					// A missing content placement in the block editor jumps to
					// the block where the keyword could go.
					return !state?.passed && placement.block ? (
						<button
							key={placement.id}
							type="button"
							title={text}
							aria-label={text}
							className={`${segment} tr-border-0 tr-p-0 tr-cursor-pointer`}
							onClick={() => selectFirstBlock(placement.block)}
						/>
					) : (
						<span
							key={placement.id}
							title={text}
							aria-label={text}
							className={segment}
						/>
					);
				})}
			</div>
			{missing.length > 0 && (
				<p className="tr-text-[11px] tr-text-tertiary tr-m-0 tr-mt-1">
					{sprintf(
						/* translators: %s: comma-separated places, e.g. "Meta description, Subheading". */
						__('Missing from: %s', 'thinkrank'),
						missing.map((p) => p.label).join(', ')
					)}
				</p>
			)}
		</li>
	);
};

/**
 * @param {Object} props          Props.
 * @param {Array}  props.keywords `keywords` from the score API.
 * @return {JSX.Element|null} Gauges, or nothing without keywords.
 */
const KeywordGauges = ({ keywords }) => {
	if (!Array.isArray(keywords) || 0 === keywords.length) {
		return null;
	}

	return (
		<ul className="tr-m-0 tr-p-0 tr-list-none tr-flex tr-flex-col tr-gap-3">
			{keywords.map((row) => (
				<KeywordGauge key={row.keyword} row={row} />
			))}
		</ul>
	);
};

export default KeywordGauges;
