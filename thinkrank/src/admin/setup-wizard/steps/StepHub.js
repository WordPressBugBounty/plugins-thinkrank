/**
 * ThinkRank Hub step — connect this site to the hub, or skip it.
 *
 * The same card as AI Tools › ThinkRank Hub, so the connection reads and
 * behaves the same in both places. Connecting leaves for the hub and comes
 * back to the wizard, which reopens on this step from the saved progress.
 * Nothing to save here: the step is optional and never blocks the wizard.
 *
 * @package
 * @since 2.15.0
 */

import MCPHubConnect from '../../components/pages/MCPHubConnect';

export default function StepHub() {
	// `tr-root` is the scope Tailwind compiles its utilities under, and the
	// card is built on them; the wizard itself is not inside that scope.
	return (
		<div className="tr-wizard__hub tr-root">
			<MCPHubConnect from="wizard" />
		</div>
	);
}
