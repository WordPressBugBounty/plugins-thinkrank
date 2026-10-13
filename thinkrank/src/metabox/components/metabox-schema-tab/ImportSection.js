/**
 * Import Schema Section
 *
 * Import source selection, URL import flow, imported data editor.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';
import { useRef, useState } from '@wordpress/element';
import { Tooltip, TextControl, TextareaControl } from '@wordpress/components';

/**
 * ImportSection Component
 * @param root0
 * @param root0.importSource
 * @param root0.setImportSource
 * @param root0.importUrl
 * @param root0.setImportUrl
 * @param root0.importedData
 * @param root0.setImportedData
 * @param root0.availableSchemas
 * @param root0.setAvailableSchemas
 * @param root0.selectedImportIndex
 * @param root0.setSelectedImportIndex
 * @param root0.isImporting
 * @param root0.importMethods
 * @param root0.activeImportMethod
 * @param root0.savedSchemas
 * @param root0.setSavedSchemas
 * @param root0.saveAdditionalSchemas
 * @param root0.editingSchemaIndex
 * @param root0.setEditingSchemaIndex
 * @param root0.showNotice
 * @param root0.setIsImporting
 * @param root0.postId
 * @param root0.onImportFromUrl
 */
const ImportSection = ({
	importSource,
	setImportSource,
	importUrl,
	setImportUrl,
	importedData,
	setImportedData,
	availableSchemas,
	setAvailableSchemas,
	selectedImportIndex,
	setSelectedImportIndex,
	isImporting,
	importMethods,
	activeImportMethod,
	savedSchemas,
	setSavedSchemas,
	saveAdditionalSchemas,
	editingSchemaIndex,
	setEditingSchemaIndex,
	showNotice,
	setIsImporting,
	postId,
	onImportFromUrl,
}) => {
	// Object and array fields are edited as JSON text, and JSON is invalid for
	// most of the keystrokes it takes to write it — delete a quote, a brace or
	// a comma and the value stops parsing. Committing only on a successful
	// parse meant the controlled textarea snapped back to the previous value on
	// every intermediate state, so the fields read as frozen and adding a
	// property was impossible (#404).
	//
	// The raw text is held here while it is being edited, and only a value that
	// parses is committed to importedData. What does not parse is reported
	// inline rather than silently discarded.
	const [jsonDrafts, setJsonDrafts] = useState({});
	const [jsonErrors, setJsonErrors] = useState({});

	// A draft outlives the value it was typed against unless it is dropped when
	// importedData is replaced from elsewhere — switching schema in the
	// dropdown, cancelling, saving, editing a saved schema, or a fresh import.
	// The field would then keep showing the old text while a different value
	// was the one about to be saved. Only this component's own commits are
	// exempt: reformatting on every keystroke that parses would fight the user
	// just as the snap-back did.
	const committedRef = useRef(importedData);
	const [trackedData, setTrackedData] = useState(importedData);

	if (importedData !== trackedData) {
		setTrackedData(importedData);

		if (importedData !== committedRef.current) {
			setJsonDrafts({});
			setJsonErrors({});
		}
	}

	const commitField = (key, value) => {
		const next = { ...importedData, [key]: value };
		committedRef.current = next;
		setImportedData(next);
	};

	const btnPrimary =
		'tr-inline-flex tr-items-center tr-gap-1.5 tr-h-8 tr-px-3 tr-rounded tr-text-xs tr-font-medium tr-text-white tr-bg-brand hover:tr-bg-brand disabled:tr-opacity-50 disabled:tr-cursor-not-allowed tr-transition-colors tr-cursor-pointer tr-border-0';
	const btnSecondary =
		'tr-inline-flex tr-items-center tr-gap-1.5 tr-h-8 tr-px-3 tr-rounded tr-border tr-border-primary tr-bg-white tr-text-xs tr-font-medium tr-text-secondary hover:tr-bg-white disabled:tr-opacity-50 disabled:tr-cursor-not-allowed tr-transition-colors tr-cursor-pointer';

	return (
		<div className="tr-w-full tr-bg-white tr-rounded-lg tr-border tr-border-secondary">
			<div className="tr-px-4 tr-py-3 tr-border-b tr-border-secondary">
				<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
					{__('Import Schema', 'thinkrank')}
				</h4>
				<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
					{__(
						'Copy structured data from any live page and reuse it on this page.',
						'thinkrank'
					)}
				</p>
			</div>
			<div className="tr-p-4 tr-flex tr-flex-col tr-gap-3">
				<div>
					<label
						htmlFor="import-schema-source"
						className="tr-block tr-text-xs tr-font-medium tr-text-secondary tr-mb-1"
					>
						{__('Import from', 'thinkrank')}
						<Tooltip
							text={__(
								'Choose where the schema will be imported from.',
								'thinkrank'
							)}
						>
							<span
								className="dashicons dashicons-editor-help"
								style={{
									color: 'var(--tr-text-secondary)',
									fontSize: '16px',
									marginLeft: '5px',
									cursor: 'help',
								}}
							></span>
						</Tooltip>
					</label>
					<select
						id="import-schema-source"
						value={importSource}
						onChange={(e) => setImportSource(e.target.value)}
						className="tr-w-full tr-h-8 tr-rounded tr-border tr-border-primary tr-bg-white tr-text-xs tr-px-2 focus:tr-outline-none"
					>
						{importMethods.map((method) => (
							<option key={method.id} value={method.id}>
								{method.label}
							</option>
						))}
					</select>
				</div>

				{activeImportMethod.id === 'URL / Online Page' && (
					<div>
						<label
							htmlFor="import-schema-url"
							className="tr-block tr-text-xs tr-font-medium tr-text-secondary tr-mb-1"
						>
							{__('Page URL', 'thinkrank')}
						</label>
						<div className="tr-flex tr-gap-2">
							<input
								type="text"
								id="import-schema-url"
								value={importUrl}
								onChange={(e) => setImportUrl(e.target.value)}
								className="tr-flex-1 tr-h-8 tr-rounded tr-border tr-border-primary tr-bg-white tr-text-xs tr-px-3 focus:tr-outline-none"
								placeholder="https://example.com/page-with-schema"
							/>
							<button
								type="button"
								onClick={onImportFromUrl}
								disabled={isImporting}
								className={btnPrimary}
							>
								{isImporting && (
									<span className="tr-w-3 tr-h-3 tr-border-2 tr-border-white tr-border-t-transparent tr-rounded-full tr-animate-spin tr-shrink-0" />
								)}
								{isImporting
									? __('Importing…', 'thinkrank')
									: __('Import', 'thinkrank')}
							</button>
						</div>
						<p className="tr-text-[11px] tr-text-tertiary tr-m-0 tr-mt-1">
							{__(
								'Enter the URL of the page that already has schema markup.',
								'thinkrank'
							)}
						</p>
					</div>
				)}

				{activeImportMethod.id !== 'URL / Online Page' &&
					activeImportMethod.component && (
						<activeImportMethod.component
							savedSchemas={savedSchemas}
							setSavedSchemas={setSavedSchemas}
							saveAdditionalSchemas={saveAdditionalSchemas}
							showNotice={showNotice}
							setIsImporting={setIsImporting}
							setImportedData={setImportedData}
							setAvailableSchemas={setAvailableSchemas}
							setSelectedImportIndex={setSelectedImportIndex}
							isImporting={isImporting}
							postId={postId}
						/>
					)}

				{/* Imported Data Editor */}
				{importedData && (
					<div
						className="imported-schema-editor"
						style={{
							marginTop: '20px',
							padding: '15px',
							background: '#f9f9f9',
							border: '1px solid #ddd',
							borderRadius: '4px',
						}}
					>
						<div
							style={{
								marginBottom: '15px',
								borderBottom: '1px solid #eee',
								paddingBottom: '10px',
							}}
						>
							<div
								style={{
									display: 'flex',
									justifyContent: 'space-between',
									alignItems: 'center',
									marginBottom: '10px',
								}}
							>
								<h5 style={{ margin: 0 }}>
									{__('Review Imported Data', 'thinkrank')}
								</h5>
								<span
									style={{
										fontSize: '12px',
										fontWeight: 'normal',
										color: 'var(--tr-text-secondary)',
									}}
								>
									{importedData['@type']
										? `${importedData['@type']} Schema`
										: __('Unknown Type', 'thinkrank')}
								</span>
							</div>

							{availableSchemas.length > 1 && (
								<div
									className="thinkrank-field"
									style={{ marginBottom: '0' }}
								>
									<label
										htmlFor="import-schema-selector"
										style={{
											display: 'block',
											marginBottom: '5px',
											fontSize: '12px',
											fontWeight: 600,
										}}
									>
										{__(
											'Select Schema to Import',
											'thinkrank'
										)}
										<span
											style={{
												fontWeight: 'normal',
												color: 'var(--tr-text-secondary)',
												marginLeft: '5px',
											}}
										>
											{__(
												`(Found ${availableSchemas.length} schemas)`,
												'thinkrank'
											)}
										</span>
									</label>
									<select
										id="import-schema-selector"
										className="widefat"
										value={selectedImportIndex}
										onChange={(e) => {
											const index = parseInt(
												e.target.value
											);
											setSelectedImportIndex(index);
											setImportedData(
												availableSchemas[index]
											);
										}}
									>
										{availableSchemas.map(
											(schema, index) => (
												<option
													key={index}
													value={index}
												>
													{schema['@type'] ||
														__(
															'Unknown Type',
															'thinkrank'
														)}{' '}
													{schema.name
														? `- ${schema.name}`
														: ''}
												</option>
											)
										)}
									</select>
								</div>
							)}
						</div>

						{/* Render fields for common properties */}
						{Object.entries(importedData).map(([key, value]) => {
							if (key === '@context' || key === '@type')
								return null;

							// Internal bookkeeping, not schema. _isActive was
							// editable as a text field, and committing the
							// string "false" — which is truthy — silently
							// re-activated a deactivated schema (#472).
							if (key.startsWith('_')) return null;

							const isObjectOrArray =
								typeof value === 'object' && value !== null;
							const displayValue = isObjectOrArray
								? JSON.stringify(value, null, 2)
								: value;

							return (
								<div
									key={key}
									className="thinkrank-field"
									style={{ marginBottom: '10px' }}
								>
									<span
										style={{
											display: 'block',
											fontWeight: 600,
											marginBottom: '5px',
											fontSize: '12px',
											// As a <label> this picked up color:#333 from the
											// `.thinkrank-field label` rule in metabox-tabs.scss;
											// carried inline so the span renders identically.
											color: '#333',
										}}
									>
										{key}
									</span>
									{isObjectOrArray ||
									(typeof displayValue === 'string' &&
										displayValue.length > 100) ? (
										<>
											<TextareaControl
												__nextHasNoMarginBottom
												value={
													jsonDrafts[key] !==
													undefined
														? jsonDrafts[key]
														: displayValue
												}
												onChange={(newValue) => {
													if (!isObjectOrArray) {
														commitField(
															key,
															newValue
														);
														return;
													}

													// Always keep what was
													// typed, so the field never
													// fights the user.
													setJsonDrafts((prev) => ({
														...prev,
														[key]: newValue,
													}));

													try {
														const parsed =
															JSON.parse(
																newValue
															);
														commitField(
															key,
															parsed
														);
														setJsonErrors(
															(prev) => ({
																...prev,
																[key]: '',
															})
														);
													} catch (e) {
														setJsonErrors(
															(prev) => ({
																...prev,
																[key]: __(
																	'Not valid JSON yet: this field will not be saved until it parses.',
																	'thinkrank'
																),
															})
														);
													}
												}}
												rows={4}
											/>
											{jsonErrors[key] ? (
												<span
													role="alert"
													style={{
														display: 'block',
														marginTop: '4px',
														fontSize: '11px',
														color: '#b32d2e',
													}}
												>
													{jsonErrors[key]}
												</span>
											) : null}
										</>
									) : (
										<TextControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											value={displayValue}
											onChange={(newValue) => {
												commitField(key, newValue);
											}}
										/>
									)}
								</div>
							);
						})}

						<div
							className="schema-actions"
							style={{
								marginTop: '15px',
								borderTop: '1px solid #e5e5e5',
								paddingTop: '10px',
								textAlign: 'right',
							}}
						>
							<button
								type="button"
								className="button button-primary"
								onClick={() => {
									const newSavedSchemas = [...savedSchemas];

									if (
										editingSchemaIndex !== null &&
										editingSchemaIndex >= 0 &&
										editingSchemaIndex <
											newSavedSchemas.length
									) {
										newSavedSchemas[editingSchemaIndex] =
											importedData;
										setSavedSchemas(newSavedSchemas);
										saveAdditionalSchemas(newSavedSchemas);
										showNotice?.(
											__(
												'Schema updated successfully.',
												'thinkrank'
											),
											'success'
										);
									} else {
										newSavedSchemas.push(importedData);
										setSavedSchemas(newSavedSchemas);
										saveAdditionalSchemas(newSavedSchemas);
										showNotice?.(
											__(
												'Schema saved successfully.',
												'thinkrank'
											),
											'success'
										);
									}

									setImportedData(null);
									setEditingSchemaIndex(null);
									setAvailableSchemas([]);
								}}
							>
								{editingSchemaIndex !== null
									? __('Update Saved Schema', 'thinkrank')
									: __('Save to Saved Schemas', 'thinkrank')}
							</button>
							<button
								type="button"
								className="button"
								style={{ marginLeft: '10px' }}
								onClick={() => {
									setImportedData(null);
									setEditingSchemaIndex(null);
								}}
							>
								{__('Cancel', 'thinkrank')}
							</button>
						</div>
					</div>
				)}
			</div>
		</div>
	);
};

export default ImportSection;
