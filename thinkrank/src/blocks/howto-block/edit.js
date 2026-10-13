/**
 * HowTo block — editor.
 *
 * Inline step editing (add / reorder / duplicate / remove, optional image per
 * step) with a lean General/Style inspector split. The preview mirrors the
 * saved output via the shared style helpers.
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
import {
	HEADING_TAGS,
	stepStyle,
	stepTitleStyle,
	stepTextStyle,
	formatTotalTime,
} from './shared';

export default function Edit({ attributes, setAttributes }) {
	const {
		heading,
		headingTag,
		description,
		steps,
		totalDays,
		totalHours,
		totalMinutes,
		showNumbers,
		outputSchema,
		stepTitleColor,
		stepTextColor,
		stepTitleFontSize,
		stepSpacing,
		stepBackground,
		stepBorderColor,
		stepBorderRadius,
	} = attributes;

	const blockProps = useBlockProps({ className: 'thinkrank-howto' });
	const HeadingTag = headingTag || 'h2';
	const list = Array.isArray(steps) ? steps : [];
	const totalTime = formatTotalTime(attributes);

	const updateStep = (index, patch) => {
		setAttributes({
			steps: list.map((s, i) => (i === index ? { ...s, ...patch } : s)),
		});
	};
	const addStep = () =>
		setAttributes({
			steps: [
				...list,
				{ title: '', text: '', imageId: 0, imageUrl: '', imageAlt: '' },
			],
		});
	const removeStep = (index) =>
		setAttributes({ steps: list.filter((_, i) => i !== index) });
	const duplicateStep = (index) => {
		const next = [...list];
		next.splice(index + 1, 0, { ...list[index] });
		setAttributes({ steps: next });
	};
	const moveStep = (index, dir) => {
		const target = index + dir;
		if (target < 0 || target >= list.length) {
			return;
		}
		const next = [...list];
		[next[index], next[target]] = [next[target], next[index]];
		setAttributes({ steps: next });
	};

	return (
		<>
			<InspectorControls>
				<TabPanel
					className="thinkrank-howto-tabs"
					activeClass="is-active"
					tabs={[
						{ name: 'general', title: __('General', 'thinkrank') },
						{ name: 'style', title: __('Style', 'thinkrank') },
					]}
				>
					{(tab) =>
						tab.name === 'general' ? (
							<>
								<PanelBody
									title={__('Content', 'thinkrank')}
									initialOpen={true}
								>
									<TextControl
										label={__(
											'Section heading',
											'thinkrank'
										)}
										value={heading}
										onChange={(v) =>
											setAttributes({ heading: v })
										}
										placeholder={__(
											'How to …',
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
											'Numbered steps',
											'thinkrank'
										)}
										checked={!!showNumbers}
										onChange={(v) =>
											setAttributes({ showNumbers: v })
										}
									/>
									<ToggleControl
										label={__(
											'Output HowTo schema (JSON-LD)',
											'thinkrank'
										)}
										help={__(
											'Adds HowTo structured data for rich results.',
											'thinkrank'
										)}
										checked={!!outputSchema}
										onChange={(v) =>
											setAttributes({ outputSchema: v })
										}
									/>
								</PanelBody>
								<PanelBody
									title={__('Total time', 'thinkrank')}
									initialOpen={false}
								>
									<RangeControl
										label={__('Days', 'thinkrank')}
										value={totalDays}
										onChange={(v) =>
											setAttributes({ totalDays: v })
										}
										min={0}
										max={30}
									/>
									<RangeControl
										label={__('Hours', 'thinkrank')}
										value={totalHours}
										onChange={(v) =>
											setAttributes({ totalHours: v })
										}
										min={0}
										max={23}
									/>
									<RangeControl
										label={__('Minutes', 'thinkrank')}
										value={totalMinutes}
										onChange={(v) =>
											setAttributes({ totalMinutes: v })
										}
										min={0}
										max={59}
									/>
								</PanelBody>
							</>
						) : (
							<>
								<PanelColorSettings
									title={__('Colors', 'thinkrank')}
									initialOpen={true}
									colorSettings={[
										{
											value: stepTitleColor,
											onChange: (v) =>
												setAttributes({
													stepTitleColor: v,
												}),
											label: __(
												'Step title',
												'thinkrank'
											),
										},
										{
											value: stepTextColor,
											onChange: (v) =>
												setAttributes({
													stepTextColor: v,
												}),
											label: __('Step text', 'thinkrank'),
										},
										{
											value: stepBackground,
											onChange: (v) =>
												setAttributes({
													stepBackground: v,
												}),
											label: __(
												'Step background',
												'thinkrank'
											),
										},
										{
											value: stepBorderColor,
											onChange: (v) =>
												setAttributes({
													stepBorderColor: v,
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
											'Step title size (px)',
											'thinkrank'
										)}
										value={stepTitleFontSize}
										onChange={(v) =>
											setAttributes({
												stepTitleFontSize: v,
											})
										}
										min={12}
										max={30}
									/>
									<RangeControl
										label={__(
											'Space between steps (px)',
											'thinkrank'
										)}
										value={stepSpacing}
										onChange={(v) =>
											setAttributes({ stepSpacing: v })
										}
										min={0}
										max={40}
									/>
									<RangeControl
										label={__(
											'Corner radius (px)',
											'thinkrank'
										)}
										value={stepBorderRadius}
										onChange={(v) =>
											setAttributes({
												stepBorderRadius: v,
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
					className="thinkrank-howto__heading"
					value={heading}
					onChange={(v) => setAttributes({ heading: v })}
					placeholder={__('How to …', 'thinkrank')}
					allowedFormats={[]}
				/>

				<RichText
					tagName="p"
					className="thinkrank-howto__description"
					value={description}
					onChange={(v) => setAttributes({ description: v })}
					placeholder={__(
						'Optional intro describing this guide…',
						'thinkrank'
					)}
				/>

				{totalTime ? (
					<p className="thinkrank-howto__duration">
						<strong>{__('Total time:', 'thinkrank')}</strong>{' '}
						{totalTime}
					</p>
				) : null}

				<div className="thinkrank-howto__steps">
					{list.map((step, index) => (
						<div
							key={index}
							className="thinkrank-howto__step thinkrank-howto__step--editing"
							style={stepStyle(attributes)}
						>
							{showNumbers ? (
								<span className="thinkrank-howto__step-number">
									{index + 1}
								</span>
							) : null}
							<div className="thinkrank-howto__step-body">
								<RichText
									tagName="div"
									className="thinkrank-howto__step-title"
									style={stepTitleStyle(attributes)}
									value={step.title}
									onChange={(v) =>
										updateStep(index, { title: v })
									}
									placeholder={__('Step title…', 'thinkrank')}
									allowedFormats={[
										'core/bold',
										'core/italic',
									]}
								/>
								{step.imageUrl ? (
									<img
										className="thinkrank-howto__step-image"
										src={step.imageUrl}
										alt={step.imageAlt || ''}
									/>
								) : null}
								<RichText
									tagName="div"
									className="thinkrank-howto__step-text"
									style={stepTextStyle(attributes)}
									value={step.text}
									onChange={(v) =>
										updateStep(index, { text: v })
									}
									placeholder={__(
										'Describe this step…',
										'thinkrank'
									)}
								/>
								<div className="thinkrank-howto__step-actions">
									<MediaUploadCheck>
										<MediaUpload
											onSelect={(media) =>
												updateStep(index, {
													imageId: media.id,
													imageUrl:
														media.sizes?.large
															?.url || media.url,
													imageAlt: media.alt || '',
												})
											}
											allowedTypes={['image']}
											value={step.imageId}
											render={({ open }) => (
												<Button
													size="small"
													icon="format-image"
													label={
														step.imageUrl
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
									{step.imageUrl ? (
										<Button
											size="small"
											icon="no-alt"
											label={__(
												'Remove image',
												'thinkrank'
											)}
											onClick={() =>
												updateStep(index, {
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
										onClick={() => moveStep(index, -1)}
									/>
									<Button
										size="small"
										icon="arrow-down-alt2"
										label={__('Move down', 'thinkrank')}
										disabled={index === list.length - 1}
										onClick={() => moveStep(index, 1)}
									/>
									<Button
										size="small"
										icon="admin-page"
										label={__('Duplicate', 'thinkrank')}
										onClick={() => duplicateStep(index)}
									/>
									<Button
										size="small"
										icon="trash"
										isDestructive
										label={__('Remove', 'thinkrank')}
										disabled={list.length <= 1}
										onClick={() => removeStep(index)}
									/>
								</div>
							</div>
						</div>
					))}
				</div>

				<Button
					variant="secondary"
					icon="plus"
					onClick={addStep}
					className="thinkrank-howto__add"
				>
					{__('Add step', 'thinkrank')}
				</Button>
			</div>
		</>
	);
}
