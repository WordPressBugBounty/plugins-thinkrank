/**
 * Step 5 — Ecosystem. Toggles select which WordPress.org plugins get installed
 * when the user clicks Continue; selection is owned by the wizard container.
 *
 * @package
 * @since 1.0.0
 */

import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import schedulepress from '../assets/plugins/schedulepress.svg';
import betterdocs from '../assets/plugins/betterdocs.svg';
import templately from '../assets/plugins/templately.svg';
import essentialAddons from '../assets/plugins/essential-addons.svg';
import essentialBlocks from '../assets/plugins/essential-blocks.svg';
import notificationx from '../assets/plugins/notificationx.svg';
import betterPayment from '../assets/plugins/better-payment.svg';
import easyjobs from '../assets/plugins/easyjobs.svg';
import betterlinks from '../assets/plugins/betterlinks.svg';
import embedpress from '../assets/plugins/embedpress.svg';
import xspeed from '../assets/plugins/xspeed.png';

const PLUGINS = [
	{
		slug: 'essential-addons',
		name: 'Essential Addons',
		logo: essentialAddons,
		desc: __(
			'Powerful Elementor widgets library with 100+ advanced, fully customizable elements & extensions to enhance your website designing experience.',
			'thinkrank'
		),
	},
	{
		slug: 'betterdocs',
		name: 'BetterDocs',
		logo: betterdocs,
		desc: __(
			'Create & organize your documentation page in a beautiful way so visitors can find any help article easily.',
			'thinkrank'
		),
	},
	{
		slug: 'xspeed',
		name: 'xSpeed Cache',
		logo: xspeed,
		desc: __(
			'A complete WordPress caching plugin: page cache, object cache, CDN, database cleanup and Core Web Vitals optimization, plus a built-in MCP server.',
			'thinkrank'
		),
	},
	{
		slug: 'betterlinks',
		name: 'BetterLinks',
		logo: betterlinks,
		desc: __(
			'Best link-shortening tool to create, shorten and manage any URL to cross-promote your brands & products, with analytics and campaigns.',
			'thinkrank'
		),
	},
	{
		slug: 'better-payment',
		name: 'Better Payment',
		logo: betterPayment,
		desc: __(
			'Streamlines transactions in Elementor, integrating PayPal, Stripe, advanced analytics, validation, and Elementor forms for secure & efficient payments.',
			'thinkrank'
		),
	},
	{
		slug: 'notificationx',
		name: 'NotificationX',
		logo: notificationx,
		desc: __(
			'Best FOMO Social Proof Plugin to boost your sales conversion. Create stunning Sales Popup & Notification Bar with Elementor support.',
			'thinkrank'
		),
	},
	{
		slug: 'schedulepress',
		name: 'SchedulePress',
		logo: schedulepress,
		desc: __(
			'Best Content Marketing Tool for WordPress – schedule, organize & auto-share blog posts with Schedule Calendar, Auto & Manual Scheduler and more.',
			'thinkrank'
		),
	},
	{
		slug: 'easyjobs',
		name: 'easy.jobs',
		logo: easyjobs,
		desc: __(
			'Easy recruitment solution to attract, manage & hire the right talent faster. Manage jobs & career pages in Elementor.',
			'thinkrank'
		),
	},
	{
		slug: 'embedpress',
		name: 'EmbedPress',
		logo: embedpress,
		desc: __(
			'Embed videos, images, posts, audio, maps and upload PDF, DOC, PPT & all other content types into your WordPress site.',
			'thinkrank'
		),
	},
	{
		slug: 'essential-blocks',
		name: 'Essential Blocks',
		logo: essentialBlocks,
		desc: __(
			'The most popular Gutenberg blocks library with 50+ advanced, customizable blocks & patterns to build stunning websites faster in the block editor.',
			'thinkrank'
		),
	},
	{
		slug: 'templately',
		name: 'Templately',
		logo: templately,
		desc: __(
			'AI-powered WordPress templates cloud with 6,500+ ready Elementor and Gutenberg templates, 1-click full-site import, global color & typography control, and more.',
			'thinkrank'
		),
	},
];

/**
 * Every selectable plugin slug — the wizard uses this as its default selection.
 */
export const ECOSYSTEM_SLUGS = PLUGINS.map((plugin) => plugin.slug);

function PluginCard({ plugin, checked, active, onToggle }) {
	return (
		<div className="tr-wizard__plugin-card">
			<div className="tr-wizard__plugin-head">
				<img
					className="tr-wizard__plugin-logo"
					src={plugin.logo}
					alt=""
					aria-hidden="true"
				/>
				<span className="tr-wizard__plugin-name">{plugin.name}</span>
				{active && (
					<span className="tr-wizard__plugin-status">
						{__('Active', 'thinkrank')}
					</span>
				)}
				<ToggleControl
					checked={checked}
					disabled={active}
					onChange={(next) => onToggle(plugin.slug, next)}
					__nextHasNoMarginBottom
				/>
			</div>
			<p className="tr-wizard__plugin-desc">{plugin.desc}</p>
		</div>
	);
}

export default function Step5Ecosystem({
	selected = [],
	activePlugins = [],
	onToggle,
}) {
	return (
		<div className="tr-wizard__ecosystem">
			<div className="tr-wizard__section-label">
				{__('WPDeveloper ecosystem', 'thinkrank')}
			</div>
			<div className="tr-wizard__plugin-grid">
				{PLUGINS.map((plugin) => (
					<PluginCard
						key={plugin.slug}
						plugin={plugin}
						checked={selected.includes(plugin.slug)}
						active={activePlugins.includes(plugin.slug)}
						onToggle={onToggle}
					/>
				))}
			</div>
		</div>
	);
}
