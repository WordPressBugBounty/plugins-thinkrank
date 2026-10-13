/**
 * Deployment Status Section
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';
import successIcon from '../../../shared/assets/success-icon.svg';
import warningIcon from '../../../shared/assets/warning-icon.svg';

const card =
	'tr-mt-4 tr-bg-white tr-rounded-lg tr-border tr-border-secondary tr-mb-4';
const cardHeader = 'tr-px-4 tr-py-3 tr-border-b tr-border-secondary';
const cardTitle = 'tr-text-sm tr-font-semibold tr-text-primary tr-m-0';
const cardBody = 'tr-p-4 tr-flex tr-flex-col tr-gap-3';
const btnPrimary =
	'tr-inline-flex tr-items-center tr-gap-1.5 tr-h-8 tr-px-3 tr-rounded tr-text-xs tr-font-medium tr-text-white tr-bg-brand hover:tr-bg-brand disabled:tr-opacity-50 disabled:tr-cursor-not-allowed tr-transition-colors tr-cursor-pointer tr-border-0';
const btnSecondary =
	'tr-inline-flex tr-items-center tr-gap-1.5 tr-h-8 tr-px-3 tr-rounded tr-border tr-border-primary tr-bg-white tr-text-xs tr-font-medium tr-text-secondary hover:tr-bg-white disabled:tr-opacity-50 disabled:tr-cursor-not-allowed tr-transition-colors tr-cursor-pointer';

const Spinner = () => (
	<span className="tr-w-3 tr-h-3 tr-border-2 tr-border-white tr-border-t-transparent tr-rounded-full tr-animate-spin tr-shrink-0" />
);

const DeploymentSection = ({
	deploymentStatus,
	isGenerating,
	isDeploying,
	onGenerateSchema,
	onDeploySchema,
}) => {
	if (deploymentStatus === 'not_generated') {
		return (
			<div className={card}>
				<div className={cardHeader}>
					<h4 className={cardTitle}>
						{__('Schema Status', 'thinkrank')}
					</h4>
				</div>
				<div className={cardBody}>
					<div className="schema-status-notice">
						<span className="dashicons dashicons-info"></span>
						<p>
							{__(
								'No schema markup generated yet. Select a schema type and click "Generate Schema" to get started.',
								'thinkrank'
							)}
						</p>
					</div>
				</div>
			</div>
		);
	}

	if (deploymentStatus === 'ready_to_deploy') {
		return (
			<div className={card}>
				<div className={cardHeader}>
					<h4 className={cardTitle}>
						{__('Deploy Schema', 'thinkrank')}
					</h4>
				</div>
				<div className={cardBody}>
					<div className="schema-status-notice success">
						<img src={successIcon} alt="" />
						<p>
							{__(
								'Schema markup generated successfully! Ready to deploy.',
								'thinkrank'
							)}
						</p>
					</div>
					<div className="schema-actions">
						<button
							type="button"
							onClick={onDeploySchema}
							disabled={isDeploying}
							className={btnPrimary}
						>
							{isDeploying && <Spinner />}
							{isDeploying
								? __('Deploying…', 'thinkrank')
								: __('Deploy Schema', 'thinkrank')}
						</button>
					</div>
					<p className="tr-text-[11px] tr-text-tertiary tr-m-0">
						{__(
							'Schema markup will be deployed as JSON-LD format in the document head.',
							'thinkrank'
						)}
					</p>
				</div>
			</div>
		);
	}

	if (deploymentStatus === 'deployed') {
		return (
			<div className={card}>
				<div className={cardHeader}>
					<h4 className={cardTitle}>
						{__('Schema Actions', 'thinkrank')}
					</h4>
				</div>
				<div className={cardBody}>
					<div className="schema-status-notice deployed">
						<img src={successIcon} alt="" />
						<p>
							{__(
								'Schema markup is deployed and active on this post.',
								'thinkrank'
							)}
						</p>
					</div>
					<div className="schema-actions">
						<button
							type="button"
							onClick={onGenerateSchema}
							disabled={isGenerating}
							className={btnPrimary}
						>
							{isGenerating && <Spinner />}
							{isGenerating
								? __('Regenerating…', 'thinkrank')
								: __('Regenerate Schema', 'thinkrank')}
						</button>
						<button
							type="button"
							onClick={onDeploySchema}
							disabled={isDeploying}
							className={btnSecondary}
						>
							{isDeploying && (
								<span className="tr-w-3 tr-h-3 tr-border-2 tr-border-brand tr-border-t-transparent tr-rounded-full tr-animate-spin tr-shrink-0" />
							)}
							{isDeploying
								? __('Redeploying…', 'thinkrank')
								: __('Redeploy Current', 'thinkrank')}
						</button>
					</div>
				</div>
			</div>
		);
	}

	if (deploymentStatus === 'ready_to_redeploy') {
		return (
			<div className={card}>
				<div className={cardHeader}>
					<h4 className={cardTitle}>
						{__('Update Schema', 'thinkrank')}
					</h4>
				</div>
				<div className={cardBody}>
					<div className="schema-status-notice warning">
						<img src={warningIcon} alt="" />
						<p>
							{__(
								'New schema markup generated. Deploy to update the existing schema.',
								'thinkrank'
							)}
						</p>
					</div>
					<div className="schema-actions">
						<button
							type="button"
							onClick={onDeploySchema}
							disabled={isDeploying}
							className={btnPrimary}
						>
							{isDeploying && <Spinner />}
							{isDeploying
								? __('Updating…', 'thinkrank')
								: __('Update Schema', 'thinkrank')}
						</button>
					</div>
					<p className="tr-text-[11px] tr-text-tertiary tr-m-0">
						{__(
							'This will update your existing schema markup with the new version.',
							'thinkrank'
						)}
					</p>
				</div>
			</div>
		);
	}

	return null;
};

export default DeploymentSection;
