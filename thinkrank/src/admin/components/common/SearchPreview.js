import classnames from 'classnames';
import { Icon } from '@wordpress/components';
import { moreVertical, globe } from '@wordpress/icons';

const decodeHtmlEntities = (str) => {
	if (typeof str !== 'string' || str === '') {
		return '';
	}
	const txt = document.createElement('textarea');
	txt.innerHTML = str;
	return txt.value;
};

export default function SearchPreview({
	siteTitle = '',
	title = '',
	description = '',
	permalink = '',
	faviconURL = '',
	deviceType = 'desktop',
	className,
}) {
	const decodedTitle = decodeHtmlEntities(title);
	const decodedDescription = decodeHtmlEntities(description);

	return (
		<div
			className={classnames(
				'tr-p-2 tr-rounded-lg tr-bg-white',
				className
			)}
		>
			<div className="tr-rounded-md tr-border tr-border-solid tr-border-secondary tr-bg-white tr-p-4 tr-shadow-sm tr-flex tr-flex-col tr-gap-1.5">
				<div className="tr-grid tr-grid-cols-[1.75rem_1fr] tr-items-center tr-gap-3">
					{faviconURL ? (
						<div className="tr-relative tr-w-7 tr-h-7 tr-rounded-full tr-overflow-hidden tr-border tr-border-secondary tr-bg-white">
							<img
								className="tr-w-full tr-h-full tr-object-cover tr-m-0"
								src={faviconURL}
								alt=""
							/>
						</div>
					) : (
						<div className="tr-w-7 tr-h-7 tr-rounded-full tr-border tr-border-secondary tr-bg-white tr-flex tr-items-center tr-justify-center tr-text-tertiary">
							<Icon icon={globe} size={16} />
						</div>
					)}

					<div className="tr-flex tr-flex-col tr-gap-0.5 tr-min-w-0">
						<span className="tr-text-primary tr-font-semibold tr-text-sm tr-leading-tight tr-truncate">
							{siteTitle}
						</span>
						<div className="tr-flex tr-items-center tr-justify-start tr-gap-2 tr-min-w-0">
							<span className="tr-text-secondary tr-text-xs tr-leading-tight tr-truncate tr-break-all">
								{permalink}
							</span>
							<Icon
								icon={moreVertical}
								size={14}
								className="tr-text-tertiary tr-shrink-0"
							/>
						</div>
					</div>
				</div>

				<div className="tr-flex tr-flex-col tr-gap-1">
					<p className="tr-text-xl tr-leading-7 tr-font-normal tr-text-[#1a0dab] tr-m-0 tr-p-0 tr-line-clamp-1 tr-break-all">
						{decodedTitle}
					</p>
					<p
						className={classnames(
							'tr-text-sm tr-leading-5 tr-font-normal tr-text-secondary tr-m-0 tr-break-words',
							deviceType === 'mobile' &&
								'tr-text-base tr-leading-6 tr-line-clamp-2'
						)}
					>
						{decodedDescription}
					</p>
				</div>
			</div>
		</div>
	);
}
