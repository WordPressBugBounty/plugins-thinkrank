/**
 * Rank Math FAQ / HowTo attribute mapping.
 *
 * The JavaScript half of includes/integrations/class-rank-math-blocks.php.
 * Both sides must agree on which items survive and what they map to, because a
 * block converted in the editor and the same block converted by the migration
 * have to end up identical. RankMathMappingParityTest reads this file and the
 * PHP one and fails when their field names drift apart.
 *
 * Only the attribute mapping lives here. The saved markup is never built by
 * hand on this side: the editor creates the block with createBlock() and
 * serializes it itself.
 */

/**
 * Rank Math block name => the ThinkRank block it converts to.
 */
export const BLOCK_MAP = {
	'rank-math/faq-block': 'thinkrank/faq',
	'rank-math/howto-block': 'thinkrank/howto',
};

/**
 * Read a block's attributes back out of its serialized form.
 *
 * core/missing keeps the whole original block, delimiters included, in its
 * `originalContent` attribute — which is the only place the unregistered
 * block's attributes still exist once Rank Math is gone.
 *
 * @param {string} originalContent Serialized block, including delimiters.
 * @return {Object} Parsed attributes, or {} when there are none to read.
 */
export function parseAttributes(originalContent) {
	if (typeof originalContent !== 'string') {
		return {};
	}

	const match = originalContent.match(
		/^\s*<!--\s+wp:[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*\s+(\{[\s\S]*?\})\s+\/?-->/
	);

	if (!match) {
		return {};
	}

	try {
		const parsed = JSON.parse(match[1]);
		return parsed && typeof parsed === 'object' ? parsed : {};
	} catch (e) {
		return {};
	}
}

/**
 * Whether an item was on the page.
 *
 * Rank Math's save output skips an item only when `visible` is explicitly
 * false, so an item with no `visible` key counts as visible. Anything it hid
 * was never rendered and must not reappear in the converted block.
 *
 * @param {Object} item Question or step.
 * @return {boolean} Whether the item was rendered.
 */
function isVisible(item) {
	return !(
		item &&
		Object.prototype.hasOwnProperty.call(item, 'visible') &&
		item.visible === false
	);
}

/**
 * Image attributes for a Rank Math imageID.
 *
 * @param {number} imageId  Attachment id.
 * @param {Object} resolved Attachment id => { url, alt }, as far as it is known.
 * @return {Object} ThinkRank image attributes.
 */
function imageAttributes(imageId, resolved) {
	const id = parseInt(imageId, 10);

	if (!id || id < 1 || !resolved[id]) {
		return { imageId: 0, imageUrl: '', imageAlt: '' };
	}

	return {
		imageId: id,
		imageUrl: resolved[id].url || '',
		imageAlt: resolved[id].alt || '',
	};
}

/**
 * Map Rank Math FAQ attributes onto thinkrank/faq attributes.
 *
 * @param {Object} attrs    Rank Math block attributes.
 * @param {Object} resolved Attachment lookup.
 * @return {Object|null} ThinkRank attributes, or null when nothing survives.
 */
export function faqAttributes(attrs, resolved = {}) {
	const questions = Array.isArray(attrs?.questions) ? attrs.questions : [];

	const faqs = questions
		.filter(
			(q) =>
				q &&
				isVisible(q) &&
				typeof q.title === 'string' &&
				q.title !== '' &&
				typeof q.content === 'string' &&
				q.content !== ''
		)
		.map((q) => ({
			question: q.title,
			answer: q.content,
			...imageAttributes(q.imageID, resolved),
		}));

	return faqs.length ? { faqs } : null;
}

/**
 * One HowTo duration field as a non-negative whole number.
 *
 * Mirrors Rank_Math_Blocks::duration_part() in PHP. A negative value becomes
 * 0 on both sides: PHP used absint(), which turned "-2" into 2, while this
 * side kept -2, so the same block converted differently depending on whether
 * the editor or the migration reached it first.
 *
 * @param {*} value Rank Math duration string (or number).
 * @return {number} Whole number, 0 or more.
 */
export function durationPart(value) {
	return Math.max(0, parseInt(value, 10) || 0);
}

/**
 * Map Rank Math HowTo attributes onto thinkrank/howto attributes.
 *
 * Looser than the FAQ: Rank Math renders a step that has only a title or only
 * a body.
 *
 * @param {Object} attrs    Rank Math block attributes.
 * @param {Object} resolved Attachment lookup.
 * @return {Object|null} ThinkRank attributes, or null when nothing survives.
 */
export function howtoAttributes(attrs, resolved = {}) {
	const sourceSteps = Array.isArray(attrs?.steps) ? attrs.steps : [];

	const steps = sourceSteps
		.filter((s) => s && isVisible(s))
		.map((s) => ({
			title: typeof s.title === 'string' ? s.title : '',
			text: typeof s.content === 'string' ? s.content : '',
			...imageAttributes(s.imageID, resolved),
		}))
		.filter((s) => s.title !== '' || s.text !== '' || s.imageId !== 0);

	if (!steps.length) {
		return null;
	}

	const mapped = {};

	if (typeof attrs.description === 'string' && attrs.description !== '') {
		mapped.description = attrs.description;
	}

	mapped.steps = steps;

	// Rank Math keeps the duration as three strings behind a hasDuration flag;
	// ThinkRank keeps three numbers and treats non-zero as "has a duration".
	if (attrs.hasDuration) {
		mapped.totalDays = durationPart(attrs.days);
		mapped.totalHours = durationPart(attrs.hours);
		mapped.totalMinutes = durationPart(attrs.minutes);
	}

	return mapped;
}

/**
 * Map a Rank Math block onto the ThinkRank block that replaces it.
 *
 * @param {string} blockName Rank Math block name.
 * @param {Object} attrs     Rank Math attributes.
 * @param {Object} resolved  Attachment lookup.
 * @return {{name: string, attributes: Object}|null} Null when not convertible.
 */
export function mapBlock(blockName, attrs, resolved = {}) {
	const target = BLOCK_MAP[blockName];

	if (!target) {
		return null;
	}

	const attributes =
		target === 'thinkrank/faq'
			? faqAttributes(attrs, resolved)
			: howtoAttributes(attrs, resolved);

	return attributes ? { name: target, attributes } : null;
}

/**
 * Every attachment id a block refers to, so they can be fetched in one go
 * before the block is created.
 *
 * @param {string} blockName Rank Math block name.
 * @param {Object} attrs     Rank Math attributes.
 * @return {number[]} Attachment ids.
 */
export function imageIds(blockName, attrs) {
	const items =
		blockName === 'rank-math/faq-block' ? attrs?.questions : attrs?.steps;

	const ids = (Array.isArray(items) ? items : [])
		.filter((item) => item && isVisible(item))
		.map((item) => parseInt(item.imageID, 10))
		.filter((id) => id > 0);

	// The HowTo block's own lead image becomes a separate core/image block.
	const main = parseInt(attrs?.imageID, 10);
	if (blockName === 'rank-math/howto-block' && main > 0) {
		ids.push(main);
	}

	return [...new Set(ids)];
}

/**
 * The HowTo block's lead image, which ThinkRank's HowTo block has no field for
 * and which therefore becomes a core/image block above the steps.
 *
 * @param {string} blockName Rank Math block name.
 * @param {Object} attrs     Rank Math attributes.
 * @param {Object} resolved  Attachment lookup.
 * @return {Object|null} core/image attributes, or null when there is none.
 */
export function mainImageAttributes(blockName, attrs, resolved = {}) {
	if (blockName !== 'rank-math/howto-block') {
		return null;
	}

	const id = parseInt(attrs?.imageID, 10);

	if (!id || id < 1 || !resolved[id]) {
		return null;
	}

	return {
		id,
		url: resolved[id].url || '',
		alt: resolved[id].alt || '',
		sizeSlug: 'full',
		linkDestination: 'none',
	};
}
