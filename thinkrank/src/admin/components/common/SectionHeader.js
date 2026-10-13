import classnames from 'classnames';
import { Container, Title, Text } from '../ui';

export default function SectionHeader({
	title,
	description,
	trailing,
	withBorder = true,
	className,
}) {
	return (
		<div
			className={classnames(
				'tr-px-6 tr-pt-5 tr-pb-4',
				withBorder && 'tr-border-b tr-border-secondary',
				className
			)}
		>
			<div className="tr-flex tr-items-start tr-justify-between tr-gap-4">
				<Container
					direction="column"
					gap={1}
					className="tr-min-w-0 tr-flex-1"
				>
					{title && (
						<Title tag="h3" size="sm">
							{title}
						</Title>
					)}
					{description && (
						<Text size={14} color="secondary">
							{description}
						</Text>
					)}
				</Container>
				{trailing && (
					<div className="tr-flex tr-shrink-0 tr-items-center tr-pt-0.5">
						{trailing}
					</div>
				)}
			</div>
		</div>
	);
}
