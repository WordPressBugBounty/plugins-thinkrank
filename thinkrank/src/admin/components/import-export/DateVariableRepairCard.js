/**
 * Date Variable Repair Card
 *
 * Migrations that ran before ThinkRank had current-date variables stored the
 * year as text, so "Best shoes %currentyear%" reads "Best shoes 2026" for good
 * (#1497). A second migration never replaces an existing value, so this card
 * finds those values and puts the variable back. Only a value that still equals
 * the source plugin's original, with a date an importer could have written, is
 * offered. A restore has no undo and a hand-typed date can still fit, so the
 * preview lists every value before anything is written (#1507).
 *
 * @package
 * @since 2.21.0
 */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { Card, Alert, Button } from '../ui';
import notify from '../../utils/notify';

const API_PATH = '/thinkrank/v1/import/date-variables';
const TYPES = ['postmeta', 'termmeta'];
const PREVIEW_STEP = 50;

/**
 * Walk every page of every source, in one mode.
 *
 * @param {Array}   sources Source plugins, {plugin, plugin_name}.
 * @param {boolean} apply   Whether to restore the values found.
 * @return {Promise<{count: number, matches: Array}>} Everything found.
 */
export const walkSources = async (sources, apply) => {
	const matches = [];

	for (const source of sources) {
		for (const type of TYPES) {
			let page = 1;
			let hasMore = true;

			while (hasMore) {
				const result = await apiFetch({
					path: API_PATH,
					method: 'POST',
					data: { plugin: source.plugin, type, page, apply },
				});

				matches.push(...(result?.matches || []));
				hasMore = !!result?.has_more;
				page++;
			}
		}
	}

	return { count: matches.length, matches };
};

/**
 * @param {Object} props         Props.
 * @param {Array}  props.sources Source plugins this site imported from.
 * @return {JSX.Element} Card.
 */
const DateVariableRepairCard = ({ sources }) => {
	// idle, scanning, found, restoring or clean.
	const [status, setStatus] = useState('idle');
	const [found, setFound] = useState({ count: 0, matches: [] });
	// How much of the list is on screen. Every value stays one click away.
	const [visible, setVisible] = useState(PREVIEW_STEP);

	const scan = async () => {
		setStatus('scanning');

		try {
			const result = await walkSources(sources, false);
			setFound(result);
			setVisible(PREVIEW_STEP);
			setStatus(result.count > 0 ? 'found' : 'clean');
		} catch (error) {
			setStatus('idle');
			notify.error(
				error?.message ||
					__('Could not check your migrated values.', 'thinkrank')
			);
		}
	};

	const restore = async () => {
		setStatus('restoring');

		try {
			const { count } = await walkSources(sources, true);
			setFound({ count: 0, matches: [] });
			setStatus('clean');
			notify.success(
				sprintf(
					/* translators: %d: number of titles and descriptions restored. */
					_n(
						'%d value now uses a live date.',
						'%d values now use a live date.',
						count,
						'thinkrank'
					),
					count
				)
			);
		} catch (error) {
			setStatus('found');
			notify.error(
				error?.message ||
					__(
						'Could not restore every value. Check again to see what is left.',
						'thinkrank'
					)
			);
		}
	};

	return (
		<Card>
			<div className="tr-flex tr-flex-col tr-gap-3">
				<div>
					<h3 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
						{__(
							'Restore live dates in migrated titles',
							'thinkrank'
						)}
					</h3>
					<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary">
						{__(
							/* translators: %currentyear% is a literal title variable, not a printf placeholder. */
							'Older migrations saved variables like %currentyear% as a fixed year, so a title keeps saying 2026 next year. This finds values that still match your previous SEO plugin and puts the variable back. Values with a date that older migrations could not have written stay as they are.',
							'thinkrank'
						)}
					</p>
				</div>

				{'clean' === status && (
					<Alert variant="info">
						{__(
							'No fixed dates to restore. If you removed your previous SEO plugin’s data, there is nothing left to compare against, so any fixed date has to be edited by hand.',
							'thinkrank'
						)}
					</Alert>
				)}

				{'found' === status && (
					<div className="tr-flex tr-flex-col tr-gap-2">
						<p className="tr-m-0 tr-text-sm tr-font-medium tr-text-primary">
							{sprintf(
								/* translators: %d: number of titles and descriptions found. */
								_n(
									'%d value can be restored.',
									'%d values can be restored.',
									found.count,
									'thinkrank'
								),
								found.count
							)}
						</p>
						<p className="tr-m-0 tr-text-xs tr-text-secondary">
							{__(
								'Check this list before restoring. A title you fixed on purpose can look the same, and restoring cannot be undone. To keep a value, edit it first, then check again.',
								'thinkrank'
							)}
						</p>
						<ul
							className="tr-m-0 tr-p-0 tr-list-none tr-flex tr-flex-col tr-gap-2"
							style={{ maxHeight: '320px', overflowY: 'auto' }}
						>
							{found.matches.slice(0, visible).map((match) => (
								<li
									key={`${match.object_type}-${match.object_id}-${match.field}`}
									className="tr-m-0 tr-text-xs tr-text-secondary"
								>
									<span className="tr-font-medium">
										{match.title}
									</span>
									<br />
									{sprintf(
										/* translators: 1: the value today, 2: the value after restoring. */
										__('%1$s → %2$s', 'thinkrank'),
										match.from,
										match.to
									)}
								</li>
							))}
						</ul>
						{found.count > visible && (
							<div className="tr-flex tr-items-center tr-gap-2">
								<span className="tr-text-xs tr-text-secondary">
									{sprintf(
										/* translators: 1: values listed so far, 2: all values found. */
										__(
											'Showing %1$d of %2$d values.',
											'thinkrank'
										),
										visible,
										found.count
									)}
								</span>
								<Button
									variant="secondary"
									size="sm"
									onClick={() =>
										setVisible(visible + PREVIEW_STEP)
									}
								>
									{__('Show more', 'thinkrank')}
								</Button>
							</div>
						)}
					</div>
				)}

				<div>
					{'found' === status || 'restoring' === status ? (
						<Button
							variant="primary"
							size="sm"
							loading={'restoring' === status}
							onClick={restore}
						>
							{sprintf(
								/* translators: %d: number of titles and descriptions to restore. */
								_n(
									'Restore %d value',
									'Restore %d values',
									found.count,
									'thinkrank'
								),
								found.count
							)}
						</Button>
					) : (
						<Button
							variant="secondary"
							size="sm"
							loading={'scanning' === status}
							onClick={scan}
						>
							{__('Check for fixed dates', 'thinkrank')}
						</Button>
					)}
				</div>
			</div>
		</Card>
	);
};

export default DateVariableRepairCard;
