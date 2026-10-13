import classnames from 'classnames';

export default function TopBar({ title, description, actions, className }) {
	return (
		<header
			className={classnames(
				'tr-shrink-0 tr-border-b tr-border-secondary tr-bg-white',
				className
			)}
			style={{ boxShadow: '0 1px 0 0 rgba(0,0,0,0.04)' }}
		>
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-6 tr-px-6 md:tr-px-8 tr-py-5">
				<div className="tr-min-w-0">
					{title && (
						<h1 className="tr-m-0 tr-text-xl tr-font-bold tr-text-primary tr-leading-snug">
							{title}
						</h1>
					)}
					{description && (
						<p className="tr-m-0 tr-mt-0.5 tr-text-sm tr-text-tertiary tr-leading-relaxed">
							{description}
						</p>
					)}
				</div>

				{actions && (
					<div className="tr-flex tr-shrink-0 tr-items-center tr-gap-2">
						{actions}
					</div>
				)}
			</div>
		</header>
	);
}
