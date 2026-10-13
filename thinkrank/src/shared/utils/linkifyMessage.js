/**
 * Render server-provided messages that may contain a simple anchor tag
 * (e.g. the AI "not set up yet" error linking to ThinkRank → Settings)
 * as React nodes with a real, clickable link — without using
 * dangerouslySetInnerHTML.
 *
 * Only `<a href="...">text</a>` is converted; every other character is
 * rendered as plain text (React escapes it), so arbitrary HTML in a
 * message can never be injected. Unsafe hrefs (anything that is not
 * http(s) or a relative path) are rendered as plain text too.
 */

const ANCHOR_REGEX = /<a\s+href="([^"]*)"[^>]*>([^<]*)<\/a>/gi;

const decodeEntities = (value) =>
	value
		.replace(/&#0?38;/g, '&')
		.replace(/&amp;/g, '&')
		.replace(/&#0?39;/g, "'");

const isSafeHref = (href) => /^https?:\/\//i.test(href) || href.startsWith('/');

/**
 * Convert a message string into React children, turning embedded anchor
 * tags into links that open in a new tab.
 *
 * @param {*} message Message to render; non-strings and strings without
 *                    anchors are returned unchanged.
 * @return {*} The original value, or an array of strings and <a> elements.
 */
export default function linkifyMessage(message) {
	if (typeof message !== 'string' || !message.includes('<a ')) {
		return message;
	}

	const parts = [];
	let lastIndex = 0;
	let match;

	ANCHOR_REGEX.lastIndex = 0;
	while ((match = ANCHOR_REGEX.exec(message)) !== null) {
		const [fullMatch, rawHref, text] = match;
		const href = decodeEntities(rawHref);

		if (match.index > lastIndex) {
			parts.push(message.slice(lastIndex, match.index));
		}

		if (isSafeHref(href)) {
			parts.push(
				<a
					key={`link-${match.index}`}
					href={href}
					target="_blank"
					rel="noopener noreferrer"
				>
					{text}
				</a>
			);
		} else {
			parts.push(text);
		}

		lastIndex = match.index + fullMatch.length;
	}

	if (lastIndex < message.length) {
		parts.push(message.slice(lastIndex));
	}

	return parts;
}
