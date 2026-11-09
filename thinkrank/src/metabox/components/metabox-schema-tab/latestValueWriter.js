/**
 * Save a setting one write at a time, and end on the last value asked for.
 *
 * A checkbox that saves on change can be clicked again while the previous
 * save is in flight. Two concurrent POSTs can land in either order, so the
 * server could keep the older choice while the box shows the newer one, and a
 * failed earlier request could roll the box back over a later one that
 * succeeded (#821).
 *
 * Here a write never overlaps another. A value requested while one is running
 * waits, and only the newest waiting value is written. `onSettled` is called
 * once the chain goes idle, with the value the UI should show: the last one
 * requested when its write succeeded, otherwise the last one the server
 * accepted.
 *
 * @param {Object}                                    options
 * @param {(value: *) => Promise<boolean>}            options.write     Persist a value; resolves true on success.
 * @param {(result: {ok: boolean, value: *}) => void} options.onSettled Called when no write is running or waiting.
 * @return {{request: (value: *, shown: *) => void}} Writer.
 */
const createLatestValueWriter = ({ write, onSettled }) => {
	let running = false;
	let waiting = null;
	let accepted;

	const run = async (value) => {
		running = true;

		let ok = false;
		try {
			ok = true === (await write(value));
		} catch (error) {
			ok = false;
		}

		if (ok) {
			accepted = value;
		}

		if (waiting) {
			const next = waiting.value;
			waiting = null;
			run(next);
			return;
		}

		running = false;
		onSettled({ ok, value: ok ? value : accepted });
	};

	return {
		/**
		 * @param {*} value Value to persist.
		 * @param {*} shown Value shown before this change. While nothing is
		 *                  running it is what the server holds, and the value
		 *                  to fall back to if the write fails.
		 */
		request(value, shown) {
			if (running) {
				waiting = { value };
				return;
			}

			accepted = shown;
			run(value);
		},
	};
};

export default createLatestValueWriter;
