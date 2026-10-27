/**
 * Client-side variable-tag resolver.
 *
 * Mirrors the Bulk/Global SEO live preview: replaces %tags% in a template with
 * the post's real values (provided by window.thinkrankMetabox.patternVariables).
 * A tag the map cannot resolve is removed, exactly as
 * Pattern_Resolver::process() removes it on the way to the page — a preview that
 * echoed a token the site then publishes nothing for would be telling the author
 * the opposite of what happens.
 *
 * @package
 * @since 1.0.0
 */

/**
 * Tokens, in both syntaxes a migrated site carries.
 *
 * `%%x%%` is matched whole and never kept: the placeholder vocabulary is the
 * single-percent form, so a double-percent token is always unresolved, and
 * eating the inner `%x%` out of it would leave a stray percent at each end. A
 * bare percent is not a token, so "50% off" is left alone. `%customfield(key)%`
 * is one token, whose map key is `customfield(key)` (#1277).
 *
 * @type {RegExp}
 */
const TOKEN = /%%[a-z0-9_-]+%%|%(customfield\([^\s%()]+\)|[a-z0-9_-]+)%/gi;

/**
 * The map key for a token: `%CustomField(x)%` resolves like
 * `%customfield(x)%`, as Pattern_Resolver matches it.
 *
 * @param {string} key Token without its percents.
 * @return {string} Map key.
 */
const keyFor = (key) => key.replace(/^customfield\(/i, 'customfield(');

/**
 * Resolve a variable-tag template against a token => value map.
 *
 * @param {string} template Pattern string, e.g. "%title% %sep% %sitename%".
 * @param {Object} vars     Token map keyed without percents (e.g. { title }).
 * @return {string} Resolved string.
 */
export const resolvePattern = (template, vars = {}) =>
	(template || '').replace(TOKEN, (match, key) =>
		key && Object.prototype.hasOwnProperty.call(vars, keyFor(key))
			? vars[keyFor(key)]
			: ''
	);
