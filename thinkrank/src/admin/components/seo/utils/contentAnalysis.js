/**
 * Content Analysis Utilities
 *
 * Utility functions for SEO content analysis, migrated from jQuery implementation
 * to maintain consistency with original functionality
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Count whitespace-separated words, as the server's
 * Word_Count_Index::count_words() does:
 *
 * - Shortcode syntax is not reading matter. `[vc_column width="1/2"]` is not
 *   three words, and a WPBakery or Divi classic page is mostly made of it.
 *   Anything shortcode-shaped is removed, including the escaped `[[name]]`.
 * - A word holds at least one letter or digit, in any script, so a dash or a
 *   full stop standing on its own is not one.
 *
 * The readability formulas read this, as on the server.
 *
 * @since 2.18.0
 *
 * @param {string} text - Text content to analyze
 * @return {number} Word count
 */
export const countWords = (text) =>
	toReadingText(text)
		.split(/\s+/)
		.filter((word) => /[\p{L}\p{N}]/u.test(word)).length;

// Scripts written without spaces between words, and how many of their
// characters make one English-equivalent word. Mirrors
// Word_Count_Index::count_word_equivalents() (#1291).
const CJK_CHARACTER =
	/[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\u30FC]/gu;
const SEA_CHARACTER =
	/[\p{Script=Thai}\p{Script=Lao}\p{Script=Khmer}\p{Script=Myanmar}]/gu;
const UNSPACED_RUN =
	/(?:[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\u30FC]|[\p{Script=Thai}\p{Script=Lao}\p{Script=Khmer}\p{Script=Myanmar}])+/gu;
const CJK_CHARACTERS_PER_WORD = 2;
const SEA_CHARACTERS_PER_WORD = 5;

/**
 * Calculate real word count from text
 *
 * The length the SEO score reads, as the server's
 * calculate_word_count_js_style() measures it
 * (Word_Count_Index::reading_text() and count_word_equivalents()), so the
 * Word Count tile does not jump when a fresh score replaces the live figure.
 * Words are counted as countWords() does; a run of Chinese, Japanese, Thai
 * or another script written without spaces is converted from characters to
 * English-equivalent words, so a Japanese article is not a handful of words.
 *
 * @param {string} text - Text content to analyze
 * @return {number} Word count
 */
export const calculateWordCount = (text) => {
	const readingText = toReadingText(text);
	const cjk = (readingText.match(CJK_CHARACTER) || []).length;
	const sea = (readingText.match(SEA_CHARACTER) || []).length;

	if (cjk === 0 && sea === 0) {
		return countWords(readingText);
	}

	return (
		countWords(readingText.replace(UNSPACED_RUN, ' ')) +
		Math.round(
			cjk / CJK_CHARACTERS_PER_WORD + sea / SEA_CHARACTERS_PER_WORD
		)
	);
};

/**
 * The text a reader reads, as every count in this file sees it.
 *
 * Shortcode syntax is removed (the same pattern as the server's
 * Word_Count_Index::reading_text()) and whitespace collapsed. The word count,
 * sentence count, syllable count and keyword occurrences all read this one
 * string, so shortcode syntax is never on one side of a ratio and not the
 * other. Before, the word count dropped `[vc_column width="1/2"]` while the
 * syllable count still read it, and a WPBakery page's Flesch fell to -58.
 *
 * @since 2.14.2
 *
 * @param {string} text Text content.
 * @return {string} Reading text.
 */
export const toReadingText = (text) => {
	if (!text || typeof text !== 'string') return '';

	return text
		.replace(/\[?\[\/?[A-Za-z][\w-]*[^[\]]*\]\]?/g, ' ')
		.replace(/\s+/g, ' ')
		.trim();
};

/**
 * Count sentences: runs of text between terminal punctuation that hold at
 * least one letter or digit. Mirrors the server's count_sentences().
 *
 * @since 2.14.2
 *
 * @param {string} text Text content.
 * @return {number} Sentence count.
 */
export const countSentences = (text) =>
	toReadingText(text)
		.split(/[.!?]+/)
		.filter((fragment) => /[\p{L}\p{N}]/u.test(fragment)).length;

/**
 * Calculate keyword density using industry standard methodology
 *
 * @param {string} content - Content to analyze
 * @param {string} keyword - Target keyword
 * @return {number} Keyword density percentage (rounded to 1 decimal)
 */
export const calculateKeywordDensity = (content, keyword) => {
	if (!content || !keyword) return 0;

	// Occurrences and the word count read the same reading text, so a
	// keyword inside a shortcode attribute is not counted against a word
	// count that leaves the shortcode out.
	const readingText = toReadingText(content);

	// Count total words
	const totalWords = calculateWordCount(readingText);
	if (totalWords === 0) return 0;

	// Count keyword occurrences (including partial matches). A plain substring
	// count, like the server's substr_count(): a keyword is text, not a
	// pattern, and "c++" built into a RegExp threw.
	const keywordOccurrences =
		readingText.toLowerCase().split(keyword.toLowerCase()).length - 1;

	// Calculate density as percentage
	const density = (keywordOccurrences / totalWords) * 100;
	return Math.round(density * 10) / 10; // Round to 1 decimal place
};

/**
 * The language of a locale: "fr" for "fr_FR", "fr-CA" or "fr".
 *
 * @param {string} locale - A WordPress locale.
 * @return {string} Lowercase language code; "en" when nothing is known.
 */
export const languageOf = (locale) =>
	String(locale || '')
		.replace('-', '_')
		.split('_')[0]
		.toLowerCase() || 'en';

/**
 * The locale of the content being scored: the post's own (Polylang/WPML, else
 * the site's) as the metabox was given it, the site's in the admin app, else
 * the admin page's own language.
 *
 * @return {string} A WordPress locale, or '' when none is known.
 */
export const detectLocale = () => {
	if (typeof window !== 'undefined') {
		const given =
			window.thinkrankMetabox?.locale || window.thinkrankAdmin?.locale;
		if (given) return String(given);
	}
	if (typeof document !== 'undefined') {
		return document.documentElement?.lang || '';
	}
	return '';
};

const VOWELS = {
	en: 'aeiouy',
	fr: 'aeiouyàâäéèêëîïôöùûüÿœæ',
};

/**
 * Estimate syllable count for readability calculations, by the rules of the
 * text's language.
 *
 * @param {string} text   - Text to analyze
 * @param {string} locale - The text's locale; its language picks the rules
 * @return {number} Estimated syllable count
 */
export const estimateSyllables = (text, locale = detectLocale()) => {
	// Same rules as the server's Text_Language::count_syllables(), so the
	// editor and the stored score agree. English: whitespace-separated tokens
	// of the reading text, letters a-z only, a trailing silent e dropped after
	// a consonant but never for consonant-le ("table"). French: letters of any
	// accent, accented vowels count, and a final e or es after a consonant is
	// mute ("porte", "portes", "école").
	const french = languageOf(locale) === 'fr';
	const vowels = french ? VOWELS.fr : VOWELS.en;
	const vowelGroups = new RegExp(`[${vowels}]+`, 'gu');
	const silentEnding = french
		? new RegExp(`[^${vowels}]es?$`, 'u')
		: /[^aeiouy]e$/;
	let syllableCount = 0;

	toReadingText(text)
		.toLowerCase()
		.split(' ')
		.forEach((token) => {
			const word = french
				? token.replace(/[^\p{L}]/gu, '')
				: token.replace(/[^a-z]/g, '');
			if (!word) return;

			let syllables = (word.match(vowelGroups) || []).length;

			if (
				syllables > 1 &&
				silentEnding.test(word) &&
				(french || !word.endsWith('le'))
			) {
				syllables--;
			}

			syllableCount += Math.max(1, syllables);
		});

	return syllableCount;
};

/**
 * Calculate readability score on the 0-100 reading-ease scale of the text's
 * language: Flesch Reading Ease for English (and any language without a
 * formula of its own), Kandel-Moles for French, as on the server.
 *
 * @param {string} text   - Text content to analyze
 * @param {string} locale - The text's locale; its language picks the formula
 * @return {string} Readability assessment with score
 */
export const calculateReadabilityScore = (text, locale = detectLocale()) => {
	if (!text) return __('No content', 'thinkrank');

	// Words, sentences and syllables from one reading text, as on the server.
	const readingText = toReadingText(text);
	const words = countWords(readingText);
	const sentences = countSentences(readingText);
	const syllables = estimateSyllables(readingText, locale);

	if (sentences === 0 || words === 0) return __('Too short', 'thinkrank');

	const avgWordsPerSentence = words / sentences;
	const avgSyllablesPerWord = syllables / words;

	const fleschScore =
		languageOf(locale) === 'fr'
			? 207 - 1.015 * avgWordsPerSentence - 73.6 * avgSyllablesPerWord
			: 206.835 -
				1.015 * avgWordsPerSentence -
				84.6 * avgSyllablesPerWord;
	// Flesch Reading Ease is unbounded below; the scale (and the server,
	// which clamps the same way) runs 0 to 100.
	const roundedScore = Math.round(Math.max(0, Math.min(100, fleschScore)));

	// Industry standard Flesch Reading Ease scale
	let level = '';
	if (roundedScore >= 90) level = __('Very Easy', 'thinkrank');
	else if (roundedScore >= 80) level = __('Easy', 'thinkrank');
	else if (roundedScore >= 70) level = __('Fairly Easy', 'thinkrank');
	else if (roundedScore >= 60) level = __('Standard', 'thinkrank');
	else if (roundedScore >= 50) level = __('Fairly Difficult', 'thinkrank');
	else if (roundedScore >= 30) level = __('Difficult', 'thinkrank');
	else level = __('Very Difficult', 'thinkrank');

	// Return professional format
	return `${level} (${roundedScore})`;
};

/**
 * Analyze heading structure from HTML content
 *
 * The post title is rendered as the page's H1 by (virtually) every theme, while
 * the editor body normally contains no H1. So a non-empty title is counted as
 * the page H1 here — otherwise a normally-structured titled post is wrongly
 * reported as "No H1", and a body H1 (on top of the title) is correctly surfaced
 * as a duplicate.
 *
 * @param {string} htmlContent - HTML content to analyze
 * @param {string} pageTitle   - Post title, counted as the page H1 when non-empty
 * @return {Object} Heading analysis with count and structure description
 */
export const analyzeHeadingStructure = (htmlContent, pageTitle = '') => {
	const hasTitleH1 = typeof pageTitle === 'string' && pageTitle.trim() !== '';

	if (!htmlContent) {
		return hasTitleH1
			? { count: 1, structure: '1 heading (Good H1)' }
			: { count: 0, structure: 'No content' };
	}

	// Create a temporary div to parse HTML
	const tempDiv = document.createElement('div');
	tempDiv.innerHTML = htmlContent;

	// Count different heading levels. The title, when present, is the page H1.
	const h1Count =
		tempDiv.querySelectorAll('h1').length + (hasTitleH1 ? 1 : 0);
	const h2Count = tempDiv.querySelectorAll('h2').length;
	const h3Count = tempDiv.querySelectorAll('h3').length;
	const h4Count = tempDiv.querySelectorAll('h4').length;
	const h5Count = tempDiv.querySelectorAll('h5').length;
	const h6Count = tempDiv.querySelectorAll('h6').length;

	const totalHeadings =
		h1Count + h2Count + h3Count + h4Count + h5Count + h6Count;

	let structure = '';
	if (totalHeadings === 0) {
		structure = 'No headings';
	} else if (h1Count === 0) {
		structure = `${totalHeadings} headings (No H1)`;
	} else if (h1Count > 1) {
		structure = `${totalHeadings} headings (${h1Count} H1s)`;
	} else {
		structure = `${totalHeadings} headings (Good H1)`;
	}

	return { count: totalHeadings, structure };
};

/**
 * Calculate content quality using industry standard SEO methodology
 * Based on established content analysis best practices
 *
 * @param {string} plainText   - Plain text content
 * @param {string} htmlContent - HTML content for structure analysis
 * @return {string} Content quality assessment
 */
export const calculateContentQuality = (plainText, htmlContent) => {
	if (!plainText) return 'No content';

	const wordCount = calculateWordCount(plainText);
	const issues = [];
	let score = 0;

	// 1. Word count analysis (Industry standard: 300+ words)
	if (wordCount < 300) {
		issues.push('Too short');
		score += 0;
	} else if (wordCount >= 300 && wordCount < 600) {
		score += 50;
	} else {
		score += 100; // 600+ words is excellent
	}

	// 2. Paragraph length analysis (SEO best practice: avoid long paragraphs)
	const tempDiv = document.createElement('div');
	tempDiv.innerHTML = htmlContent;
	const paragraphs = tempDiv.querySelectorAll('p');
	let longParagraphs = 0;

	paragraphs.forEach((p) => {
		const pText = p.textContent || p.innerText || '';
		const pWordCount = calculateWordCount(pText);
		if (pWordCount > 150) {
			// Industry standard: flag paragraphs over 150 words
			longParagraphs++;
		}
	});

	if (longParagraphs === 0) {
		score += 100;
	} else if (longParagraphs <= 2) {
		score += 50;
		issues.push('Some long paragraphs');
	} else {
		score += 0;
		issues.push('Too many long paragraphs');
	}

	// 3. Subheading distribution (SEO best practice: subheadings every 300 words)
	const headings = tempDiv.querySelectorAll('h2, h3, h4, h5, h6').length;
	const expectedHeadings = Math.floor(wordCount / 300);

	if (headings >= expectedHeadings && headings > 0) {
		score += 100;
	} else if (headings > 0) {
		score += 50;
		issues.push('Could use more subheadings');
	} else {
		score += 0;
		issues.push('No subheadings');
	}

	// Calculate final score (0-100)
	const finalScore = Math.round(score / 3); // Average of 3 criteria

	// Return professional assessment
	if (finalScore >= 80) return 'Good';
	else if (finalScore >= 50) return 'OK';
	return 'Needs improvement';
};

/**
 * Calculate keyword optimization using industry standard SEO methodology
 * Based on established SEO best practices
 *
 * @param {string} plainText       - Plain text content
 * @param {string} htmlContent     - HTML content for structure analysis
 * @param {string} title           - Post title
 * @param {string} targetKeyword   - Focus keyword
 * @param {string} metaDescription - Meta description
 * @return {string} Keyword optimization assessment
 */
export const calculateKeywordOptimization = (
	plainText,
	htmlContent,
	title,
	targetKeyword,
	metaDescription = ''
) => {
	if (!targetKeyword || !targetKeyword.trim()) {
		return 'No focus keyword';
	}

	if (!plainText) return 'No content';

	const keywordLower = targetKeyword.toLowerCase();
	const contentLower = plainText.toLowerCase();
	const titleLower = title.toLowerCase();

	let checks = 0;
	let totalChecks = 0;

	// 1. Keyword in title (Essential SEO check)
	totalChecks++;
	if (titleLower.includes(keywordLower)) {
		checks++;
	}

	// 2. Keyword density (Industry standard: 0.5% - 2.5%)
	totalChecks++;
	const density = calculateKeywordDensity(plainText, targetKeyword);
	if (density >= 0.5 && density <= 2.5) {
		checks++;
	}

	// 3. Keyword in first paragraph (SEO best practice)
	totalChecks++;
	const tempDiv = document.createElement('div');
	tempDiv.innerHTML = htmlContent;
	const firstParagraph = tempDiv.querySelector('p');
	if (firstParagraph) {
		const firstParagraphText = (
			firstParagraph.textContent ||
			firstParagraph.innerText ||
			''
		).toLowerCase();
		if (firstParagraphText.includes(keywordLower)) {
			checks++;
		}
	}

	// 4. Keyword in subheadings (Content structure optimization)
	totalChecks++;
	const subheadings = tempDiv.querySelectorAll('h2, h3, h4, h5, h6');
	let keywordInSubheading = false;
	subheadings.forEach((heading) => {
		const headingText = (
			heading.textContent ||
			heading.innerText ||
			''
		).toLowerCase();
		if (headingText.includes(keywordLower)) {
			keywordInSubheading = true;
		}
	});
	if (keywordInSubheading) {
		checks++;
	}

	// 5. Keyword in meta description (Meta optimization)
	if (metaDescription) {
		totalChecks++;
		if (metaDescription.toLowerCase().includes(keywordLower)) {
			checks++;
		}
	}

	// Calculate score based on checks passed
	const percentage = Math.round((checks / totalChecks) * 100);

	// Return professional assessment
	if (percentage >= 80) return 'Good';
	else if (percentage >= 60) return 'OK';
	return 'Needs improvement';
};

/**
 * Keys inside a block's attribute JSON that hold user-visible text.
 *
 * Mirrors Builder_Content::CONTENT_KEYS on the PHP side. Matching on the key
 * keeps CSS classes and option slugs out of the word count.
 */
const BUILDER_CONTENT_KEYS = [
	'text',
	'title',
	'subtitle',
	'heading',
	'subheading',
	'content',
	'description',
	'caption',
	'excerpt',
	'label',
	'value',
	'html',
	'editor',
	'quote',
	'answer',
	'question',
	'body',
	'button_text',
];

/**
 * Pull user-visible text out of page-builder block markup.
 *
 * The block editor hands over builder markup verbatim — it cannot render
 * blocks it has no client-side registration for — and builders such as Divi 5
 * keep the words inside the block's attribute JSON, which lives in an HTML
 * comment. Parsing that markup as HTML therefore yields no text at all, which
 * is how a builder page could report "No content" beside a perfectly good
 * saved score.
 *
 * This mirrors Builder_Content::from_block_attributes() on the server so the
 * live panel and the saved score agree.
 *
 * @param {string} markup Raw editor markup.
 * @return {string} Extracted HTML, or '' when there is nothing to take.
 */
export const extractBuilderText = (markup) => {
	if (!markup || typeof markup !== 'string' || !markup.includes('<!-- wp:')) {
		return '';
	}

	const collected = [];

	const walk = (node, key) => {
		if (Array.isArray(node)) {
			node.forEach((child) => walk(child, key));
			return;
		}

		if (node && typeof node === 'object') {
			Object.entries(node).forEach(([childKey, child]) =>
				walk(child, childKey)
			);
			return;
		}

		if (typeof node !== 'string' || !node.trim()) {
			return;
		}

		// Markup is content wherever it appears; bare strings only count when
		// their key says they are content.
		const isContentKey =
			typeof key === 'string' &&
			BUILDER_CONTENT_KEYS.includes(key.toLowerCase());

		if (isContentKey || node.includes('<')) {
			collected.push(node);
		}
	};

	const pattern = /<!--\s*wp:[^\s]+\s+(\{.*?\})\s*\/?-->/gs;
	let match = pattern.exec(markup);
	while (match !== null) {
		try {
			walk(JSON.parse(match[1]));
		} catch (e) {
			// Not valid attribute JSON — skip this block.
		}
		match = pattern.exec(markup);
	}

	// De-duplicate: builders repeat a value across responsive breakpoints,
	// which would otherwise multiply the word count.
	return [...new Set(collected)].join('\n');
};

/**
 * Plain text from editor markup.
 *
 * DOMParser rather than innerHTML so nothing in the markup can run, with a
 * regex fallback for an environment that has no parser.
 *
 * @param {string} markup HTML or block markup.
 * @return {string} Text, untrimmed.
 */
export const htmlToPlainText = (markup) => {
	if (!markup || typeof markup !== 'string') {
		return '';
	}

	try {
		const doc = new DOMParser().parseFromString(markup, 'text/html');

		return doc.body.textContent || doc.body.innerText || '';
	} catch (e) {
		return markup.replace(/<[^>]*>/g, '');
	}
};

/**
 * Resolve editor markup to the text the panel should measure.
 *
 * Mirrors Builder_Content::resolve_markup() on the server, and the important
 * half of that mirror is *when* the block-attribute path runs: only once the
 * markup itself yields no text. Reaching for it unconditionally throws the
 * page away — a post whose paragraphs are ordinary HTML and whose one
 * ThinkRank FAQ block keeps its `question`/`answer` strings in attribute JSON
 * measured the FAQ alone, so Word Count, Keyword Density, Readability, Content
 * Quality and the per-keyword gauges all described a couple of accordion rows
 * instead of the article above them. The server never had the bug; it treats
 * attribute extraction as a fallback for markup that rendered to nothing,
 * which is what a page builder such as Divi 5 leaves behind.
 *
 * @param {string} markup Raw editor markup.
 * @return {{text: string, html: string}} Text to measure, and the markup it
 *                                        came from (headings and images are
 *                                        analyzed off that markup).
 */
export const resolveEditorContent = (markup) => {
	const html = typeof markup === 'string' ? markup : '';
	const direct = htmlToPlainText(html).trim();

	if (direct) {
		return { text: direct, html };
	}

	const builder = extractBuilderText(html);

	if (!builder) {
		return { text: '', html };
	}

	return { text: htmlToPlainText(builder).trim(), html: builder };
};
