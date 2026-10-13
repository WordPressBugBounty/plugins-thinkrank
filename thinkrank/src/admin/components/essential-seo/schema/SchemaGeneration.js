/**
 * Schema Generation Component
 *
 * Schema types selector for site-level schemas (Organization, LocalBusiness,
 * Person, WebSite). Content-specific schemas live in the post metabox.
 *
 * @package
 * @since 1.0.0
 */

import React from 'react';
import { __ } from '@wordpress/i18n';

import { getSupportedSchemaTypes } from '../../../config/schema-settings-config';

// ── Icons ─────────────────────────────────────────────────

const TypeIcons = {
	Organization: (
		<svg
			width="22"
			height="22"
			viewBox="0 0 24 24"
			fill="none"
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
		>
			<path
				d="M3 21V7l9-4 9 4v14"
				stroke="currentColor"
				strokeWidth="1.6"
				strokeLinejoin="round"
			/>
			<path
				d="M3 21h18M9 12h2m2 0h2M9 16h2m2 0h2"
				stroke="currentColor"
				strokeWidth="1.6"
				strokeLinecap="round"
			/>
		</svg>
	),
	LocalBusiness: (
		<svg
			width="22"
			height="22"
			viewBox="0 0 24 24"
			fill="none"
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
		>
			<path
				d="M12 21s-7-6.5-7-12a7 7 0 1 1 14 0c0 5.5-7 12-7 12Z"
				stroke="currentColor"
				strokeWidth="1.6"
				strokeLinejoin="round"
			/>
			<circle
				cx="12"
				cy="9"
				r="2.5"
				stroke="currentColor"
				strokeWidth="1.6"
			/>
		</svg>
	),
	Person: (
		<svg
			width="22"
			height="22"
			viewBox="0 0 24 24"
			fill="none"
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
		>
			<circle
				cx="12"
				cy="8"
				r="4"
				stroke="currentColor"
				strokeWidth="1.6"
			/>
			<path
				d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"
				stroke="currentColor"
				strokeWidth="1.6"
				strokeLinecap="round"
			/>
		</svg>
	),
	WebSite: (
		<svg
			width="22"
			height="22"
			viewBox="0 0 24 24"
			fill="none"
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
		>
			<circle
				cx="12"
				cy="12"
				r="9"
				stroke="currentColor"
				strokeWidth="1.6"
			/>
			<path
				d="M3 12h18M12 3c2.5 3 4 6 4 9s-1.5 6-4 9c-2.5-3-4-6-4-9s1.5-6 4-9Z"
				stroke="currentColor"
				strokeWidth="1.6"
				strokeLinejoin="round"
			/>
		</svg>
	),
};

const CheckIcon = ({ size = 14 }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 14 14"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M3 7.5L6 10.5L11 4.5"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

const SparkleIcon = () => (
	<svg
		width="18"
		height="18"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8L12 3Z"
			fill="currentColor"
		/>
		<path
			d="M19 14l.8 2 2 .8-2 .8L19 19.5l-.8-1.9-2-.8 2-.8.8-2Z"
			fill="currentColor"
			opacity="0.7"
		/>
	</svg>
);

// ── Schema Type Card ──────────────────────────────────────

const TYPE_DESCRIPTIONS = {
	Organization: __(
		'Brand, contact, logo and social profiles applied across the entire site.',
		'thinkrank'
	),
	LocalBusiness: __(
		'Business with a physical address, hours and service area ideal for storefronts.',
		'thinkrank'
	),
	Person: __(
		'A featured individual or author identity used across the site.',
		'thinkrank'
	),
	WebSite: __(
		'Site name, alternate names, and sitelinks search box for branded queries.',
		'thinkrank'
	),
};

const SchemaTypeCard = ({ type, checked, onToggle }) => {
	const description =
		TYPE_DESCRIPTIONS[type] || __('Schema markup for ' + type, 'thinkrank');
	const icon = TypeIcons[type] || TypeIcons.WebSite;

	return (
		<button
			type="button"
			onClick={() => onToggle(type, !checked)}
			aria-pressed={checked}
			style={{
				position: 'relative',
				display: 'flex',
				gap: '14px',
				alignItems: 'flex-start',
				textAlign: 'left',
				padding: '18px',
				border: checked
					? '1.5px solid #4f46e5'
					: '1.5px solid var(--tr-border)',
				borderRadius: '12px',
				background: checked ? '#f7f6ff' : '#fff',
				cursor: 'pointer',
				transition:
					'border-color 120ms ease, background-color 120ms ease, box-shadow 120ms ease',
				boxShadow: checked
					? '0 1px 2px rgba(79, 70, 229, 0.08)'
					: 'none',
				width: '100%',
				font: 'inherit',
				color: 'inherit',
			}}
		>
			<div
				style={{
					width: '40px',
					height: '40px',
					borderRadius: '10px',
					background: checked ? '#eceaff' : '#f5f6fa',
					color: checked ? '#4f46e5' : 'var(--tr-text-body)',
					display: 'flex',
					alignItems: 'center',
					justifyContent: 'center',
					flexShrink: 0,
					transition: 'background-color 120ms ease, color 120ms ease',
				}}
			>
				{icon}
			</div>

			<div style={{ flex: 1, minWidth: 0 }}>
				<div
					style={{
						fontSize: '14px',
						fontWeight: 600,
						color: 'var(--tr-text-heading)',
						marginBottom: '4px',
					}}
				>
					{type}
				</div>
				<div
					style={{
						fontSize: '12.5px',
						color: 'var(--tr-text-body)',
						lineHeight: 1.45,
					}}
				>
					{description}
				</div>
			</div>

			<div
				style={{
					width: '20px',
					height: '20px',
					borderRadius: '6px',
					background: checked ? '#4f46e5' : '#fff',
					border: checked
						? '1.5px solid #4f46e5'
						: '1.5px solid #d4d8e0',
					color: '#fff',
					display: 'flex',
					alignItems: 'center',
					justifyContent: 'center',
					flexShrink: 0,
					transition:
						'background-color 120ms ease, border-color 120ms ease',
				}}
				aria-hidden="true"
			>
				{checked && <CheckIcon />}
			</div>
		</button>
	);
};

// ── Main Component ────────────────────────────────────────

const SchemaGeneration = ({ settings, onSettingChange }) => {
	const supportedTypes = getSupportedSchemaTypes('site');

	React.useEffect(() => {
		const currentEnabledTypes = settings.enabled_schema_types || [];
		const cleanedTypes = currentEnabledTypes.filter((type) =>
			supportedTypes.includes(type)
		);
		if (cleanedTypes.length !== currentEnabledTypes.length) {
			onSettingChange('enabled_schema_types', cleanedTypes);
		}
	}, []);

	const handleSchemaTypeToggle = (schemaType, checked) => {
		const currentTypes = settings.enabled_schema_types || [];
		const newTypes = checked
			? [...currentTypes, schemaType]
			: currentTypes.filter((type) => type !== schemaType);
		onSettingChange('enabled_schema_types', newTypes);
	};

	const allEnabledTypes = settings.enabled_schema_types || [];
	const enabledTypes = allEnabledTypes.filter((type) =>
		supportedTypes.includes(type)
	);

	return (
		<div className="tab-content">
			{/* ── Schema types card ────────────────────────────────── */}
			<div
				style={{
					border: '1px solid var(--tr-border)',
					borderRadius: '12px',
					background: '#fff',
					overflow: 'hidden',
					marginBottom: '20px',
				}}
			>
				{/* ── Card header ──────────────────────────────────── */}
				<div
					style={{
						display: 'flex',
						alignItems: 'flex-start',
						gap: '16px',
						padding: '20px 24px',
						borderBottom: '1px solid var(--tr-border)',
					}}
				>
					<div
						style={{
							width: '44px',
							height: '44px',
							borderRadius: '10px',
							background: '#eef0fd',
							color: '#4f46e5',
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'center',
							flexShrink: 0,
						}}
					>
						<SparkleIcon />
					</div>
					<div style={{ flex: 1, minWidth: 0 }}>
						<h2
							style={{
								margin: 0,
								fontSize: '16px',
								fontWeight: 600,
								color: 'var(--tr-text-heading)',
							}}
						>
							{__('Site-Level Schema Types', 'thinkrank')}
						</h2>
						<p
							style={{
								margin: '4px 0 0',
								fontSize: '13px',
								color: 'var(--tr-text-body)',
								lineHeight: 1.5,
							}}
						>
							{__(
								'Pick the structured-data entities that describe your site as a whole. Article, BlogPosting, HowTo and FAQ schemas are managed per-post in the ThinkRank metabox.',
								'thinkrank'
							)}
						</p>
					</div>
					<span
						style={{
							fontSize: '12px',
							fontWeight: 600,
							padding: '4px 10px',
							borderRadius: '999px',
							whiteSpace: 'nowrap',
							flexShrink: 0,
							background:
								enabledTypes.length > 0 ? '#eceaff' : '#f3f4f6',
							color:
								enabledTypes.length > 0
									? '#4f46e5'
									: 'var(--tr-text-body)',
						}}
					>
						{enabledTypes.length > 0
							? __(`${enabledTypes.length} selected`, 'thinkrank')
							: __('None selected', 'thinkrank')}
					</span>
				</div>

				{/* ── Schema type grid ─────────────────────────────── */}
				<div
					className="thinkrank-schema-types-grid"
					style={{
						display: 'grid',
						gridTemplateColumns:
							'repeat(auto-fill, minmax(260px, 1fr))',
						gap: '14px',
						padding: '20px 24px',
					}}
				>
					{supportedTypes.map((schemaType) => (
						<SchemaTypeCard
							key={schemaType}
							type={schemaType}
							checked={enabledTypes.includes(schemaType)}
							onToggle={handleSchemaTypeToggle}
						/>
					))}
				</div>
			</div>

			{/* ── Empty selection notice ───────────────────────────── */}
			{enabledTypes.length === 0 && (
				<div
					style={{
						display: 'flex',
						alignItems: 'flex-start',
						gap: '12px',
						padding: '14px 16px',
						borderRadius: '10px',
						background: '#fff8e5',
						border: '1px solid #fde68a',
						marginBottom: '24px',
					}}
				>
					<div
						style={{
							fontSize: '16px',
							lineHeight: 1,
							color: '#b45309',
						}}
					>
						!
					</div>
					<div
						style={{
							fontSize: '13px',
							color: '#8a6d3b',
							lineHeight: 1.5,
						}}
					>
						{__(
							'No schema types selected yet. Pick at least one above so ThinkRank has something to generate.',
							'thinkrank'
						)}
					</div>
				</div>
			)}

			{/* ── Tip: content schemas live in the metabox ────────── */}
			<div
				style={{
					display: 'flex',
					gap: '14px',
					alignItems: 'flex-start',
					padding: '16px 20px',
					border: '1px solid var(--tr-border)',
					borderRadius: '12px',
					background: '#fafbfc',
				}}
			>
				<div
					style={{
						width: '36px',
						height: '36px',
						borderRadius: '10px',
						background: '#fff',
						color: '#4f46e5',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						flexShrink: 0,
						border: '1px solid var(--tr-border)',
					}}
					aria-hidden="true"
				>
					<svg
						width="18"
						height="18"
						viewBox="0 0 24 24"
						fill="none"
						xmlns="http://www.w3.org/2000/svg"
					>
						<path
							d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Z"
							stroke="currentColor"
							strokeWidth="1.6"
							strokeLinejoin="round"
						/>
						<path
							d="M14 3v6h6M8 13h8M8 17h5"
							stroke="currentColor"
							strokeWidth="1.6"
							strokeLinecap="round"
							strokeLinejoin="round"
						/>
					</svg>
				</div>
				<div style={{ flex: 1, minWidth: 0 }}>
					<div
						style={{
							fontSize: '13px',
							fontWeight: 600,
							color: 'var(--tr-text-heading)',
							marginBottom: '4px',
						}}
					>
						{__(
							'Need Article, BlogPosting, HowTo or FAQ?',
							'thinkrank'
						)}
					</div>
					<div
						style={{
							fontSize: '13px',
							color: 'var(--tr-text-body)',
							lineHeight: 1.5,
						}}
					>
						{__(
							'Content-specific schemas use the actual post data plus AI optimization, so they live in the ThinkRank metabox when editing individual posts and pages.',
							'thinkrank'
						)}
					</div>
				</div>
			</div>
		</div>
	);
};

export default SchemaGeneration;
