/**
 * Step 6 — Social Profiles (functional).
 *
 * Full-width rows: brand icon + network label on the left, the URL input inline
 * on the right. Saved on continue into the Organization schema sameAs.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';

import facebook from '../assets/social/facebook.svg';
import x from '../assets/social/x.svg';
import instagram from '../assets/social/instagram.svg';
import youtube from '../assets/social/youtube.svg';
import pinterest from '../assets/social/pinterest.svg';
import whatsapp from '../assets/social/whatsapp.svg';
import linkedin from '../assets/social/linkedin.svg';
import telegram from '../assets/social/telegram.svg';

const FIELDS = [
	{
		key: 'facebook',
		label: __('Facebook', 'thinkrank'),
		placeholder: 'https://www.facebook.com/my-page-url',
		icon: facebook,
	},
	{
		key: 'x',
		label: __('X', 'thinkrank'),
		placeholder: 'https://x.com/myaccount',
		icon: x,
	},
	{
		key: 'instagram',
		label: __('Instagram', 'thinkrank'),
		placeholder: 'https://www.instagram.com/my-page-url',
		icon: instagram,
	},
	{
		key: 'youtube',
		label: __('YouTube', 'thinkrank'),
		placeholder: 'https://www.youtube.com/my-channel-url',
		icon: youtube,
	},
	{
		key: 'pinterest',
		label: __('Pinterest', 'thinkrank'),
		placeholder: 'https://www.pinterest.com/my-page-url',
		icon: pinterest,
	},
	{
		key: 'whatsapp',
		label: __('WhatsApp', 'thinkrank'),
		placeholder: 'https://wa.me/number',
		icon: whatsapp,
	},
	{
		key: 'linkedin',
		label: __('Linkedin', 'thinkrank'),
		placeholder: 'https://www.linkedin.com/in/',
		icon: linkedin,
	},
	{
		key: 'telegram',
		label: __('Telegram', 'thinkrank'),
		placeholder: 'https://t.me',
		icon: telegram,
	},
];

export default function Step6Social({ social, onSocialChange }) {
	return (
		<div className="tr-wizard__social-list">
			{FIELDS.map((field) => (
				<div key={field.key} className="tr-wizard__social-row">
					<img
						className="tr-wizard__social-brand"
						src={field.icon}
						alt=""
						aria-hidden="true"
					/>
					<span className="tr-wizard__social-name">
						{field.label}
					</span>
					<input
						className="tr-wizard__social-input"
						type="url"
						value={social[field.key] || ''}
						placeholder={field.placeholder}
						aria-label={field.label}
						onChange={(e) =>
							onSocialChange({ [field.key]: e.target.value })
						}
					/>
				</div>
			))}
		</div>
	);
}
