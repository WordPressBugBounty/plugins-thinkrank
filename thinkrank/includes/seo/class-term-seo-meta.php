<?php
/**
 * Term SEO meta store.
 *
 * @package ThinkRank\SEO
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads and writes a taxonomy term's ThinkRank SEO fields.
 *
 * The one place that knows which `_thinkrank_*` term meta key holds which
 * field and how each is sanitized. The term edit screen and the
 * get-term-seo / update-term-seo abilities all go through it, so the form and
 * the agent cannot store the same field two different ways (#885).
 *
 * Fields are keyed by their canonical name (`title`, `description`, …), the
 * same names the abilities take. The redirect is not here: it lives in Pro's
 * rules table and goes through Object_Redirect.
 *
 * @since 2.16.0
 */
class Term_Seo_Meta {
	/**
	 * Variable-tag templates on a single line.
	 *
	 * The frontend runs each through Pattern_Resolver::resolve_term_value(),
	 * so they are sanitized as templates: sanitize_text_field() would strip
	 * %date% and %category% as percent-encoding (#521).
	 */
	public const TEMPLATE_FIELDS = [
		'title'         => '_thinkrank_seo_title',
		'og_title'      => '_thinkrank_og_title',
		'twitter_title' => '_thinkrank_twitter_title',
	];

	/**
	 * Variable-tag templates that may span lines.
	 */
	public const TEXTAREA_FIELDS = [
		'description'         => '_thinkrank_meta_description',
		'og_description'      => '_thinkrank_og_description',
		'twitter_description' => '_thinkrank_twitter_description',
	];

	/**
	 * URLs.
	 */
	public const URL_FIELDS = [
		'canonical_url' => '_thinkrank_canonical_url',
		'og_image'      => '_thinkrank_og_image',
		'twitter_image' => '_thinkrank_twitter_image',
	];

	/**
	 * Plain text.
	 */
	public const TEXT_FIELDS = [
		'focus_keyword' => '_thinkrank_focus_keyword',
	];

	/**
	 * JSON objects: the robots flags and the advanced robots directives.
	 */
	public const JSON_FIELDS = [
		'robots_meta'          => '_thinkrank_robots_meta',
		'advanced_robots_meta' => '_thinkrank_advanced_robots_meta',
	];

	/**
	 * Robots flags apply_meta_robots_override() reads.
	 */
	public const ROBOTS_FLAGS = [ 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' ];

	/**
	 * Values `max-image-preview` accepts.
	 */
	public const IMAGE_PREVIEW_SIZES = [ 'none', 'standard', 'large' ];

	/**
	 * Whether the robots override is switched on.
	 */
	public const ROBOTS_ENABLED_KEY = '_thinkrank_robots_meta_enabled';

	/**
	 * Every stored field of a term, keyed by canonical name.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string, mixed>
	 */
	public static function read( int $term_id ): array {
		$data = [];

		foreach ( self::string_fields() as $key => $meta_key ) {
			$data[ $key ] = (string) get_term_meta( $term_id, $meta_key, true );
		}

		$data['robots_meta_enabled'] = (bool) get_term_meta( $term_id, self::ROBOTS_ENABLED_KEY, true );

		foreach ( self::JSON_FIELDS as $key => $meta_key ) {
			$data[ $key ] = self::decode_json( get_term_meta( $term_id, $meta_key, true ) );
		}

		return $data;
	}

	/**
	 * Store the fields present in $settings; absent keys are left alone.
	 *
	 * A string field that sanitizes to empty is deleted rather than stored as
	 * an empty string, so a cleared field falls back to the global template.
	 *
	 * @param int                  $term_id  Term ID.
	 * @param array<string, mixed> $settings Canonical field name => raw, unslashed value.
	 * @return bool Whether any field was written or deleted.
	 */
	public static function write( int $term_id, array $settings ): bool {
		$touched = false;

		foreach ( self::TEMPLATE_FIELDS as $key => $meta_key ) {
			if ( array_key_exists( $key, $settings ) ) {
				self::save_string( $term_id, $meta_key, Pattern_Resolver::sanitize_template( (string) $settings[ $key ] ) );
				$touched = true;
			}
		}

		foreach ( self::TEXTAREA_FIELDS as $key => $meta_key ) {
			if ( array_key_exists( $key, $settings ) ) {
				self::save_string( $term_id, $meta_key, Pattern_Resolver::sanitize_template_textarea( (string) $settings[ $key ] ) );
				$touched = true;
			}
		}

		foreach ( self::URL_FIELDS as $key => $meta_key ) {
			if ( array_key_exists( $key, $settings ) ) {
				self::save_string( $term_id, $meta_key, esc_url_raw( trim( (string) $settings[ $key ] ) ) );
				$touched = true;
			}
		}

		foreach ( self::TEXT_FIELDS as $key => $meta_key ) {
			if ( array_key_exists( $key, $settings ) ) {
				self::save_string( $term_id, $meta_key, sanitize_text_field( (string) $settings[ $key ] ) );
				$touched = true;
			}
		}

		if ( array_key_exists( 'robots_meta_enabled', $settings ) ) {
			update_term_meta( $term_id, self::ROBOTS_ENABLED_KEY, (int) (bool) $settings['robots_meta_enabled'] );
			$touched = true;
		}

		foreach ( self::JSON_FIELDS as $key => $meta_key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$value = $settings[ $key ];
				// The edit screen posts the object as a JSON string; the
				// abilities pass it decoded.
				if ( is_string( $value ) ) {
					$value = self::decode_json( $value );
				}

				$value = 'robots_meta' === $key
					? self::sanitize_robots( (array) $value )
					: self::sanitize_advanced_robots( (array) $value );

				// Slashed: update_term_meta() unslashes, which would eat the
				// backslash of every `\"` and `\uXXXX` escape in the JSON.
				update_term_meta( $term_id, $meta_key, wp_slash( (string) wp_json_encode( (array) $value ) ) );
				$touched = true;
			}
		}

		return $touched;
	}

	/**
	 * Keep only the robots flags the frontend reads, as booleans.
	 *
	 * Index and noindex are one choice. The frontend and Indexability decide
	 * on `noindex` alone, so when both arrive true, `index` is stored false
	 * rather than kept as a contradiction the robots tab would show ticked.
	 *
	 * @since 2.16.0 Never stores `index` and `noindex` both true.
	 *
	 * @param array<string, mixed> $value Submitted flags.
	 * @return array<string, bool>
	 */
	private static function sanitize_robots( array $value ): array {
		$clean = [];

		foreach ( self::ROBOTS_FLAGS as $flag ) {
			if ( array_key_exists( $flag, $value ) ) {
				$clean[ $flag ] = (bool) $value[ $flag ];
			}
		}

		if ( ! empty( $clean['noindex'] ) && ! empty( $clean['index'] ) ) {
			$clean['index'] = false;
		}

		return $clean;
	}

	/**
	 * Keep only the advanced robots directives the frontend reads, typed.
	 *
	 * @param array<string, mixed> $value Submitted directives.
	 * @return array<string, bool|int|string>
	 */
	private static function sanitize_advanced_robots( array $value ): array {
		$clean = [];

		foreach ( [ 'snippet_enabled', 'video_preview_enabled', 'image_preview_enabled' ] as $flag ) {
			if ( array_key_exists( $flag, $value ) ) {
				$clean[ $flag ] = (bool) $value[ $flag ];
			}
		}

		foreach ( [ 'max_snippet', 'max_video_preview' ] as $limit ) {
			if ( array_key_exists( $limit, $value ) && is_numeric( $value[ $limit ] ) ) {
				$clean[ $limit ] = max( -1, (int) $value[ $limit ] );
			}
		}

		if ( isset( $value['max_image_preview'] ) && in_array( $value['max_image_preview'], self::IMAGE_PREVIEW_SIZES, true ) ) {
			$clean['max_image_preview'] = $value['max_image_preview'];
		}

		return $clean;
	}

	/**
	 * Every field stored as a plain string, canonical name => meta key.
	 *
	 * @return array<string, string>
	 */
	private static function string_fields(): array {
		return self::TEMPLATE_FIELDS + self::TEXTAREA_FIELDS + self::URL_FIELDS + self::TEXT_FIELDS;
	}

	/**
	 * Update a string meta value, deleting it when the sanitized value is empty.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $meta_key Meta key.
	 * @param string $value    Sanitized value.
	 * @return void
	 */
	private static function save_string( int $term_id, string $meta_key, string $value ): void {
		if ( '' === $value ) {
			delete_term_meta( $term_id, $meta_key );
			return;
		}

		// Slashed: update_term_meta() unslashes, so a backslash typed into a
		// title or description would otherwise be lost on the way in.
		update_term_meta( $term_id, $meta_key, wp_slash( $value ) );
	}

	/**
	 * Decode a JSON meta value into an array.
	 *
	 * @param mixed $value Raw stored value.
	 * @return array<string, mixed>
	 */
	private static function decode_json( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return [];
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : [];
	}
}
