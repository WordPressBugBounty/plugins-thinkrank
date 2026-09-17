<?php
/**
 * List images ability.
 *
 * @package ThinkRank\Abilities\Media
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Lists Media Library images with their alt-text status.
 *
 * The entry point for the alt-text workflow: filter to `missing`, take the ids,
 * and write alt text one at a time with update-image-alt-text. Read-only.
 */
class List_Images extends Image_Alt_Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/list-images';
		$this->label       = __( 'List ThinkRank Images', 'thinkrank' );
		$this->description = __( 'List Media Library images with their alt text and whether it is missing. Filter with alt_status "missing" to find the images that need work, then use update-image-alt-text on each id. Returns a total so you can page through with page and per_page.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => true,
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 1.0,
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
				'alt_status' => [
					'type'        => 'string',
					'enum'        => [ 'all', 'missing', 'present' ],
					'description' => __( 'Which images to return. "missing" is the one to use when filling gaps. Default "all".', 'thinkrank' ),
				],
				'search'     => [
					'type'        => 'string',
					'description' => __( 'Optional title or filename search.', 'thinkrank' ),
				],
				'page'       => [ 'type' => 'integer' ],
				'per_page'   => [
					'type'        => 'integer',
					'description' => __( 'Results per page, 1-100. Default 20.', 'thinkrank' ),
				],
			],
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
				'images'     => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => $this->image_properties(),
					],
				],
				'total'      => [
					'type'        => 'integer',
					'description' => __( 'Images matching the filter, across every page.', 'thinkrank' ),
				],
				'page'       => [ 'type' => 'integer' ],
				'per_page'   => [ 'type' => 'integer' ],
				'alt_status' => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		$input      = (array) $input;
		$alt_status = (string) ( $input['alt_status'] ?? 'all' );
		$page       = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per_page   = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;

		$args = [
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => $this->attachment_statuses(),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		];

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}

		// An image with a whitespace-only alt value is missing alt text as far
		// as a screen reader is concerned, but its meta row exists — so
		// 'NOT EXISTS' alone would report it as present. The trim check in
		// describe_image() is what the caller actually sees; this query only
		// has to be a superset, and the filter below settles it.
		if ( 'missing' === $alt_status ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one bounded page of attachments for an agent-driven listing.
				'relation' => 'OR',
				[
					'key'     => self::ALT_META_KEY,
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => self::ALT_META_KEY,
					'value'   => '',
					'compare' => '=',
				],
			];
		} elseif ( 'present' === $alt_status ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one bounded page of attachments for an agent-driven listing.
				[
					'key'     => self::ALT_META_KEY,
					'value'   => '',
					'compare' => '!=',
				],
			];
		}

		$query  = new \WP_Query( $args );
		$images = [];

		foreach ( $query->posts as $attachment_id ) {
			$image = $this->describe_image( (int) $attachment_id );

			// Settle the whitespace case the meta query cannot express.
			if ( 'missing' === $alt_status && $image['has_alt'] ) {
				continue;
			}
			if ( 'present' === $alt_status && ! $image['has_alt'] ) {
				continue;
			}

			$images[] = $image;
		}

		return [
			'images'     => $images,
			'total'      => (int) $query->found_posts,
			'page'       => $page,
			'per_page'   => $per_page,
			'alt_status' => $alt_status,
		];
	}
}
