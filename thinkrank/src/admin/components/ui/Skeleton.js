import classnames from 'classnames';

export default function Skeleton({ className, width, height }) {
	return (
		<span
			className={classnames(
				'tr-block tr-rounded tr-bg-line-2 tr-animate-skeleton-pulse',
				className
			)}
			style={{ width, height }}
			aria-hidden="true"
		/>
	);
}

export function SkeletonCard({ lines = 3, label }) {
	return (
		<div
			role="status"
			aria-label={label || 'Loading…'}
			// tr-min-w keeps the card from collapsing: its bars are %-width, so
			// it has no intrinsic width, and a shrink-to-fit ancestor (e.g. a
			// tr-items-start flex column) would squeeze it to ~0.
			className="tr-w-full tr-min-w-[260px] tr-p-6 tr-bg-white tr-rounded-lg"
		>
			<Skeleton className="tr-h-5 tr-w-1/3 tr-mb-4" />
			{Array.from({ length: lines }).map((_, i) => (
				<Skeleton
					key={i}
					className={classnames(
						'tr-h-4 tr-mb-2',
						i === lines - 1 ? 'tr-w-2/3' : 'tr-w-full'
					)}
				/>
			))}
		</div>
	);
}

export function SkeletonText({ lines = 2 }) {
	return (
		<div className="tr-flex tr-flex-col tr-gap-2 tr-min-w-[200px]">
			{Array.from({ length: lines }).map((_, i) => (
				<Skeleton
					key={i}
					className={classnames(
						'tr-h-4',
						i === lines - 1 ? 'tr-w-4/5' : 'tr-w-full'
					)}
				/>
			))}
		</div>
	);
}
