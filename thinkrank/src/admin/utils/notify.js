/**
 * notify — transient toast notifications.
 *
 * Use for ephemeral, operation-outcome feedback: "Saved", "Completed",
 * "Applied", and quick errors ("Failed, try again"). Persistent or
 * contextual messages — validation detail, results the user must read or
 * act on — should stay as inline <Alert> banners driven by component
 * state; do not route those through here.
 *
 * Thin wrapper over react-toastify so the toast-vs-banner policy and the
 * default options live in one place. Because toasts are global there is
 * no state or prop-drilling: any component can `import notify` and call
 * it. The single <ToastContainer/> is mounted in components/App.js.
 *
 * @package
 * @since 1.0.0
 */

import { toast } from 'react-toastify';

const notify = {
	success: (message, options) => toast.success(message, options),
	error: (message, options) => toast.error(message, options),
	warning: (message, options) => toast.warning(message, options),
	info: (message, options) => toast.info(message, options),
};

export default notify;
