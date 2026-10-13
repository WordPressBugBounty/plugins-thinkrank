/**
 * AI Button Component
 *
 * Standardized button component for AI-powered features
 * Includes sparkle icon and consistent loading states
 *
 * @package
 * @since 1.0.0
 */

import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * AI Button Component
 *
 * @param {Object}   props             Component props
 * @param {string}   props.children    Button text
 * @param {boolean}  props.isLoading   Loading state
 * @param {string}   props.variant     Button variant (primary, secondary)
 * @param {string}   props.size        Button size (small, medium, large)
 * @param {boolean}  props.showSparkle Show sparkle icon
 * @param {string}   props.className   Additional CSS classes
 * @param {Function} props.onClick     Click handler
 * @param {boolean}  props.disabled    Disabled state
 * @param {Object}   props.rest        Additional props
 */
const AIButton = ({
	children,
	isLoading = false,
	variant = 'primary',
	size = 'medium',
	showSparkle = true,
	className = '',
	onClick,
	disabled = false,
	...rest
}) => {
	const buttonClasses = [
		'thinkrank-ai-button',
		`thinkrank-ai-button--${variant}`,
		`thinkrank-ai-button--${size}`,
		className,
	]
		.filter(Boolean)
		.join(' ');

	const handleClick = (event) => {
		if (!isLoading && !disabled && onClick) {
			onClick(event);
		}
	};

	return (
		<Button
			className={buttonClasses}
			disabled={isLoading || disabled}
			onClick={handleClick}
			isPrimary={variant === 'primary'}
			isSecondary={variant === 'secondary'}
			{...rest}
		>
			{isLoading ? (
				<span className="tr-animate-pulse">
					{__('Processing…', 'thinkrank')}
				</span>
			) : (
				<>
					{showSparkle && (
						<span className="sparkle-icon" aria-hidden="true">
							✨
						</span>
					)}
					{children}
				</>
			)}
		</Button>
	);
};

export default AIButton;
