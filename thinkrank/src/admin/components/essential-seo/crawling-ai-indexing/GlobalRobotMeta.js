/**
 * Global Robot Meta Component
 *
 * Settings for global robots meta tags.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Card,
	CardBody,
	CheckboxControl,
	Button,
	Flex,
	Tooltip,
} from '@wordpress/components';
import SettingsCard from '../../common/SettingsCard';
import { SkeletonCard } from '../../ui';

/**
 * Global Robot Meta Component
 */
import apiFetch from '@wordpress/api-fetch';
import logger from '../../../../shared/utils/logger';

/**
 * Global Robot Meta Component
 */
const GlobalRobotMeta = () => {
	const [isLoading, setIsLoading] = useState(true);
	const [isSaving, setIsSaving] = useState(false);
	const [hasChanges, setHasChanges] = useState(false);
	const [notice, setNotice] = useState(null);

	// State for checkboxes
	const [robotsMeta, setRobotsMeta] = useState({
		index: true,
		noindex: false,
		nofollow: false,
		noarchive: false,
		noimageindex: false,
		nosnippet: false,
	});

	useEffect(() => {
		loadSettings();
	}, []);

	const loadSettings = async () => {
		setIsLoading(true);
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/global-robot-meta/settings',
				method: 'GET',
			});

			if (response && response.settings) {
				setRobotsMeta((prev) => ({
					...prev,
					...response.settings,
				}));
			}
		} catch (error) {
			logger.error('Failed to load global robot meta settings:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to load settings. Using defaults.',
					'thinkrank'
				),
			});
		} finally {
			setIsLoading(false);
		}
	};

	const handleChange = (key, value) => {
		setRobotsMeta((prev) => {
			const newSettings = {
				...prev,
				[key]: value,
			};

			// index and noindex are two views of one decision, so unticking
			// either is the same statement as ticking the other. Only ever
			// CLEARING the opposite flag left {index:false, noindex:false},
			// which the emitter reads as "indexable" — so unticking Index did
			// nothing at all (#559).
			if (key === 'index') {
				newSettings.noindex = !value;
			} else if (key === 'noindex') {
				newSettings.index = !value;
			}

			return newSettings;
		});
		setHasChanges(true);
	};

	const saveSettings = async () => {
		setIsSaving(true);
		setNotice(null);

		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/global-robot-meta/settings',
				method: 'POST',
				data: {
					settings: robotsMeta,
				},
			});

			setNotice({
				status: 'success',
				message: __(
					'Global Robot Meta settings saved successfully!',
					'thinkrank'
				),
			});
			setHasChanges(false);
		} catch (error) {
			logger.error('Failed to save global robot meta settings:', error);
			setNotice({
				status: 'error',
				message:
					error.message ||
					__('Failed to save settings.', 'thinkrank'),
			});
		} finally {
			setIsSaving(false);
		}
	};

	// Help icon component
	const HelpIcon = ({ text }) => (
		<Tooltip text={text}>
			<span
				style={{
					display: 'inline-flex',
					alignItems: 'center',
					justifyContent: 'center',
					width: '16px',
					height: '16px',
					borderRadius: '50%',
					background: '#ddd',
					color: 'var(--tr-text-secondary)',
					fontSize: '10px',
					fontWeight: 'bold',
					marginLeft: '4px',
					cursor: 'help',
				}}
			>
				?
			</span>
		</Tooltip>
	);

	if (isLoading) {
		return (
			<div className="thinkrank-global-robot-meta">
				<SkeletonCard
					lines={4}
					label={__('Loading settings…', 'thinkrank')}
				/>
			</div>
		);
	}

	return (
		<div className="thinkrank-global-robot-meta">
			<SettingsCard
				title={__('Global Robot Meta', 'thinkrank')}
				enabled={true}
				showDisabledWarning={false}
				notice={notice}
				onNoticeRemove={() => setNotice(null)}
			>
				<div
					style={{
						display: 'flex',
						flexDirection: 'row',
						alignItems: 'flex-start',
						padding: '10px 0',
					}}
				>
					{/* Left Label */}
					<div
						style={{
							width: '200px',
							flexShrink: 0,
							fontWeight: '600',
							fontSize: '14px',
							marginTop: '8px',
						}}
					>
						{__('Robots Meta', 'thinkrank')}
					</div>

					{/* Right Content */}
					<div style={{ flex: 1 }}>
						<div
							style={{
								display: 'grid',
								gridTemplateColumns: '1fr 1fr',
								gap: '10px',
							}}
						>
							{/* Column 1 */}
							<div>
								<Flex
									justify="initial"
									align="center"
									className="thinkrank-mb-sm"
								>
									<CheckboxControl
										label={__('Index', 'thinkrank')}
										checked={robotsMeta.index}
										onChange={(val) =>
											handleChange('index', val)
										}
										__nextHasNoMarginBottom
									/>
									<HelpIcon
										text={__(
											'Allow search engines to index this content.',
											'thinkrank'
										)}
									/>
								</Flex>

								<Flex
									justify="initial"
									align="center"
									className="thinkrank-mb-sm"
								>
									<CheckboxControl
										label={__('No Follow', 'thinkrank')}
										checked={robotsMeta.nofollow}
										onChange={(val) =>
											handleChange('nofollow', val)
										}
										__nextHasNoMarginBottom
									/>
									<HelpIcon
										text={__(
											'Do not follow links on this page.',
											'thinkrank'
										)}
									/>
								</Flex>

								<Flex
									justify="initial"
									align="center"
									className="thinkrank-mb-sm"
								>
									<CheckboxControl
										label={__(
											'No Image Index',
											'thinkrank'
										)}
										checked={robotsMeta.noimageindex}
										onChange={(val) =>
											handleChange('noimageindex', val)
										}
										__nextHasNoMarginBottom
									/>
									<HelpIcon
										text={__(
											'Do not index images on this page.',
											'thinkrank'
										)}
									/>
								</Flex>
							</div>

							{/* Column 2 */}
							<div>
								<Flex
									justify="initial"
									align="center"
									className="thinkrank-mb-sm"
								>
									<CheckboxControl
										label={__('No Index', 'thinkrank')}
										checked={robotsMeta.noindex}
										onChange={(val) =>
											handleChange('noindex', val)
										}
										__nextHasNoMarginBottom
									/>
									<HelpIcon
										text={__(
											'Do not allow search engines to index this content.',
											'thinkrank'
										)}
									/>
								</Flex>

								<Flex
									justify="initial"
									align="center"
									className="thinkrank-mb-sm"
								>
									<CheckboxControl
										label={__('No Archive', 'thinkrank')}
										checked={robotsMeta.noarchive}
										onChange={(val) =>
											handleChange('noarchive', val)
										}
										__nextHasNoMarginBottom
									/>
									<HelpIcon
										text={__(
											'Do not show a cached link in search results.',
											'thinkrank'
										)}
									/>
								</Flex>

								<Flex
									justify="initial"
									align="center"
									className="thinkrank-mb-sm"
								>
									<CheckboxControl
										label={__('No Snippet', 'thinkrank')}
										checked={robotsMeta.nosnippet}
										onChange={(val) =>
											handleChange('nosnippet', val)
										}
										__nextHasNoMarginBottom
									/>
									<HelpIcon
										text={__(
											'Do not show a snippet in search results.',
											'thinkrank'
										)}
									/>
								</Flex>
							</div>
						</div>

						<p
							className="description"
							style={{
								marginTop: '15px',
								color: 'var(--tr-text-secondary)',
								fontSize: '13px',
							}}
						>
							{__(
								'Default values for robots meta tag. These can be changed for individual posts, taxonomies, etc.',
								'thinkrank'
							)}
						</p>
					</div>
				</div>

				{/* Save Button */}
				<Flex justify="flex-end" className="thinkrank-mt-lg">
					<Button
						variant="primary"
						onClick={saveSettings}
						isBusy={isSaving}
						disabled={!hasChanges || isSaving}
					>
						{isSaving
							? __('Saving…', 'thinkrank')
							: __('Save Changes', 'thinkrank')}
					</Button>
				</Flex>
			</SettingsCard>
		</div>
	);
};

export default GlobalRobotMeta;
