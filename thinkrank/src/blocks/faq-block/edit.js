/**
 * FAQ block — editor.
 *
 * Inline Q&A editing (add / reorder / duplicate / remove) with a lean
 * General/Style inspector split. The preview mirrors the saved output via the
 * shared style helpers.
 */

import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	useBlockProps,
	RichText,
	PanelColorSettings,
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TabPanel,
	TextControl,
	SelectControl,
	ToggleControl,
	RangeControl,
	Button,
} from '@wordpress/components';
import { HEADING_TAGS, itemStyle, questionStyle, answerStyle } from './shared';

export default function Edit({ attributes, setAttributes }) {
	const {
		heading,
		headingTag,
		faqs,
		firstOpen,
		outputSchema,
		questionColor,
		questionBackground,
		answerColor,
		titleFontSize,
		itemSpacing,
		itemBackground,
		itemBorderColor,
		itemBorderRadius,
	} = attributes;

	const blockProps = useBlockProps({ className: 'thinkrank-faq' });
	const HeadingTag = headingTag || 'h2';
	const list = Array.isArray(faqs) ? faqs : [];

	const updateFaq = (index, patch) => {
		setAttributes({
			faqs: list.map((f, i) => (i === index ? { ...f, ...patch } : f)),
		});
	};
	const addFaq = () =>
		setAttributes({
			faqs: [
				...list,
				{
					question: '',
					answer: '',
					imageId: 0,
					imageUrl: '',
					imageAlt: '',
				},
			],
		});
	const removeFaq = (index) =>
		setAttributes({ faqs: list.filter((_, i) => i !== index) });
	const duplicateFaq = (index) => {
		const next = [...list];
		next.splice(index + 1, 0, { ...list[index] });
		setAttributes({ faqs: next });
	};
	const moveFaq = (index, dir) => {
		const target = index + dir;
		if (target < 0 || target >= list.length) {
			return;
		}
		const next = [...list];
		[next[index], next[target]] = [next[target], next[index]];
		setAttributes({ faqs: next });
	};

	return (
		<>
			<InspectorControls>
				<TabPanel
					className="thinkrank-faq-tabs"
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
								<TextControl
									label={__('Section heading', 'thinkrank')}
									value={heading}
									onChange={(v) =>
										setAttributes({ heading: v })
									}
									placeholder={__(
										'Frequently asked questions',
										'thinkrank'
									)}
								/>
								<SelectControl
									label={__('Heading tag', 'thinkrank')}
									value={headingTag}
									options={HEADING_TAGS}
									onChange={(v) =>
										setAttributes({ headingTag: v })
									}
								/>
								<ToggleControl
									label={__(
										'Open first item by default',
										'thinkrank'
									)}
									checked={!!firstOpen}
									onChange={(v) =>
										setAttributes({ firstOpen: v })
									}
								/>
								<ToggleControl
									label={__(
										'Output FAQ schema (JSON-LD)',
										'thinkrank'
									)}
									help={__(
										'Adds FAQPage structured data for rich results.',
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
											value: questionColor,
											onChange: (v) =>
												setAttributes({
													questionColor: v,
												}),
											label: __('Question', 'thinkrank'),
										},
										{
											value: questionBackground,
											onChange: (v) =>
												setAttributes({
													questionBackground: v,
												}),
											label: __(
												'Question background',
												'thinkrank'
											),
										},
										{
											value: answerColor,
											onChange: (v) =>
												setAttributes({
													answerColor: v,
												}),
											label: __('Answer', 'thinkrank'),
										},
										{
											value: itemBackground,
											onChange: (v) =>
												setAttributes({
													itemBackground: v,
												}),
											label: __(
												'Item background',
												'thinkrank'
											),
										},
										{
											value: itemBorderColor,
											onChange: (v) =>
												setAttributes({
													itemBorderColor: v,
												}),
											label: __('Border', 'thinkrank'),
										},
									]}
								/>
								<PanelBody
									title={__('Spacing & size', 'thinkrank')}
									initialOpen={false}
								>
									<RangeControl
										label={__(
											'Question size (px)',
											'thinkrank'
										)}
										value={titleFontSize}
										onChange={(v) =>
											setAttributes({
												titleFontSize: v,
											})
										}
										min={12}
										max={30}
									/>
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
										max={32}
									/>
									<RangeControl
										label={__(
											'Corner radius (px)',
											'thinkrank'
										)}
										value={itemBorderRadius}
										onChange={(v) =>
											setAttributes({
												itemBorderRadius: v,
											})
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
					className="thinkrank-faq__heading"
					value={heading}
					onChange={(v) => setAttributes({ heading: v })}
					placeholder={__('Frequently asked questions', 'thinkrank')}
					allowedFormats={[]}
				/>

				{list.map((faq, index) => (
					<div
						key={index}
						className="thinkrank-faq__item thinkrank-faq__item--editing"
						style={itemStyle(attributes)}
					>
						<RichText
							tagName="div"
							className="thinkrank-faq__question"
							style={questionStyle(attributes)}
							value={faq.question}
							onChange={(v) => updateFaq(index, { question: v })}
							placeholder={__('Question…', 'thinkrank')}
							allowedFormats={['core/bold', 'core/italic']}
						/>
						<RichText
							tagName="div"
							className="thinkrank-faq__answer"
							style={answerStyle(attributes)}
							value={faq.answer}
							onChange={(v) => updateFaq(index, { answer: v })}
							placeholder={__('Answer…', 'thinkrank')}
						/>
						{faq.imageUrl ? (
							<img
								className="thinkrank-faq__image"
								src={faq.imageUrl}
								alt={faq.imageAlt || ''}
							/>
						) : null}
						<div className="thinkrank-faq__item-actions">
							<MediaUploadCheck>
								<MediaUpload
									onSelect={(media) =>
										updateFaq(index, {
											imageId: media.id,
											imageUrl:
												media.sizes?.large?.url ||
												media.url,
											imageAlt: media.alt || '',
										})
									}
									allowedTypes={['image']}
									value={faq.imageId}
									render={({ open }) => (
										<Button
											size="small"
											icon="format-image"
											label={
												faq.imageUrl
													? __(
															'Replace image',
															'thinkrank'
														)
													: __(
															'Add image',
															'thinkrank'
														)
											}
											onClick={open}
										/>
									)}
								/>
							</MediaUploadCheck>
							{faq.imageUrl ? (
								<Button
									size="small"
									icon="no-alt"
									label={__('Remove image', 'thinkrank')}
									onClick={() =>
										updateFaq(index, {
											imageId: 0,
											imageUrl: '',
											imageAlt: '',
										})
									}
								/>
							) : null}
							<Button
								size="small"
								icon="arrow-up-alt2"
								label={__('Move up', 'thinkrank')}
								disabled={index === 0}
								onClick={() => moveFaq(index, -1)}
							/>
							<Button
								size="small"
								icon="arrow-down-alt2"
								label={__('Move down', 'thinkrank')}
								disabled={index === list.length - 1}
								onClick={() => moveFaq(index, 1)}
							/>
							<Button
								size="small"
								icon="admin-page"
								label={__('Duplicate', 'thinkrank')}
								onClick={() => duplicateFaq(index)}
							/>
							<Button
								size="small"
								icon="trash"
								isDestructive
								label={__('Remove', 'thinkrank')}
								disabled={list.length <= 1}
								onClick={() => removeFaq(index)}
							/>
						</div>
					</div>
				))}

				<Button
					variant="secondary"
					icon="plus"
					onClick={addFaq}
					className="thinkrank-faq__add"
				>
					{__('Add question', 'thinkrank')}
				</Button>
			</div>
		</>
	);
}
