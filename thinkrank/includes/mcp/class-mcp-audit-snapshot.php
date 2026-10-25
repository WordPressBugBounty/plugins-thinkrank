<?php
/**
 * MCP audit snapshots — the before/after of one agent write, and its undo.
 *
 * The activity log records what a write changed without touching a single
 * ability. It does that through each write's read counterpart: the ability a
 * human would call to look first (`update-post-seo` → `get-post-seo`). The
 * counterpart is read before and after the call, and both reads are projected
 * onto the keys the call actually wrote. That projection is the diff the
 * Activity tab shows, and the `before` half is what Revert writes back,
 * through the same update ability, so the ability's own validation, sanitising
 * and cache purging run exactly as they did for the original call.
 *
 * Abilities do not agree on shape. Most update a `settings` object and read it
 * back either under `settings`, under `data`, or flat, so the projection looks
 * for the written keys in that order and records where it found them. The few
 * that do not fit a lookup are described in OVERRIDES.
 *
 * A write is recorded as revertable only when every written key was found in
 * the before-read, none of them is a credential, and the snapshot fits the
 * size cap. Anything else is still logged with whatever could be captured,
 * just without a Revert button. A wrong undo is worse than none.
 *
 * Pure: abilities are passed in as data, so this class is unit-testable.
 *
 * @package ThinkRank\Mcp
 * @since 2.15.0
 */

declare(strict_types=1);

namespace ThinkRank\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Before/after capture, redaction and revert-input building for MCP writes.
 */
final class Mcp_Audit_Snapshot {

	/**
	 * Cap for the redacted input summary, in bytes.
	 */
	public const INPUT_CAP = 1024;

	/**
	 * Cap for each of `before` and `after`, in bytes. A snapshot over this is
	 * dropped and the row is not revertable.
	 */
	public const SNAPSHOT_CAP = 16384;

	/**
	 * What a masked credential looks like in the log.
	 */
	public const MASK = '••••';

	/**
	 * Arguments that steer a call instead of carrying a value to store. They
	 * are never part of the diff.
	 */
	private const CONTROL_KEYS = [ 'regenerate', 'generate', 'overwrite', 'mode', 'dry_run', 'force' ];

	/**
	 * Arguments that identify the object a write targets. Used for the
	 * object column; the read counterpart's own input schema decides which
	 * of them are passed to it.
	 */
	private const OBJECT_KEYS = [
		'post_id'       => 'post',
		'term_id'       => 'term',
		'attachment_id' => 'attachment',
		'post_type'     => 'post_type',
		'entity'        => 'entity',
		'id'            => 'item',
		// fix-seo-check: the analyzer check a fix was applied to.
		'check_id'      => 'seo_check',
	];

	/**
	 * Tools whose shape a generic lookup cannot follow.
	 *
	 * - keys:     the keys to diff, when the arguments do not name them (an
	 *             alt text written with `generate: true` carries no alt_text).
	 * - view:     where in the read counterpart's output to find them.
	 * - extra:    arguments the revert call needs on top of the values, such
	 *             as `mode: replace` so restoring FAQ items does not append.
	 * - identity: arguments that identify the target for the update call
	 *             even though the read counterpart takes none.
	 * - strategy: `changes` reads the before/after the ability itself
	 *             reported, for a bulk write whose targets are only known
	 *             once it has run. `item_*` say how to undo one entry.
	 * - object:   type and id for the log's object column, when the
	 *             arguments do not name a target.
	 *
	 * `fix-seo-check` has no entry, on purpose. Revert re-runs the row's own
	 * tool with the before values, which for a fix would apply the fix again,
	 * and what a fix changes depends on the check it names. It is logged with
	 * its check and without before/after, so it offers no Revert; its undo is
	 * the `undo` the ability answers with, which ThinkRank Hub keeps.
	 *
	 * `update-post-content` sets `get` to '' for the same kind of reason. Its
	 * name pairs it with `get-post-content`, whose `content` is the rendered
	 * plain text, so a generic Revert would write that text over the block
	 * markup. Its undo is restore-post-content with the revision the edit
	 * returns. `update-post-slug` needs no entry: there is no `get-post-slug`.
	 *
	 * Extended by ThinkRank Pro through `thinkrank_mcp_audit_snapshot_map`.
	 */
	private const OVERRIDES = [
		'update-post-content'          => [
			'get' => '',
		],
		'update-faq'                   => [
			'keys'  => [ 'items' ],
			'extra' => [ 'mode' => 'replace' ],
		],
		'update-image-alt-text'        => [
			'keys'  => [ 'alt_text' ],
			'extra' => [ 'overwrite' => true ],
		],
		'update-email-report-settings' => [
			'view' => 'config',
		],
		// The write also switches robots.txt management on, so the switch
		// is part of what a revert has to put back.
		'update-robots-txt'            => [
			'keys' => [ 'content', 'enabled' ],
		],
		'update-content-type-matrix'   => [
			'view'     => 'matrix',
			'identity' => [ 'entity' ],
		],
		'fill-missing-alt-text'        => [
			'strategy'   => 'changes',
			'item_tool'  => 'update-image-alt-text',
			'item_id'    => 'attachment_id',
			'item_value' => 'alt_text',
			'item_read'  => 'get-image-alt-text',
			'item_extra' => [ 'overwrite' => true ],
			'object'     => [
				'type' => 'media',
				'id'   => 'alt-text',
			],
		],
	];

	/**
	 * The override entry for a tool, after Pro's filter.
	 *
	 * @param string $tool Bare tool name.
	 * @return array<string,mixed>
	 */
	public static function override( string $tool ): array {
		$map = self::OVERRIDES;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters how the MCP activity log snapshots and reverts writes.
			 *
			 * Keyed by bare tool name. See Mcp_Audit_Snapshot::OVERRIDES for
			 * the keys an entry may carry. A Pro write with a read
			 * counterpart of a different name sets `get` to that tool.
			 *
			 * @since 2.15.0
			 *
			 * @param array<string,array<string,mixed>> $map Overrides.
			 */
			$filtered = apply_filters( 'thinkrank_mcp_audit_snapshot_map', $map );
			if ( is_array( $filtered ) ) {
				$map = $filtered;
			}
		}

		return isset( $map[ $tool ] ) && is_array( $map[ $tool ] ) ? $map[ $tool ] : [];
	}

	/**
	 * The read counterpart's bare name for a write: `update-x` → `get-x`.
	 *
	 * @param string $tool Bare write tool name.
	 * @return string '' when the name has no counterpart form.
	 */
	public static function counterpart( string $tool ): string {
		$override = self::override( $tool );
		if ( isset( $override['get'] ) && is_string( $override['get'] ) ) {
			return $override['get'];
		}
		if ( 0 === strpos( $tool, 'update-' ) ) {
			return 'get-' . substr( $tool, 7 );
		}
		return '';
	}

	/**
	 * Plan how to snapshot one write.
	 *
	 * @param string             $tool              Bare write tool name.
	 * @param array<string,mixed> $args             Arguments the agent sent.
	 * @param string[]           $update_props      The write's input schema property names.
	 * @param string[]|null      $get_props         The read counterpart's input property names, null when it does not exist.
	 * @return array<string,mixed> Plan; `strategy` is `counterpart`, `changes` or `none`.
	 */
	public static function plan( string $tool, array $args, array $update_props, ?array $get_props ): array {
		$override = self::override( $tool );
		$object   = is_array( $override['object'] ?? null )
			? [
				'type' => (string) ( $override['object']['type'] ?? '' ),
				'id'   => (string) ( $override['object']['id'] ?? '' ),
			]
			: self::object_of( $tool, $args );

		if ( 'changes' === ( $override['strategy'] ?? '' ) ) {
			return [
				'strategy' => 'changes',
				'object'   => $object,
				'item'     => [
					'tool'  => (string) ( $override['item_tool'] ?? '' ),
					'id'    => (string) ( $override['item_id'] ?? 'id' ),
					'value' => (string) ( $override['item_value'] ?? 'value' ),
					'read'  => (string) ( $override['item_read'] ?? '' ),
					'extra' => is_array( $override['item_extra'] ?? null ) ? $override['item_extra'] : [],
				],
			];
		}

		$get = self::counterpart( $tool );
		if ( '' === $get || null === $get_props ) {
			return [
				'strategy' => 'none',
				'object'   => $object,
			];
		}

		// Arguments both the read and the write take identify the target.
		$get_args = [];
		foreach ( $get_props as $prop ) {
			if ( array_key_exists( $prop, $args ) && ! in_array( $prop, self::CONTROL_KEYS, true ) ) {
				$get_args[ $prop ] = $args[ $prop ];
			}
		}

		$identity = $get_args;
		foreach ( (array) ( $override['identity'] ?? [] ) as $prop ) {
			if ( array_key_exists( $prop, $args ) ) {
				$identity[ $prop ] = $args[ $prop ];
			}
		}

		$wrapped = in_array( 'settings', $update_props, true );

		if ( isset( $override['keys'] ) && is_array( $override['keys'] ) ) {
			$keys = array_values( array_map( 'strval', $override['keys'] ) );
		} elseif ( $wrapped ) {
			$keys = is_array( $args['settings'] ?? null ) ? array_map( 'strval', array_keys( $args['settings'] ) ) : [];
		} else {
			$keys = [];
			foreach ( array_keys( $args ) as $key ) {
				$key = (string) $key;
				if ( array_key_exists( $key, $identity ) || in_array( $key, self::CONTROL_KEYS, true ) ) {
					continue;
				}
				$keys[] = $key;
			}
		}

		return [
			'strategy' => 'counterpart',
			'object'   => $object,
			'get'      => $get,
			'get_args' => $get_args,
			'identity' => $identity,
			'keys'     => array_values( array_unique( $keys ) ),
			'wrapped'  => $wrapped,
			'view'     => isset( $override['view'] ) ? (string) $override['view'] : '',
			'extra'    => is_array( $override['extra'] ?? null ) ? $override['extra'] : [],
		];
	}

	/**
	 * Project a read counterpart's output onto the planned keys.
	 *
	 * Records the view it used into $plan so the after-read and a later
	 * revert look in the same place.
	 *
	 * @param mixed               $output Read counterpart output.
	 * @param array<string,mixed> $plan   Plan from plan(); `view` is updated.
	 * @return array<string,mixed>|null Null when any key is missing.
	 */
	public static function project( $output, array &$plan ): ?array {
		if ( ! is_array( $output ) || empty( $plan['keys'] ) ) {
			return null;
		}

		$views = '' !== ( $plan['view'] ?? '' ) ? [ $plan['view'] ] : [ 'settings', 'data', '' ];

		foreach ( $views as $view ) {
			$source = self::view( $output, (string) $view, $plan['identity'] ?? [] );
			if ( null === $source ) {
				continue;
			}

			$projected = [];
			foreach ( $plan['keys'] as $key ) {
				if ( ! array_key_exists( $key, $source ) ) {
					$projected = null;
					break;
				}
				$projected[ $key ] = $source[ $key ];
			}

			if ( null !== $projected ) {
				$plan['view'] = (string) $view;
				return $projected;
			}
		}

		return null;
	}

	/**
	 * Resolve one view of a read output.
	 *
	 * @param array<string,mixed> $output   Read output.
	 * @param string              $view     '' (root), a key, or `matrix`.
	 * @param array<string,mixed> $identity Identity arguments.
	 * @return array<string,mixed>|null
	 */
	private static function view( array $output, string $view, array $identity ): ?array {
		if ( '' === $view ) {
			return $output;
		}

		if ( 'matrix' === $view ) {
			// One entity of the content type matrix: its feature values plus
			// its sitemap flag, which is what update-content-type-matrix takes.
			// `noindex` is deliberately absent: the read reports the resolved
			// robots value, not the override the write sets, so a write that
			// touched it is not revertable rather than reverted wrongly.
			$entity = (string) ( $identity['entity'] ?? '' );
			foreach ( (array) ( $output['entities'] ?? [] ) as $item ) {
				if ( is_array( $item ) && (string) ( $item['key'] ?? '' ) === $entity ) {
					$values = is_array( $item['values'] ?? null ) ? $item['values'] : [];
					if ( array_key_exists( 'sitemap_include', $item ) ) {
						$values['sitemap_include'] = $item['sitemap_include'];
					}
					$state = \ThinkRank\SEO\Content_Type_Robots_State::validate( $item['robots_state'] ?? null );
					$fingerprint = $item['robots_state_fingerprint'] ?? null;
					if ( null !== $state && is_string( $fingerprint ) && preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
						// Normalize empty objects for the audit's associative JSON decode.
						$values['robots_state'] = $state;
						$values['expected_robots_state'] = $fingerprint;
					}
					return $values;
				}
			}
			return null;
		}

		return isset( $output[ $view ] ) && is_array( $output[ $view ] ) ? $output[ $view ] : null;
	}

	/**
	 * The arguments that write `before` back through the update ability.
	 *
	 * @param array<string,mixed> $plan   Plan with its view resolved.
	 * @param array<string,mixed> $before Projected before-values.
	 * @param array<string,mixed> $current Fresh projected values for guarded restores.
	 * @return array<string,mixed>
	 */
	public static function revert_input( array $plan, array $before, array $current = [] ): array {
		if ( 'matrix' === ( $plan['view'] ?? '' ) && array_key_exists( 'robots_state', $before ) ) {
			// The old state is the destination, but the lock must compare the
			// current read. A missing guard must fail closed in the writer.
			$before['expected_robots_state'] = $current['expected_robots_state'] ?? '';
		}
		$input = (array) ( $plan['identity'] ?? [] );

		if ( ! empty( $plan['wrapped'] ) ) {
			$input['settings'] = $before;
		} else {
			$input = array_merge( $input, $before );
		}

		return array_merge( $input, (array) ( $plan['extra'] ?? [] ) );
	}

	/**
	 * Whether a key names a credential.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_secret_key( string $key ): bool {
		// `_token$` rather than `token`, so a limit such as `max_tokens` is
		// not masked into a value nobody can read or revert.
		return 1 === preg_match( '/(api_?key|secret|password|passwd|_token$|^token$|verification|_key$|^key$)/i', $key );
	}

	/**
	 * Mask every credential in a structure, keeping a "has value" signal and
	 * the last four characters, the same as the settings screens.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	public static function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $key => $value ) {
			$data[ $key ] = is_string( $key ) && self::is_secret_key( $key )
				? self::mask( $value )
				: self::redact( $value );
		}
		return $data;
	}

	/**
	 * Mask a value held under a secret key: every string or number in it,
	 * however deep, keeping only its last four characters. A boolean says
	 * whether something is set, not what it is, so it stays readable.
	 *
	 * @param mixed $value Value under a secret key.
	 * @return mixed
	 */
	private static function mask( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'mask' ], $value );
		}
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return $value;
		}
		$value = (string) $value;
		return '' === $value ? '' : self::MASK . ( strlen( $value ) > 4 ? substr( $value, -4 ) : '' );
	}

	/**
	 * Whether a structure carries a credential anywhere in it.
	 *
	 * @param mixed $data Data.
	 * @return bool
	 */
	public static function contains_secret( $data ): bool {
		if ( ! is_array( $data ) ) {
			return false;
		}
		foreach ( $data as $key => $value ) {
			// A boolean or null under a secret-sounding name, such as the
			// sitemap's exclude_password_protected, holds no secret.
			if ( is_string( $key ) && self::is_secret_key( $key ) && ! is_bool( $value ) && null !== $value ) {
				return true;
			}
			if ( self::contains_secret( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The redacted, size-capped input summary.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return string
	 */
	public static function summary( array $args ): string {
		$json = (string) wp_json_encode( self::redact( $args ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $json ) <= self::INPUT_CAP ) {
			return $json;
		}
		// Cut on a character boundary so the column never holds broken UTF-8.
		return mb_strcut( $json, 0, self::INPUT_CAP - 3, 'UTF-8' ) . '...';
	}

	/**
	 * Encode a snapshot for storage, or null when it is over the cap.
	 *
	 * @param array<string,mixed>|null $data Snapshot.
	 * @return string|null
	 */
	public static function encode( ?array $data ): ?string {
		if ( null === $data ) {
			return null;
		}
		$json = wp_json_encode( self::redact( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > self::SNAPSHOT_CAP ) {
			return null;
		}
		return $json;
	}

	/**
	 * Compare two snapshots the way a person would.
	 *
	 * Abilities hand back `"1"` where they took `1`, and `true` where they
	 * stored `"1"`; reading those as a change would refuse every revert as
	 * stale. Scalars are compared as strings, booleans as "1" / "", and maps
	 * without regard to key order.
	 *
	 * @param mixed $a First.
	 * @param mixed $b Second.
	 * @return bool
	 */
	public static function same( $a, $b ): bool {
		return wp_json_encode( self::canonical( $a ) ) === wp_json_encode( self::canonical( $b ) );
	}

	/**
	 * Canonical form for same().
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			$out     = [];
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::canonical( $item );
			}
			if ( ! $is_list ) {
				ksort( $out );
			}
			return $out;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( null === $value ) {
			return '';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Object type and id a write targets, for the log's object column.
	 *
	 * @param string              $tool Bare tool name.
	 * @param array<string,mixed> $args Arguments.
	 * @return array{type:string,id:string}
	 */
	public static function object_of( string $tool, array $args ): array {
		foreach ( self::OBJECT_KEYS as $key => $type ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				return [
					'type' => $type,
					'id'   => substr( (string) $args[ $key ], 0, 64 ),
				];
			}
		}

		// A settings write without a target names the settings group.
		$group = preg_replace( '/^(update|set|run|publish|generate|submit|send|fill|bulk)-/', '', $tool );

		return [
			'type' => 'settings',
			'id'   => substr( (string) $group, 0, 64 ),
		];
	}

	/**
	 * Before/after for a bulk write that reports its own changes.
	 *
	 * Reads `changes: [{id, before, after}]` from the ability's output.
	 *
	 * @param mixed $output Ability output.
	 * @return array{before:array<string,string>,after:array<string,string>}|null
	 */
	public static function from_changes( $output ): ?array {
		if ( ! is_array( $output ) || ! is_array( $output['changes'] ?? null ) || [] === $output['changes'] ) {
			return null;
		}
		$before = [];
		$after  = [];
		foreach ( $output['changes'] as $change ) {
			if ( ! is_array( $change ) || ! isset( $change['id'] ) ) {
				continue;
			}
			$id            = (string) (int) $change['id'];
			$before[ $id ] = (string) ( $change['before'] ?? '' );
			$after[ $id ]  = (string) ( $change['after'] ?? '' );
		}
		return [] === $before ? null : [
			'before' => $before,
			'after'  => $after,
		];
	}
}
