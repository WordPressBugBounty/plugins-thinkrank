<?php
/**
 * Update post slug ability.
 *
 * @package ThinkRank\Abilities\Content
 * @since 2.18.0
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\AI\SEOScoreCalculator;
use ThinkRank\Core\Database;
use ThinkRank\SEO\Focus_Keywords;
use ThinkRank\SEO\Global_SEO_Post_Types;
use ThinkRank\SEO\Post_Content_Editor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Plans, then changes, a post's URL slug.
 *
 * The SEO score asks for the focus keyword in the slug and for a short one,
 * and nothing on the connector could change it. A slug is the page's address,
 * so the same plan → confirm shape as update-post-content applies, and one
 * case is refused outright: a published page. WordPress keeps the old slug and
 * redirects it only for non-hierarchical types (wp_check_for_changed_slugs()),
 * so a page's old address, and every child page's, would start answering 404.
 * That change belongs with a redirect, made by a person in wp-admin.
 *
 * Undo is the same call with the previous slug, which the answer returns.
 */
class Update_Post_Slug extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-post-slug';
		$this->label       = __( 'Update ThinkRank Post Slug', 'thinkrank' );
		$this->description = __( 'Change the URL slug of a post. This changes a live address: on a published post the new URL is live as soon as the change is confirmed, and WordPress redirects the old URL to it. Published pages and other hierarchical content are refused, because WordPress does not redirect their old addresses (nor their child pages\'). Pass the slug get-post-content returned as expected_slug. Call once without confirmation_token: nothing changes, and the answer is the plan (old and new URL, whether the old URL redirects, whether each focus keyword is in the slug before and after) with a confirmation_token. Show it to the person and call again with the same slug and token only after they agree. WordPress may append a number to keep the slug unique; the plan shows the slug that will be stored. To undo, call again with the previous slug from the answer. Changing a slug can cost a page its existing search rankings for a while, so suggest it for new or low-traffic pages.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			// Not destructive (#675): the old slug is returned, the old URL
			// redirects, and the same call puts it back.
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 0.4,
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
			'required'             => [ 'post_id', 'slug', 'expected_slug' ],
			'properties'           => [
				'post_id'            => [
					'type'    => 'integer',
					'minimum' => 1,
				],
				'slug'               => [
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => __( 'The new slug: lowercase words joined by hyphens. Anything else is converted the way WordPress converts a slug.', 'thinkrank' ),
				],
				'expected_slug'      => [
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => __( 'The slug get-post-content returned. The change is refused if the slug changed since.', 'thinkrank' ),
				],
				'confirmation_token' => [
					'type'        => 'string',
					'pattern'     => Post_Content_Editor::TOKEN_PATTERN,
					'description' => __( 'The confirmation_token from the plan, sent with the same slug only after the person agreed. Omit it to plan.', 'thinkrank' ),
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
		$pair = [
			'type'       => 'object',
			'properties' => [
				'before' => [ 'type' => 'string' ],
				'after'  => [ 'type' => 'string' ],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'status'                => [
					'type' => 'string',
					'enum' => [ 'planned', 'applied' ],
				],
				'changed'               => [ 'type' => 'boolean' ],
				'already_applied'       => [ 'type' => 'boolean' ],
				'post_id'               => [ 'type' => 'integer' ],
				'slug'                  => $pair,
				'url'                   => $pair,
				'previous_slug'         => [
					'type'        => 'string',
					'description' => __( 'Send this as slug, with the new slug as expected_slug, to undo.', 'thinkrank' ),
				],
				'old_url'               => [
					'type'        => 'string',
					'enum'        => [ 'redirects', 'not_public' ],
					'description' => __( 'redirects: WordPress sends visitors of the old URL to the new one. not_public: the post is not published, so no live address changes.', 'thinkrank' ),
				],
				'adjusted'              => [
					'type'        => 'boolean',
					'description' => __( 'True when the stored slug differs from the one asked for, because WordPress converted it or added a number to keep it unique.', 'thinkrank' ),
				],
				'focus_keyword_in_slug' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'keyword' => [ 'type' => 'string' ],
							'before'  => [ 'type' => 'boolean' ],
							'after'   => [ 'type' => 'boolean' ],
						],
					],
				],
				'confirmation_token'    => [ 'type' => 'string' ],
				'confirmation_expires'  => [ 'type' => 'integer' ],
				'warnings'              => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
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
		$input = (array) $input;
		$post  = Post_Content_Editor::resolve_post( (int) ( $input['post_id'] ?? 0 ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$refusal = $this->refusal( $post );

		if ( is_wp_error( $refusal ) ) {
			return $refusal;
		}

		$post_id   = (int) $post->ID;
		$current   = (string) $post->post_name;
		$requested = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$token     = isset( $input['confirmation_token'] ) ? (string) $input['confirmation_token'] : '';

		if ( '' === $requested ) {
			return new \WP_Error(
				'thinkrank_invalid_slug',
				__( 'The slug is empty once converted to a URL slug. Use letters or numbers.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$predicted = (string) wp_unique_post_slug( $requested, $post_id, (string) $post->post_status, (string) $post->post_type, (int) $post->post_parent );

		if ( '' !== $token ) {
			$confirmation = Post_Content_Editor::read_confirmation( $token, 'slug', $post_id );

			if ( is_wp_error( $confirmation ) ) {
				return $confirmation;
			}

			if ( hash_equals( $confirmation['after'], self::hash( $current ) ) ) {
				return $this->answer( 'applied', $post, $current, $current, $requested, false, true, [ __( 'This slug change had already been saved; nothing was written again.', 'thinkrank' ) ] );
			}
		}

		if ( (string) ( $input['expected_slug'] ?? '' ) !== $current ) {
			return new \WP_Error(
				'thinkrank_slug_version_conflict',
				__( 'The slug changed after it was read. Nothing was changed. Call get-post-content again.', 'thinkrank' ),
				[
					'status' => 409,
					'slug'   => $current,
				]
			);
		}

		if ( $predicted === $current ) {
			return new \WP_Error(
				'thinkrank_no_change',
				__( 'That is already the post\'s slug.', 'thinkrank' ),
				[ 'status' => 422 ]
			);
		}

		if ( '' === $token ) {
			$confirmation = Post_Content_Editor::confirmation( 'slug', $post_id, self::before_hash( $post ), self::hash( $predicted ) );
			$answer       = $this->answer( 'planned', $post, $current, $predicted, $requested, false, false, [] );

			return $answer + [
				'confirmation_token'   => $confirmation['token'],
				'confirmation_expires' => $confirmation['expires'],
			];
		}

		if ( ! hash_equals( $confirmation['before'], self::before_hash( $post ) ) || ! hash_equals( $confirmation['after'], self::hash( $predicted ) ) ) {
			return new \WP_Error(
				'thinkrank_confirmation_stale',
				__( 'This confirmation was issued for a different slug, or before the post last changed. Nothing was changed. Plan again and ask for agreement again.', 'thinkrank' ),
				[ 'status' => 409 ]
			);
		}

		$saved = wp_update_post(
			[
				'ID'        => $post_id,
				'post_name' => wp_slash( $predicted ),
			],
			true
		);

		if ( is_wp_error( $saved ) || (int) $saved !== $post_id ) {
			return new \WP_Error(
				'thinkrank_save_failed',
				is_wp_error( $saved ) ? $saved->get_error_message() : __( 'WordPress did not save the post.', 'thinkrank' ),
				[ 'status' => 500 ]
			);
		}

		clean_post_cache( $post_id );
		$fresh  = get_post( $post_id );
		$stored = $fresh instanceof \WP_Post ? (string) $fresh->post_name : $predicted;

		$warnings = Post_Content_Editor::purge( $post_id );

		if ( $stored !== $predicted ) {
			$warnings[] = __( 'WordPress stored a different slug from the plan. The answer shows the stored one.', 'thinkrank' );
		}

		return $this->answer( 'applied', $fresh instanceof \WP_Post ? $fresh : $post, $current, $stored, $requested, true, false, $warnings );
	}

	/**
	 * Why this post's slug is not changed here, if it is not.
	 *
	 * @param \WP_Post $post Post.
	 * @return true|\WP_Error
	 */
	private function refusal( \WP_Post $post ) {
		$id     = (int) $post->ID;
		$status = (string) $post->post_status;

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error(
				'thinkrank_cannot_edit_post',
				__( 'You are not allowed to edit this post.', 'thinkrank' ),
				[ 'status' => 403 ]
			);
		}

		if ( 'revision' === $post->post_type || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || in_array( $status, [ 'trash', 'auto-draft', 'inherit' ], true ) ) {
			return new \WP_Error(
				'thinkrank_post_not_editable',
				__( 'This is a revision, an autosave, or a post in the trash, so its slug is not changed here.', 'thinkrank' ),
				[ 'status' => 422 ]
			);
		}

		if ( 'attachment' === $post->post_type || ! Global_SEO_Post_Types::is_allowed( (string) $post->post_type ) ) {
			return new \WP_Error(
				'thinkrank_post_type_not_supported',
				__( 'ThinkRank does not manage this content type. Use list-content-types to see the types it does.', 'thinkrank' ),
				[ 'status' => 422 ]
			);
		}

		if ( 'publish' === $status && is_post_type_hierarchical( (string) $post->post_type ) ) {
			return new \WP_Error(
				'thinkrank_slug_change_unredirected',
				__( 'This is a published page (or other hierarchical content). WordPress does not redirect its old address, or its child pages\' addresses, when its slug changes, so visitors and search engines would get a 404. Change it in wp-admin together with a redirect.', 'thinkrank' ),
				[ 'status' => 422 ]
			);
		}

		return true;
	}

	/**
	 * The answer for a plan or a saved change.
	 *
	 * @param string   $status          'planned' or 'applied'.
	 * @param \WP_Post $post            Post.
	 * @param string   $before          Slug before.
	 * @param string   $after           Slug after.
	 * @param string   $requested       Slug asked for, converted.
	 * @param bool     $changed         Whether this call wrote.
	 * @param bool     $already_applied Whether the change had already landed.
	 * @param string[] $warnings        Warnings.
	 * @return array<string, mixed>
	 */
	private function answer( string $status, \WP_Post $post, string $before, string $after, string $requested, bool $changed, bool $already_applied, array $warnings ): array {
		$old            = clone $post;
		$old->post_name = $before;
		$new            = clone $post;
		$new->post_name = $after;

		return [
			'status'                => $status,
			'changed'               => $changed,
			'already_applied'       => $already_applied,
			'post_id'               => (int) $post->ID,
			'slug'                  => [
				'before' => $before,
				'after'  => $after,
			],
			'url'                   => [
				'before' => (string) get_permalink( $old ),
				'after'  => (string) get_permalink( $new ),
			],
			'previous_slug'         => $before,
			'old_url'               => 'publish' === $post->post_status ? 'redirects' : 'not_public',
			'adjusted'              => $after !== $requested,
			'focus_keyword_in_slug' => $this->keywords_in_slug( $post, $before, $after ),
			'warnings'              => $warnings,
		];
	}

	/**
	 * Whether each focus keyword is in the slug, before and after.
	 *
	 * Through the score's own placement check, so the answer agrees with the
	 * editor's "keyword in slug" gauge.
	 *
	 * @param \WP_Post $post   Post.
	 * @param string   $before Slug before.
	 * @param string   $after  Slug after.
	 * @return array<int, array{keyword: string, before: bool, after: bool}>
	 */
	private function keywords_in_slug( \WP_Post $post, string $before, string $after ): array {
		$keywords = Focus_Keywords::get( (int) $post->ID );

		if ( [] === $keywords ) {
			return [];
		}

		try {
			$calculator = new SEOScoreCalculator( new Database() );
			$check      = static function ( string $slug ) use ( $calculator, $post, $keywords ): array {
				$found = [];
				foreach ( $calculator->keyword_placements( [ 'slug' => $slug, 'title' => (string) $post->post_title, 'content' => '' ], [], $keywords ) as $row ) {
					$found[ (string) $row['keyword'] ] = ! empty( $row['placements']['slug']['passed'] );
				}
				return $found;
			};
			$old        = $check( $before );
			$new        = $check( $after );
		} catch ( \Throwable $error ) {
			return [];
		}

		$rows = [];
		foreach ( $keywords as $keyword ) {
			$rows[] = [
				'keyword' => (string) $keyword,
				'before'  => ! empty( $old[ (string) $keyword ] ),
				'after'   => ! empty( $new[ (string) $keyword ] ),
			];
		}

		return $rows;
	}

	/**
	 * Hash of the state a slug change is planned from, for a confirmation.
	 *
	 * The slug and the post's modified time, as a content edit's version
	 * holds them. Every save moves the modified time, so a confirmation is
	 * spent once the change it agreed to is made: after the slug is changed
	 * back, the post holds the old slug again but not the old modified time,
	 * and replaying the confirmation would re-apply a change nobody agreed to
	 * a second time. The after hash stays the slug alone, so a retry of the
	 * same call still answers already_applied.
	 *
	 * @since 2.18.0
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function before_hash( \WP_Post $post ): string {
		return hash( 'sha256', 'slug|' . (string) $post->post_modified_gmt . '|' . (string) $post->post_name );
	}

	/**
	 * Hash of a slug, for a confirmation.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function hash( string $slug ): string {
		return hash( 'sha256', 'slug|' . $slug );
	}
}
