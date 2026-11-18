/**
 * Organization relationships — parent company, sub-organizations, brands.
 *
 * Three lists rather than one, because the distinction between them is the
 * part users get wrong: subOrganization is for divisions and separate legal
 * entities, brand is for the names the organization sells under. A company's
 * apps and product lines are brands however they are marketed, and filing them
 * as sub-organizations tells a crawler there are several companies where there
 * is one. The copy here says so at the point of entry rather than in docs.
 *
 * @package
 * @since 2.22.0
 */

import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Card, Input, Textarea } from '../../../ui';

/* ── Icons ──────────────────────────────────────────────────────────────── */

const IconTrash = () => (
	<svg
		width="13"
		height="13"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polyline points="3 6 5 6 21 6" />
		<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
	</svg>
);

const IconPlus = () => (
	<svg
		width="13"
		height="13"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<line x1="12" y1="5" x2="12" y2="19" />
		<line x1="5" y1="12" x2="19" y2="12" />
	</svg>
);

/* ── Validation ─────────────────────────────────────────────────────────── */

/**
 * Whether a string is a complete http(s) URL.
 *
 * Mirrors what the server stores: a value without a scheme is dropped rather
 * than guessed at, because a crawler resolving schema has no knowledge of this
 * site's base URL.
 *
 * @param {string} value Candidate URL.
 * @return {boolean} True when the value parses as an http(s) URL with a host.
 */
const isHttpUrl = (value) => {
	try {
		const url = new URL(value.trim());
		return (
			(url.protocol === 'http:' || url.protocol === 'https:') &&
			!!url.hostname &&
			url.hostname.includes('.')
		);
	} catch (e) {
		return false;
	}
};

/**
 * Error for an optional URL field.
 *
 * @param {string} value Field value.
 * @return {string} Error message, or an empty string.
 */
const urlError = (value) => {
	const trimmed = (value || '').trim();
	if (!trimmed || isHttpUrl(trimmed)) {
		return '';
	}
	return __('Enter the full URL, starting with https://', 'thinkrank');
};

/**
 * Error for a name field that the entry cannot be saved without.
 *
 * @param {string} value Field value.
 * @param {number} index Row index.
 * @param {Array}  rows  All rows in this list.
 * @return {string} Error message, or an empty string.
 */
const nameError = (value, index, rows) => {
	const trimmed = (value || '').trim();
	if (!trimmed) {
		return __('A name is required, or this entry is dropped.', 'thinkrank');
	}
	const clash = rows.some(
		(row, i) =>
			i < index &&
			(row.name || '').trim().toLowerCase() === trimmed.toLowerCase()
	);
	return clash
		? __('Already used above. Duplicates are dropped.', 'thinkrank')
		: '';
};

/* ── sameAs text field ──────────────────────────────────────────────────── */

const toLines = (value) => (Array.isArray(value) ? value.join('\n') : '');

const fromLines = (value) =>
	value
		.split('\n')
		.map((line) => line.trim())
		.filter(Boolean);

/**
 * A one-URL-per-line field over a `same_as` array.
 *
 * The array cannot round-trip what is being typed: the Enter that starts the
 * next URL is an empty line, which the array drops, so rendering the field
 * from the array swallowed every newline and ran the second URL into the
 * first. The text as typed lives here, and the array is only written from it,
 * so the stored value is always the clean list. The text is replaced from the
 * array only when the array changes to something the text does not already
 * say (a reset, or the stored settings adopted after a save).
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.value    Stored URLs.
 * @param {Function} props.onChange Called with the cleaned URL list.
 * @return {JSX.Element} The field.
 */
export const SameAsTextarea = ({ value, onChange, ...props }) => {
	const [text, setText] = useState(() => toLines(value));

	useEffect(() => {
		setText((current) =>
			toLines(fromLines(current)) === toLines(value)
				? current
				: toLines(value)
		);
	}, [value]);

	return (
		<Textarea
			{...props}
			value={text}
			onChange={(e) => {
				setText(e.target.value);
				onChange(fromLines(e.target.value));
			}}
		/>
	);
};

/* ── Shared list scaffolding ────────────────────────────────────────────── */

const ListSection = ({
	title,
	subtitle,
	itemLabel,
	addLabel,
	emptyLabel,
	rows,
	onChange,
	max,
	disabled,
	children,
}) => (
	<Card padding={false}>
		<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
			<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
				{title}
			</h3>
			<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
				{subtitle}
			</p>
		</div>
		<div className="tr-p-5 tr-flex tr-flex-col tr-gap-3">
			{rows.length === 0 ? (
				<div className="tr-text-center tr-py-6">
					<p className="tr-text-sm tr-text-tertiary tr-m-0">
						{emptyLabel}
					</p>
				</div>
			) : (
				rows.map((row, index) => (
					<div
						key={index}
						className="tr-border tr-border-secondary tr-rounded-lg tr-overflow-hidden"
					>
						<div className="tr-flex tr-items-center tr-justify-between tr-px-4 tr-py-2.5 tr-bg-white tr-border-b tr-border-secondary">
							<span className="tr-text-xs tr-font-semibold tr-text-secondary">
								{itemLabel} {index + 1}
							</span>
							<button
								type="button"
								onClick={() =>
									onChange(rows.filter((_, i) => i !== index))
								}
								disabled={disabled}
								className="tr-flex tr-items-center tr-gap-1 tr-text-xs tr-font-medium tr-text-danger tr-bg-danger-bg tr-border tr-border-danger tr-rounded tr-px-2 tr-py-1 tr-transition-colors tr-duration-150 tr-cursor-pointer disabled:tr-opacity-50 disabled:tr-cursor-not-allowed"
							>
								<IconTrash /> {__('Remove', 'thinkrank')}
							</button>
						</div>
						<div className="tr-p-4 tr-flex tr-flex-col tr-gap-3">
							{children(row, index)}
						</div>
					</div>
				))
			)}

			<div>
				<button
					type="button"
					onClick={() => onChange([...rows, { name: '' }])}
					disabled={disabled || rows.length >= max}
					className="tr-flex tr-items-center tr-gap-1.5 tr-text-xs tr-font-medium tr-text-brand tr-bg-transparent tr-border tr-border-brand tr-rounded tr-px-3 tr-py-1.5 tr-transition-colors tr-duration-150 tr-cursor-pointer disabled:tr-opacity-50 disabled:tr-cursor-not-allowed"
				>
					<IconPlus /> {addLabel}
				</button>
			</div>
		</div>
	</Card>
);

/* ── Component ──────────────────────────────────────────────────────────── */

const SchemaOrganizationRelations = ({
	settings,
	onSettingChange,
	disabled,
}) => {
	const parent =
		settings.organization_parent &&
		!Array.isArray(settings.organization_parent)
			? settings.organization_parent
			: {};
	const subs = Array.isArray(settings.organization_subs)
		? settings.organization_subs
		: [];
	const brands = Array.isArray(settings.organization_brands)
		? settings.organization_brands
		: [];

	const setParent = (field, value) =>
		onSettingChange('organization_parent', { ...parent, [field]: value });

	const setRow = (key, rows, index, field, value) =>
		onSettingChange(
			key,
			rows.map((row, i) =>
				i === index ? { ...row, [field]: value } : row
			)
		);

	return (
		<>
			{/* ── Parent company ───────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Parent Company', 'thinkrank')}
					</h3>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'The company this organization belongs to. Leave empty if it belongs to none.',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('Name', 'thinkrank')}
						value={parent.name || ''}
						onChange={(e) => setParent('name', e.target.value)}
						placeholder={__('Example Holdings Ltd', 'thinkrank')}
						description={__(
							'Required. Without a name there is nothing to publish.',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Input
						label={__('Website', 'thinkrank')}
						value={parent.url || ''}
						onChange={(e) => setParent('url', e.target.value)}
						placeholder="https://example.com"
						error={urlError(parent.url)}
						disabled={disabled}
					/>
					<SameAsTextarea
						label={__('Also Known As', 'thinkrank')}
						value={parent.same_as}
						onChange={(urls) => setParent('same_as', urls)}
						rows={3}
						placeholder={
							'https://www.wikidata.org/wiki/Q123\nhttps://www.linkedin.com/company/example'
						}
						description={__(
							'One URL per line. Pages that identify the same company elsewhere, such as Wikipedia, Wikidata or LinkedIn.',
							'thinkrank'
						)}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Sub-organizations ────────────────────────────────────── */}
			<ListSection
				title={__('Sub-Organizations', 'thinkrank')}
				subtitle={__(
					'Divisions or separate legal entities under this organization. Products and apps are not sub-organizations: add those as brands below.',
					'thinkrank'
				)}
				itemLabel={__('Sub-Organization', 'thinkrank')}
				addLabel={__('Add Sub-Organization', 'thinkrank')}
				emptyLabel={__(
					'No sub-organizations added. Most sites need none.',
					'thinkrank'
				)}
				rows={subs}
				onChange={(rows) => onSettingChange('organization_subs', rows)}
				max={25}
				disabled={disabled}
			>
				{(row, index) => (
					<>
						<Input
							label={__('Name', 'thinkrank')}
							value={row.name || ''}
							onChange={(e) =>
								setRow(
									'organization_subs',
									subs,
									index,
									'name',
									e.target.value
								)
							}
							error={nameError(row.name, index, subs)}
							disabled={disabled}
						/>
						<Input
							label={__('Website', 'thinkrank')}
							value={row.url || ''}
							onChange={(e) =>
								setRow(
									'organization_subs',
									subs,
									index,
									'url',
									e.target.value
								)
							}
							placeholder="https://division.example.com"
							error={urlError(row.url)}
							disabled={disabled}
						/>
						<Input
							label={__('Logo URL', 'thinkrank')}
							value={row.logo || ''}
							onChange={(e) =>
								setRow(
									'organization_subs',
									subs,
									index,
									'logo',
									e.target.value
								)
							}
							placeholder="https://division.example.com/logo.png"
							error={urlError(row.logo)}
							disabled={disabled}
						/>
						<SameAsTextarea
							label={__('Also Known As', 'thinkrank')}
							value={row.same_as}
							onChange={(urls) =>
								setRow(
									'organization_subs',
									subs,
									index,
									'same_as',
									urls
								)
							}
							rows={2}
							description={__('One URL per line.', 'thinkrank')}
							disabled={disabled}
						/>
					</>
				)}
			</ListSection>

			{/* ── Brands ───────────────────────────────────────────────── */}
			<ListSection
				title={__('Brands', 'thinkrank')}
				subtitle={__(
					'The names this organization sells under. Product lines and apps belong here, and a product page can point at the brand it belongs to.',
					'thinkrank'
				)}
				itemLabel={__('Brand', 'thinkrank')}
				addLabel={__('Add Brand', 'thinkrank')}
				emptyLabel={__('No brands added yet.', 'thinkrank')}
				rows={brands}
				onChange={(rows) =>
					onSettingChange('organization_brands', rows)
				}
				max={50}
				disabled={disabled}
			>
				{(row, index) => (
					<>
						<Input
							label={__('Name', 'thinkrank')}
							value={row.name || ''}
							onChange={(e) =>
								setRow(
									'organization_brands',
									brands,
									index,
									'name',
									e.target.value
								)
							}
							error={nameError(row.name, index, brands)}
							disabled={disabled}
						/>
						<Input
							label={__('Website', 'thinkrank')}
							value={row.url || ''}
							onChange={(e) =>
								setRow(
									'organization_brands',
									brands,
									index,
									'url',
									e.target.value
								)
							}
							placeholder="https://brand.example.com"
							error={urlError(row.url)}
							disabled={disabled}
						/>
						<Input
							label={__('Logo URL', 'thinkrank')}
							value={row.logo || ''}
							onChange={(e) =>
								setRow(
									'organization_brands',
									brands,
									index,
									'logo',
									e.target.value
								)
							}
							placeholder="https://brand.example.com/logo.png"
							error={urlError(row.logo)}
							disabled={disabled}
						/>
					</>
				)}
			</ListSection>
		</>
	);
};

export default SchemaOrganizationRelations;
