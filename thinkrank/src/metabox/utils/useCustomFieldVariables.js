/**
 * Custom field values for a `%customfield(key)%` the author types.
 *
 * The editor page carries only the custom fields its saved SEO fields and
 * templates already name (Pattern_Resolver::variables(), bounded since the
 * #1277 review). A key typed afterwards is looked up here, a short while
 * after typing stops, so the live preview shows the item's value rather than
 * dropping the token.
 *
 * @package
 * @since 2.19.0
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Wait after the last keystroke before looking keys up.
 *
 * @type {number}
 */
export const LOOKUP_DELAY = 400;

/**
 * At most this many keys per lookup; Pattern_Resolver::CUSTOM_FIELD_LIMIT.
 *
 * @type {number}
 */
const MAX_KEYS = 20;

const TOKEN = /%customfield\(([^\s%()]+)\)%/gi;

/**
 * The meta keys the custom field tokens in some templates name.
 *
 * @param {string[]} templates Templates.
 * @return {string[]} Unique keys.
 */
export const customFieldKeys = (templates) => {
	const keys = new Set();
	templates.forEach((template) => {
		if (typeof template !== 'string') {
			return;
		}
		for (const match of template.matchAll(TOKEN)) {
			keys.add(match[1]);
		}
	});
	return [...keys];
};

/**
 * The token as typed, for a key whose value is not known yet.
 *
 * Mapped to itself, the preview keeps showing `%customfield(key)%` while it
 * is being looked up, or after a lookup failed, instead of dropping it as if
 * the field were empty (#1277 review).
 *
 * @param {string} key Meta key.
 * @return {string} The token.
 */
const asTyped = (key) => `%customfield(${key})%`;

/**
 * @param {Object}   baseVars   Variables the page was given.
 * @param {string[]} templates  The fields as typed.
 * @param {string}   objectType 'post' or 'term'.
 * @param {number}   objectId   Post or term ID.
 * @return {{vars: Object, status: string, error: string, retry: Function}}
 *         The variables (baseVars plus looked-up fields, unknown ones as
 *         typed), the lookup's state ('idle', 'loading' or 'error'), and a
 *         function that asks again after a failure.
 */
const useCustomFieldVariables = (baseVars, templates, objectType, objectId) => {
	const [fetched, setFetched] = useState({});
	const [failure, setFailure] = useState({ keys: '', message: '' });
	// Bumped by retry(), so the same keys are asked for again after a
	// failure (#1277 review).
	const [attempt, setAttempt] = useState(0);
	// Only the newest lookup may set values.
	const latest = useRef(0);

	const has = (map, key) =>
		Object.prototype.hasOwnProperty.call(map, `customfield(${key})`);

	const missing = customFieldKeys(templates).filter(
		(key) => !has(baseVars, key) && !has(fetched, key)
	);
	const missingKey = missing.slice(0, MAX_KEYS).join('\n');

	useEffect(() => {
		if (!missingKey || !objectId) {
			return undefined;
		}

		const request = ++latest.current;
		const timer = setTimeout(async () => {
			try {
				const response = await apiFetch({
					path: addQueryArgs('/thinkrank/v1/custom-fields', {
						object_type: objectType,
						object_id: objectId,
						keys: missingKey.split('\n'),
					}),
				});
				if (request === latest.current) {
					setFetched((prev) => ({
						...prev,
						...(response?.values || {}),
					}));
					setFailure({ keys: '', message: '' });
				}
			} catch (e) {
				if (request === latest.current) {
					setFailure({
						keys: missingKey,
						message:
							e?.message ||
							__(
								'Custom field values could not be loaded.',
								'thinkrank'
							),
					});
				}
			}
		}, LOOKUP_DELAY);

		return () => clearTimeout(timer);
	}, [missingKey, objectType, objectId, attempt]);

	const unknown = {};
	missing.forEach((key) => {
		unknown[`customfield(${key})`] = asTyped(key);
	});

	let status = 'idle';
	if (missingKey && objectId) {
		status = failure.keys === missingKey ? 'error' : 'loading';
	}

	// Look the same keys up again: clears the failure, which puts the
	// status back to loading, and reruns the lookup.
	const retry = () => {
		setFailure({ keys: '', message: '' });
		setAttempt((n) => n + 1);
	};

	return {
		vars: { ...unknown, ...fetched, ...baseVars },
		status,
		error: 'error' === status ? failure.message : '',
		retry,
	};
};

export default useCustomFieldVariables;
