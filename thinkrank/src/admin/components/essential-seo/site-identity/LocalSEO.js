/**
 * Local SEO Component
 *
 * Handles local business SEO settings for Site Identity
 * Provides comprehensive local business information management
 *
 * @package
 * @since 1.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import {
	Card,
	Input,
	Textarea,
	Select,
	SearchableSelect,
	Toggle,
	Container,
} from '../../ui';
import SectionHeader from '../../common/SectionHeader';
import FormField from '../../common/FormField';
import ProTeaser from '../../pro/ProTeaser';
import ProLicenseNotice from '../../pro/ProLicenseNotice';
import { LocalSeoIllustration } from '../../pro/pro-teaser-illustrations';
import { isProUnlicensed } from '../../pro/proPlan';

/**
 * Multi-location manager slot. Pro fills `thinkrank.localSeo.locationsTab` with
 * the locations manager; otherwise free users see a locked upsell teaser.
 */
const MultiLocation = () => {
	// Plan before filter. Pro's admin bundle registers this filter whether or
	// not the licence is valid, so consulting it first rendered the locations
	// manager on a site whose Pro routes were never registered (#533). The
	// spacing wrapper stays on the teaser only — the Pro panel was always
	// returned bare.
	if (window.thinkrankAdmin?.isPro) {
		return applyFilters('thinkrank.localSeo.locationsTab', null) ?? null;
	}

	// Pro is here but unlicensed: warn about the licence rather than upselling
	// a plugin this site already has.
	if (isProUnlicensed()) {
		return (
			<div style={{ marginTop: '16px' }}>
				<ProLicenseNotice
					feature={__('Multiple Business Locations', 'thinkrank')}
				/>
			</div>
		);
	}

	return (
		<div style={{ marginTop: '16px' }}>
			<ProTeaser
				illustration={<LocalSeoIllustration />}
				title={__('Multiple Business Locations', 'thinkrank')}
				description={__(
					'Run more than one storefront or office? Give each location its own SEO so customers and Google find the right branch every time.',
					'thinkrank'
				)}
				features={[
					__(
						'Add unlimited locations with their own address, hours, and geo',
						'thinkrank'
					),
					__(
						'Output LocalBusiness schema for every location automatically',
						'thinkrank'
					),
					__(
						'Display them anywhere with the [thinkrank_locations] shortcode',
						'thinkrank'
					),
				]}
			/>
		</div>
	);
};

/**
 * Get business type options
 */
const FALLBACK_BUSINESS_TYPES = [
	{ label: 'Local Business', value: 'LocalBusiness' },
	{ label: 'Restaurant', value: 'Restaurant' },
	{ label: 'Store', value: 'Store' },
	{ label: 'Professional Service', value: 'ProfessionalService' },
];

/**
 * schema.org LocalBusiness subtypes, localized from PHP.
 *
 * The control used to offer five hardcoded types, so a business that was none
 * of them — the report was about HealthAndBeautyBusiness — simply could not be
 * described (#623). The list is ~150 entries and is also the MCP ability's
 * enum, so it lives in PHP (`Local_Business_Types_Config`) and is handed over
 * at localize time rather than being mirrored here, where a second copy would
 * be a second thing to keep in step.
 *
 * The fallback covers a page rendered before the global is available; it is
 * deliberately short, since it is a stopgap rather than a second source.
 *
 * Labels are the schema.org names and are not translated: the stored value IS
 * the label minus spacing, so a translated label would have a non-English site
 * searching an English list for a term it was shown translated.
 *
 * A stored value that is not in the list (a type an older importer wrote
 * verbatim, such as Rank Math's `Organization`) is appended as its own option.
 * Without it the control matched nothing and rendered blank, so the site
 * looked unconfigured while it was in fact publishing Local Business. The
 * label says so, since the next save stores Local Business in its place.
 *
 * @param {string} current The stored business type.
 * @return {Array<{label: string, value: string}>} Options for the control.
 */
export const getBusinessTypeOptions = (current = '') => {
	const localized = window.thinkrankAdmin?.localBusinessTypes;

	const isFullList = Array.isArray(localized) && localized.length > 0;
	const options = isFullList
		? localized.map(({ value, label }) => ({ value, label }))
		: FALLBACK_BUSINESS_TYPES;

	if (!current || options.some(({ value }) => value === current)) {
		return options;
	}

	// The short fallback cannot tell a legacy value from a valid type it
	// simply does not carry, so it shows the value as is.
	if (!isFullList) {
		return [...options, { value: current, label: current }];
	}

	return [
		...options,
		{
			value: current,
			label: sprintf(
				/* translators: %s: a stored business type that is not a schema.org LocalBusiness type, e.g. Organization. */
				__(
					'%s (not a local business type, published as Local Business)',
					'thinkrank'
				),
				current
			),
		},
	];
};

/**
 * Local SEO Component
 * @param root0
 * @param root0.settings
 * @param root0.handleSettingChange
 * @param root0.onPersistSetting    Autosave one key immediately (feature switches).
 * @param root0.isPersistingSetting True while such an autosave is in flight.
 */
const LocalSEO = ({
	settings,
	handleSettingChange,
	onPersistSetting,
	isPersistingSetting = false,
}) => {
	const disabled = !settings.enabled;

	/**
	 * Local SEO on/off is a feature switch, so flipping it is the save. The
	 * parent owns the settings object and the autosave; fall back to the plain
	 * (Save-button) path if it wasn't given one.
	 * @param {boolean} value New local_seo_enabled value.
	 */
	const toggleLocalSeo = (value) => {
		if (!onPersistSetting) {
			handleSettingChange('local_seo_enabled', value);
			return;
		}

		onPersistSetting(
			'local_seo_enabled',
			value,
			value
				? __('Local SEO is on.', 'thinkrank')
				: __('Local SEO is off.', 'thinkrank')
		);
	};

	return (
		<Container direction="column" gap={4}>
			<Card padding={false}>
				<SectionHeader
					title={__('Local SEO Settings', 'thinkrank')}
					description={__(
						'Enable local business SEO features and configure business details.',
						'thinkrank'
					)}
					withBorder={settings.local_seo_enabled}
					trailing={
						<Toggle
							label={
								isPersistingSetting
									? __('Saving…', 'thinkrank')
									: __('Enable local SEO', 'thinkrank')
							}
							checked={settings.local_seo_enabled}
							onChange={toggleLocalSeo}
							disabled={disabled || isPersistingSetting}
						/>
					}
				/>

				{settings.local_seo_enabled && (
					<Container
						direction="column"
						gap={6}
						className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
					>
						<FormField
							id="tr-ls-business-type"
							label={__('Business Type', 'thinkrank')}
							description={__(
								'Type of business for schema markup',
								'thinkrank'
							)}
							disabled={disabled}
						>
							<SearchableSelect
								id="tr-ls-business-type"
								value={
									settings.business_type || 'LocalBusiness'
								}
								options={getBusinessTypeOptions(
									settings.business_type
								)}
								onChange={(next) =>
									handleSettingChange(
										'business_type',
										next || 'LocalBusiness'
									)
								}
								placeholder={__(
									'Search business types…',
									'thinkrank'
								)}
								clearable={false}
								disabled={disabled}
							/>
						</FormField>

						<FormField
							id="tr-ls-business-name"
							label={__('Business Name', 'thinkrank')}
							description={__(
								'Official name of your business',
								'thinkrank'
							)}
							disabled={disabled}
							className="tr-pt-6"
						>
							<Input
								id="tr-ls-business-name"
								value={settings.business_name || ''}
								onChange={(e) =>
									handleSettingChange(
										'business_name',
										e.target.value
									)
								}
								placeholder={__(
									'e.g., Acme Coffee Roasters',
									'thinkrank'
								)}
								disabled={disabled}
							/>
						</FormField>

						<FormField
							id="tr-ls-business-address"
							label={__('Street Address', 'thinkrank')}
							description={__(
								'Street address (e.g., 123 Main Street)',
								'thinkrank'
							)}
							disabled={disabled}
							className="tr-pt-6"
						>
							<Textarea
								id="tr-ls-business-address"
								value={settings.business_address || ''}
								onChange={(e) =>
									handleSettingChange(
										'business_address',
										e.target.value
									)
								}
								placeholder={__(
									'e.g., 123 Main Street\nSuite 200',
									'thinkrank'
								)}
								rows={2}
								disabled={disabled}
							/>
						</FormField>

						<div className="tr-grid tr-grid-cols-2 tr-gap-4 tr-pt-6">
							<FormField
								id="tr-ls-business-city"
								label={__('City', 'thinkrank')}
								description={__('City name', 'thinkrank')}
								disabled={disabled}
							>
								<Input
									id="tr-ls-business-city"
									value={settings.business_city || ''}
									onChange={(e) =>
										handleSettingChange(
											'business_city',
											e.target.value
										)
									}
									placeholder={__(
										'e.g., San Francisco',
										'thinkrank'
									)}
									disabled={disabled}
								/>
							</FormField>

							<FormField
								id="tr-ls-business-state"
								label={__('State/Province', 'thinkrank')}
								description={__(
									'State or province',
									'thinkrank'
								)}
								disabled={disabled}
							>
								<Input
									id="tr-ls-business-state"
									value={settings.business_state || ''}
									onChange={(e) =>
										handleSettingChange(
											'business_state',
											e.target.value
										)
									}
									placeholder={__(
										'e.g., California',
										'thinkrank'
									)}
									disabled={disabled}
								/>
							</FormField>
						</div>

						<div className="tr-grid tr-grid-cols-2 tr-gap-4 tr-pt-6">
							<FormField
								id="tr-ls-business-postal"
								label={__('Postal Code', 'thinkrank')}
								description={__(
									'ZIP or postal code',
									'thinkrank'
								)}
								disabled={disabled}
							>
								<Input
									id="tr-ls-business-postal"
									value={settings.business_postal_code || ''}
									onChange={(e) =>
										handleSettingChange(
											'business_postal_code',
											e.target.value
										)
									}
									placeholder={__('e.g., 94103', 'thinkrank')}
									disabled={disabled}
								/>
							</FormField>

							<FormField
								id="tr-ls-business-country"
								label={__('Country', 'thinkrank')}
								description={__('Country name', 'thinkrank')}
								disabled={disabled}
							>
								<Input
									id="tr-ls-business-country"
									value={settings.business_country || ''}
									onChange={(e) =>
										handleSettingChange(
											'business_country',
											e.target.value
										)
									}
									placeholder={__(
										'e.g., United States',
										'thinkrank'
									)}
									disabled={disabled}
								/>
							</FormField>
						</div>

						<FormField
							id="tr-ls-business-phone"
							label={__('Business Phone', 'thinkrank')}
							description={__(
								'Primary business phone number',
								'thinkrank'
							)}
							disabled={disabled}
							className="tr-pt-6"
						>
							<Input
								id="tr-ls-business-phone"
								value={settings.business_phone || ''}
								onChange={(e) =>
									handleSettingChange(
										'business_phone',
										e.target.value
									)
								}
								placeholder={__(
									'e.g., +1 555-123-4567',
									'thinkrank'
								)}
								disabled={disabled}
							/>
						</FormField>

						<FormField
							id="tr-ls-business-email"
							label={__('Business Email', 'thinkrank')}
							description={__(
								'Business email address for contact information',
								'thinkrank'
							)}
							disabled={disabled}
							className="tr-pt-6"
						>
							<Input
								id="tr-ls-business-email"
								value={settings.business_email || ''}
								onChange={(e) =>
									handleSettingChange(
										'business_email',
										e.target.value
									)
								}
								type="email"
								placeholder={__(
									'e.g., hello@example.com',
									'thinkrank'
								)}
								disabled={disabled}
							/>
						</FormField>

						<div className="tr-grid tr-grid-cols-2 tr-gap-4 tr-pt-6">
							<FormField
								id="tr-ls-business-lat"
								label={__('Latitude', 'thinkrank')}
								description={__(
									'Latitude coordinate (e.g., 37.7749)',
									'thinkrank'
								)}
								disabled={disabled}
							>
								<Input
									id="tr-ls-business-lat"
									value={settings.business_latitude || ''}
									onChange={(e) =>
										handleSettingChange(
											'business_latitude',
											e.target.value
										)
									}
									type="number"
									step="any"
									placeholder="e.g., 37.7749"
									disabled={disabled}
								/>
							</FormField>

							<FormField
								id="tr-ls-business-lng"
								label={__('Longitude', 'thinkrank')}
								description={__(
									'Longitude coordinate (e.g., -122.4194)',
									'thinkrank'
								)}
								disabled={disabled}
							>
								<Input
									id="tr-ls-business-lng"
									value={settings.business_longitude || ''}
									onChange={(e) =>
										handleSettingChange(
											'business_longitude',
											e.target.value
										)
									}
									type="number"
									step="any"
									placeholder="e.g., -122.4194"
									disabled={disabled}
								/>
							</FormField>
						</div>

						<FormField
							id="tr-ls-price-range"
							label={__('Price Range', 'thinkrank')}
							description={__(
								'Relative price range for your business (used in schema markup)',
								'thinkrank'
							)}
							disabled={disabled}
							className="tr-pt-6"
						>
							<Select
								id="tr-ls-price-range"
								value={settings.business_price_range || ''}
								options={[
									{
										label: __(
											'Select price range…',
											'thinkrank'
										),
										value: '',
									},
									{
										label: __(
											'$ (Inexpensive)',
											'thinkrank'
										),
										value: '$',
									},
									{
										label: __('$$ (Moderate)', 'thinkrank'),
										value: '$$',
									},
									{
										label: __(
											'$$$ (Expensive)',
											'thinkrank'
										),
										value: '$$$',
									},
									{
										label: __(
											'$$$$ (Very Expensive)',
											'thinkrank'
										),
										value: '$$$$',
									},
								]}
								onChange={(e) =>
									handleSettingChange(
										'business_price_range',
										e.target.value
									)
								}
								disabled={disabled}
							/>
						</FormField>

						<div className="tr-pt-6">
							<div className="tr-mb-4">
								<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0 tr-mb-1">
									{__('Business Hours', 'thinkrank')}
								</h4>
								<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mb-4">
									{__(
										'Set your business operating hours for each day of the week',
										'thinkrank'
									)}
								</p>
							</div>

							{(() => {
								// Ensure business_hours is properly structured
								const defaultHours = {
									monday: {
										open: '09:00',
										close: '17:00',
										closed: true,
									},
									tuesday: {
										open: '09:00',
										close: '17:00',
										closed: true,
									},
									wednesday: {
										open: '09:00',
										close: '17:00',
										closed: true,
									},
									thursday: {
										open: '09:00',
										close: '17:00',
										closed: true,
									},
									friday: {
										open: '09:00',
										close: '17:00',
										closed: true,
									},
									saturday: {
										open: '10:00',
										close: '16:00',
										closed: true,
									},
									sunday: {
										open: '09:00',
										close: '17:00',
										closed: true,
									},
								};

								const businessHours =
									settings.business_hours &&
									typeof settings.business_hours === 'object'
										? {
												...defaultHours,
												...settings.business_hours,
											}
										: defaultHours;

								const updateBusinessHours = (
									day,
									field,
									value
								) => {
									const currentHours =
										settings.business_hours &&
										typeof settings.business_hours ===
											'object'
											? {
													...defaultHours,
													...settings.business_hours,
												}
											: defaultHours;
									const newHours = {
										...currentHours,
										[day]: {
											...(currentHours[day] ||
												defaultHours[day]),
											[field]: value,
										},
									};
									handleSettingChange(
										'business_hours',
										newHours
									);
								};

								return Object.entries(businessHours).map(
									([day, hours]) => (
										<div
											key={day}
											className="tr-flex tr-items-center tr-gap-4 tr-mb-3"
										>
											<div
												className="tr-flex tr-items-center tr-shrink-0"
												style={{ width: '140px' }}
											>
												<Toggle
													id={`business-hours-${day}`}
													label={
														day
															.charAt(0)
															.toUpperCase() +
														day.slice(1)
													}
													checked={!hours?.closed}
													onChange={(open) =>
														updateBusinessHours(
															day,
															'closed',
															!open
														)
													}
													disabled={!settings.enabled}
												/>
											</div>

											<div className="tr-flex tr-flex-1 tr-gap-3">
												{!hours?.closed ? (
													<>
														<div className="tr-flex tr-flex-1 tr-items-center tr-h-11 tr-border tr-border-primary tr-rounded tr-px-4 tr-bg-white">
															<span className="tr-text-sm tr-text-tertiary tr-mr-auto">
																{__(
																	'From',
																	'thinkrank'
																)}
															</span>
															<input
																type="time"
																value={
																	hours?.open ||
																	'09:00'
																}
																onChange={(e) =>
																	updateBusinessHours(
																		day,
																		'open',
																		e.target
																			.value
																	)
																}
																disabled={
																	!settings.enabled
																}
																style={{
																	border: 'none',
																	background:
																		'transparent',
																	boxShadow:
																		'none',
																	padding:
																		'0',
																	margin: '0',
																	color: 'var(--tr-text-heading)',
																	fontWeight:
																		'500',
																	fontSize:
																		'14px',
																	outline:
																		'none',
																	textAlign:
																		'right',
																	cursor: 'pointer',
																}}
															/>
														</div>
														<div className="tr-flex tr-flex-1 tr-items-center tr-h-11 tr-border tr-border-primary tr-rounded tr-px-4 tr-bg-white">
															<span className="tr-text-sm tr-text-tertiary tr-mr-auto">
																{__(
																	'To',
																	'thinkrank'
																)}
															</span>
															<input
																type="time"
																value={
																	hours?.close ||
																	'17:00'
																}
																onChange={(e) =>
																	updateBusinessHours(
																		day,
																		'close',
																		e.target
																			.value
																	)
																}
																disabled={
																	!settings.enabled
																}
																style={{
																	border: 'none',
																	background:
																		'transparent',
																	boxShadow:
																		'none',
																	padding:
																		'0',
																	margin: '0',
																	color: 'var(--tr-text-heading)',
																	fontWeight:
																		'500',
																	fontSize:
																		'14px',
																	outline:
																		'none',
																	textAlign:
																		'right',
																	cursor: 'pointer',
																}}
															/>
														</div>
													</>
												) : (
													<div className="tr-flex tr-flex-1 tr-items-center tr-h-11 tr-bg-white tr-rounded tr-px-4 tr-gap-2">
														<svg
															width="16"
															height="16"
															viewBox="0 0 24 24"
															fill="none"
															stroke="#8c9bb7"
															strokeWidth="2"
															strokeLinecap="round"
															strokeLinejoin="round"
														>
															<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
														</svg>
														<span className="tr-text-sm tr-text-tertiary">
															{__(
																'Closed',
																'thinkrank'
															)}
														</span>
													</div>
												)}
											</div>
										</div>
									)
								);
							})()}
						</div>
					</Container>
				)}
			</Card>

			<MultiLocation />
		</Container>
	);
};

export default LocalSEO;
