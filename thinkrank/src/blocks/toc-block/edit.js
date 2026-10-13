/**
 * TOC block — editor.
 *
 * Live-derives the heading list from the document's core/heading blocks and
 * keeps the block attribute in sync (Rank Math approach). Headings without an
 * anchor get one assigned directly on the heading block, so the saved heading
 * markup carries the id and the TOC links work with no front-end JavaScript.
 */

import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import {
	InspectorControls,
	useBlockProps,
	RichText,
	PanelColorSettings,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TabPanel,
	SelectControl,
	ToggleControl,
	RangeControl,
	Notice,
} from '@wordpress/components';
import { HEADING_TAGS, LIST_STYLES, slugify, listItemStyle } from './shared';

export default function Edit({ attributes, setAttributes }) {
	const {
		heading,
		headingTag,
		headings,
		maxLevel,
		listStyle,
		outputSchema,
		linkColor,
		itemSpacing,
	} = attributes;

	const blockProps = useBlockProps({ className: 'thinkrank-toc' });
	const HeadingTag = headingTag || 'h2';

	const { updateBlockAttributes, __unstableMarkNextChangeAsNotPersistent } =
		useDispatch('core/block-editor');

	// All heading blocks in document order (including nested ones).
	const headingBlocks = useSelect((select) => {
		const editor = select('core/block-editor');
		return editor
			.getClientIdsWithDescendants()
			.map((clientId) => editor.getBlock(clientId))
			.filter((block) => block && block.name === 'core/heading');
	}, []);

	useEffect(() => {
		const used = new Set();
		const derived = [];

		headingBlocks.forEach((block) => {
			const level = block.attributes.level ?? 2;
			const content = (block.attributes.content || '').toString();
			const text = content.replace(/<[^>]+>/g, '').trim();
			if (!text || level < 2 || level > maxLevel) {
				return;
			}

			let anchor = block.attributes.anchor || '';
			if (!anchor) {
				const base = slugify(text) || 'section';
				anchor = base;
				let n = 2;
				while (used.has(anchor)) {
					anchor = `${base}-${n++}`;
				}
				// Persist the generated id on the heading block itself so the
				// saved <h2 id="..."> markup carries it to the front end.
				updateBlockAttributes(block.clientId, { anchor });
			}
			used.add(anchor);

			derived.push({ content: text, level, anchor });
		});

		const changed =
			JSON.stringify(derived) !== JSON.stringify(headings || []);
		if (changed) {
			// Keep the sync out of the undo stack — it's derived state.
			__unstableMarkNextChangeAsNotPersistent();
			setAttributes({ headings: derived });
		}
	}, [headingBlocks, maxLevel]);

	const list = Array.isArray(headings) ? headings : [];

	return (
		<>
			<InspectorControls>
				<TabPanel
					className="thinkrank-toc-tabs"
					activeClass="is-active"
					tabs={[
						{ name: 'general', title: __('General', 'thinkrank') },
						{ name: 'style', title: __('Style', 'thinkrank') },
					]}
				>
					{(tab) =>
						tab.name === 'general' ? (
							<PanelBody
								title={__('Content', 'thinkrank')}
								initialOpen={true}
							>
								<SelectControl
									label={__('Heading tag', 'thinkrank')}
									value={headingTag}
									options={HEADING_TAGS}
									onChange={(v) =>
										setAttributes({ headingTag: v })
									}
								/>
								<SelectControl
									label={__(
										'Include headings up to',
										'thinkrank'
									)}
									value={String(maxLevel)}
									options={[
										{ label: 'H2', value: '2' },
										{ label: 'H3', value: '3' },
										{ label: 'H4', value: '4' },
									]}
									onChange={(v) =>
										setAttributes({
											maxLevel: parseInt(v, 10),
										})
									}
								/>
								<SelectControl
									label={__('List style', 'thinkrank')}
									value={listStyle}
									options={LIST_STYLES}
									onChange={(v) =>
										setAttributes({ listStyle: v })
									}
								/>
								<ToggleControl
									label={__(
										'Output TOC schema (JSON-LD)',
										'thinkrank'
									)}
									help={__(
										'Adds SiteNavigationElement structured data for the listed sections.',
										'thinkrank'
									)}
									checked={!!outputSchema}
									onChange={(v) =>
										setAttributes({ outputSchema: v })
									}
								/>
							</PanelBody>
						) : (
							<>
								<PanelColorSettings
									title={__('Colors', 'thinkrank')}
									initialOpen={true}
									colorSettings={[
										{
											value: linkColor,
											onChange: (v) =>
												setAttributes({
													linkColor: v,
												}),
											label: __('Links', 'thinkrank'),
										},
									]}
								/>
								<PanelBody
									title={__('Spacing', 'thinkrank')}
									initialOpen={false}
								>
									<RangeControl
										label={__(
											'Space between items (px)',
											'thinkrank'
										)}
										value={itemSpacing}
										onChange={(v) =>
											setAttributes({ itemSpacing: v })
										}
										min={0}
										max={24}
									/>
								</PanelBody>
							</>
						)
					}
				</TabPanel>
			</InspectorControls>

			<div {...blockProps}>
				<RichText
					tagName={HeadingTag}
					className="thinkrank-toc__heading"
					value={heading}
					onChange={(v) => setAttributes({ heading: v })}
					placeholder={__('Table of Contents', 'thinkrank')}
					allowedFormats={[]}
				/>

				{list.length === 0 ? (
					<Notice status="info" isDismissible={false}>
						{__(
							'Add headings (H2 and below) to the post and they will appear here automatically.',
							'thinkrank'
						)}
					</Notice>
				) : (
					<ul
						className={`thinkrank-toc__list thinkrank-toc__list--${listStyle}`}
					>
						{list.map((item, index) => (
							<li
								key={index}
								className={`thinkrank-toc__item thinkrank-toc__item--level-${item.level}`}
								style={listItemStyle(attributes)}
							>
								<a href={`#${item.anchor}`}>{item.content}</a>
							</li>
						))}
					</ul>
				)}
			</div>
		</>
	);
}
