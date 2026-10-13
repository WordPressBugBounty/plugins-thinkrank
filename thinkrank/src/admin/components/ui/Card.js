import classnames from 'classnames';

export default function Card({ className, children, padding = true }) {
	return (
		<div
			className={classnames(
				'tr-bg-white tr-border tr-border-secondary tr-rounded-xl',
				padding && 'tr-p-6',
				className
			)}
		>
			{children}
		</div>
	);
}

export function CardHeader({ title, description, action, className }) {
	return (
		<div
			className={classnames(
				'tr-flex tr-items-start tr-justify-between tr-mb-5',
				className
			)}
		>
			<div>
				<h3 className="tr-text-base tr-font-semibold tr-text-primary tr-m-0">
					{title}
				</h3>
				{description && (
					<p className="tr-text-sm tr-text-tertiary tr-mt-1 tr-mb-0">
						{description}
					</p>
				)}
			</div>
			{action && <div className="tr-shrink-0 tr-ml-4">{action}</div>}
		</div>
	);
}

export function CardDivider() {
	return <hr className="tr-border-secondary tr-my-5" />;
}
