<?php
/**
 * Update sitemap settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Sitemap_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates ThinkRank XML sitemap settings.
 *
 * Sitemap settings are persisted through the Sitemap_Generator SEO manager.
 */
class Update_Sitemap_Settings extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-sitemap-settings';
		$this->label       = __( 'Update ThinkRank Sitemap Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank XML sitemap settings: per-type inclusion toggles, exclusion lists, and the index/splitting, styling, filename and search-engine ping options. Read the current values with get-sitemap-settings first; only the keys you pass are changed.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			// Settings writes are recoverable: the matching get-* ability reads
			// the previous value, so nothing is lost that cannot be put back.
			// `destructive` is reserved for calls that lose data or reach
			// outside the site, and marking routine configuration with it made
			// MCP clients demand a human approval for every save — which users
			// reported as a permission bug, because the client's refusal reads
			// as "No approval received" (#675).
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 2.0,
			'openWorldHint' => false,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_input_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'settings'   => [
					'type'        => 'object',
					'description' => __( 'Sitemap settings to update.', 'thinkrank' ),
					'properties'  => Settings_Key_Map::sitemap(),
				],
				'regenerate' => [
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Rebuild the served sitemap before returning, so the change is live when this call reports success. Leave it on unless you are making several changes in a row and want to rebuild once at the end, in which case the last call should set it to true.', 'thinkrank' ),
				],
			],
			'required'             => [ 'settings' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success'       => [ 'type' => 'boolean' ],
				'message'       => [ 'type' => 'string' ],
				// Whether the SERVED sitemap reflects the change yet. Saving and
				// serving are separate steps, and reporting only the save let an
				// agent state the exclusion had taken effect while the static
				// file still listed the page (#764).
				'rebuilt'       => [ 'type' => 'boolean' ],
				'pending_since' => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Unix time a rebuild has been outstanding since, when the served sitemap has not caught up.', 'thinkrank' ),
				],
			],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

		if ( empty( $settings ) ) {
			return new \WP_Error(
				'thinkrank_missing_sitemap_settings_payload',
				__( 'A sitemap settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$gen    = new Sitemap_Generator();
		$merged = $gen->get_settings( 'site', null );
		$patch  = Settings_Key_Map::coerce( Settings_Key_Map::sitemap(), $settings );
		$merged = array_merge( $merged, $patch );

		if ( empty( $patch ) ) {
			return new \WP_Error(
				'thinkrank_no_valid_sitemap_setting_keys',
				__( 'No valid sitemap setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$result = $gen->save_settings( 'site', null, $merged );

		if ( ! $result ) {
			return [
				'success' => false,
				'message' => __( 'Failed to update sitemap settings.', 'thinkrank' ),
				'rebuilt' => false,
			];
		}

		// Mark the rebuild outstanding either way, so a caller that opts out of
		// the synchronous rebuild still converges via cron or the request-time
		// takeover, and so a failed rebuild below is retried rather than lost.
		$gen->schedule_regeneration();

		$regenerate = ! isset( $input['regenerate'] ) || (bool) $input['regenerate'];

		// Rebuild inline rather than leaving it to WP-Cron. The sitemap is a
		// static file on most installs, so nothing re-runs PHP for it; where
		// cron does not fire, the old debounced-only path left the served file
		// stale indefinitely while this ability had already reported success
		// (#764). A settings change is deliberate and infrequent, and the
		// rebuild is lock-guarded, so doing it now is the honest thing.
		$rebuilt = $regenerate ? $gen->regenerate_sitemap_from_settings() : false;

		$pending_since = Sitemap_Generator::regeneration_pending_since();

		if ( $rebuilt && 0 === $pending_since ) {
			return [
				'success'       => true,
				'message'       => __( 'Sitemap settings updated and the served sitemap was rebuilt.', 'thinkrank' ),
				'rebuilt'       => true,
				'pending_since' => null,
			];
		}

		// Saved, but the file a crawler fetches does not reflect it yet. Say so
		// rather than report a success the caller cannot verify.
		return [
			'success'       => true,
			'message'       => $regenerate
				? __( 'Sitemap settings updated, but the served sitemap could not be rebuilt yet and still shows the previous contents. It will be retried automatically; purge-caches with the "sitemap" scope forces another attempt.', 'thinkrank' )
				: __( 'Sitemap settings updated. The served sitemap has not been rebuilt, as requested; run purge-caches with the "sitemap" scope when you are ready to publish the change.', 'thinkrank' ),
			'rebuilt'       => false,
			'pending_since' => $pending_since > 0 ? $pending_since : null,
		];
	}
}
