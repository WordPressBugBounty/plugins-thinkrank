import classnames from 'classnames';

export default function Shell({ topNav, children, className }) {
	return (
		<div
			id="thinkrank-root"
			className={classnames(
				'tr-root tr-flex tr-flex-col tr-min-h-screen tr-bg-white tr-font-sans',
				className
			)}
		>
			{topNav}
			{children}
		</div>
	);
}
