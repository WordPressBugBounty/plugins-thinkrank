import classnames from 'classnames';
import { scoreColor } from '@utils/scoreScheme';

const SIZE_CONFIG = {
	sm: { size: 56, stroke: 4, radius: 22, fontSize: 'tr-text-sm' },
	md: { size: 80, stroke: 6, radius: 32, fontSize: 'tr-text-xl' },
	lg: { size: 108, stroke: 7, radius: 44, fontSize: 'tr-text-2xl' },
};

export default function ScoreRing({
	score = 0,
	size = 'md',
	label,
	className,
}) {
	const cfg = SIZE_CONFIG[size];
	const circumference = 2 * Math.PI * cfg.radius;
	const offset = circumference - (score / 100) * circumference;
	const color = scoreColor(score);

	return (
		<div
			className={classnames(
				'tr-flex tr-flex-col tr-items-center tr-gap-1',
				className
			)}
		>
			<div className="tr-relative tr-inline-flex tr-items-center tr-justify-center">
				<svg
					width={cfg.size}
					height={cfg.size}
					viewBox={`0 0 ${cfg.size} ${cfg.size}`}
					className="-tr-rotate-90"
				>
					<circle
						cx={cfg.size / 2}
						cy={cfg.size / 2}
						r={cfg.radius}
						fill="none"
						stroke="#E2E8F0"
						strokeWidth={cfg.stroke}
					/>
					<circle
						cx={cfg.size / 2}
						cy={cfg.size / 2}
						r={cfg.radius}
						fill="none"
						stroke={color}
						strokeWidth={cfg.stroke}
						strokeLinecap="round"
						strokeDasharray={circumference}
						strokeDashoffset={offset}
						style={{
							transition: 'stroke-dashoffset 0.8s ease-out',
						}}
					/>
				</svg>
				<span
					className={classnames(
						'tr-absolute tr-font-bold tr-tabular-nums',
						cfg.fontSize
					)}
					style={{ color }}
				>
					{score}
				</span>
			</div>
			{label && (
				<span className="tr-text-xs tr-text-tertiary tr-font-medium">
					{label}
				</span>
			)}
		</div>
	);
}
