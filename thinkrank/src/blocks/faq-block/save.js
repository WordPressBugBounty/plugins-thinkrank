/**
 * FAQ block — static save.
 *
 * Outputs a native <details>/<summary> accordion (collapsible with no
 * JavaScript). The FAQPage JSON-LD is NOT emitted here — it is appended
 * server-side via a render_block filter so it survives KSES on save.
 */

import { useBlockProps, RichText } from '@wordpress/block-editor';
import { itemStyle, questionStyle, answerStyle } from './shared';

export default function save({ attributes }) {
	const { heading, headingTag, faqs, firstOpen } = attributes;
	const blockProps = useBlockProps.save({ className: 'thinkrank-faq' });
	const HeadingTag = headingTag || 'h2';
	const items = (faqs || []).filter(
		(f) => f.question || f.answer || f.imageUrl
	);

	if (items.length === 0) {
		return null;
	}

	return (
		<div {...blockProps}>
			{heading ? (
				<RichText.Content
					tagName={HeadingTag}
					className="thinkrank-faq__heading"
					value={heading}
				/>
			) : null}

			{items.map((faq, index) => (
				<details
					key={index}
					className="thinkrank-faq__item"
					style={itemStyle(attributes)}
					open={firstOpen && index === 0 ? true : undefined}
				>
					<RichText.Content
						tagName="summary"
						className="thinkrank-faq__question"
						style={questionStyle(attributes)}
						value={faq.question}
					/>
					<RichText.Content
						tagName="div"
						className="thinkrank-faq__answer"
						style={answerStyle(attributes)}
						value={faq.answer}
					/>
					{faq.imageUrl ? (
						<img
							className="thinkrank-faq__image"
							src={faq.imageUrl}
							alt={faq.imageAlt || ''}
						/>
					) : null}
				</details>
			))}
		</div>
	);
}
