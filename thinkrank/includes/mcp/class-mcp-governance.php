<?php
/**
 * MCP governance settings — how long agent activity is kept and how long a
 * new connection token lives.
 *
 * Stored in its own option and edited from the MCP screen only. Neither value
 * is exposed as an MCP write on purpose: an agent that could shorten the
 * retention of its own activity log, or extend the life of the token it is
 * holding, would defeat the point of both.
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
 * Retention and token-lifetime settings for the MCP server.
 */
final class Mcp_Governance {

	/**
	 * Option key.
	 */
	public const OPTION = 'thinkrank_mcp_governance';

	/**
	 * Allowed activity-log retention, in days.
	 */
	public const RETENTION_CHOICES = [ 30, 90, 365 ];

	/**
	 * Allowed connection-token lifetimes, in days. 0 = never expires.
	 */
	public const TOKEN_TTL_CHOICES = [ 30, 90, 365, 0 ];

	/**
	 * Defaults: keep 90 days of activity; new tokens expire after 90 days.
	 */
	private const DEFAULTS = [
		'retention_days' => 90,
		'token_ttl_days' => 90,
	];

	/**
	 * Current settings, defaults merged and every value reduced to a choice
	 * the screen offers, so a hand-edited option cannot store something the
	 * prune or the token minting would then apply.
	 *
	 * @return array{retention_days:int,token_ttl_days:int}
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, [] );
		return self::sanitize( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Save a partial update.
	 *
	 * @param array<string,mixed> $patch Keys to change.
	 * @return array{retention_days:int,token_ttl_days:int}
	 */
	public static function save( array $patch ): array {
		$settings = self::sanitize( array_merge( self::settings(), $patch ) );
		update_option( self::OPTION, $settings, false );
		return $settings;
	}

	/**
	 * Reduce stored values to the offered choices.
	 *
	 * @param array<string,mixed> $values Values.
	 * @return array{retention_days:int,token_ttl_days:int}
	 */
	public static function sanitize( array $values ): array {
		$retention = (int) ( $values['retention_days'] ?? self::DEFAULTS['retention_days'] );
		$ttl       = (int) ( $values['token_ttl_days'] ?? self::DEFAULTS['token_ttl_days'] );

		return [
			'retention_days' => in_array( $retention, self::RETENTION_CHOICES, true ) ? $retention : self::DEFAULTS['retention_days'],
			'token_ttl_days' => in_array( $ttl, self::TOKEN_TTL_CHOICES, true ) ? $ttl : self::DEFAULTS['token_ttl_days'],
		];
	}

	/**
	 * Expiry timestamp for a token minted now with the given lifetime.
	 *
	 * @param int|null $days Lifetime in days; null = the configured default. 0 = never.
	 * @return int Unix timestamp, or 0 for never.
	 */
	public static function expiry_for( ?int $days = null ): int {
		$days = null === $days ? self::settings()['token_ttl_days'] : $days;
		if ( ! in_array( $days, self::TOKEN_TTL_CHOICES, true ) ) {
			$days = self::DEFAULTS['token_ttl_days'];
		}
		return 0 === $days ? 0 : time() + $days * DAY_IN_SECONDS;
	}
}
