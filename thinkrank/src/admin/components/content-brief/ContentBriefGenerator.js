/**
 * Content Brief Generator Component
 *
 * Main interface for generating AI-powered content briefs
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import apiFetch from '@wordpress/api-fetch';
import {
	Alert,
	Select,
	Button,
	Card,
	CardHeader,
	CardDivider,
	EmptyState,
} from '../ui';
import { Textarea } from '../ui/Input';

import KeywordInput from './KeywordInput';
import ContentStructure from './ContentStructure';
import ContentBriefInsertTeaser from './ContentBriefInsertTeaser';
import ProLicenseNotice from '../pro/ProLicenseNotice';
import { isProUnlicensed } from '../pro/proPlan';
import ModelBadge from '../shared/ModelBadge';
import ConfirmationDialog from '../import-export/ConfirmationDialog';
import logger from '../../../shared/utils/logger';
import notify from '../../utils/notify';
import linkifyMessage from '../../../shared/utils/linkifyMessage';

const ContentBriefGenerator = () => {
	// Form state
	const [formData, setFormData] = useState({
		target_keywords: [''],
		content_type: 'blog_post',
		target_audience: 'general',
		content_length: 'medium',
		tone: 'professional',
		competitor_urls: [''],
		additional_context: '',
	});

	// UI state
	const [isGenerating, setIsGenerating] = useState(false);
	const [generatedBrief, setGeneratedBrief] = useState(null);
	const [error, setError] = useState(null);
	const [savedBriefs, setSavedBriefs] = useState([]);
	const [copied, setCopied] = useState(false);
	const [briefPendingDelete, setBriefPendingDelete] = useState(null);

	const aiConfigured = Boolean(window.thinkrankAdmin?.aiConfigured);

	// Content type options
	const contentTypeOptions = [
		{ label: __('Blog Post', 'thinkrank'), value: 'blog_post' },
		{ label: __('Product Page', 'thinkrank'), value: 'product_page' },
		{ label: __('Landing Page', 'thinkrank'), value: 'landing_page' },
		{ label: __('Tutorial', 'thinkrank'), value: 'tutorial' },
	];

	// Target audience options
	const audienceOptions = [
		{ label: __('General Audience', 'thinkrank'), value: 'general' },
		{ label: __('Beginners', 'thinkrank'), value: 'beginners' },
		{ label: __('Professionals', 'thinkrank'), value: 'professionals' },
		{ label: __('Experts', 'thinkrank'), value: 'experts' },
	];

	// Content length options
	const lengthOptions = [
		{ label: __('Short (500-800 words)', 'thinkrank'), value: 'short' },
		{ label: __('Medium (1000-1500 words)', 'thinkrank'), value: 'medium' },
		{ label: __('Long (2000+ words)', 'thinkrank'), value: 'long' },
	];

	// Tone options
	const toneOptions = [
		{ label: __('Professional', 'thinkrank'), value: 'professional' },
		{ label: __('Casual', 'thinkrank'), value: 'casual' },
		{ label: __('Technical', 'thinkrank'), value: 'technical' },
		{ label: __('Friendly', 'thinkrank'), value: 'friendly' },
	];

	// Load saved briefs on component mount
	useEffect(() => {
		loadSavedBriefs();
	}, []);

	/**
	 * Load saved briefs from API
	 */
	const loadSavedBriefs = async () => {
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/content-brief/list',
				method: 'GET',
			});

			if (response.success) {
				setSavedBriefs(response.data);
			}
		} catch (error) {
			logger.error('Failed to load saved briefs:', error);
		}
	};

	/**
	 * Handle form field changes
	 * @param field
	 * @param value
	 */
	const handleFieldChange = (field, value) => {
		setFormData((prev) => ({
			...prev,
			[field]: value,
		}));
	};

	/**
	 * Handle keyword changes
	 * @param keywords
	 */
	const handleKeywordsChange = (keywords) => {
		setFormData((prev) => ({
			...prev,
			target_keywords: keywords,
		}));
	};

	/**
	 * Handle competitor URL changes
	 * @param urls
	 */
	const handleCompetitorUrlsChange = (urls) => {
		setFormData((prev) => ({
			...prev,
			competitor_urls: urls,
		}));
	};

	/**
	 * Validate form data
	 */
	const validateForm = () => {
		const errors = [];

		// Check if at least one keyword is provided
		const validKeywords = formData.target_keywords.filter(
			(keyword) => keyword.trim() !== ''
		);
		if (validKeywords.length === 0) {
			errors.push(
				__('At least one target keyword is required.', 'thinkrank')
			);
		}

		// Validate competitor URLs if provided
		const validUrls = formData.competitor_urls.filter(
			(url) => url.trim() !== ''
		);
		for (const url of validUrls) {
			try {
				new URL(url);
			} catch {
				errors.push(__(`Invalid URL: ${url}`, 'thinkrank'));
			}
		}

		return errors;
	};

	/**
	 * Generate content brief
	 */
	const generateBrief = async () => {
		setError(null);

		// Validate form
		const validationErrors = validateForm();
		if (validationErrors.length > 0) {
			setError(validationErrors.join(' '));
			return;
		}

		setIsGenerating(true);

		try {
			// Clean up form data
			const cleanedData = {
				...formData,
				target_keywords: formData.target_keywords.filter(
					(keyword) => keyword.trim() !== ''
				),
				competitor_urls: formData.competitor_urls.filter(
					(url) => url.trim() !== ''
				),
			};

			const response = await apiFetch({
				path: '/thinkrank/v1/content-brief/generate',
				method: 'POST',
				data: cleanedData,
			});

			if (response.success) {
				setGeneratedBrief(response.data);
				loadSavedBriefs(); // Refresh saved briefs list

				// Scroll to the generated content area after a brief delay to ensure content is rendered
				setTimeout(() => {
					const contentSection = document.getElementById(
						'generated-content-section'
					);
					if (contentSection) {
						contentSection.scrollIntoView({
							behavior: 'smooth',
							block: 'start',
						});
					}
				}, 200);
			} else {
				setError(
					response.message ||
						__('Failed to generate content brief.', 'thinkrank')
				);
			}
		} catch (error) {
			// Handle API configuration errors gracefully without console spam
			if (
				error.message &&
				error.message.includes('Please configure your AI provider')
			) {
				setError(error.message);
			} else {
				// Only log unexpected errors to console
				logger.error('Brief generation error:', error);
				setError(
					error.message ||
						__(
							'An error occurred while generating the content brief.',
							'thinkrank'
						)
				);
			}
		} finally {
			setIsGenerating(false);
		}
	};

	/**
	 * Delete a saved brief (called from the confirmation dialog)
	 * @param briefId
	 */
	const deleteSavedBrief = async (briefId) => {
		setBriefPendingDelete(null);

		try {
			const response = await apiFetch({
				path: `/thinkrank/v1/content-brief/${briefId}`,
				method: 'DELETE',
			});

			if (response.success) {
				// Remove the brief from the local state
				setSavedBriefs(
					savedBriefs.filter((brief) => brief.id !== briefId)
				);

				// If the deleted brief is currently displayed, clear it
				if (generatedBrief && generatedBrief.id === briefId) {
					setGeneratedBrief(null);
				}
			}
		} catch (error) {
			logger.error('Failed to delete brief:', error);
			setError(
				__('Failed to delete brief. Please try again.', 'thinkrank')
			);
		}
	};

	/**
	 * Clear current brief
	 */
	const clearBrief = () => {
		setGeneratedBrief(null);
		setError(null);
	};

	/**
	 * Copy text to clipboard with fallback (same as ContentStructure.js)
	 * @param text
	 */
	const copyToClipboard = async (text) => {
		const showCopiedFeedback = () => {
			setCopied(true);
			setTimeout(() => setCopied(false), 2000);
		};

		try {
			// Try modern clipboard API first
			if (navigator.clipboard && navigator.clipboard.writeText) {
				await navigator.clipboard.writeText(text);
				showCopiedFeedback();
				return;
			}

			// Fallback for older browsers or non-HTTPS contexts
			const textArea = document.createElement('textarea');
			textArea.value = text;
			textArea.style.position = 'fixed';
			textArea.style.left = '-999999px';
			textArea.style.top = '-999999px';
			document.body.appendChild(textArea);
			textArea.focus();
			textArea.select();

			try {
				document.execCommand('copy');
				showCopiedFeedback();
			} catch (err) {
				logger.error('Failed to copy text: ', err);
			}

			document.body.removeChild(textArea);
		} catch (err) {
			logger.error('Copy to clipboard failed: ', err);
		}
	};

	/**
	 * Format brief data as plain text for export
	 * @param data
	 */
	const formatBriefAsText = (data) => {
		let text = '';

		// Header
		if (data.title && Array.isArray(data.title) && data.title[0]) {
			text += `Content Brief: "${data.title[0]}"\n`;
			text += '='.repeat(60) + '\n\n';
		} else if (data.title && typeof data.title === 'string') {
			text += `Content Brief: "${data.title}"\n`;
			text += '='.repeat(60) + '\n\n';
		}

		// Generation parameters
		if (data.generation_params) {
			const params = data.generation_params;
			text += 'BRIEF CONFIGURATION\n';
			text += `-`.repeat(30) + '\n';
			text += `Target Keywords: ${params.target_keywords?.join(', ') || 'N/A'}\n`;
			text += `Content Type: ${params.content_type || 'N/A'}\n`;
			text += `Target Audience: ${params.target_audience || 'N/A'}\n`;
			text += `Content Length: ${params.content_length || 'N/A'}\n`;
			text += `Tone: ${params.tone || 'N/A'}\n`;
			if (params.competitor_urls && params.competitor_urls.length > 0) {
				text += `Competitor URLs: ${params.competitor_urls.join(', ')}\n`;
			}
			if (params.additional_context) {
				text += `Additional Context: ${params.additional_context}\n`;
			}
			text += '\n';
		}

		// Title suggestions
		if (data.title && Array.isArray(data.title) && data.title.length > 1) {
			text += 'TITLE SUGGESTIONS\n';
			text += `-`.repeat(30) + '\n';
			data.title.forEach((title, index) => {
				text += `${index + 1}. ${title}\n`;
			});
			text += '\n';
		}

		// Content outline
		if (
			data.outline &&
			Array.isArray(data.outline) &&
			data.outline.length > 0
		) {
			text += 'CONTENT OUTLINE\n';
			text += `-`.repeat(30) + '\n';
			data.outline.forEach((item, index) => {
				const indent = '  '.repeat((item.level || 1) - 1);
				text += `${indent}${index + 1}. ${item.heading || item}`;
				if (item.word_count && item.word_count > 0) {
					text += ` (${item.word_count} words)`;
				}
				text += '\n';

				if (
					item.key_points &&
					Array.isArray(item.key_points) &&
					item.key_points.length > 0
				) {
					item.key_points.forEach((point) => {
						text += `${indent}   • ${point}\n`;
					});
				}
				text += '\n';
			});
		}

		// SEO recommendations
		if (data.seo_recommendations) {
			text += 'SEO RECOMMENDATIONS\n';
			text += `-`.repeat(30) + '\n';

			const seo = data.seo_recommendations;
			if (seo.related_keywords && seo.related_keywords.length > 0) {
				text += `Related Keywords: ${seo.related_keywords.join(', ')}\n`;
			}
			if (seo.internal_links && seo.internal_links.length > 0) {
				text += 'Internal Linking Opportunities:\n';
				seo.internal_links.forEach((link) => {
					text += `  • ${link}\n`;
				});
			}
			text += '\n';
		}

		// Call-to-actions
		if (data.call_to_actions && data.call_to_actions.length > 0) {
			text += 'CALL-TO-ACTION SUGGESTIONS\n';
			text += `-`.repeat(30) + '\n';
			data.call_to_actions.forEach((cta, index) => {
				text += `${index + 1}. ${cta}\n`;
			});
			text += '\n';
		}

		// Footer
		text += '='.repeat(60) + '\n';
		if (data.created_at) {
			text += `Generated on: ${data.created_at}\n`;
		}
		text += 'Powered by ThinkRank\n';

		return text;
	};

	/**
	 * Load a saved brief
	 * @param brief
	 */
	const loadSavedBrief = (brief) => {
		// Load the brief data into the form
		if (brief.brief_data) {
			setFormData({
				target_keywords: brief.brief_data.target_keywords || [''],
				content_type: brief.brief_data.content_type || 'blog_post',
				target_audience: brief.brief_data.target_audience || 'general',
				content_length: brief.brief_data.content_length || 'medium',
				tone: brief.brief_data.tone || 'professional',
				competitor_urls: brief.brief_data.competitor_urls || [''],
				additional_context: brief.brief_data.additional_context || '',
			});
		}

		// Set the generated brief
		setGeneratedBrief(brief.brief_data);
		setError(null);

		// Scroll to the generated content area after a brief delay to ensure content is rendered
		setTimeout(() => {
			const contentSection = document.getElementById(
				'generated-content-section'
			);
			if (contentSection) {
				contentSection.scrollIntoView({
					behavior: 'smooth',
					block: 'start',
				});
			}
		}, 100);
	};

	const downloadAsTxt = () => {
		const formattedText = formatBriefAsText(generatedBrief);
		const blob = new Blob([formattedText], { type: 'text/plain' });
		const url = URL.createObjectURL(blob);
		const link = document.createElement('a');
		link.href = url;
		link.download = `content-brief-${Date.now()}.txt`;
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		URL.revokeObjectURL(url);
	};

	const printAsPdf = () => {
		const formattedText = formatBriefAsText(generatedBrief);
		const printWindow = window.open('', '_blank');

		// window.open() returns null under a pop-up blocker, and the next line
		// then threw an uncaught TypeError — the button simply appeared dead
		// with no explanation (#404).
		if (!printWindow) {
			notify.error(
				__(
					'Allow pop-ups for this site to print the brief.',
					'thinkrank'
				)
			);
			return;
		}

		printWindow.document.write(
			`<html><head><title>Content Brief</title><style>body{font-family:Arial,sans-serif;margin:40px;line-height:1.6;color:#333}pre{white-space:pre-wrap;font-family:Arial,sans-serif;font-size:12px}@media print{body{margin:20px}@page{margin:1in}}</style></head><body><pre>${formattedText.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre></body></html>`
		);
		printWindow.document.close();
		printWindow.print();
	};

	return (
		<div>
			{error && (
				<Alert
					variant="error"
					className="tr-mb-6"
					onDismiss={() => setError(null)}
				>
					{linkifyMessage(error)}
				</Alert>
			)}

			{/* Form + Saved Briefs grid */}
			<div className="tr-grid tr-grid-cols-1 lg:tr-grid-cols-5 tr-gap-6 tr-mb-8">
				{/* Left: AI form card (3/5) */}
				<div className="lg:tr-col-span-3">
					<div className="tr-bg-white tr-rounded-xl tr-shadow-sm tr-overflow-hidden">
						{/* Header */}
						<div className="tr-relative tr-overflow-hidden tr-bg-[linear-gradient(90deg,#4c4cff_58%,#3535b0_118%)] tr-px-6 tr-py-5 tr-flex tr-items-center tr-gap-3.5">
							{/* Decorative glow accents */}
							<span
								aria-hidden="true"
								className="tr-pointer-events-none tr-absolute tr-top-[-2.5rem] tr-right-[-2rem] tr-w-40 tr-h-40 tr-rounded-full tr-bg-white/10 tr-blur-2xl"
							/>
							<span
								aria-hidden="true"
								className="tr-pointer-events-none tr-absolute tr-bottom-[-3rem] tr-left-[6rem] tr-w-32 tr-h-32 tr-rounded-full tr-bg-white/5 tr-blur-2xl"
							/>
							<span className="tr-relative tr-flex tr-items-center tr-justify-center tr-w-11 tr-h-11 tr-rounded-xl tr-bg-white/15 tr-ring-1 tr-ring-white/25 tr-text-white tr-shrink-0 tr-shadow-sm">
								<svg
									className="tr-w-5 tr-h-5"
									viewBox="0 0 24 24"
									fill="none"
									stroke="currentColor"
									strokeWidth="1.8"
									strokeLinecap="round"
									strokeLinejoin="round"
									aria-hidden="true"
								>
									<path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9L12 3z" />
									<path d="M19 15l.7 1.8L21.5 17.5l-1.8.7L19 20l-.7-1.8L16.5 17.5l1.8-.7L19 15z" />
								</svg>
							</span>
							<div className="tr-relative">
								<h3 className="tr-text-white tr-font-semibold tr-text-base tr-m-0 tr-leading-tight">
									{__('Create Content Brief', 'thinkrank')}
								</h3>
								<p className="tr-text-white/70 tr-text-xs tr-m-0 tr-mt-0.5">
									{__(
										'AI-powered content strategy and optimization',
										'thinkrank'
									)}
								</p>
							</div>
						</div>

						{/* Form body */}
						<div className="tr-p-6 tr-flex tr-flex-col tr-gap-6">
							{/* Primary fields */}
							<div className="tr-flex tr-flex-col tr-gap-5">
								<KeywordInput
									keywords={formData.target_keywords}
									onChange={handleKeywordsChange}
									label={__('Target Keywords', 'thinkrank')}
									help={__(
										'Enter your primary and secondary keywords',
										'thinkrank'
									)}
								/>

								<div className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-2 tr-gap-4">
									<Select
										label={__('Content Type', 'thinkrank')}
										value={formData.content_type}
										options={contentTypeOptions}
										onChange={(e) =>
											handleFieldChange(
												'content_type',
												e.target.value
											)
										}
									/>
									<Select
										label={__(
											'Target Audience',
											'thinkrank'
										)}
										value={formData.target_audience}
										options={audienceOptions}
										onChange={(e) =>
											handleFieldChange(
												'target_audience',
												e.target.value
											)
										}
									/>
									<Select
										label={__(
											'Content Length',
											'thinkrank'
										)}
										value={formData.content_length}
										options={lengthOptions}
										onChange={(e) =>
											handleFieldChange(
												'content_length',
												e.target.value
											)
										}
									/>
									<Select
										label={__('Tone', 'thinkrank')}
										value={formData.tone}
										options={toneOptions}
										onChange={(e) =>
											handleFieldChange(
												'tone',
												e.target.value
											)
										}
									/>
								</div>
							</div>

							{/* Advanced options */}
							<div className="tr-border-t tr-border-secondary tr-pt-6 tr-flex tr-flex-col tr-gap-4">
								<div className="tr-flex tr-items-center tr-gap-2">
									<span className="tr-text-[11px] tr-font-semibold tr-uppercase tr-tracking-wide tr-text-tertiary">
										{__('Advanced Options', 'thinkrank')}
									</span>
									<span
										className="tr-flex-1 tr-h-px tr-bg-secondary"
										aria-hidden="true"
									/>
								</div>
								<KeywordInput
									keywords={formData.competitor_urls}
									onChange={handleCompetitorUrlsChange}
									label={__(
										'Competitor URLs (Optional)',
										'thinkrank'
									)}
									help={__(
										'Add competitor URLs for comprehensive analysis. Analysis may take 30-60 seconds.',
										'thinkrank'
									)}
									placeholder={__(
										'https://example.com/competitor-article',
										'thinkrank'
									)}
								/>
								<Textarea
									label={__(
										'Additional Context',
										'thinkrank'
									)}
									value={formData.additional_context}
									onChange={(e) =>
										handleFieldChange(
											'additional_context',
											e.target.value
										)
									}
									placeholder={__(
										'Any specific requirements, brand guidelines, or context…',
										'thinkrank'
									)}
									rows={3}
								/>
							</div>
						</div>

						{/* Footer with generate button */}
						<div className="tr-px-6 tr-py-4 tr-bg-white tr-border-t tr-border-secondary">
							<Button
								variant="primary"
								size="lg"
								className="tr-w-full tr-justify-center tr-bg-brand tr-border-0 hover:tr-bg-[#3641D9]"
								onClick={generateBrief}
								loading={isGenerating}
								disabled={isGenerating || !aiConfigured}
								title={
									!aiConfigured
										? __(
												'Configure an AI provider in Settings to enable AI generation',
												'thinkrank'
											)
										: undefined
								}
							>
								{!isGenerating && (
									<svg
										aria-hidden="true"
										width="14"
										height="17"
										viewBox="0 0 15 18"
										fill="none"
										xmlns="http://www.w3.org/2000/svg"
										className="tr-mr-1.5 tr-text-white"
									>
										<path
											d="M5.58072 3.39707L6.0826 4.79082C6.35798 5.55489 6.79852 6.2488 7.37282 6.8231C7.94712 7.3974 8.64103 7.83794 9.4051 8.11332L10.7988 8.6152C10.8264 8.62529 10.8501 8.64359 10.8669 8.66762C10.8837 8.69165 10.8927 8.72026 10.8927 8.74957C10.8927 8.77888 10.8837 8.80749 10.8669 8.83152C10.8501 8.85556 10.8264 8.87385 10.7988 8.88395L9.4051 9.38582C8.64103 9.6612 7.94712 10.1017 7.37282 10.676C6.79852 11.2503 6.35798 11.9443 6.0826 12.7083L5.58072 14.1021C5.57063 14.1296 5.55233 14.1534 5.5283 14.1701C5.50427 14.1869 5.47566 14.1959 5.44635 14.1959C5.41704 14.1959 5.38843 14.1869 5.3644 14.1701C5.34036 14.1534 5.32207 14.1296 5.31197 14.1021L4.8101 12.7083C4.53472 11.9443 4.09418 11.2503 3.51988 10.676C2.94558 10.1017 2.25167 9.6612 1.4876 9.38582L0.093849 8.88395C0.0663279 8.87385 0.0425693 8.85556 0.025785 8.83152C0.00900062 8.80749 0 8.77888 0 8.74957C0 8.72026 0.00900062 8.69165 0.025785 8.66762C0.0425693 8.64359 0.0663279 8.62529 0.093849 8.6152L1.4876 8.11332C2.25167 7.83794 2.94558 7.3974 3.51988 6.8231C4.09418 6.2488 4.53472 5.55489 4.8101 4.79082L5.31197 3.39707C5.32179 3.36927 5.33998 3.3452 5.36405 3.32817C5.38811 3.31115 5.41687 3.302 5.44635 3.302C5.47583 3.302 5.50459 3.31115 5.52865 3.32817C5.55272 3.3452 5.57091 3.36927 5.58072 3.39707ZM11.8545 0.0476961L12.1088 0.753321C12.2484 1.14018 12.4715 1.49151 12.7624 1.78232C13.0532 2.07313 13.4045 2.29625 13.7913 2.43582L14.497 2.6902C14.511 2.69529 14.523 2.70455 14.5316 2.71674C14.5401 2.72893 14.5447 2.74344 14.5447 2.75832C14.5447 2.7732 14.5401 2.78772 14.5316 2.7999C14.523 2.81209 14.511 2.82136 14.497 2.82645L13.7913 3.08082C13.4045 3.22039 13.0532 3.44352 12.7624 3.73432C12.4715 4.02513 12.2484 4.37646 12.1088 4.76332L11.8545 5.46895C11.8494 5.48293 11.8401 5.495 11.8279 5.50353C11.8157 5.51207 11.8012 5.51664 11.7863 5.51664C11.7715 5.51664 11.757 5.51207 11.7448 5.50353C11.7326 5.495 11.7233 5.48293 11.7182 5.46895L11.4639 4.76332C11.3243 4.37646 11.1012 4.02513 10.8103 3.73432C10.5195 3.44352 10.1682 3.22039 9.78135 3.08082L9.07572 2.82645C9.06174 2.82136 9.04967 2.81209 9.04114 2.7999C9.03261 2.78772 9.02803 2.7732 9.02803 2.75832C9.02803 2.74344 9.03261 2.72893 9.04114 2.71674C9.04967 2.70455 9.06174 2.69529 9.07572 2.6902L9.78135 2.43582C10.1682 2.29625 10.5195 2.07313 10.8103 1.78232C11.1012 1.49151 11.3243 1.14018 11.4639 0.753321L11.7182 0.0476961C11.7233 0.0337168 11.7326 0.0216418 11.7448 0.0131093C11.757 0.00457683 11.7715 0 11.7863 0C11.8012 0 11.8157 0.00457683 11.8279 0.0131093C11.8401 0.0216418 11.8494 0.0337168 11.8545 0.0476961ZM11.8545 12.0308L12.1088 12.7364C12.2484 13.1233 12.4715 13.4746 12.7624 13.7654C13.0532 14.0563 13.4045 14.2794 13.7913 14.4189L14.497 14.6733C14.511 14.6784 14.523 14.6877 14.5316 14.6999C14.5401 14.7121 14.5447 14.7266 14.5447 14.7414C14.5447 14.7563 14.5401 14.7708 14.5316 14.783C14.523 14.7952 14.511 14.8045 14.497 14.8096L13.7913 15.0639C13.4045 15.2035 13.0532 15.4266 12.7624 15.7174C12.4715 16.0083 12.2484 16.3596 12.1088 16.7464L11.8545 17.4521C11.8494 17.4661 11.8401 17.4781 11.8279 17.4867C11.8157 17.4952 11.8012 17.4998 11.7863 17.4998C11.7715 17.4998 11.757 17.4952 11.7448 17.4867C11.7326 17.4781 11.7233 17.4661 11.7182 17.4521L11.4639 16.7464C11.3243 16.3596 11.1012 16.0083 10.8103 15.7174C10.5195 15.4266 10.1682 15.2035 9.78135 15.0639L9.07572 14.8096C9.06174 14.8045 9.04967 14.7952 9.04114 14.783C9.03261 14.7708 9.02803 14.7563 9.02803 14.7414C9.02803 14.7266 9.03261 14.7121 9.04114 14.6999C9.04967 14.6877 9.06174 14.6784 9.07572 14.6733L9.78135 14.4189C10.1682 14.2794 10.5195 14.0563 10.8103 13.7654C11.1012 13.4746 11.3243 13.1233 11.4639 12.7364L11.7182 12.0308C11.7413 11.9671 11.832 11.9671 11.8545 12.0308Z"
											fill="currentColor"
										/>
									</svg>
								)}
								{isGenerating
									? formData.competitor_urls.filter((u) =>
											u.trim()
										).length > 0
										? __(
												'Analyzing competitors & generating…',
												'thinkrank'
											)
										: __('Generating Brief…', 'thinkrank')
									: __('Generate Content Brief', 'thinkrank')}
							</Button>
							{!aiConfigured && (
								<p className="tr-text-xs tr-text-tertiary tr-text-center tr-m-0 tr-mt-2">
									{__(
										'Add an AI provider API key in ThinkRank → Settings to enable content brief generation.',
										'thinkrank'
									)}
								</p>
							)}
						</div>
					</div>
				</div>

				{/* Right: Saved briefs (2/5) */}
				<div className="lg:tr-col-span-2 tr-relative">
					<div className="tr-bg-white tr-rounded-xl tr-shadow-sm tr-overflow-hidden tr-flex tr-flex-col lg:tr-absolute lg:tr-inset-0">
						<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary tr-flex tr-items-center tr-gap-2">
							<svg
								aria-hidden="true"
								width="14"
								height="17"
								viewBox="0 0 15 18"
								fill="none"
								xmlns="http://www.w3.org/2000/svg"
								className="tr-text-brand"
							>
								<path
									d="M5.58072 3.39707L6.0826 4.79082C6.35798 5.55489 6.79852 6.2488 7.37282 6.8231C7.94712 7.3974 8.64103 7.83794 9.4051 8.11332L10.7988 8.6152C10.8264 8.62529 10.8501 8.64359 10.8669 8.66762C10.8837 8.69165 10.8927 8.72026 10.8927 8.74957C10.8927 8.77888 10.8837 8.80749 10.8669 8.83152C10.8501 8.85556 10.8264 8.87385 10.7988 8.88395L9.4051 9.38582C8.64103 9.6612 7.94712 10.1017 7.37282 10.676C6.79852 11.2503 6.35798 11.9443 6.0826 12.7083L5.58072 14.1021C5.57063 14.1296 5.55233 14.1534 5.5283 14.1701C5.50427 14.1869 5.47566 14.1959 5.44635 14.1959C5.41704 14.1959 5.38843 14.1869 5.3644 14.1701C5.34036 14.1534 5.32207 14.1296 5.31197 14.1021L4.8101 12.7083C4.53472 11.9443 4.09418 11.2503 3.51988 10.676C2.94558 10.1017 2.25167 9.6612 1.4876 9.38582L0.093849 8.88395C0.0663279 8.87385 0.0425693 8.85556 0.025785 8.83152C0.00900062 8.80749 0 8.77888 0 8.74957C0 8.72026 0.00900062 8.69165 0.025785 8.66762C0.0425693 8.64359 0.0663279 8.62529 0.093849 8.6152L1.4876 8.11332C2.25167 7.83794 2.94558 7.3974 3.51988 6.8231C4.09418 6.2488 4.53472 5.55489 4.8101 4.79082L5.31197 3.39707C5.32179 3.36927 5.33998 3.3452 5.36405 3.32817C5.38811 3.31115 5.41687 3.302 5.44635 3.302C5.47583 3.302 5.50459 3.31115 5.52865 3.32817C5.55272 3.3452 5.57091 3.36927 5.58072 3.39707ZM11.8545 0.0476961L12.1088 0.753321C12.2484 1.14018 12.4715 1.49151 12.7624 1.78232C13.0532 2.07313 13.4045 2.29625 13.7913 2.43582L14.497 2.6902C14.511 2.69529 14.523 2.70455 14.5316 2.71674C14.5401 2.72893 14.5447 2.74344 14.5447 2.75832C14.5447 2.7732 14.5401 2.78772 14.5316 2.7999C14.523 2.81209 14.511 2.82136 14.497 2.82645L13.7913 3.08082C13.4045 3.22039 13.0532 3.44352 12.7624 3.73432C12.4715 4.02513 12.2484 4.37646 12.1088 4.76332L11.8545 5.46895C11.8494 5.48293 11.8401 5.495 11.8279 5.50353C11.8157 5.51207 11.8012 5.51664 11.7863 5.51664C11.7715 5.51664 11.757 5.51207 11.7448 5.50353C11.7326 5.495 11.7233 5.48293 11.7182 5.46895L11.4639 4.76332C11.3243 4.37646 11.1012 4.02513 10.8103 3.73432C10.5195 3.44352 10.1682 3.22039 9.78135 3.08082L9.07572 2.82645C9.06174 2.82136 9.04967 2.81209 9.04114 2.7999C9.03261 2.78772 9.02803 2.7732 9.02803 2.75832C9.02803 2.74344 9.03261 2.72893 9.04114 2.71674C9.04967 2.70455 9.06174 2.69529 9.07572 2.6902L9.78135 2.43582C10.1682 2.29625 10.5195 2.07313 10.8103 1.78232C11.1012 1.49151 11.3243 1.14018 11.4639 0.753321L11.7182 0.0476961C11.7233 0.0337168 11.7326 0.0216418 11.7448 0.0131093C11.757 0.00457683 11.7715 0 11.7863 0C11.8012 0 11.8157 0.00457683 11.8279 0.0131093C11.8401 0.0216418 11.8494 0.0337168 11.8545 0.0476961ZM11.8545 12.0308L12.1088 12.7364C12.2484 13.1233 12.4715 13.4746 12.7624 13.7654C13.0532 14.0563 13.4045 14.2794 13.7913 14.4189L14.497 14.6733C14.511 14.6784 14.523 14.6877 14.5316 14.6999C14.5401 14.7121 14.5447 14.7266 14.5447 14.7414C14.5447 14.7563 14.5401 14.7708 14.5316 14.783C14.523 14.7952 14.511 14.8045 14.497 14.8096L13.7913 15.0639C13.4045 15.2035 13.0532 15.4266 12.7624 15.7174C12.4715 16.0083 12.2484 16.3596 12.1088 16.7464L11.8545 17.4521C11.8494 17.4661 11.8401 17.4781 11.8279 17.4867C11.8157 17.4952 11.8012 17.4998 11.7863 17.4998C11.7715 17.4998 11.757 17.4952 11.7448 17.4867C11.7326 17.4781 11.7233 17.4661 11.7182 17.4521L11.4639 16.7464C11.3243 16.3596 11.1012 16.0083 10.8103 15.7174C10.5195 15.4266 10.1682 15.2035 9.78135 15.0639L9.07572 14.8096C9.06174 14.8045 9.04967 14.7952 9.04114 14.783C9.03261 14.7708 9.02803 14.7563 9.02803 14.7414C9.02803 14.7266 9.03261 14.7121 9.04114 14.6999C9.04967 14.6877 9.06174 14.6784 9.07572 14.6733L9.78135 14.4189C10.1682 14.2794 10.5195 14.0563 10.8103 13.7654C11.1012 13.4746 11.3243 13.1233 11.4639 12.7364L11.7182 12.0308C11.7413 11.9671 11.832 11.9671 11.8545 12.0308Z"
									fill="currentColor"
								/>
							</svg>
							<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
								{__('Saved Briefs', 'thinkrank')}
							</h3>
							{savedBriefs.length > 0 && (
								<span className="tr-ml-auto tr-inline-flex tr-items-center tr-justify-center tr-min-w-[20px] tr-h-5 tr-px-1.5 tr-rounded-full tr-bg-brand-bg tr-text-brand tr-text-xs tr-font-semibold">
									{savedBriefs.length}
								</span>
							)}
						</div>
						<div
							className="tr-flex-1 tr-overflow-y-auto tr-p-4"
							style={{ minHeight: 0 }}
						>
							{savedBriefs.length > 0 ? (
								<div className="tr-flex tr-flex-col tr-gap-1">
									{savedBriefs.map((brief) => (
										<div
											key={brief.id}
											className="tr-bg-white tr-border tr-border-transparent tr-border-b tr-border-b-[#E5E7EB] tr-p-4 tr-transition-all tr-duration-150"
										>
											<div className="tr-flex tr-items-start tr-gap-3">
												<span className="tr-flex tr-items-center tr-justify-center tr-w-9 tr-h-9 tr-rounded-md tr-bg-brand-bg tr-text-brand tr-shrink-0">
													<svg
														className="tr-w-[18px] tr-h-[18px]"
														viewBox="0 0 24 24"
														fill="none"
														stroke="currentColor"
														strokeWidth="1.8"
														aria-hidden="true"
													>
														<path
															strokeLinecap="round"
															strokeLinejoin="round"
															d="M14 3v4a1 1 0 0 0 1 1h4M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"
														/>
														<path
															strokeLinecap="round"
															d="M9 13h6M9 17h4"
														/>
													</svg>
												</span>
												<div className="tr-flex-1 tr-min-w-0">
													<h4
														title={
															brief.title ||
															undefined
														}
														className="tr-text-sm tr-font-semibold tr-text-primary tr-truncate tr-m-0"
													>
														{brief.title ||
															__(
																'Untitled Brief',
																'thinkrank'
															)}
													</h4>
													<div className="tr-flex tr-items-center tr-gap-2 tr-mt-1">
														<p className="tr-text-xs tr-text-tertiary tr-m-0">
															{new Date(
																brief.created_at
															).toLocaleDateString(
																undefined,
																{
																	year: 'numeric',
																	month: 'short',
																	day: 'numeric',
																}
															)}
														</p>
														{brief.brief_data
															?.generation_meta && (
															<ModelBadge
																provider={
																	brief
																		.brief_data
																		.generation_meta
																		.provider
																}
																model={
																	brief
																		.brief_data
																		.generation_meta
																		.model
																}
															/>
														)}
													</div>
												</div>
											</div>

											{brief.target_keywords &&
												brief.target_keywords.length >
													0 && (
													<div className="tr-flex tr-flex-wrap tr-gap-1 tr-mt-3">
														{brief.target_keywords
															.slice(0, 3)
															.map(
																(
																	keyword,
																	i
																) => (
																	<span
																		key={i}
																		className="tr-inline-flex tr-items-center tr-px-2 tr-py-0.5 tr-rounded-full tr-text-xs tr-bg-brand-bg tr-text-brand"
																	>
																		{
																			keyword
																		}
																	</span>
																)
															)}
														{brief.target_keywords
															.length > 3 && (
															<span className="tr-inline-flex tr-items-center tr-px-2 tr-py-0.5 tr-rounded-full tr-text-xs tr-bg-line-2 tr-text-secondary">
																+
																{brief
																	.target_keywords
																	.length - 3}
															</span>
														)}
													</div>
												)}

											<div className="tr-flex tr-items-center tr-gap-2 tr-mt-4">
												<Button
													variant="secondary"
													size="sm"
													className="tr-justify-center"
													onClick={() =>
														loadSavedBrief(brief)
													}
												>
													<svg
														className="tr-w-3.5 tr-h-3.5 tr-mr-1"
														viewBox="0 0 24 24"
														fill="none"
														stroke="currentColor"
														strokeWidth="2"
														aria-hidden="true"
													>
														<path
															strokeLinecap="round"
															strokeLinejoin="round"
															d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"
														/>
														<path
															strokeLinecap="round"
															strokeLinejoin="round"
															d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
														/>
													</svg>
													{__(
														'View Brief',
														'thinkrank'
													)}
												</Button>
												<button
													className="tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded tr-border tr-border-secondary tr-bg-white tr-text-tertiary hover:tr-border-danger hover:tr-text-white hover:tr-bg-danger tr-transition-colors tr-shrink-0"
													onClick={() =>
														setBriefPendingDelete(
															brief
														)
													}
													title={__(
														'Delete brief',
														'thinkrank'
													)}
												>
													<svg
														className="tr-w-4 tr-h-4"
														fill="none"
														stroke="currentColor"
														viewBox="0 0 24 24"
													>
														<path
															strokeLinecap="round"
															strokeLinejoin="round"
															strokeWidth={2}
															d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
														/>
													</svg>
												</button>
											</div>
										</div>
									))}
								</div>
							) : (
								<EmptyState
									size="sm"
									title={__(
										'No Saved Briefs Yet',
										'thinkrank'
									)}
									description={__(
										'Generate your first content brief to start building your library.',
										'thinkrank'
									)}
								/>
							)}
						</div>
					</div>
				</div>
			</div>

			{/* Generated brief */}
			{generatedBrief && (
				<div
					id="generated-content-section"
					className="tr-bg-white tr-rounded-lg tr-border tr-border-secondary tr-shadow-sm"
				>
					{/* Brand top accent — rounded to match the card since the
					    card no longer clips overflow (needed for the sticky sidebar). */}
					<div
						className="tr-h-1 tr-bg-brand"
						style={{
							borderTopLeftRadius: '12px',
							borderTopRightRadius: '12px',
						}}
					/>

					{/* Header */}
					<div className="tr-px-6 tr-py-4 tr-border-b tr-border-secondary">
						<div className="tr-flex tr-items-center tr-justify-between tr-flex-wrap tr-gap-3">
							{/* Left: title + badge */}
							<div className="tr-flex tr-items-center tr-gap-3">
								<div className="tr-w-8 tr-h-8 tr-rounded-lg tr-bg-brand tr-text-white tr-flex tr-items-center tr-justify-center tr-shrink-0">
									<svg
										className="tr-w-4 tr-h-4"
										fill="none"
										stroke="currentColor"
										viewBox="0 0 24 24"
									>
										<path
											strokeLinecap="round"
											strokeLinejoin="round"
											strokeWidth={2.5}
											d="M5 13l4 4L19 7"
										/>
									</svg>
								</div>
								<div>
									<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
										{__(
											'Generated Content Brief',
											'thinkrank'
										)}
									</h3>
									<p className="tr-text-xs tr-text-tertiary tr-m-0">
										{__(
											'AI-powered content strategy',
											'thinkrank'
										)}
									</p>
								</div>
								<ModelBadge
									provider={generatedBrief.ai_provider}
									model={generatedBrief.ai_model}
								/>
							</div>

							{/* Right: actions */}
							<div className="tr-flex tr-items-center tr-gap-2 tr-flex-wrap">
								{/* Extension slot: Pro overrides this with the real
								    "Insert into post" button. The default is a
								    Pro-badged upsell teaser so free users still
								    see the action. */}
								{/* Plan first. Pro registers this filter even when
								    unlicensed, so consulting it directly rendered
								    Pro's real button pointed at a route that is
								    not registered — clicking it surfaced a raw
								    rest_no_route error (#533). */}
								{(() => {
									if (window.thinkrankAdmin?.isPro) {
										return applyFilters(
											'thinkrank.contentBrief.resultActions',
											<ContentBriefInsertTeaser />,
											{
												briefData: generatedBrief,
												formData,
											}
										);
									}

									if (isProUnlicensed()) {
										return (
											<ProLicenseNotice
												feature={__(
													'Insert Brief into a Post',
													'thinkrank'
												)}
											/>
										);
									}

									return <ContentBriefInsertTeaser />;
								})()}
								<Button
									variant="secondary"
									size="sm"
									onClick={downloadAsTxt}
								>
									<svg
										className="tr-w-3.5 tr-h-3.5 tr-mr-1.5"
										fill="none"
										stroke="currentColor"
										viewBox="0 0 24 24"
									>
										<path
											strokeLinecap="round"
											strokeLinejoin="round"
											strokeWidth={2}
											d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
										/>
									</svg>
									{__('Save as Text', 'thinkrank')}
								</Button>
								<Button
									variant="secondary"
									size="sm"
									onClick={printAsPdf}
								>
									<svg
										className="tr-w-3.5 tr-h-3.5 tr-mr-1.5"
										fill="none"
										stroke="currentColor"
										viewBox="0 0 24 24"
									>
										<path
											strokeLinecap="round"
											strokeLinejoin="round"
											strokeWidth={2}
											d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"
										/>
									</svg>
									{__('Print / PDF', 'thinkrank')}
								</Button>
								<Button
									variant="secondary"
									size="sm"
									onClick={() =>
										copyToClipboard(
											formatBriefAsText(generatedBrief)
										)
									}
									title={
										copied
											? __('Copied!', 'thinkrank')
											: __(
													'Copy to clipboard',
													'thinkrank'
												)
									}
								>
									{copied ? (
										<svg
											className="tr-w-3.5 tr-h-3.5 tr-mr-1.5 tr-text-success"
											fill="none"
											stroke="currentColor"
											viewBox="0 0 24 24"
										>
											<path
												strokeLinecap="round"
												strokeLinejoin="round"
												strokeWidth={2.5}
												d="M5 13l4 4L19 7"
											/>
										</svg>
									) : (
										<svg
											className="tr-w-3.5 tr-h-3.5 tr-mr-1.5"
											fill="none"
											stroke="currentColor"
											viewBox="0 0 24 24"
										>
											<path
												strokeLinecap="round"
												strokeLinejoin="round"
												strokeWidth={2}
												d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
											/>
										</svg>
									)}
									{copied
										? __('Copied!', 'thinkrank')
										: __('Copy', 'thinkrank')}
								</Button>
								<Button
									variant="ghost"
									size="sm"
									onClick={clearBrief}
								>
									<svg
										className="tr-w-3.5 tr-h-3.5 tr-mr-1.5"
										fill="none"
										stroke="currentColor"
										viewBox="0 0 24 24"
									>
										<path
											strokeLinecap="round"
											strokeLinejoin="round"
											strokeWidth={2}
											d="M6 18L18 6M6 6l12 12"
										/>
									</svg>
									{__('Close', 'thinkrank')}
								</Button>
							</div>
						</div>
					</div>

					{/* Content */}
					<div className="tr-p-6">
						<ContentStructure
							briefData={generatedBrief}
							formData={formData}
						/>
					</div>
				</div>
			)}

			{/* Delete confirmation */}
			{briefPendingDelete && (
				<ConfirmationDialog
					title={__('Delete this brief?', 'thinkrank')}
					message={sprintf(
						/* translators: %s: saved brief title */
						__(
							'"%s" will be permanently deleted. This action cannot be undone.',
							'thinkrank'
						),
						briefPendingDelete.title ||
							__('Untitled Brief', 'thinkrank')
					)}
					confirmLabel={__('Delete Brief', 'thinkrank')}
					isDestructive
					onConfirm={() => deleteSavedBrief(briefPendingDelete.id)}
					onCancel={() => setBriefPendingDelete(null)}
				/>
			)}
		</div>
	);
};

export default ContentBriefGenerator;
