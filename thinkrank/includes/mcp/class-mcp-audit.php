<?php
/**
 * MCP activity log — every agent write, visible, exportable and revertable.
 *
 * Written from one place: Mcp_Server::call_tool() hands every tools/call to
 * record(), which wraps the ability's execution. Every ability, free or Pro,
 * is therefore covered without any of them knowing the log exists.
 *
 * What is stored:
 * - A write (any call Mcp_Tools::is_write_call() says changes state: every
 *   tool whose ability does not declare `readonly: true`, and a call that
 *   sets a write argument) gets one row with the redacted arguments and,
 *   where the write has a read counterpart, the before/after of the keys it
 *   changed. See Mcp_Audit_Snapshot for how that is captured.
 * - A refused call (scope, read-only credential, failed permission check)
 *   gets one row with result `denied`, read or write.
 * - Reads are counted, not logged: one row per client per day whose `calls`
 *   goes up. An agent paging through 5,000 posts adds one row, not 5,000.
 *
 * Logging can never break a call. Every database touch is guarded, and a
 * site whose table has not been created yet simply records nothing.
 *
 * @package ThinkRank\Mcp
 * @since 2.15.0
 */

declare(strict_types=1);

namespace ThinkRank\Mcp;

use ThinkRank\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Activity log of MCP calls, with revert.
 */
final class Mcp_Audit {

	/**
	 * Table name without the WordPress prefix.
	 */
	public const TABLE = 'thinkrank_mcp_audit';

	/**
	 * Daily retention prune hook.
	 */
	public const PRUNE_HOOK = 'thinkrank_mcp_audit_prune';

	/**
	 * One-off event that carries on a prune that hit PRUNE_MAX_BATCHES.
	 */
	public const PRUNE_CONTINUE_HOOK = 'thinkrank_mcp_audit_prune_more';

	/**
	 * Cached client and tool lists for the Activity filters.
	 */
	public const FACETS_TRANSIENT = 'thinkrank_mcp_audit_facets';

	/**
	 * Schema version that created the table.
	 */
	private const TABLE_SINCE = '1.10.0';

	/**
	 * Error codes that mean "refused", not "failed".
	 */
	private const DENIAL_CODES = [
		'thinkrank_mcp_read_only',
		'thinkrank_mcp_scope_denied',
		'ability_invalid_permissions',
		'rest_forbidden',
	];

	/**
	 * Most rows one export returns.
	 */
	public const EXPORT_LIMIT = 5000;

	/**
	 * Rows the export reads, and holds in memory, at a time. Small because a
	 * row can carry two 16 KB snapshots.
	 */
	private const EXPORT_CHUNK = 100;

	/**
	 * A revert claims its row by setting `reverted_by` to this plus the
	 * time, a value no row id reaches. The time lets a claim that a fatal
	 * (a timeout) left behind expire after REVERT_CLAIM_TTL instead of
	 * holding the row forever. A revert that ran but could not write its own
	 * row leaves the row pointing at itself, which does not expire.
	 *
	 * @since 2.15.0
	 */
	public const REVERT_CLAIM_BASE = 9000000000000000000;

	/**
	 * Seconds after which an unfinished claim may be taken again.
	 *
	 * @since 2.15.0
	 */
	public const REVERT_CLAIM_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Rows deleted per prune query, so a year of backlog never runs as one
	 * long-locking statement.
	 */
	private const PRUNE_BATCH = 5000;

	/**
	 * Batches one prune run may delete. A backlog, or retention cut from 365
	 * days to 30, carries on in a follow-up event a minute later instead of
	 * holding one cron request for as long as it takes.
	 */
	private const PRUNE_MAX_BATCHES = 10;

	/**
	 * Fully prefixed table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * The table name as it is written into SQL: quoted, never through the
	 * %i placeholder, which WordPress 6.0 and 6.1 leave in the query so that
	 * every write, read and revert of the log fails there (#1140).
	 *
	 * @return string
	 */
	private static function sql_table(): string {
		return Database::quote_identifier( self::table() );
	}

	/**
	 * Whether the log can be written: a real wpdb, and a schema version that
	 * includes the table. Read from the autoloaded version option rather than
	 * a SHOW TABLES on every MCP call.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'insert' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}
		$version = (string) get_option( 'thinkrank_seo_db_version', '0.0.0' );
		return version_compare( $version, self::TABLE_SINCE, '>=' );
	}

	// -- Recording ---------------------------------------------------------

	/**
	 * Run one tools/call and log it.
	 *
	 * @param string   $tool Bare tool name.
	 * @param array    $args Arguments the agent sent.
	 * @param callable $run  Executes the call; returns its result or WP_Error.
	 * @return mixed The call's own result, untouched.
	 */
	public static function record( string $tool, array $args, callable $run ) {
		$started  = microtime( true );
		$is_write = Mcp_Tools::is_write_call( $tool, $args );

		if ( ! $is_write ) {
			$result = $run();
			try {
				if ( self::is_denial( $result ) ) {
					self::insert( self::base_row( 'read', 'denied', $tool, $args, $started, $result ) );
				} elseif ( ! is_wp_error( $result ) ) {
					self::count_read();
				}
			} catch ( \Throwable $e ) {
				self::debug( $e );
			}
			return $result;
		}

		$plan   = null;
		$before = null;

		// Snapshot only a call that will run: a refused write changes nothing,
		// and reading its counterpart would be wasted work.
		try {
			if ( self::available() && null === Mcp_Tools::check_access( $tool, $args ) ) {
				$plan = self::plan_for( $tool, $args );
				if ( 'counterpart' === $plan['strategy'] ) {
					$before = Mcp_Audit_Snapshot::project( self::run( $plan['get'], $plan['get_args'] ), $plan );
				}
			}
		} catch ( \Throwable $e ) {
			self::debug( $e );
			$plan   = null;
			$before = null;
		}

		$result = $run();

		try {
			$status = self::is_denial( $result ) ? 'denied' : ( is_wp_error( $result ) ? 'error' : 'ok' );
			$row    = self::base_row( 'write', $status, $tool, $args, $started, $result );

			$after = null;
			if ( 'ok' === $status && null !== $plan ) {
				if ( 'counterpart' === $plan['strategy'] ) {
					$after = Mcp_Audit_Snapshot::project( self::run( $plan['get'], $plan['get_args'] ), $plan );
				} elseif ( 'changes' === $plan['strategy'] ) {
					$changes = Mcp_Audit_Snapshot::from_changes( $result );
					if ( null !== $changes ) {
						$before = $changes['before'];
						$after  = $changes['after'];
					}
				}
			}

			if ( null !== $plan ) {
				$row['target_type'] = (string) $plan['object']['type'];
				$row['target_id']   = (string) $plan['object']['id'];
			}

			$before_json = Mcp_Audit_Snapshot::encode( $before );
			$after_json  = Mcp_Audit_Snapshot::encode( $after );

			$row['before_json'] = $before_json;
			$row['after_json']  = $after_json;
			$row['meta']        = null !== $plan ? (string) wp_json_encode( $plan ) : null;
			$row['revertable']  = ( 'ok' === $status
				&& null !== $plan
				&& null !== $before_json
				&& null !== $after_json
				&& ! Mcp_Audit_Snapshot::contains_secret( $before )
				&& ! Mcp_Audit_Snapshot::same( $before, $after )
				&& ( 'counterpart' !== $plan['strategy'] || null !== Mcp_Audit_Snapshot::pad_merged( $tool, (array) $before, (array) $after ) ) ) ? 1 : 0;

			self::insert( $row );
		} catch ( \Throwable $e ) {
			self::debug( $e );
		}

		return $result;
	}

	/**
	 * Snapshot plan for a write, from its ability's and counterpart's schemas.
	 *
	 * @param string $tool Bare tool name.
	 * @param array  $args Arguments.
	 * @return array<string,mixed>
	 */
	private static function plan_for( string $tool, array $args ): array {
		$ability      = Mcp_Tools::resolve( $tool );
		$update_props = $ability ? self::schema_props( $ability ) : [];

		$get_name  = Mcp_Audit_Snapshot::counterpart( $tool );
		$get       = '' !== $get_name ? Mcp_Tools::resolve( $get_name ) : null;
		$get_props = $get ? self::schema_props( $get ) : null;

		return Mcp_Audit_Snapshot::plan( $tool, $args, $update_props, $get_props );
	}

	/**
	 * Input property names of an ability.
	 *
	 * @param object $ability WP_Ability.
	 * @return string[]
	 */
	private static function schema_props( $ability ): array {
		$schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : [];
		$props  = is_array( $schema ) && isset( $schema['properties'] ) ? $schema['properties'] : [];
		if ( $props instanceof \ArrayObject ) {
			$props = $props->getArrayCopy();
		}
		return is_array( $props ) ? array_map( 'strval', array_keys( $props ) ) : [];
	}

	/**
	 * Execute an ability directly, as the current user, outside the scope
	 * check. Used for the log's own reads and for an admin's revert.
	 *
	 * @param string $tool  Bare tool name.
	 * @param array  $input Input.
	 * @return mixed|\WP_Error
	 */
	private static function run( string $tool, array $input ) {
		$ability = Mcp_Tools::resolve( $tool );
		if ( ! $ability ) {
			return new \WP_Error( 'thinkrank_mcp_unknown_tool', $tool );
		}
		return $ability->execute( $input );
	}

	/**
	 * Fields every row carries.
	 *
	 * @param string $kind    write | read | revert.
	 * @param string $status  ok | error | denied.
	 * @param string $tool    Bare tool name.
	 * @param array  $args    Arguments.
	 * @param float  $started microtime( true ) at the start of the call.
	 * @param mixed  $result  The call's result.
	 * @return array<string,mixed>
	 */
	private static function base_row( string $kind, string $status, string $tool, array $args, float $started, $result ): array {
		$now = time();

		return [
			'ts'            => gmdate( 'Y-m-d H:i:s', $now ),
			'day'           => gmdate( 'Y-m-d', $now ),
			'kind'          => $kind,
			'result'        => $status,
			'client_kind'   => Mcp_Session::kind(),
			'client_key'    => Mcp_Session::client_key(),
			'client_name'   => substr( Mcp_Session::client_name(), 0, 191 ),
			'user_id'       => Mcp_Session::active() ? Mcp_Session::user_id() : get_current_user_id(),
			'tool'          => substr( $tool, 0, 100 ),
			'input_summary' => Mcp_Audit_Snapshot::summary( $args ),
			'duration_ms'   => (int) max( 0, round( ( microtime( true ) - $started ) * 1000 ) ),
			'ip'            => self::client_ip(),
			'message'       => is_wp_error( $result ) ? substr( (string) $result->get_error_message(), 0, 255 ) : '',
		];
	}

	/**
	 * Whether a call result is a refusal.
	 *
	 * @param mixed $result Result.
	 * @return bool
	 */
	private static function is_denial( $result ): bool {
		return is_wp_error( $result ) && in_array( $result->get_error_code(), self::DENIAL_CODES, true );
	}

	/**
	 * Add one read to today's counter for the calling client.
	 *
	 * One atomic upsert against the unique `rollup_key`, so reads that arrive
	 * together can never split a client's day into several rows.
	 *
	 * @return void
	 */
	private static function count_read(): void {
		if ( ! self::available() ) {
			return;
		}
		global $wpdb;

		$now = time();
		$day = gmdate( 'Y-m-d', $now );
		$ts  = gmdate( 'Y-m-d H:i:s', $now );
		$key = Mcp_Session::client_key();

		$table = self::sql_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- counter upsert on the plugin's own log table; the name is quoted by sql_table().
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (ts, day, kind, result, client_kind, client_key, client_name, user_id, calls, ip, rollup_key) VALUES (%s, %s, %s, %s, %s, %s, %s, %d, 1, %s, %s) ON DUPLICATE KEY UPDATE calls = calls + 1, ts = %s",
				$ts,
				$day,
				'read',
				'ok',
				Mcp_Session::kind(),
				$key,
				substr( Mcp_Session::client_name(), 0, 191 ),
				Mcp_Session::user_id(),
				self::client_ip(),
				self::rollup_key( $day, $key ),
				$ts
			)
		);
		// phpcs:enable
		self::facets_saw( $key, '' );
	}

	/**
	 * The unique key of one client's read counter for one day. Only read
	 * counters carry one; every other row leaves it NULL, which a unique
	 * index allows any number of.
	 *
	 * @param string $day        Y-m-d (UTC).
	 * @param string $client_key Client key.
	 * @return string
	 */
	public static function rollup_key( string $day, string $client_key ): string {
		return 'read|' . $day . '|' . $client_key;
	}

	/**
	 * Fold read counters written before `rollup_key` existed into one keyed
	 * row per client per day: calls summed into the oldest row, the others
	 * deleted. Only installs that ran the unreleased 1.10.0/1.10.1 schema
	 * have any; elsewhere this is one query that finds nothing.
	 *
	 * @since 2.15.0
	 * @return int Rows deleted.
	 */
	public static function migrate_read_rollups(): int {
		if ( ! self::available() ) {
			return 0;
		}
		global $wpdb;
		$table = self::table();
		$sql   = self::sql_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- one-off migration of the plugin's own log table; the name is quoted by sql_table().
		$rows = $wpdb->get_results(
			"SELECT id, day, client_key, calls FROM {$sql} WHERE kind = 'read' AND result = 'ok' AND (day, client_key) IN (SELECT day, client_key FROM (SELECT day, client_key FROM {$sql} WHERE kind = 'read' AND result = 'ok' AND rollup_key IS NULL) legacy) ORDER BY id",
			ARRAY_A
		);
		// phpcs:enable

		$groups = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$groups[ $row['day'] . '|' . $row['client_key'] ][] = $row;
		}

		$deleted = 0;
		foreach ( $groups as $group ) {
			$keep  = $group[0];
			$extra = array_map( 'intval', array_column( array_slice( $group, 1 ), 'id' ) );

			if ( [] !== $extra ) {
				$placeholders = implode( ', ', array_fill( 0, count( $extra ), '%d' ) );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per id, all passed in one array.
				$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$sql} WHERE id IN ({$placeholders})", $extra ) );
				// phpcs:enable
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off migration of the plugin's own log table.
			$wpdb->update(
				$table,
				[
					'calls'      => (int) array_sum( array_column( $group, 'calls' ) ),
					'rollup_key' => self::rollup_key( (string) $keep['day'], (string) $keep['client_key'] ),
				],
				[ 'id' => (int) $keep['id'] ]
			);
		}

		return $deleted;
	}

	/**
	 * Insert one row.
	 *
	 * @param array<string,mixed> $row Column => value.
	 * @return int Inserted id, 0 on failure.
	 */
	private static function insert( array $row ): int {
		if ( ! self::available() ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- write to the plugin's own log table.
		$ok = $wpdb->insert( self::table(), $row );
		if ( $ok ) {
			self::facets_saw( (string) ( $row['client_key'] ?? '' ), (string) ( $row['tool'] ?? '' ) );
		}
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Caller IP for the log.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Leave a trail when logging itself fails, without failing the call.
	 *
	 * @param \Throwable $e Error.
	 * @return void
	 */
	private static function debug( \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[TR-MCP] activity log: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic.
		}
	}

	// -- Reading -----------------------------------------------------------

	/**
	 * A page of activity, newest first.
	 *
	 * @param array<string,mixed> $filters  client, tool, object, result, q, changes_only, since (Y-m-d H:i:s UTC).
	 * @param int                 $page     1-based page.
	 * @param int                 $per_page Rows per page, at most 100.
	 * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int}
	 */
	public static function query( array $filters, int $page = 1, int $per_page = 25 ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );

		if ( ! self::available() ) {
			return [
				'rows'  => [],
				'total' => 0,
				'page'  => $page,
				'pages' => 0,
			];
		}

		global $wpdb;
		[ $where, $params ] = self::where( $filters );
		$table              = self::sql_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the WHERE clause is built from fixed fragments with placeholders; every value is in $params, passed as one array.
		// Unfiltered, the WHERE is '1=1' with nothing to bind, and prepare()
		// raises a doing-it-wrong notice for a query with no placeholder.
		$count = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total = (int) $wpdb->get_var( [] === $params ? $count : $wpdb->prepare( $count, $params ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY ts DESC, id DESC LIMIT %d OFFSET %d",
				array_merge( $params, [ $per_page, ( $page - 1 ) * $per_page ] )
			),
			ARRAY_A
		);
		// phpcs:enable

		return [
			'rows'  => self::format_all( is_array( $rows ) ? $rows : [] ),
			'total' => $total,
			'page'  => $page,
			'pages' => (int) ceil( $total / $per_page ),
		];
	}

	/**
	 * WHERE clause and its values for a filter set.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function where( array $filters ): array {
		global $wpdb;

		$clauses = [ '1=1' ];
		$params  = [];

		if ( '' !== (string) ( $filters['client'] ?? '' ) ) {
			$clauses[] = 'client_key = %s';
			$params[]  = (string) $filters['client'];
		}
		if ( '' !== (string) ( $filters['tool'] ?? '' ) ) {
			$clauses[] = 'tool = %s';
			$params[]  = (string) $filters['tool'];
		}
		if ( in_array( (string) ( $filters['result'] ?? '' ), [ 'ok', 'error', 'denied' ], true ) ) {
			$clauses[] = 'result = %s';
			$params[]  = (string) $filters['result'];
		}
		if ( '' !== (string) ( $filters['object'] ?? '' ) ) {
			$object = explode( ':', (string) $filters['object'], 2 );
			if ( 2 === count( $object ) ) {
				$clauses[] = 'target_type = %s AND target_id = %s';
				$params[]  = $object[0];
				$params[]  = $object[1];
			} else {
				$clauses[] = 'target_id = %s';
				$params[]  = $object[0];
			}
		}
		if ( ! empty( $filters['changes_only'] ) ) {
			$clauses[] = "kind IN ('write','revert') AND result = 'ok'";
		}
		if ( '' !== (string) ( $filters['since'] ?? '' ) ) {
			$clauses[] = 'ts >= %s';
			$params[]  = (string) $filters['since'];
		}
		if ( '' !== trim( (string) ( $filters['q'] ?? '' ) ) ) {
			$like      = '%' . $wpdb->esc_like( trim( (string) $filters['q'] ) ) . '%';
			$clauses[] = '(tool LIKE %s OR client_name LIKE %s OR target_id LIKE %s OR input_summary LIKE %s OR message LIKE %s)';
			array_push( $params, $like, $like, $like, $like, $like );
		}

		return [ implode( ' AND ', $clauses ), $params ];
	}

	/**
	 * One stored row, by id.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null Raw row.
	 */
	public static function find( int $id ): ?array {
		if ( ! self::available() || $id <= 0 ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- read of the plugin's own log table; the name is quoted by sql_table().
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::sql_table() . ' WHERE id = %d', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * A formatted row as the calling MCP credential may see it.
	 *
	 * The snapshots are taken without a scope check, so a credential reading
	 * the log could otherwise see values from areas it was never granted.
	 * Where the credential cannot read the row's tool, its arguments, both
	 * snapshots, its message and the target's title (a post or term title
	 * is content too) are withheld; who, what and when stay. Outside an MCP request
	 * every area is open, so the admin screen sees every row in full.
	 *
	 * @param array<string,mixed> $row Formatted row (see format()).
	 * @return array<string,mixed>
	 */
	public static function for_caller( array $row ): array {
		$tool = (string) ( $row['tool'] ?? '' );
		if ( '' === $tool ) {
			return $row;
		}

		$ability = Mcp_Tools::resolve( $tool );
		$name    = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';

		if ( Mcp_Scopes::allows( Mcp_Session::levels(), $tool, false, $name ) ) {
			return $row;
		}

		$row['input']           = null;
		$row['before']          = null;
		$row['after']           = null;
		$row['message']         = '';
		$row['object']['label'] = '';
		$row['withheld']        = true;
		return $row;
	}

	/**
	 * Format a batch of stored rows, loading the users, posts and terms they
	 * name in one query each first. format() looks each one up, which was a
	 * query per row on a cold cache: up to 200 for a page of 100, thousands
	 * for an export.
	 *
	 * @param array<int,array<string,mixed>> $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function format_all( array $rows ): array {
		self::prime( $rows );
		return array_map( [ self::class, 'format' ], $rows );
	}

	/**
	 * Warm the object cache for everything a batch of rows names.
	 *
	 * @param array<int,array<string,mixed>> $rows Raw rows.
	 * @return array{users:int[],posts:int[],terms:int[]} The ids primed.
	 */
	public static function prime( array $rows ): array {
		$ids = [
			'users' => [],
			'posts' => [],
			'terms' => [],
		];
		foreach ( $rows as $row ) {
			if ( (int) ( $row['user_id'] ?? 0 ) > 0 ) {
				$ids['users'][] = (int) $row['user_id'];
			}
			$id   = (int) ( $row['target_id'] ?? 0 );
			$type = (string) ( $row['target_type'] ?? '' );
			if ( $id > 0 && in_array( $type, [ 'post', 'attachment' ], true ) ) {
				$ids['posts'][] = $id;
			} elseif ( $id > 0 && 'term' === $type ) {
				$ids['terms'][] = $id;
			}
		}
		$ids = array_map(
			static function ( array $group ): array {
				return array_values( array_unique( $group ) );
			},
			$ids
		);

		if ( [] !== $ids['users'] && function_exists( 'cache_users' ) ) {
			cache_users( $ids['users'] );
		}
		if ( [] !== $ids['posts'] && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids['posts'], false, false );
		}
		if ( [] !== $ids['terms'] && function_exists( '_prime_term_caches' ) ) {
			_prime_term_caches( $ids['terms'], false );
		}

		return $ids;
	}

	/**
	 * A stored row as the screen, the export and the ability see it.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	public static function format( array $row ): array {
		$user_id = (int) ( $row['user_id'] ?? 0 );
		$user    = $user_id > 0 && function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
		$input   = json_decode( (string) ( $row['input_summary'] ?? '' ), true );

		return [
			'id'          => (int) $row['id'],
			'ts'          => str_replace( ' ', 'T', (string) $row['ts'] ) . 'Z',
			'kind'        => (string) $row['kind'],
			'result'      => (string) $row['result'],
			'client'      => [
				'kind' => (string) $row['client_kind'],
				'key'  => (string) $row['client_key'],
				'name' => self::client_label( $row ),
			],
			'user'        => [
				'id'   => $user_id,
				'name' => $user ? (string) $user->display_name : '',
			],
			'tool'        => (string) $row['tool'],
			'object'      => [
				'type'  => (string) $row['target_type'],
				'id'    => (string) $row['target_id'],
				'label' => self::object_label( (string) $row['target_type'], (string) $row['target_id'] ),
			],
			'input'       => is_array( $input ) ? $input : (string) ( $row['input_summary'] ?? '' ),
			'before'      => null !== $row['before_json'] ? json_decode( (string) $row['before_json'], true ) : null,
			'after'       => null !== $row['after_json'] ? json_decode( (string) $row['after_json'], true ) : null,
			'revertable'  => 1 === (int) $row['revertable'] && ( 0 === (int) $row['reverted_by'] || self::claim_expired( (int) $row['reverted_by'] ) ),
			// A claim, or a row pointing at itself (undone with no entry of
			// its own to name), links to nothing.
			'reverted_by' => (int) $row['reverted_by'] >= self::REVERT_CLAIM_BASE || (int) $row['reverted_by'] === (int) $row['id'] ? 0 : (int) $row['reverted_by'],
			'parent_id'   => (int) $row['parent_id'],
			'calls'       => (int) $row['calls'],
			'duration_ms' => (int) $row['duration_ms'],
			'ip'          => (string) $row['ip'],
			'message'     => (string) $row['message'],
		];
	}

	/**
	 * Display name for the caller of a row.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return string
	 */
	private static function client_label( array $row ): string {
		$name = (string) ( $row['client_name'] ?? '' );
		if ( '' !== $name ) {
			return $name;
		}
		switch ( (string) $row['client_kind'] ) {
			case Mcp_Session::STATIC_TOKEN:
				return __( 'Connection token', 'thinkrank' );
			case 'admin':
				return __( 'Administrator', 'thinkrank' );
			default:
				return __( 'Unknown client', 'thinkrank' );
		}
	}

	/**
	 * Readable label for a row's object.
	 *
	 * @param string $type Object type.
	 * @param string $id   Object id.
	 * @return string
	 */
	private static function object_label( string $type, string $id ): string {
		if ( in_array( $type, [ 'post', 'attachment' ], true ) && function_exists( 'get_the_title' ) && (int) $id > 0 ) {
			$title = get_the_title( (int) $id );
			return '' !== $title ? $title : '#' . $id;
		}
		if ( 'term' === $type && function_exists( 'get_term' ) && (int) $id > 0 ) {
			$term = get_term( (int) $id );
			return $term && ! is_wp_error( $term ) ? (string) $term->name : '#' . $id;
		}
		return $id;
	}

	/**
	 * Write activity rows as CSV to a stream, newest first, capped at
	 * EXPORT_LIMIT.
	 *
	 * Each chunk is written before the next is read, so memory holds one
	 * chunk, not the export: 5,000 rows with two 16 KB snapshots each would
	 * otherwise be well over 100 MB as one string, before any copy of it.
	 *
	 * @param array<string,mixed> $filters Filters as query().
	 * @param resource            $handle  Writable stream, php://output for the download.
	 * @return int Rows written.
	 */
	public static function stream_csv( array $filters, $handle ): int {
		$header = [ 'id', 'time_utc', 'kind', 'result', 'client', 'client_key', 'user', 'tool', 'object_type', 'object_id', 'object', 'calls', 'duration_ms', 'ip', 'message', 'input', 'before', 'after', 'reverted_by', 'revert_of' ];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing the download stream, not a file.
		fwrite( $handle, self::csv_line( $header ) . "\r\n" );
		$written = 0;

		foreach ( self::export_chunks( $filters ) as $chunk ) {
			$lines = [];
			foreach ( $chunk as $row ) {
				$lines[] = self::csv_line(
					[
						$row['id'],
						$row['ts'],
						$row['kind'],
						$row['result'],
						$row['client']['name'],
						$row['client']['key'],
						$row['user']['name'],
						$row['tool'],
						$row['object']['type'],
						$row['object']['id'],
						$row['object']['label'],
						$row['calls'],
						$row['duration_ms'],
						$row['ip'],
						$row['message'],
						is_string( $row['input'] ) ? $row['input'] : wp_json_encode( $row['input'] ),
						null === $row['before'] ? '' : wp_json_encode( $row['before'] ),
						null === $row['after'] ? '' : wp_json_encode( $row['after'] ),
						$row['reverted_by'] ? $row['reverted_by'] : '',
						$row['parent_id'] ? $row['parent_id'] : '',
					]
				);
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing the download stream, not a file.
			fwrite( $handle, implode( "\r\n", $lines ) . "\r\n" );
			$written += count( $lines );
			if ( function_exists( 'flush' ) ) {
				flush();
			}
		}

		return $written;
	}

	/**
	 * The rows an export covers, newest first, in formatted chunks.
	 *
	 * Keyset pagination over (ts, id) with no COUNT: paging through query()
	 * re-counted the whole filtered set for every chunk and scanned a longer
	 * OFFSET each time.
	 *
	 * @param array<string,mixed> $filters Filters (see where()).
	 * @return \Generator<int,array<int,array<string,mixed>>>
	 */
	private static function export_chunks( array $filters ): \Generator {
		if ( ! self::available() ) {
			return;
		}

		global $wpdb;
		[ $where, $params ] = self::where( $filters );
		$table              = self::sql_table();
		$left               = self::EXPORT_LIMIT;
		$cursor             = null;

		while ( $left > 0 ) {
			$limit = min( self::EXPORT_CHUNK, $left );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the WHERE clause is built from fixed fragments with placeholders; every value is in the args array.
			$rows = null === $cursor
				? $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$table} WHERE {$where} ORDER BY ts DESC, id DESC LIMIT %d",
						array_merge( $params, [ $limit ] )
					),
					ARRAY_A
				)
				: $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$table} WHERE {$where} AND (ts < %s OR (ts = %s AND id < %d)) ORDER BY ts DESC, id DESC LIMIT %d",
						array_merge( $params, [ $cursor['ts'], $cursor['ts'], $cursor['id'], $limit ] )
					),
					ARRAY_A
				);
			// phpcs:enable

			if ( ! is_array( $rows ) || [] === $rows ) {
				return;
			}

			yield self::format_all( $rows );

			$left -= count( $rows );
			if ( count( $rows ) < $limit ) {
				return;
			}
			$last   = end( $rows );
			$cursor = [
				'ts' => (string) $last['ts'],
				'id' => (int) $last['id'],
			];
		}
	}

	/**
	 * One RFC 4180 CSV line. Cells that a spreadsheet would run as a formula
	 * are prefixed with a quote: agent-supplied text lands in this file.
	 *
	 * @param array<int,mixed> $cells Cells.
	 * @return string
	 */
	private static function csv_line( array $cells ): string {
		$out = [];
		foreach ( $cells as $cell ) {
			$cell = (string) $cell;
			if ( '' !== $cell && in_array( $cell[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
				$cell = "'" . $cell;
			}
			$out[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}
		return implode( ',', $out );
	}

	/**
	 * Per-client counts for the Connected AI apps card.
	 *
	 * @param int $days Window in days.
	 * @return array<string,array{changes:int,reads:int,denied:int,last_change:string}>
	 */
	public static function client_stats( int $days = 30 ): array {
		if ( ! self::available() ) {
			return [];
		}
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate over the plugin's own log table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT client_key,
					SUM(CASE WHEN kind = 'write' AND result = 'ok' THEN 1 ELSE 0 END) AS changes,
					SUM(CASE WHEN kind = 'read' AND result = 'ok' THEN calls ELSE 0 END) AS read_calls,
					SUM(CASE WHEN result = 'denied' THEN 1 ELSE 0 END) AS denied,
					MAX(CASE WHEN kind = 'write' AND result = 'ok' THEN ts ELSE NULL END) AS last_change
				FROM " . self::sql_table() . ' WHERE ts >= %s GROUP BY client_key', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the name is quoted by sql_table().
				$since
			),
			ARRAY_A
		);

		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (string) $row['client_key'] ] = [
				'changes'     => (int) $row['changes'],
				'reads'       => (int) $row['read_calls'],
				'denied'      => (int) $row['denied'],
				'last_change' => null !== $row['last_change'] ? str_replace( ' ', 'T', (string) $row['last_change'] ) . 'Z' : '',
			];
		}
		return $out;
	}

	/**
	 * Successful agent changes since a moment, for the email report.
	 *
	 * @param string $since Y-m-d H:i:s UTC.
	 * @param string $until Y-m-d H:i:s UTC.
	 * @return int
	 */
	public static function count_changes( string $since, string $until ): int {
		if ( ! self::available() ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate over the plugin's own log table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::sql_table() . " WHERE kind = 'write' AND result = 'ok' AND ts >= %s AND ts < %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the name is quoted by sql_table().
				$since,
				$until
			)
		);
	}

	/**
	 * Distinct clients and tools seen, for the screen's filters.
	 *
	 * Cached: they were rebuilt from the whole log on every page change and
	 * search. facets_saw() drops the cache when a row names a client or tool
	 * it does not list. One that pruning removes lingers until the cache
	 * expires, and filtering by it simply finds nothing.
	 *
	 * @return array{clients:array<int,array{key:string,name:string}>,tools:string[]}
	 */
	public static function facets(): array {
		if ( ! self::available() ) {
			return [
				'clients' => [],
				'tools'   => [],
			];
		}

		$cached = get_transient( self::FACETS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['clients'], $cached['tools'] ) ) {
			return $cached;
		}

		global $wpdb;
		$table = self::sql_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- facets of the plugin's own log table; the name is quoted by sql_table() and nothing else varies.
		$clients = $wpdb->get_results( "SELECT client_key, client_kind, MAX(client_name) AS client_name FROM {$table} GROUP BY client_key, client_kind", ARRAY_A );
		$tools   = $wpdb->get_col( "SELECT DISTINCT tool FROM {$table} WHERE tool <> '' ORDER BY tool" );
		// phpcs:enable

		$out = [];
		foreach ( is_array( $clients ) ? $clients : [] as $client ) {
			$out[] = [
				'key'  => (string) $client['client_key'],
				'name' => self::client_label( $client ),
			];
		}

		$facets = [
			'clients' => $out,
			'tools'   => array_map( 'strval', is_array( $tools ) ? $tools : [] ),
		];
		set_transient( self::FACETS_TRANSIENT, $facets, HOUR_IN_SECONDS );
		return $facets;
	}

	/**
	 * Drop the cached facets when a row names a client or tool they lack.
	 *
	 * @param string $client_key Client key of the row just written.
	 * @param string $tool       Tool of the row just written; '' for none.
	 * @return void
	 */
	public static function facets_saw( string $client_key, string $tool ): void {
		$cached = get_transient( self::FACETS_TRANSIENT );
		if ( ! is_array( $cached ) ) {
			return;
		}
		$known_client = in_array( $client_key, array_column( (array) ( $cached['clients'] ?? [] ), 'key' ), true );
		$known_tool   = '' === $tool || in_array( $tool, (array) ( $cached['tools'] ?? [] ), true );
		if ( ! $known_client || ! $known_tool ) {
			delete_transient( self::FACETS_TRANSIENT );
		}
	}

	// -- Retention ---------------------------------------------------------

	/**
	 * Schedule the daily prune and hook it.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::PRUNE_HOOK, [ self::class, 'prune' ] );
		add_action( self::PRUNE_CONTINUE_HOOK, [ self::class, 'prune' ] );
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Delete rows older than the retention setting, at most
	 * PRUNE_MAX_BATCHES batches per run.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune(): int {
		if ( ! self::available() ) {
			return 0;
		}
		global $wpdb;
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - Mcp_Governance::settings()['retention_days'] * DAY_IN_SECONDS );
		$deleted = 0;
		$batches = 0;
		$table   = self::sql_table();

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- retention prune of the plugin's own log table; the name is quoted by sql_table().
			$batch    = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE ts < %s LIMIT %d", $cutoff, self::PRUNE_BATCH ) );
			$deleted += $batch;
			++$batches;
		} while ( self::PRUNE_BATCH === $batch && $batches < self::PRUNE_MAX_BATCHES );

		// Stopped at the cap with more to go: carry on shortly.
		if ( self::PRUNE_BATCH === $batch && ! wp_next_scheduled( self::PRUNE_CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::PRUNE_CONTINUE_HOOK );
		}

		return $deleted;
	}

	// -- Revert ------------------------------------------------------------

	/**
	 * Undo one logged write.
	 *
	 * Re-applies `before` through the same update ability the agent used, and
	 * refuses with a 409 when the value has changed since the agent wrote it
	 * (someone edited in between) unless $force is set. The revert is itself
	 * a row, linked both ways.
	 *
	 * @param int  $id    Row id.
	 * @param bool $force Apply even though the value changed since.
	 * @return array<string,mixed>|\WP_Error
	 * @throws \Throwable Whatever the ability threw, after the claim is released.
	 */
	public static function revert( int $id, bool $force = false ) {
		$row = self::find( $id );
		if ( null === $row ) {
			return new \WP_Error( 'thinkrank_mcp_revert_not_found', __( 'That activity entry no longer exists.', 'thinkrank' ), [ 'status' => 404 ] );
		}
		if ( 'write' !== $row['kind'] || 'ok' !== $row['result'] || 1 !== (int) $row['revertable'] ) {
			return new \WP_Error( 'thinkrank_mcp_revert_unsupported', __( 'This change cannot be reverted.', 'thinkrank' ), [ 'status' => 400 ] );
		}
		$by = (int) $row['reverted_by'];
		if ( ( 0 !== $by && ! self::claim_expired( $by ) ) || ! self::claim( (int) $row['id'] ) ) {
			return new \WP_Error( 'thinkrank_mcp_revert_done', __( 'This change has already been reverted.', 'thinkrank' ), [ 'status' => 409 ] );
		}

		try {
			$result = self::revert_claimed( $row, $force );
		} catch ( \Throwable $e ) {
			self::release( (int) $row['id'] );
			throw $e;
		}
		if ( is_wp_error( $result ) ) {
			self::release( (int) $row['id'] );
		}
		return $result;
	}

	/**
	 * Whether `reverted_by` holds a claim old enough to be taken again.
	 *
	 * @param int $reverted_by Stored value.
	 * @return bool
	 */
	private static function claim_expired( int $reverted_by ): bool {
		return $reverted_by >= self::REVERT_CLAIM_BASE
			&& $reverted_by < self::REVERT_CLAIM_BASE + time() - self::REVERT_CLAIM_TTL;
	}

	/**
	 * Claim a row for revert. Two reverts can both read a row as not yet
	 * reverted; only the one whose claim lands may run the write. An
	 * expired claim may be taken over.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	private static function claim( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-set on the plugin's own log table.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::sql_table() . ' SET reverted_by = %d WHERE id = %d AND (reverted_by = 0 OR (reverted_by >= %d AND reverted_by < %d))', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the name is quoted by sql_table().
				self::REVERT_CLAIM_BASE + time(),
				$id,
				self::REVERT_CLAIM_BASE,
				self::REVERT_CLAIM_BASE + time() - self::REVERT_CLAIM_TTL
			)
		);
		return 1 === (int) $claimed;
	}

	/**
	 * Give back a claim after a revert that changed nothing.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	private static function release( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to the plugin's own log table.
		$wpdb->update( self::table(), [ 'reverted_by' => 0 ], [ 'id' => $id ] );
	}

	/**
	 * Revert a row this request has claimed.
	 *
	 * @param array<string,mixed> $row   Raw row.
	 * @param bool                $force Apply even if the value changed since.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function revert_claimed( array $row, bool $force ) {
		$plan   = json_decode( (string) $row['meta'], true );
		$before = json_decode( (string) $row['before_json'], true );
		$after  = json_decode( (string) $row['after_json'], true );
		if ( ! is_array( $plan ) || ! is_array( $before ) || ! is_array( $after ) ) {
			return new \WP_Error( 'thinkrank_mcp_revert_unsupported', __( 'This change cannot be reverted.', 'thinkrank' ), [ 'status' => 400 ] );
		}

		$started = microtime( true );

		if ( 'changes' === ( $plan['strategy'] ?? '' ) ) {
			return self::revert_items( $row, $plan, $before, $after, $force, $started );
		}

		$current = Mcp_Audit_Snapshot::project( self::run( (string) $plan['get'], (array) $plan['get_args'] ), $plan );
		if ( null === $current ) {
			return new \WP_Error( 'thinkrank_mcp_revert_unreadable', __( 'The current value could not be read, so nothing was changed.', 'thinkrank' ), [ 'status' => 500 ] );
		}

		if ( ! $force && ! Mcp_Audit_Snapshot::same( $current, $after ) ) {
			return new \WP_Error(
				'thinkrank_mcp_revert_stale',
				__( 'This value has changed since the AI assistant wrote it. Reverting would also undo the later edit.', 'thinkrank' ),
				[
					'status'  => 409,
					'current' => Mcp_Audit_Snapshot::redact( $current ),
					'after'   => $after,
					'before'  => $before,
				]
			);
		}

		// What the revert must leave behind: `before`, plus an explicit value
		// for each subkey the write added to a key its ability merges.
		$target = Mcp_Audit_Snapshot::pad_merged( (string) $row['tool'], $before, $after );
		if ( null === $target ) {
			return new \WP_Error( 'thinkrank_mcp_revert_unsupported', __( 'This change cannot be reverted.', 'thinkrank' ), [ 'status' => 400 ] );
		}

		$input  = Mcp_Audit_Snapshot::revert_input( $plan, $target, $current );
		$result = self::run( (string) $row['tool'], $input );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 400 ] );
		}

		// An ability can answer success and still keep part of the value, so
		// the revert counts only when the value read back is the one sent.
		$now = Mcp_Audit_Snapshot::project( self::run( (string) $plan['get'], (array) $plan['get_args'] ), $plan );
		if ( null === $now || ! Mcp_Audit_Snapshot::same( $now, $target ) ) {
			return new \WP_Error(
				'thinkrank_mcp_revert_incomplete',
				__( 'The earlier value could not be fully restored, so this change is still marked as not reverted. Check the setting before trying again.', 'thinkrank' ),
				[
					'status'   => 500,
					'current'  => null === $now ? null : Mcp_Audit_Snapshot::redact( $now ),
					'expected' => $target,
				]
			);
		}

		return self::record_revert( $row, $plan, $input, $current, $now, '', $started );
	}

	/**
	 * Undo a bulk write entry by entry, skipping any entry edited since.
	 *
	 * @param array<string,mixed> $row     Original row.
	 * @param array<string,mixed> $plan    Plan.
	 * @param array<string,mixed> $before  id => value before.
	 * @param array<string,mixed> $after   id => value written.
	 * @param bool                $force   Revert edited entries too.
	 * @param float               $started Start time.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function revert_items( array $row, array $plan, array $before, array $after, bool $force, float $started ) {
		$item     = (array) $plan['item'];
		$restored = [];
		$replaced = [];
		$skipped  = [];

		foreach ( $before as $id => $old ) {
			$read    = self::run( (string) $item['read'], [ (string) $item['id'] => (int) $id ] );
			$current = is_array( $read ) && array_key_exists( (string) $item['value'], $read ) ? (string) $read[ (string) $item['value'] ] : null;

			if ( null === $current || ( ! $force && ! Mcp_Audit_Snapshot::same( $current, $after[ $id ] ?? null ) ) ) {
				$skipped[] = (int) $id;
				continue;
			}

			$input = array_merge(
				[
					(string) $item['id']    => (int) $id,
					(string) $item['value'] => (string) $old,
				],
				(array) $item['extra']
			);

			if ( is_wp_error( self::run( (string) $item['tool'], $input ) ) ) {
				$skipped[] = (int) $id;
				continue;
			}

			$replaced[ (string) $id ] = $current;
			$restored[ (string) $id ] = (string) $old;
		}

		if ( [] === $restored ) {
			return new \WP_Error(
				'thinkrank_mcp_revert_stale',
				__( 'Every entry in this change has been edited since the AI assistant wrote it, so nothing was reverted.', 'thinkrank' ),
				[
					'status'  => 409,
					'skipped' => $skipped,
				]
			);
		}

		$message = [] === $skipped ? '' : sprintf(
			/* translators: %d: number of entries left as they are. */
			_n( '%d entry was edited since and was left as it is.', '%d entries were edited since and were left as they are.', count( $skipped ), 'thinkrank' ),
			count( $skipped )
		);

		$result            = self::record_revert( $row, $plan, [ 'entries' => array_keys( $restored ) ], $replaced, $restored, $message, $started );
		$result['skipped'] = $skipped;
		return $result;
	}

	/**
	 * Log a revert and link it to the row it undid.
	 *
	 * @param array<string,mixed>      $row     Original row.
	 * @param array<string,mixed>      $plan    Plan.
	 * @param array<string,mixed>      $input   What the revert sent.
	 * @param array<string,mixed>|null $before  Value just before the revert.
	 * @param array<string,mixed>|null $after   Value after the revert.
	 * @param string                   $message Note for the row.
	 * @param float                    $started Start time.
	 * @return array<string,mixed>
	 */
	private static function record_revert( array $row, array $plan, array $input, ?array $before, ?array $after, string $message, float $started ): array {
		$user = wp_get_current_user();
		$now  = time();

		$new_id = self::insert(
			[
				'ts'            => gmdate( 'Y-m-d H:i:s', $now ),
				'day'           => gmdate( 'Y-m-d', $now ),
				'kind'          => 'revert',
				'result'        => 'ok',
				'client_kind'   => 'admin',
				'client_key'    => 'admin',
				'client_name'   => $user && $user->exists() ? substr( (string) $user->display_name, 0, 191 ) : '',
				'user_id'       => get_current_user_id(),
				'tool'          => (string) $row['tool'],
				'target_type'   => (string) $row['target_type'],
				'target_id'     => (string) $row['target_id'],
				'input_summary' => Mcp_Audit_Snapshot::summary( $input ),
				'before_json'   => Mcp_Audit_Snapshot::encode( $before ),
				'after_json'    => Mcp_Audit_Snapshot::encode( $after ),
				'meta'          => (string) wp_json_encode( $plan ),
				'revertable'    => 0,
				'parent_id'     => (int) $row['id'],
				'duration_ms'   => (int) max( 0, round( ( microtime( true ) - $started ) * 1000 ) ),
				'ip'            => self::client_ip(),
				'message'       => substr( $message, 0, 255 ),
			]
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- link the original row to its revert.
		// Without a revert row the change was still undone, but there is no
		// entry to link it to, and row #1 is not that entry. The row points
		// at itself instead: unlike the claim, that never expires, so a
		// forced revert cannot run the undo a second time over later edits.
		$wpdb->update( self::table(), [ 'reverted_by' => $new_id > 0 ? $new_id : (int) $row['id'] ], [ 'id' => (int) $row['id'] ] );

		$stored = self::find( $new_id );

		return [
			'reverted' => true,
			'id'       => $new_id,
			'row'      => null !== $stored ? self::format( $stored ) : null,
			'message'  => $message,
		];
	}
}
