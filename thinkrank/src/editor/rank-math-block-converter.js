/**
 * Convert Rank Math FAQ / HowTo blocks from inside the editor.
 *
 * The Migration screen converts a whole site in one pass; this is the per-post
 * escape hatch for the state users actually land in — Rank Math deactivated,
 * and a post full of "Your site doesn't include support for the 'FAQ by Rank
 * Math' block" warnings (#777).
 *
 * Two entry points, because the block behaves differently depending on whether
 * Rank Math is still installed and active:
 *
 * - **Rank Math inactive** — the block is unregistered, so the editor parses it
 *   as `core/missing`. Its `originalContent` still holds the whole original
 *   block, delimiters and attribute JSON included, which is the only surviving
 *   copy of its attributes. A toolbar button converts that one block, and a
 *   second converts every one left on the page.
 * - **Rank Math active** — the block is registered and valid, so there is
 *   nothing to repair. A block transform puts the conversion in the normal
 *   Transform menu instead.
 *
 * The converted block is built with createBlock() and serialized by the editor,
 * so its markup is whatever the block's own save() produces.
 */

import { addFilter } from '@wordpress/hooks';
import { createBlock } from '@wordpress/blocks';
import {
	BlockControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import {
	BLOCK_MAP,
	parseAttributes,
	mapBlock,
	imageIds,
	mainImageAttributes,
} from './rank-math-mapping';

/**
 * Resolve attachment ids to the url/alt the blocks need.
 *
 * Fetched rather than read from a store selector: a selector returns undefined
 * until its resolver has run, which on a first click would silently drop every
 * image. An id whose attachment has since been deleted simply fails and is left
 * out, so the converted block drops that image instead of carrying a dead URL.
 *
 * @param {number[]} ids Attachment ids.
 * @return {Promise<Object>} Attachment id => { url, alt }.
 */
async function resolveImages(ids) {
	const resolved = {};

	await Promise.all(
		ids.map(async (id) => {
			try {
				const media = await apiFetch({ path: `/wp/v2/media/${id}` });

				if (media && media.source_url) {
					resolved[id] = {
						url: media.source_url,
						alt: media.alt_text || '',
					};
				}
			} catch (e) {
				// Attachment is gone; the block renders without it.
			}
		})
	);

	return resolved;
}

/**
 * Build the replacement blocks for one Rank Math block.
 *
 * @param {Object} block The core/missing block.
 * @return {Promise<Object[]|null>} Blocks to replace it with, or null when not
 *         convertible.
 */
async function buildReplacement(block) {
	const blockName = block?.attributes?.originalName;

	if (!BLOCK_MAP[blockName]) {
		return null;
	}

	const attrs = parseAttributes(block.attributes.originalContent);
	const resolved = await resolveImages(imageIds(blockName, attrs));
	const mapped = mapBlock(blockName, attrs, resolved);

	if (!mapped) {
		// Every item was hidden, so there is nothing to show. Replacing the
		// block with nothing still beats leaving the warning in place for
		// content that was never on the page.
		return [];
	}

	const blocks = [];

	const mainImage = mainImageAttributes(blockName, attrs, resolved);
	if (mainImage) {
		blocks.push(createBlock('core/image', mainImage));
	}

	blocks.push(createBlock(mapped.name, mapped.attributes));

	return blocks;
}

/**
 * Whether a block is a convertible Rank Math leftover.
 *
 * @param {Object} block Parsed block.
 * @return {boolean} Whether it can be converted.
 */
function isConvertibleMissing(block) {
	return (
		block?.name === 'core/missing' &&
		!!BLOCK_MAP[block?.attributes?.originalName]
	);
}

/**
 * Collect every convertible leftover in a block tree.
 *
 * @param {Object[]} blocks Blocks to walk.
 * @param {Object[]} out    Accumulator.
 * @return {Object[]} Convertible blocks, in document order.
 */
function collectConvertible(blocks, out = []) {
	(blocks || []).forEach((block) => {
		if (isConvertibleMissing(block)) {
			out.push(block);
		}

		if (block?.innerBlocks?.length) {
			collectConvertible(block.innerBlocks, out);
		}
	});

	return out;
}

/**
 * Add the conversion controls to an unsupported Rank Math block.
 */
const withRankMathConversion = createHigherOrderComponent(
	(BlockEdit) => (props) => {
		// Hooks run unconditionally. Bailing out before them would change the
		// hook order between renders the moment a block stops or starts being a
		// Rank Math leftover, which React treats as a fatal error.
		const { replaceBlocks } = useDispatch(blockEditorStore);
		const getBlocks = useSelect(
			(select) => select(blockEditorStore).getBlocks,
			[]
		);

		const convertOne = useCallback(async () => {
			const replacement = await buildReplacement(props);

			if (replacement) {
				replaceBlocks(props.clientId, replacement);
			}
		}, [props, replaceBlocks]);

		const convertAll = useCallback(async () => {
			// Snapshot the list first: each replaceBlocks() call invalidates the
			// tree, so re-walking it mid-loop would skip blocks.
			const targets = collectConvertible(getBlocks());

			for (const block of targets) {
				// eslint-disable-next-line no-await-in-loop
				const replacement = await buildReplacement(block);

				if (replacement) {
					replaceBlocks(block.clientId, replacement);
				}
			}
		}, [getBlocks, replaceBlocks]);

		if (!isConvertibleMissing(props)) {
			return <BlockEdit {...props} />;
		}

		const isFaq = props.attributes.originalName === 'rank-math/faq-block';
		const remaining = collectConvertible(getBlocks()).length;

		return (
			<>
				<BlockEdit {...props} />
				<BlockControls>
					<ToolbarGroup>
						<ToolbarButton onClick={convertOne}>
							{isFaq
								? __('Convert to ThinkRank FAQ', 'thinkrank')
								: __('Convert to ThinkRank HowTo', 'thinkrank')}
						</ToolbarButton>
						{remaining > 1 && (
							<ToolbarButton onClick={convertAll}>
								{__(
									'Convert all Rank Math blocks on this page',
									'thinkrank'
								)}
							</ToolbarButton>
						)}
					</ToolbarGroup>
				</BlockControls>
			</>
		);
	},
	'withRankMathConversion'
);

addFilter(
	'editor.BlockEdit',
	'thinkrank/rank-math-block-converter',
	withRankMathConversion
);

/**
 * While Rank Math is active its blocks are registered and valid, so the
 * conversion belongs in the Transform menu rather than in a repair button.
 *
 * Registered lazily on the first editor render: the ThinkRank blocks have to
 * exist before a transform can name them, and the Rank Math block has to be
 * registered before its transforms can be extended.
 */
addFilter(
	'blocks.registerBlockType',
	'thinkrank/rank-math-block-transforms',
	(settings, name) => {
		if (!BLOCK_MAP[name]) {
			return settings;
		}

		const target = BLOCK_MAP[name];

		const transform = {
			type: 'block',
			blocks: [target],
			transform: (attributes) => {
				const mapped = mapBlock(name, attributes, {});

				// Images cannot be resolved synchronously in a transform, so a
				// transformed block keeps its text and loses its images. The
				// Migration screen and the repair button both resolve them.
				return mapped
					? createBlock(mapped.name, mapped.attributes)
					: createBlock(target);
			},
		};

		return {
			...settings,
			transforms: {
				...(settings.transforms || {}),
				to: [...(settings.transforms?.to || []), transform],
			},
		};
	}
);
