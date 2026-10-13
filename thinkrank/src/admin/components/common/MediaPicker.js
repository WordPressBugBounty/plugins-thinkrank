/**
 * Media Picker Component
 *
 * WordPress media library integration for selecting images.
 *
 * Two ways in: drag a file onto the zone (uploaded straight to the media
 * library via the REST API) or click to browse the existing library through
 * wp.media. Once something is chosen the zone becomes a framed preview with
 * the filename, dimensions, and replace/remove actions.
 *
 * The value contract is unchanged from the button-only version — single mode
 * emits a URL string, multiple mode emits an array of attachment objects — so
 * every existing call site keeps working.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useRef, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import classnames from 'classnames';

import logger from '../../../shared/utils/logger';

/**
 * Last path segment of a URL, without the query string.
 *
 * The stored value for a single selection is only a URL, so the filename has
 * to be recovered from it rather than read from the attachment.
 *
 * @param {string} url Media URL.
 * @return {string} Filename, or an empty string.
 */
const fileNameFromUrl = (url) => {
	if (typeof url !== 'string' || !url) {
		return '';
	}

	try {
		const path = new URL(url, window.location.origin).pathname;
		return decodeURIComponent(path.split('/').pop() || '');
	} catch (e) {
		return url.split('/').pop()?.split('?')[0] || '';
	}
};

/**
 * Human-readable file size.
 *
 * @param {number} bytes Size in bytes.
 * @return {string} Formatted size, or an empty string when not a number.
 */
const formatBytes = (bytes) => {
	if (typeof bytes !== 'number' || !isFinite(bytes) || bytes <= 0) {
		return '';
	}

	const units = ['B', 'KB', 'MB', 'GB'];
	const i = Math.min(
		Math.floor(Math.log(bytes) / Math.log(1024)),
		units.length - 1
	);
	const value = bytes / Math.pow(1024, i);

	return `${value >= 10 || i === 0 ? Math.round(value) : value.toFixed(1)} ${
		units[i]
	}`;
};

/**
 * The site's upload ceiling, when WordPress has told us what it is.
 *
 * Returned rather than hard-coded so the hint never promises a limit the
 * server will refuse.
 *
 * @return {number|null} Max upload size in bytes, or null if unknown.
 */
const getMaxUploadSize = () => {
	const fromMedia =
		window.wp?.media?.view?.settings?.post?.max_upload_size ??
		window._wpPluploadSettings?.defaults?.filters?.max_file_size;

	// plupload reports this as a suffixed string ("2147483648b"), while
	// wp.media hands over a plain number — take the leading digits of either.
	const digits = String(fromMedia ?? '').match(/^\d+/);
	const parsed = digits ? Number(digits[0]) : NaN;

	return isFinite(parsed) && parsed > 0 ? parsed : null;
};

/**
 * Whether a dropped file matches the accepted types.
 *
 * `allowedTypes` follows the wp.media vocabulary: a bare kind ("image") or a
 * full mime type ("image/png").
 *
 * @param {File}     file         Dropped file.
 * @param {string[]} allowedTypes Accepted types.
 * @return {boolean} True when the file is accepted.
 */
const isAllowedFile = (file, allowedTypes) => {
	if (!Array.isArray(allowedTypes) || allowedTypes.length === 0) {
		return true;
	}

	const mime = file.type || '';
	const kind = mime.split('/')[0];

	return allowedTypes.some((type) =>
		type.includes('/') ? type === mime : type === kind
	);
};

/**
 * Short description of what the zone accepts.
 *
 * @param {string[]} allowedTypes Accepted types.
 * @return {string} Label such as "Images".
 */
const describeAllowedTypes = (allowedTypes) => {
	if (!Array.isArray(allowedTypes) || allowedTypes.length === 0) {
		return __('Files', 'thinkrank');
	}

	const labels = {
		image: __('Images', 'thinkrank'),
		video: __('Videos', 'thinkrank'),
		audio: __('Audio', 'thinkrank'),
		application: __('Documents', 'thinkrank'),
	};

	return allowedTypes
		.map((type) => {
			const kind = type.split('/')[0];
			return type.includes('/')
				? type.split('/')[1].toUpperCase()
				: labels[kind] || kind;
		})
		.join(', ');
};

/**
 * Normalize a REST attachment into the shape wp.media selections produce.
 *
 * @param {Object} attachment Attachment from /wp/v2/media.
 * @return {Object} Normalized media object.
 */
const fromRestAttachment = (attachment) => ({
	id: attachment.id,
	url: attachment.source_url,
	alt: attachment.alt_text || '',
	title: attachment.title?.rendered || '',
	filename: fileNameFromUrl(attachment.source_url),
	mime: attachment.mime_type || '',
	width: attachment.media_details?.width || 0,
	height: attachment.media_details?.height || 0,
});

const UploadIcon = () => (
	<svg
		width="20"
		height="20"
		viewBox="0 0 24 24"
		fill="none"
		aria-hidden="true"
	>
		<path
			d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
		<path
			d="M4 15v2.5A2.5 2.5 0 0 0 6.5 20h11a2.5 2.5 0 0 0 2.5-2.5V15"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

const CloseIcon = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 16 16"
		fill="none"
		aria-hidden="true"
	>
		<path
			d="M4 4l8 8M12 4l-8 8"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
		/>
	</svg>
);

/**
 * Media Picker Component
 *
 * @param {Object}   root0              Props.
 * @param {*}        root0.value        Selected media (URL string, or array in multiple mode).
 * @param {Function} root0.onChange     Called with the new value.
 * @param {string}   root0.label        Field label.
 * @param {string}   root0.help         Help text below the control.
 * @param {string[]} root0.allowedTypes Accepted media types.
 * @param {boolean}  root0.multiple     Allow selecting more than one item.
 * @param {boolean}  root0.disabled     Disable the control.
 */
const MediaPicker = ({
	value,
	onChange,
	label,
	help,
	allowedTypes = ['image'],
	multiple = false,
	disabled = false,
}) => {
	const [isDraggingOver, setIsDraggingOver] = useState(false);
	const [isUploading, setIsUploading] = useState(false);
	const [error, setError] = useState('');
	const [dimensions, setDimensions] = useState(null);

	// dragenter/dragleave also fire when crossing child elements, so a plain
	// boolean flickers. Counting entries and exits keeps the highlight steady.
	const dragDepth = useRef(0);

	const hasMedia = multiple
		? Array.isArray(value) && value.length > 0
		: !!value;

	const maxUploadSize = getMaxUploadSize();

	/**
	 * Open the WordPress media library.
	 */
	const openMediaLibrary = useCallback(() => {
		if (disabled) return;

		if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
			logger.error('WordPress media library not available');
			setError(
				__(
					'The WordPress media library is unavailable on this screen.',
					'thinkrank'
				)
			);
			return;
		}

		setError('');

		const frame = wp.media({
			title: label || __('Select Media', 'thinkrank'),
			button: {
				text: __('Use this media', 'thinkrank'),
			},
			multiple,
			library: {
				type: allowedTypes,
			},
		});

		frame.on('select', () => {
			const selection = frame.state().get('selection');

			if (multiple) {
				const attachments = selection.map((attachment) => {
					const data = attachment.toJSON();
					return {
						id: data.id,
						url: data.url,
						alt: data.alt || '',
						title: data.title || '',
						filename: data.filename || '',
						mime: data.mime || '',
						width: data.width || 0,
						height: data.height || 0,
					};
				});
				onChange(attachments);
			} else {
				const attachment = selection.first().toJSON();
				setDimensions(
					attachment.width && attachment.height
						? { width: attachment.width, height: attachment.height }
						: null
				);
				onChange(attachment.url);
			}
		});

		frame.open();
	}, [disabled, label, multiple, allowedTypes, onChange]);

	/**
	 * Upload dropped files to the media library.
	 *
	 * @param {FileList|File[]} files Files from the drop event.
	 */
	const uploadFiles = useCallback(
		async (files) => {
			const incoming = Array.from(files || []);
			if (incoming.length === 0) return;

			const accepted = incoming.filter((file) =>
				isAllowedFile(file, allowedTypes)
			);

			if (accepted.length === 0) {
				setError(
					sprintf(
						/* translators: %s: accepted file types, e.g. "Images". */
						__('Only %s can be uploaded here.', 'thinkrank'),
						describeAllowedTypes(allowedTypes)
					)
				);
				return;
			}

			const queue = multiple ? accepted : [accepted[0]];

			const tooBig = queue.find(
				(file) => maxUploadSize && file.size > maxUploadSize
			);

			if (tooBig) {
				setError(
					sprintf(
						/* translators: %s: maximum upload size, e.g. "5 MB". */
						__(
							'That file is larger than the %s limit.',
							'thinkrank'
						),
						formatBytes(maxUploadSize)
					)
				);
				return;
			}

			setError('');
			setIsUploading(true);

			try {
				const uploaded = [];

				for (const file of queue) {
					const body = new FormData();
					body.append('file', file, file.name);

					// eslint-disable-next-line no-await-in-loop
					const attachment = await apiFetch({
						path: '/wp/v2/media',
						method: 'POST',
						body,
					});

					uploaded.push(fromRestAttachment(attachment));
				}

				if (multiple) {
					const existing = Array.isArray(value) ? value : [];
					onChange([...existing, ...uploaded]);
				} else {
					const [first] = uploaded;
					setDimensions(
						first.width && first.height
							? { width: first.width, height: first.height }
							: null
					);
					onChange(first.url);
				}
			} catch (uploadError) {
				logger.error('Media upload failed', uploadError);
				setError(
					uploadError?.message ||
						__('Upload failed. Please try again.', 'thinkrank')
				);
			} finally {
				setIsUploading(false);
			}
		},
		[allowedTypes, maxUploadSize, multiple, onChange, value]
	);

	const handleDragEnter = (event) => {
		if (disabled || isUploading) return;
		event.preventDefault();
		dragDepth.current += 1;
		setIsDraggingOver(true);
	};

	const handleDragOver = (event) => {
		if (disabled || isUploading) return;
		// Without this the browser navigates to the dropped file.
		event.preventDefault();
	};

	const handleDragLeave = (event) => {
		if (disabled || isUploading) return;
		event.preventDefault();
		dragDepth.current = Math.max(0, dragDepth.current - 1);
		if (dragDepth.current === 0) {
			setIsDraggingOver(false);
		}
	};

	const handleDrop = (event) => {
		if (disabled || isUploading) return;
		event.preventDefault();
		dragDepth.current = 0;
		setIsDraggingOver(false);
		uploadFiles(event.dataTransfer?.files);
	};

	/**
	 * Clear the selection.
	 */
	const removeMedia = () => {
		setDimensions(null);
		setError('');
		onChange(multiple ? [] : '');
	};

	/**
	 * Drop one item from a multiple selection.
	 *
	 * @param {number} index Index to remove.
	 */
	const removeItemAt = (index) => {
		const next = (Array.isArray(value) ? value : []).filter(
			(item, i) => i !== index
		);
		onChange(next);
	};

	const dropHandlers = {
		onDragEnter: handleDragEnter,
		onDragOver: handleDragOver,
		onDragLeave: handleDragLeave,
		onDrop: handleDrop,
	};

	const singleUrl = typeof value === 'object' && value ? value.url : value;
	const singleName =
		(typeof value === 'object' && value?.filename) ||
		fileNameFromUrl(singleUrl);

	/**
	 * The dashed zone shown until something is selected.
	 */
	const renderDropZone = () => (
		<button
			type="button"
			onClick={openMediaLibrary}
			disabled={disabled || isUploading}
			aria-label={
				label
					? sprintf(
							/* translators: %s: field label, e.g. "Organization Logo". */
							__('Select media for %s', 'thinkrank'),
							label
						)
					: __('Select Media', 'thinkrank')
			}
			className={classnames(
				'tr-w-full tr-flex tr-items-center tr-gap-2.5 tr-text-left',
				'tr-px-3 tr-py-2.5 tr-rounded-md tr-border-[1.5px] tr-border-dashed',
				'tr-transition-colors tr-duration-150 tr-cursor-pointer',
				'focus:tr-outline-none focus-visible:tr-border-brand',
				'disabled:tr-cursor-not-allowed disabled:tr-opacity-60',
				isDraggingOver
					? 'tr-border-brand tr-bg-brand-bg'
					: 'tr-border-primary tr-bg-[#F5F6FA] hover:tr-border-brand hover:tr-bg-brand-bg'
			)}
			{...dropHandlers}
		>
			<span
				className={classnames(
					'tr-shrink-0 tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded tr-bg-white tr-border tr-border-secondary',
					isDraggingOver ? 'tr-text-brand' : 'tr-text-tertiary'
				)}
			>
				<UploadIcon />
			</span>

			<span className="tr-flex-1 tr-min-w-0 tr-text-sm tr-text-secondary tr-truncate">
				{isUploading
					? __('Uploading…', 'thinkrank')
					: __('Drop a file or browse', 'thinkrank')}
			</span>

			<span className="tr-shrink-0 tr-text-xs tr-text-tertiary">
				{maxUploadSize
					? sprintf(
							/* translators: 1: accepted types, 2: max size. */
							__('%1$s · up to %2$s', 'thinkrank'),
							describeAllowedTypes(allowedTypes),
							formatBytes(maxUploadSize)
						)
					: describeAllowedTypes(allowedTypes)}
			</span>
		</button>
	);

	/**
	 * Secondary line under the filename: progress, then real dimensions once
	 * the preview image has loaded and reported them.
	 *
	 * @return {string} Status text.
	 */
	const describeSelection = () => {
		if (isUploading) {
			return __('Uploading…', 'thinkrank');
		}

		if (dimensions) {
			return sprintf(
				/* translators: 1: width in pixels, 2: height in pixels. */
				__('%1$d × %2$d px', 'thinkrank'),
				dimensions.width,
				dimensions.height
			);
		}

		return __('Image', 'thinkrank');
	};

	/**
	 * The framed preview shown once a single item is selected.
	 */
	const renderSinglePreview = () => (
		<div
			className={classnames(
				'tr-flex tr-items-center tr-gap-2.5 tr-p-2 tr-rounded-md',
				'tr-border-[1.5px] tr-bg-white tr-transition-colors tr-duration-150',
				isDraggingOver ? 'tr-border-brand' : 'tr-border-primary'
			)}
			{...dropHandlers}
		>
			<span className="tr-shrink-0 tr-w-10 tr-h-10 tr-rounded tr-border tr-border-secondary tr-bg-white tr-flex tr-items-center tr-justify-center tr-overflow-hidden">
				<img
					src={singleUrl}
					alt=""
					onLoad={(event) =>
						setDimensions({
							width: event.target.naturalWidth,
							height: event.target.naturalHeight,
						})
					}
					className="tr-max-w-full tr-max-h-full tr-object-contain"
				/>
			</span>

			<div className="tr-flex-1 tr-min-w-0">
				<p
					className="tr-text-sm tr-text-primary tr-m-0 tr-truncate"
					title={singleName}
				>
					{singleName || __('Selected media', 'thinkrank')}
				</p>
				<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
					{describeSelection()}
				</p>
			</div>

			<div className="tr-flex tr-items-center tr-gap-1 tr-shrink-0">
				<button
					type="button"
					onClick={openMediaLibrary}
					disabled={disabled || isUploading}
					className="tr-text-sm tr-font-medium tr-text-brand tr-bg-transparent tr-border-0 tr-cursor-pointer tr-px-2 tr-py-1 tr-rounded hover:tr-bg-brand-bg disabled:tr-cursor-not-allowed disabled:tr-opacity-60"
				>
					{__('Replace', 'thinkrank')}
				</button>
				<button
					type="button"
					onClick={removeMedia}
					disabled={disabled || isUploading}
					aria-label={__('Remove media', 'thinkrank')}
					className="tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-rounded tr-text-tertiary tr-bg-transparent tr-border-0 tr-cursor-pointer hover:tr-bg-danger-bg hover:tr-text-danger disabled:tr-cursor-not-allowed disabled:tr-opacity-60"
				>
					<CloseIcon />
				</button>
			</div>
		</div>
	);

	/**
	 * Thumbnail grid for a multiple selection.
	 */
	const renderMultiplePreview = () => (
		<div className="tr-flex tr-flex-wrap tr-gap-2">
			{value.map((item, index) => {
				const url = typeof item === 'object' ? item.url : item;
				return (
					<div
						key={item?.id ?? url ?? index}
						className="tr-relative tr-w-20 tr-h-20 tr-rounded tr-border tr-border-secondary tr-bg-white tr-overflow-hidden tr-flex tr-items-center tr-justify-center"
					>
						<img
							src={url}
							alt={item?.alt || ''}
							className="tr-max-w-full tr-max-h-full tr-object-contain"
						/>
						<button
							type="button"
							onClick={() => removeItemAt(index)}
							disabled={disabled || isUploading}
							aria-label={__('Remove media', 'thinkrank')}
							className="tr-absolute tr-top-1 tr-right-1 tr-flex tr-items-center tr-justify-center tr-w-5 tr-h-5 tr-rounded tr-bg-white/90 tr-text-tertiary tr-border tr-border-secondary tr-cursor-pointer hover:tr-text-danger disabled:tr-cursor-not-allowed"
						>
							<CloseIcon />
						</button>
					</div>
				);
			})}
		</div>
	);

	return (
		// `tr-root` is the scope Tailwind compiles its utilities under, so the
		// zone below styles correctly outside the admin app shell too (e.g. the
		// setup wizard).
		<div className="thinkrank-media-picker tr-root">
			{label && (
				// Block so the existing .thinkrank-media-picker label spacing
				// applies — the <Spacer> that used to provide it is gone.
				<span className="components-base-control__label tr-block">
					{label}
				</span>
			)}

			<div className="tr-flex tr-flex-col tr-gap-2">
				{multiple && hasMedia && renderMultiplePreview()}

				{!multiple && hasMedia
					? renderSinglePreview()
					: renderDropZone()}

				{error && (
					<p
						role="alert"
						className="tr-text-xs tr-text-danger tr-m-0"
					>
						{error}
					</p>
				)}
			</div>

			{help && <p className="components-base-control__help">{help}</p>}
		</div>
	);
};

export default MediaPicker;
