/**
 * Role Manager Tab
 *
 * Role × capability matrix for ThinkRank access control. Admins choose which
 * roles can access which ThinkRank areas. The administrator role always has
 * full access and is intentionally not listed.
 *
 * Built on the ThinkRank design system (src/admin/components/ui) — see
 * docs/ui/design-system.md.
 *
 * @package
 * @since 1.12.0
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import classnames from 'classnames';
import { Card, Button, Alert, Badge, SkeletonCard } from '../ui';
import notify from '../../utils/notify';

const API = '/thinkrank/v1/role-manager';

const RoleManagerTab = () => {
	const [isLoading, setIsLoading] = useState(true);
	const [isSaving, setIsSaving] = useState(false);
	const [roles, setRoles] = useState([]);
	const [capabilities, setCapabilities] = useState([]);
	const [matrix, setMatrix] = useState({});
	const [baseCap, setBaseCap] = useState('thinkrank_access');
	// The WordPress capability a role needs before any grant here takes effect.
	const [baselineCap, setBaselineCap] = useState('edit_posts');
	// Banner state is for the load failure only — a persistent condition the
	// user has to act on (reload). The save outcome is transient and goes to a
	// toast, per the policy in utils/notify.js.
	const [notice, setNotice] = useState(null);

	useEffect(() => {
		apiFetch({ path: API })
			.then((res) => {
				const data = res?.data || {};
				setRoles(data.roles || []);
				setCapabilities(data.capabilities || []);
				setMatrix(data.matrix || {});
				if (data.base_cap) {
					setBaseCap(data.base_cap);
				}
				if (data.baseline_cap) {
					setBaselineCap(data.baseline_cap);
				}
			})
			.catch((e) =>
				setNotice({
					type: 'error',
					message:
						e?.message || __('Failed to load roles.', 'thinkrank'),
				})
			)
			.finally(() => setIsLoading(false));
	}, []);

	const hasCap = (roleSlug, capKey) =>
		(matrix[roleSlug] || []).includes(capKey);

	const toggle = useCallback(
		(roleSlug, capKey) => {
			setMatrix((prev) => {
				const current = new Set(prev[roleSlug] || []);
				if (current.has(capKey)) {
					current.delete(capKey);
				} else {
					current.add(capKey);
					current.add(baseCap);
				}
				return { ...prev, [roleSlug]: Array.from(current) };
			});
		},
		[baseCap]
	);

	const save = useCallback(async () => {
		setIsSaving(true);
		setNotice(null);
		try {
			const res = await apiFetch({
				path: API,
				method: 'POST',
				data: { matrix },
			});
			setMatrix(res?.data?.matrix || matrix);
			notify.success(__('Access settings saved.', 'thinkrank'));
		} catch (e) {
			notify.error(e?.message || __('Failed to save.', 'thinkrank'));
		} finally {
			setIsSaving(false);
		}
	}, [matrix]);

	if (isLoading) {
		return (
			<SkeletonCard lines={5} label={__('Loading roles…', 'thinkrank')} />
		);
	}

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{notice && (
				<Alert variant={notice.type} onDismiss={() => setNotice(null)}>
					{notice.message}
				</Alert>
			)}

			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-slate-100">
					<h3 className="tr-text-base tr-font-semibold tr-text-slate-900 tr-m-0">
						{__('Role Manager', 'thinkrank')}
					</h3>
					<p className="tr-text-sm tr-text-slate-500 tr-mt-0.5 tr-mb-0">
						{__(
							'Choose which user roles can access each ThinkRank area. Administrators always have full access.',
							'thinkrank'
						)}
					</p>
				</div>

				{roles.length === 0 ? (
					<div className="tr-p-8 tr-text-center tr-text-sm tr-text-slate-400">
						{__('No editable roles found.', 'thinkrank')}
					</div>
				) : (
					<>
						{/* Table at md and up. Both axes are pinned: at 390px
						    the grid is 1087px wide in a 356px window, so
						    reaching the last role meant scrolling the
						    capability label 714px off the left edge and
						    ticking permissions against a row the user could no
						    longer identify (#837). A sticky cell is
						    transparent by default, so the background class is
						    what stops the scrolling rows showing through it,
						    and the height cap gives the header something to
						    stick against. */}
						<div className="tr-hidden md:tr-block tr-relative tr-overflow-auto tr-max-h-[70vh]">
							<table className="tr-w-full tr-border-collapse tr-text-sm">
								<thead>
									<tr className="tr-border-b tr-border-slate-200">
										<th className="tr-sticky tr-left-0 tr-top-0 tr-z-20 tr-bg-white tr-text-left tr-px-4 tr-py-3 tr-font-semibold tr-text-slate-500 tr-border-r tr-border-slate-100">
											{__('Capability', 'thinkrank')}
										</th>
										{roles.map((role) => (
											<th
												key={role.slug}
												className={classnames(
													'tr-sticky tr-top-0 tr-z-10 tr-bg-white tr-text-center tr-px-4 tr-py-3 tr-font-semibold',
													role.can_use === false
														? 'tr-text-slate-400'
														: 'tr-text-slate-900'
												)}
											>
												{role.name}
												{role.can_use === false && (
													<span className="tr-block tr-text-[11px] tr-font-normal tr-text-slate-400 tr-mt-0.5">
														{__(
															'cannot use ThinkRank',
															'thinkrank'
														)}
													</span>
												)}
											</th>
										))}
									</tr>
								</thead>
								<tbody>
									{capabilities.map((cap) => (
										<tr
											key={cap.key}
											className="tr-border-b tr-border-slate-100"
										>
											<td className="tr-sticky tr-left-0 tr-z-10 tr-bg-white tr-px-4 tr-py-2.5 tr-text-slate-700 tr-border-r tr-border-slate-100">
												{cap.label}
												{cap.key === baseCap && (
													<Badge
														variant="info"
														className="tr-ml-2 tr-uppercase"
													>
														{__(
															'base',
															'thinkrank'
														)}
													</Badge>
												)}
											</td>
											{roles.map((role) => (
												<td
													key={role.slug}
													className="tr-text-center tr-px-4 tr-py-2.5"
												>
													{/* The box stays 16px as drawn and the
													    label around it carries the touch
													    target: wp-admin forces padding:0 on
													    a checkbox, so padding the input
													    itself does nothing. */}
													{/* eslint-disable-next-line jsx-a11y/label-has-associated-control */}
													<label className="tr-inline-flex tr-items-center tr-justify-center tr-p-1.5 tr-cursor-pointer">
														<input
															type="checkbox"
															className="tr-w-4 tr-h-4 tr-border tr-border-slate-300 tr-cursor-pointer"
															style={{
																accentColor:
																	'var(--tr-accent)',
															}}
															checked={hasCap(
																role.slug,
																cap.key
															)}
															onChange={() =>
																toggle(
																	role.slug,
																	cap.key
																)
															}
															aria-label={`${role.name}: ${cap.label}`}
														/>
													</label>
												</td>
											))}
										</tr>
									))}
								</tbody>
							</table>
						</div>

						{/* Below md, one card per role. Every checkbox carries
						    its own visible label, so nothing has to stay on
						    screen while something else scrolls, and there is no
						    horizontal scrolling at all. Same matrix state and
						    the same toggle(), so a save from here sends the
						    identical payload. */}
						<div className="md:tr-hidden tr-flex tr-flex-col tr-gap-4 tr-p-4">
							{roles.map((role) => (
								<div
									key={role.slug}
									className="tr-border tr-border-slate-200 tr-rounded-lg tr-overflow-hidden"
								>
									<div className="tr-px-4 tr-py-3 tr-bg-slate-50 tr-border-b tr-border-slate-200">
										<h4
											className={classnames(
												'tr-text-sm tr-font-semibold tr-m-0',
												role.can_use === false
													? 'tr-text-slate-400'
													: 'tr-text-slate-900'
											)}
										>
											{role.name}
										</h4>
										{role.can_use === false && (
											<span className="tr-block tr-text-[11px] tr-font-normal tr-text-slate-400 tr-mt-0.5">
												{__(
													'cannot use ThinkRank',
													'thinkrank'
												)}
											</span>
										)}
									</div>
									<ul className="tr-m-0 tr-p-0 tr-list-none">
										{capabilities.map((cap) => (
											<li
												key={cap.key}
												className="tr-border-b tr-border-slate-100 last:tr-border-b-0"
											>
												<label
													className="tr-flex tr-items-center tr-gap-3 tr-px-4 tr-py-3 tr-cursor-pointer"
													htmlFor={`tr-role-${role.slug}-${cap.key}`}
												>
													<input
														type="checkbox"
														id={`tr-role-${role.slug}-${cap.key}`}
														className="tr-w-4 tr-h-4 tr-border tr-border-slate-300 tr-cursor-pointer tr-shrink-0"
														style={{
															accentColor:
																'var(--tr-accent)',
														}}
														checked={hasCap(
															role.slug,
															cap.key
														)}
														onChange={() =>
															toggle(
																role.slug,
																cap.key
															)
														}
													/>
													<span className="tr-text-sm tr-text-slate-700">
														{cap.label}
														{cap.key ===
															baseCap && (
															<Badge
																variant="info"
																className="tr-ml-2 tr-uppercase"
															>
																{__(
																	'base',
																	'thinkrank'
																)}
															</Badge>
														)}
													</span>
												</label>
											</li>
										))}
									</ul>
								</div>
							))}
						</div>
					</>
				)}

				{roles.some((role) => role.can_use === false) && (
					<p className="tr-px-5 tr-pt-4 tr-pb-0 tr-m-0 tr-text-xs tr-text-slate-500">
						{sprintf(
							/* translators: %s: WordPress capability, e.g. edit_posts. */
							__(
								'Roles marked "cannot use ThinkRank" lack the %s capability, which ThinkRank areas require in addition to the access granted here. You can still set their access now. It takes effect once the role gains that capability.',
								'thinkrank'
							),
							baselineCap
						)}
					</p>
				)}

				<div className="tr-flex tr-justify-end tr-px-5 tr-py-4 tr-border-t tr-border-slate-100">
					<Button
						variant="primary"
						loading={isSaving}
						disabled={roles.length === 0}
						onClick={save}
					>
						{__('Save changes', 'thinkrank')}
					</Button>
				</div>
			</Card>
		</div>
	);
};

export default RoleManagerTab;
