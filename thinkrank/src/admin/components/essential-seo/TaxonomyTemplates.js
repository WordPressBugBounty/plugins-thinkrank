/**
 * Taxonomy templates
 *
 * A default title and meta description template for each public taxonomy
 * (#1425). Every custom taxonomy used to share the one Site Identity Archive
 * Title format and had no default description at all, so a site with hundreds
 * of recipe categories could only fix them one term at a time.
 *
 * The templates live in the taxonomy's Content Type Matrix row, so this screen
 * reads and writes the matrix route: the GET carries `title_template` and
 * `description_template` on every taxonomy row, and the POST takes the same
 * two keys. Each taxonomy saves on its own, so a failed save never touches
 * another taxonomy's edits.
 *
 * @package
 * @since 2.20.1
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Button,
	Card,
	EmptyState,
	Input,
	SettingsLoadError,
	SkeletonCard,
	Text,
} from '../ui';
import SectionHeader from '../common/SectionHeader';
import FormField from '../common/FormField';
import notify from '../../utils/notify';
import { restUrl } from '../../utils/restUrl';
import logger from '../../../shared/utils/logger';

const FIELDS = ['title_template', 'description_template'];

const TITLE_VARIABLES = [
	{ label: __('+ Term', 'thinkrank'), value: '%term_title%' },
	{ label: __('+ Taxonomy', 'thinkrank'), value: '%taxonomy_name%' },
	{ label: __('+ Parent term', 'thinkrank'), value: '%parent_term%' },
	{ label: __('+ Site Title', 'thinkrank'), value: '%site_title%' },
	{ label: __('+ Separator', 'thinkrank'), value: '%sep%' },
];

const DESCRIPTION_VARIABLES = [
	{
		label: __('+ Term description', 'thinkrank'),
		value: '%term_description%',
	},
	{ label: __('+ Term', 'thinkrank'), value: '%term_title%' },
	{ label: __('+ Post count', 'thinkrank'), value: '%count%' },
	{ label: __('+ Site Title', 'thinkrank'), value: '%site_title%' },
	{
		label: __('+ Site Description', 'thinkrank'),
		value: '%site_description%',
	},
];

/**
 * The fallback each empty title template leaves the term archive on.
 *
 * @param {string} taxonomy Taxonomy slug.
 * @return {string} Help text.
 */
const titleFallback = (taxonomy) => {
	if ('category' === taxonomy) {
		return __(
			'Empty: terms use the Category format under Site Identity.',
			'thinkrank'
		);
	}
	if ('post_tag' === taxonomy) {
		return __(
			'Empty: terms use the Tag format under Site Identity.',
			'thinkrank'
		);
	}
	return __(
		'Empty: terms use the Archive Title format under Site Identity.',
		'thinkrank'
	);
};

const valuesOf = (row) => ({
	title_template: row.title_template || '',
	description_template: row.description_template || '',
});

const TaxonomyTemplates = () => {
	const [rows, setRows] = useState([]);
	// Unsaved edits, by entity key. A row without an entry shows what is stored.
	const [drafts, setDrafts] = useState({});
	const [isLoading, setIsLoading] = useState(true);
	const [loadFailed, setLoadFailed] = useState(false);
	const [savingKey, setSavingKey] = useState(null);

	const load = useCallback(async () => {
		setIsLoading(true);

		try {
			const response = await fetch(restUrl('global-seo/matrix'), {
				headers: { 'X-WP-Nonce': window.thinkrankAdmin.restNonce },
			});

			if (!response.ok) {
				throw new Error(`Matrix request failed: ${response.status}`);
			}

			const data = await response.json();

			if (!data.success || !data.data) {
				throw new Error('Matrix response carried no data');
			}

			setRows(
				(data.data.entities || []).filter(
					(entity) => 'taxonomy' === entity.group
				)
			);
			setDrafts({});
			setLoadFailed(false);
		} catch (error) {
			logger.error('Error loading taxonomy templates:', error);
			setLoadFailed(true);
		} finally {
			setIsLoading(false);
		}
	}, []);

	useEffect(() => {
		load();
	}, [load]);

	const valueFor = (row) => drafts[row.key] || valuesOf(row);

	const isDirty = (row) => {
		const draft = drafts[row.key];
		if (!draft) {
			return false;
		}
		const stored = valuesOf(row);
		return FIELDS.some((field) => draft[field] !== stored[field]);
	};

	const update = (row, field, value) => {
		setDrafts((current) => ({
			...current,
			[row.key]: {
				...(current[row.key] || valuesOf(row)),
				[field]: value,
			},
		}));
	};

	const insertVariable = (row, field, token) => {
		let current = valueFor(row)[field];
		if (current.length > 0 && !current.endsWith(' ')) {
			current += ' ';
		}
		update(row, field, current + token);
	};

	const save = async (row) => {
		const payload = valueFor(row);
		setSavingKey(row.key);

		try {
			const response = await fetch(restUrl('global-seo/matrix'), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.thinkrankAdmin.restNonce,
				},
				body: JSON.stringify({ entity: row.key, settings: payload }),
			});

			if (!response.ok) {
				throw new Error(`Save failed: ${response.status}`);
			}

			const saved = await response.json();
			if (!saved?.success || !saved?.data) {
				throw new Error('Save response carried no data');
			}

			// What was stored, which the sanitizer may have tidied.
			setRows((current) =>
				current.map((entry) =>
					entry.key === row.key
						? { ...entry, ...valuesOf(saved.data) }
						: entry
				)
			);
			setDrafts((current) => {
				const next = { ...current };
				delete next[row.key];
				return next;
			});
			notify.success(
				sprintf(
					/* translators: %s: taxonomy name, e.g. Categories. */
					__('%s templates saved.', 'thinkrank'),
					row.label
				)
			);
		} catch (error) {
			logger.error('Error saving taxonomy templates:', error);
			notify.error(
				__('Could not save these templates. Try again.', 'thinkrank')
			);
		} finally {
			setSavingKey(null);
		}
	};

	const renderVariableHints = (row, field, variables) => (
		<Text as="div" size={12} color="help" className="tr-mt-1">
			{__('Use variables:', 'thinkrank')}{' '}
			{variables.map((variable) => (
				<button
					type="button"
					key={variable.value}
					onClick={() => insertVariable(row, field, variable.value)}
					className="thinkrank-variable-tag"
					title={__('Click to insert', 'thinkrank')}
					disabled={savingKey === row.key}
				>
					{variable.label}
				</button>
			))}
		</Text>
	);

	if (isLoading) {
		return (
			<SkeletonCard
				lines={5}
				label={__('Loading taxonomies…', 'thinkrank')}
			/>
		);
	}

	if (loadFailed) {
		return (
			<SettingsLoadError
				onRetry={load}
				isRetrying={isLoading}
				message={__(
					'Your taxonomy templates could not be read, so this screen has nothing to edit yet. Nothing has changed. Retry to load them.',
					'thinkrank'
				)}
			/>
		);
	}

	if (0 === rows.length) {
		return (
			<EmptyState
				title={__('No public taxonomies', 'thinkrank')}
				description={__(
					'This site has no public taxonomy with archive pages to set templates for.',
					'thinkrank'
				)}
			/>
		);
	}

	return (
		<div className="tr-flex tr-flex-col tr-gap-6">
			{rows.map((row) => {
				const values = valueFor(row);
				const idBase = `tr-tax-${row.object}`;
				const saving = savingKey === row.key;

				return (
					<Card padding={false} key={row.key}>
						<SectionHeader
							title={row.label}
							description={__(
								'Default title and meta description for every term that has no SEO title or description of its own.',
								'thinkrank'
							)}
							trailing={
								<Button
									variant="primary"
									size="sm"
									loading={saving}
									disabled={
										!isDirty(row) || null !== savingKey
									}
									onClick={() => save(row)}
								>
									{__('Save', 'thinkrank')}
								</Button>
							}
						/>
						<div className="tr-flex tr-flex-col tr-gap-6 tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2">
							<FormField
								id={`${idBase}-title`}
								label={__('Title Format', 'thinkrank')}
								help={titleFallback(row.object)}
							>
								<Input
									id={`${idBase}-title`}
									value={values.title_template}
									onChange={(e) =>
										update(
											row,
											'title_template',
											e.target.value
										)
									}
									placeholder="%term_title% %sep% %site_title%"
									disabled={saving}
								/>
								{renderVariableHints(
									row,
									'title_template',
									TITLE_VARIABLES
								)}
							</FormField>

							<FormField
								id={`${idBase}-description`}
								label={__('Description Format', 'thinkrank')}
								help={__(
									'Empty: terms use their own WordPress description.',
									'thinkrank'
								)}
								className="tr-pt-6"
							>
								<Input
									id={`${idBase}-description`}
									value={values.description_template}
									onChange={(e) =>
										update(
											row,
											'description_template',
											e.target.value
										)
									}
									placeholder="%term_description%"
									disabled={saving}
								/>
								{renderVariableHints(
									row,
									'description_template',
									DESCRIPTION_VARIABLES
								)}
							</FormField>
						</div>
					</Card>
				);
			})}
		</div>
	);
};

export default TaxonomyTemplates;
