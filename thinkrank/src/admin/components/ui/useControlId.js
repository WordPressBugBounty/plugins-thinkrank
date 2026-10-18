import { useState } from '@wordpress/element';

// A form control needs an id so its <label htmlFor> can point at it. The ui
// controls used to derive that id from the label alone, so every control with
// the same label got the same id: the ten URL and Type fields of the sitemap
// rows were all id="url" and id="type", and only the first pair was labelled
// (#1255). The same slug could also land on an id WordPress already uses on the
// page (a "Title" field became id="title"). A per-instance number keeps the
// readable slug and makes the id unique, for every repeated-row form at once.
//
// WordPress 6.0 ships React 17, which has no useId, so the counter is our own.
let lastInstance = 0;

/**
 * Turn a label into the readable part of an id.
 *
 * @param {*} label The control's label. Anything but a string has no slug.
 * @return {string} Lower-case, hyphenated slug, or '' when there is none.
 */
export const labelSlug = (label) =>
	typeof label === 'string'
		? label
				.toLowerCase()
				.trim()
				.replace(/[^a-z0-9_-]+/g, '-')
				.replace(/^-+|-+$/g, '')
		: '';

/**
 * The id a control renders with.
 *
 * @since 2.17.0
 *
 * @param {string|undefined} id       Id the caller passed. Always wins.
 * @param {*}                label    The control's label, used for the slug.
 * @param {string}           fallback Slug to use when the label has none.
 * @return {string} The caller's id, or `<slug>-<n>`, unique on the page.
 */
export default function useControlId(id, label, fallback = 'tr-control') {
	const [instance] = useState(() => ++lastInstance);

	if (id) {
		return id;
	}

	return `${labelSlug(label) || fallback}-${instance}`;
}
