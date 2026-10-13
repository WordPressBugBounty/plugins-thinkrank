/**
 * TOC block — static save.
 *
 * Renders the heading list captured by edit() as a linked list. Indentation
 * follows heading level via per-level classes. The SiteNavigationElement
 * JSON-LD is NOT emitted here — it is appended server-side via a render_block
 * filter so it survives KSES on save.
 */

import { useBlockProps, RichText } from '@wordpress/block-editor';
import { listItemStyle } from './shared';

export default function save({ attributes }) {
	const { heading, headingTag, headings, listStyle } = attributes;
	const list = (headings || []).filter((h) => h.content && h.anchor);

	if (list.length === 0) {
		return null;
	}

	const blockProps = useBlockProps.save({ className: 'thinkrank-toc' });
	const HeadingTag = headingTag || 'h2';

	return (
		<div {...blockProps}>
			{heading ? (
				<RichText.Content
					tagName={HeadingTag}
					className="thinkrank-toc__heading"
					value={heading}
				/>
			) : null}

			<nav aria-label="Table of contents">
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
			</nav>
		</div>
	);
}
