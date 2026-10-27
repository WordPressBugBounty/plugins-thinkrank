/**
 * Template preview against real items.
 *
 * The Search Engine Preview above it fills the template with sample text,
 * which cannot show what %price% or %customfield(key)% give each item. This
 * asks the server to resolve the unsaved templates for the post type's newest
 * published items, so the admin sees every item's own values before saving
 * (#1277).
 *
 * @package
 * @since 2.19.0
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import { Container, Label, Text, SkeletonText } from '../ui';

const PATH = '/thinkrank/v1/global-seo/preview';

/**
 * Wait after the last keystroke before asking the server again.
 *
 * @type {number}
 */
export const PREVIEW_DELAY = 400;

/**
 * Why the templates do not change an item, or '' when they do.
 *
 * @param {Object} item A preview item.
 * @return {string} The note to show.
 */
const ownValueNote = (item) => {
	if (item.own_title && item.own_description) {
		return __(
			'This item has its own title and description, so the templates do not change it.',
			'thinkrank'
		);
	}
	if (item.own_title) {
		return __(
			'This item has its own title, so the title template does not change it.',
			'thinkrank'
		);
	}
	if (item.own_description) {
		return __(
			'This item has its own description, so the description template does not change it.',
			'thinkrank'
		);
	}
	return '';
};

/**
 * @param {Object} props
 * @param {string} props.postType    Post type slug.
 * @param {string} props.title       Title template as typed.
 * @param {string} props.description Description template as typed.
 */
const TemplatePreview = ({ postType, title, description }) => {
	// The newest settled answer, with the inputs it answered. Whether the
	// rows on screen belong to the template as typed is worked out while
	// rendering, from those inputs, rather than set by an effect: an effect
	// runs after the paint, so the first frame after an edit showed the old
	// rows as current (#1277 review).
	const [settled, setSettled] = useState(null);
	// Only the newest request may set data or errors: an older one can land
	// after it while the admin is still typing.
	const latest = useRef(0);

	const inputs = JSON.stringify([postType, title, description]);

	useEffect(() => {
		const request = ++latest.current;

		const timer = setTimeout(async () => {
			try {
				const response = await apiFetch({
					path: addQueryArgs(PATH, {
						post_type: postType,
						title,
						description,
					}),
				});

				if (request === latest.current) {
					setSettled({
						inputs,
						postType,
						items: Array.isArray(response?.items)
							? response.items
							: [],
						error: '',
					});
				}
			} catch (e) {
				if (request === latest.current) {
					setSettled({
						inputs,
						postType,
						items: null,
						error:
							e?.message ||
							__('The preview could not be loaded.', 'thinkrank'),
					});
				}
			}
		}, PREVIEW_DELAY);

		return () => clearTimeout(timer);
		// `inputs` is the three props.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [inputs]);

	// True from the moment a template or the post type changes until the
	// newest request settles.
	const pending = !settled || settled.inputs !== inputs;
	// Rows are shown only for the templates as typed: while an edit or a
	// post-type switch is loading, the previous rows are hidden behind the
	// skeleton rather than shown dimmed, so no value the edited template does
	// not produce is on screen (#1277 review).
	const items =
		!pending && settled && Array.isArray(settled.items)
			? settled.items
			: null;
	const error = settled && !pending ? settled.error : '';

	return (
		<Container direction="column" gap={2.5}>
			<Label>{__('Preview with your content', 'thinkrank')}</Label>
			<Text as="div" size={12} color="help">
				{__(
					'How the templates read on your newest items, with each item’s own values. Nothing is saved.',
					'thinkrank'
				)}
			</Text>

			{error && (
				<Text as="div" size={12} color="error" role="alert">
					{error}
				</Text>
			)}

			{pending && (
				<div aria-busy="true" data-testid="template-preview-loading">
					{settled && (
						<Text as="div" size={12} color="help" role="status">
							{__('Updating the preview…', 'thinkrank')}
						</Text>
					)}
					<SkeletonText lines={3} />
				</div>
			)}

			{!error && Array.isArray(items) && 0 === items.length && (
				<Text as="div" size={12} color="help">
					{__(
						'Nothing is published here yet, so there is nothing to preview.',
						'thinkrank'
					)}
				</Text>
			)}

			{!error && Array.isArray(items) && items.length > 0 && (
				<ul
					className="tr-flex tr-flex-col tr-gap-3 tr-m-0 tr-p-0 tr-list-none"
					data-testid="template-preview-list"
				>
					{items.map((item) => (
						<li
							key={item.id}
							className="tr-flex tr-flex-col tr-gap-1"
							data-testid="template-preview-item"
						>
							<Text as="div" size={12} color="help">
								{item.name}
							</Text>
							<Text as="div" weight={500}>
								{item.title}
							</Text>
							<Text as="div" size={14}>
								{item.description}
							</Text>
							{ownValueNote(item) && (
								<Text as="div" size={12} color="help">
									{ownValueNote(item)}
								</Text>
							)}
						</li>
					))}
				</ul>
			)}
		</Container>
	);
};

export default TemplatePreview;
