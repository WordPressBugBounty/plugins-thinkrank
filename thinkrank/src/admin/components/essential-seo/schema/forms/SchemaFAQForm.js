/**
 * Schema FAQ Form Component
 *
 * Redesigned — uses plugin UI components and Tailwind classes.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Input, Textarea } from '../../../ui';

const IconPlus = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<line x1="12" y1="5" x2="12" y2="19" />
		<line x1="5" y1="12" x2="19" y2="12" />
	</svg>
);

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
		<path d="M19 6l-1 14H6L5 6" />
		<path d="M10 11v6" />
		<path d="M14 11v6" />
		<path d="M9 6V4h6v2" />
	</svg>
);

const SchemaFAQForm = ({ settings, onSettingChange, isLoading }) => {
	const disabled = !settings.enabled;
	const faqQuestions = settings.faq_questions || [];

	const addQuestion = () => {
		onSettingChange('faq_questions', [
			...faqQuestions,
			{ question: '', answer: '' },
		]);
	};

	const updateQuestion = (index, field, value) => {
		const updated = [...faqQuestions];
		updated[index] = { ...updated[index], [field]: value };
		onSettingChange('faq_questions', updated);
	};

	const removeQuestion = (index) => {
		onSettingChange(
			'faq_questions',
			faqQuestions.filter((_, i) => i !== index)
		);
	};

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{/* ── Page Information ──────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('FAQ Page Information', 'thinkrank')}
					</h3>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__('Basic details about your FAQ page', 'thinkrank')}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('Page Title', 'thinkrank')}
						value={settings.faq_page_name || ''}
						onChange={(e) =>
							onSettingChange('faq_page_name', e.target.value)
						}
						description={__('Title of your FAQ page', 'thinkrank')}
						placeholder={__(
							'e.g., Frequently Asked Questions',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Textarea
						label={__('Page Description', 'thinkrank')}
						value={settings.faq_page_description || ''}
						onChange={(e) =>
							onSettingChange(
								'faq_page_description',
								e.target.value
							)
						}
						description={__(
							'Brief description of your FAQ page',
							'thinkrank'
						)}
						placeholder={__(
							'e.g., Find answers to commonly asked questions about our products.',
							'thinkrank'
						)}
						rows={2}
						disabled={disabled}
					/>
					<Input
						label={__('Page URL', 'thinkrank')}
						type="url"
						value={settings.faq_page_url || ''}
						onChange={(e) =>
							onSettingChange('faq_page_url', e.target.value)
						}
						description={__(
							'URL of your FAQ page (optional)',
							'thinkrank'
						)}
						placeholder="https://example.com/faq"
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Questions & Answers ───────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary tr-flex tr-items-center tr-justify-between">
					<div>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Questions & Answers', 'thinkrank')}
						</h3>
						<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
							{__(
								'Each question is structured for rich snippets in search results',
								'thinkrank'
							)}
						</p>
					</div>
					<button
						onClick={addQuestion}
						disabled={disabled}
						className="tr-flex tr-items-center tr-gap-1.5 tr-text-xs tr-font-medium tr-text-brand tr-bg-brand-bg tr-border tr-border-brand tr-rounded tr-px-3 tr-py-1.5 hover:tr-bg-brand-bg tr-transition-colors tr-duration-150 tr-cursor-pointer disabled:tr-opacity-50 disabled:tr-cursor-not-allowed tr-shrink-0"
					>
						<IconPlus /> {__('Add Question', 'thinkrank')}
					</button>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-3">
					{faqQuestions.length === 0 ? (
						<div className="tr-text-center tr-py-8">
							<p className="tr-text-sm tr-text-tertiary tr-m-0">
								{__(
									'No questions added yet. Click "Add Question" to get started.',
									'thinkrank'
								)}
							</p>
						</div>
					) : (
						faqQuestions.map((faq, index) => (
							<div
								key={index}
								className="tr-border tr-border-secondary tr-rounded-lg tr-overflow-hidden"
							>
								<div className="tr-flex tr-items-center tr-justify-between tr-px-4 tr-py-2.5 tr-bg-white tr-border-b tr-border-secondary">
									<span className="tr-text-xs tr-font-semibold tr-text-secondary">
										{__('Question', 'thinkrank')}{' '}
										{index + 1}
									</span>
									<button
										onClick={() => removeQuestion(index)}
										disabled={disabled}
										className="tr-flex tr-items-center tr-gap-1 tr-text-xs tr-font-medium tr-text-danger tr-bg-danger-bg tr-border tr-border-danger tr-rounded tr-px-2 tr-py-1 hover:tr-bg-danger-bg tr-transition-colors tr-duration-150 tr-cursor-pointer disabled:tr-opacity-50 disabled:tr-cursor-not-allowed"
									>
										<IconTrash />{' '}
										{__('Remove', 'thinkrank')}
									</button>
								</div>
								<div className="tr-p-4 tr-flex tr-flex-col tr-gap-3">
									<Input
										label={__('Question', 'thinkrank')}
										value={faq.question || ''}
										onChange={(e) =>
											updateQuestion(
												index,
												'question',
												e.target.value
											)
										}
										placeholder={__(
											'Enter the frequently asked question…',
											'thinkrank'
										)}
										disabled={disabled}
									/>
									<Textarea
										label={__('Answer', 'thinkrank')}
										value={faq.answer || ''}
										onChange={(e) =>
											updateQuestion(
												index,
												'answer',
												e.target.value
											)
										}
										placeholder={__(
											'Enter the detailed answer…',
											'thinkrank'
										)}
										rows={3}
										disabled={disabled}
									/>
								</div>
							</div>
						))
					)}
				</div>
			</Card>
		</div>
	);
};

export default SchemaFAQForm;
