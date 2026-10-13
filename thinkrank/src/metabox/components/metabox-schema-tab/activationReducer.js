/**
 * Saved-schema activation rule.
 *
 * The free plugin keeps one saved schema active at a time: activating one
 * deactivates the others. ThinkRank Pro can swap in its own rule through the
 * `thinkrank.schemaTab.activationReducer` filter (#673).
 *
 * @package
 */

import { __ } from '@wordpress/i18n';

/**
 * Toggle one saved schema, keeping at most one active.
 *
 * @param {Array}  schemas Saved schemas, each with an `_isActive` flag.
 * @param {number} index   Index of the schema being toggled.
 * @return {{schemas: Array, message: string}} Next schemas and the notice to show.
 */
const singleActiveReducer = (schemas, index) => {
	if (schemas[index]._isActive) {
		return {
			schemas: schemas.map((schema, i) =>
				i === index ? { ...schema, _isActive: false } : schema
			),
			message: __(
				'Schema deactivated. Update the deployment to apply changes.',
				'thinkrank'
			),
		};
	}

	const hadOtherActive = schemas.some(
		(schema, i) => i !== index && schema._isActive
	);

	return {
		schemas: schemas.map((schema, i) => ({
			...schema,
			_isActive: i === index,
		})),
		message: hadOtherActive
			? __(
					'Schema activated, and the previously active one was switched off. Update the deployment to apply changes.',
					'thinkrank'
				)
			: __(
					'Schema activated. Update the deployment to apply changes.',
					'thinkrank'
				),
	};
};

export default singleActiveReducer;
