/**
 * Card layout for a wizard step: header (optional badge + title + subtitle) +
 * divider + body.
 *
 * @package
 * @since 1.0.0
 */

export default function StepLayout({
	badge,
	title,
	subtitle,
	children,
	footer,
}) {
	return (
		<div className="tr-wizard__card">
			<div className="tr-wizard__header">
				{badge && (
					<span className="tr-wizard__badge-pill">{badge}</span>
				)}
				{title && <h2 className="tr-wizard__title">{title}</h2>}
				{subtitle && <p className="tr-wizard__subtitle">{subtitle}</p>}
			</div>
			<div className="tr-wizard__divider" />
			<div className="tr-wizard__body">{children}</div>
			{footer}
		</div>
	);
}
