/**
 * HowTo block — static save.
 *
 * Outputs the heading, optional description + total time, and an ordered step
 * list with optional step images. The HowTo JSON-LD is NOT emitted here — it
 * is appended server-side via a render_block filter so it survives KSES on
 * save.
 */

import { useBlockProps, RichText } from '@wordpress/block-editor';
import {
	stepStyle,
	stepTitleStyle,
	stepTextStyle,
	formatTotalTime,
} from './shared';

export default function save({ attributes }) {
	const { heading, headingTag, description, steps, showNumbers } = attributes;
	const items = (steps || []).filter((s) => s.title || s.text || s.imageUrl);

	if (items.length === 0) {
		return null;
	}

	const blockProps = useBlockProps.save({ className: 'thinkrank-howto' });
	const HeadingTag = headingTag || 'h2';
	const totalTime = formatTotalTime(attributes);

	const ListTag = showNumbers ? 'ol' : 'ul';

	return (
		<div {...blockProps}>
			{heading ? (
				<RichText.Content
					tagName={HeadingTag}
					className="thinkrank-howto__heading"
					value={heading}
				/>
			) : null}

			{description ? (
				<RichText.Content
					tagName="p"
					className="thinkrank-howto__description"
					value={description}
				/>
			) : null}

			{totalTime ? (
				<p className="thinkrank-howto__duration">
					<strong>Total time:</strong> {totalTime}
				</p>
			) : null}

			<ListTag className="thinkrank-howto__steps">
				{items.map((step, index) => (
					<li
						key={index}
						className="thinkrank-howto__step"
						style={stepStyle(attributes)}
					>
						<RichText.Content
							tagName="div"
							className="thinkrank-howto__step-title"
							style={stepTitleStyle(attributes)}
							value={step.title}
						/>
						{step.imageUrl ? (
							<img
								className="thinkrank-howto__step-image"
								src={step.imageUrl}
								alt={step.imageAlt || ''}
							/>
						) : null}
						<RichText.Content
							tagName="div"
							className="thinkrank-howto__step-text"
							style={stepTextStyle(attributes)}
							value={step.text}
						/>
					</li>
				))}
			</ListTag>
		</div>
	);
}
