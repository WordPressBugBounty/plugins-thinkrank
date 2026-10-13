import { isValidElement } from '@wordpress/element';
import classnames from 'classnames';
import { Container, Text, Title, InfoTooltip } from '../ui';

export default function PageHeader({
	title = '',
	description = '',
	icon: IconComponent = null,
	secondaryButton = null,
	infoTooltip = null,
	afterDescription = null,
	className,
}) {
	if (!title && !description && !secondaryButton && !afterDescription) {
		return null;
	}

	return (
		<Container direction="column" gap={3} className={classnames(className)}>
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-flex-1">
				<Container direction="column" gap={0.5}>
					<Container direction="row" gap={2} align="center">
						<Title
							size="md"
							icon={
								IconComponent ? (
									<IconComponent
										className="tr-w-5 tr-h-5 tr-text-primary"
										aria-hidden="true"
									/>
								) : null
							}
							iconPosition="left"
						>
							{title}
						</Title>
						{infoTooltip && (
							<span className="tr-mt-[3px]">
								<InfoTooltip content={infoTooltip} />
							</span>
						)}
					</Container>
					{description && (
						<Text size={14} weight={400} color="secondary">
							{description}
						</Text>
					)}
				</Container>

				{secondaryButton ? secondaryButton : null}
			</div>
			{isValidElement(afterDescription) ? afterDescription : null}
		</Container>
	);
}
