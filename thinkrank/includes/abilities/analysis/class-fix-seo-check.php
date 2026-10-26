<?php
/**
 * Fix a Site SEO Analyzer check ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\AI_Crawlers;
use ThinkRank\SEO\Image_SEO_Manager;
use ThinkRank\SEO\LLMs_Txt_Manager;
use ThinkRank\SEO\Schema_Management_System;
use ThinkRank\SEO\SEO_Analyzer;
use ThinkRank\SEO\SEO_Analyzer_Fixer;
use ThinkRank\SEO\Site_Identity_Manager;
use ThinkRank\SEO\Sitemap_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Applies the Site SEO Analyzer's one-click fix for one check (#1118).
 *
 * Mirrors `POST /thinkrank/v1/seo-analyzer/fix`, the audit's Fix button: the
 * same SEO_Analyzer_Fixer, under the same `manage_options` capability. What
 * it adds is the undo. Before the fix runs, it reads what the fix is about to
 * change and answers with the existing write that puts it back, so ThinkRank
 * Hub can record a fix and undo it later through the normal write path.
 *
 * The accepted check ids are the fixer's built-in ones, not the filtered
 * list: an input schema is the same on every site, since the hub stops a tool
 * whose schema differs from the release's manifest (#1066). A built-in fix a
 * site has filtered out is refused here with the fixer's own reason.
 *
 * Two answers differ from the Fix button's, for a caller that records each
 * change and undoes it later: a fix that saved its change and then found
 * something else in the way is answered as fixed, with its undo, not as an
 * error; and the AI crawler fix is refused where it would delete a robots.txt
 * ThinkRank does not manage (see refusal_for()).
 *
 * The search visibility fix makes the site indexable and no ability can put
 * that back, so it is never applied in passing (#1351): it is refused outside
 * production, as the Fix button is, and here it also needs `confirm: true`,
 * which a caller sends only after the person agreed to the fix's warning.
 */
class Fix_Seo_Check extends Ability_Base {

	/**
	 * The write that puts back what each undoable fix changes, by MCP tool name.
	 *
	 * Exactly the checks SEO_Analyzer_Fixer::built_in() marks `undo`, so the
	 * audit's `fix_undoable` and this ability's answer cannot disagree;
	 * FixSeoCheckAbilityTest holds the two lists together.
	 *
	 * @var array<string, string>
	 */
	public const UNDO_ABILITIES = [
		'xml_sitemap'       => 'update-sitemap-settings',
		'ai_crawler_access' => 'update-site-identity-settings',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/fix-seo-check';
		$this->label       = __( 'Fix Site SEO Analyzer Check', 'thinkrank' );
		$this->description = __( 'Apply the Site SEO Analyzer\'s one-click fix for one check, as the Fix button on the audit does: search_visibility, xml_sitemap, schema, image_alt_text, ai_crawler_access or llms_txt. Read get-seo-analyzer first, fix only a check whose can_auto_fix is true, and show the person its fix_label and fix_warning before applying it. search_visibility makes the whole site visible to search engines and cannot be undone: it is refused unless the site is production, and needs confirm true, sent only after the person agreed to its fix_warning. Returns fixed, message and data; image_alt_text fills one batch per call, so call again while data.remaining is above zero. A fix that saved its change but found something else still in the way answers fixed true with that in the message; only a fix that changed nothing answers an error. undo is the existing write that puts back what the fix changed, with the arguments read just before it ran (update-sitemap-settings for xml_sitemap, update-site-identity-settings for ai_crawler_access), or null where no tool can, as the audit\'s fix_undoable says beforehand. Call run-seo-analyzer afterwards for the updated audit.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			// Each fix is a settings change the audit has already explained,
			// and none of them deletes anything a person wrote: the alt text
			// fill skips images that have alt text. `destructive` is kept for
			// calls that lose something or reach outside the site for good
			// (docs/mcp-server.md, "What destructive means here"). Making a
			// site visible to search engines is the same kind of change as the
			// robots settings, which are not destructive either. The one fix
			// with no way back, search_visibility, asks for `confirm` in its
			// own input instead, so the routine fixes need no approval (#675,
			// #1351).
			'destructive'   => false,
			// A fix moves its settings to one fixed state, so applying it
			// again changes nothing more. The alt text fill continues with the
			// next batch toward the same end state.
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
			'required'             => [ 'check_id' ],
			'properties'           => [
				'check_id' => [
					'type'        => 'string',
					'enum'        => array_keys( SEO_Analyzer_Fixer::built_in() ),
					'description' => __( 'The check to fix, by the id get-seo-analyzer gives it in checks[].id.', 'thinkrank' ),
				],
				'confirm'  => [
					'type'        => 'boolean',
					'description' => __( 'Required as true for search_visibility, and sent only after the person agreed to its fix_warning: that fix makes the site visible to search engines and cannot be undone. Ignored for the other checks.', 'thinkrank' ),
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
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'fixed', 'message', 'data', 'undo' ],
			'properties'           => [
				'fixed'   => [
					'type'        => 'boolean',
					'description' => __( 'Whether the fix changed the site. True also when it saved its change but found something else still in the way, which the message names. False when it ran but had nothing it could change. A fix that could not change anything answers an error instead.', 'thinkrank' ),
				],
				'message' => [
					'type'        => 'string',
					'description' => __( 'What happened, in words to show the person, including anything still in the way after the fix.', 'thinkrank' ),
				],
				'data'    => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'description'          => __( 'What the fix reports besides the message. image_alt_text gives updated (images filled by this call), remaining (images still without alt text) and skipped_low_quality (images left empty because the alt text format would only have given them filler such as a number or "image"); the other fixes report nothing here.', 'thinkrank' ),
					'properties'           => [
						'updated'   => [ 'type' => 'integer' ],
						'remaining' => [ 'type' => 'integer' ],
						'skipped_low_quality' => [ 'type' => 'integer' ],
					],
				],
				'undo'    => [
					'type'                 => [ 'object', 'null' ],
					'additionalProperties' => false,
					'required'             => [ 'ability', 'arguments' ],
					'description'          => __( 'How to put back what the fix changed: call this tool with these arguments, as given. The arguments hold the values read just before the fix ran. Null when no tool can put the change back.', 'thinkrank' ),
					'properties'           => [
						'ability'   => [
							'type'        => 'string',
							'enum'        => array_values( array_unique( self::UNDO_ABILITIES ) ),
							'description' => __( 'The tool to call, by its MCP name.', 'thinkrank' ),
						],
						'arguments' => [
							'type'        => 'object',
							'description' => __( 'The arguments to call it with, which match its input schema.', 'thinkrank' ),
						],
					],
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
		$check_id = isset( $input['check_id'] ) && is_string( $input['check_id'] ) ? $input['check_id'] : '';
		$fixable  = SEO_Analyzer_Fixer::can_fix( $check_id );

		$confirm = isset( $input['confirm'] ) && true === $input['confirm'];
		$refusal = $fixable ? self::refusal_for( $check_id, $confirm ) : null;
		if ( null !== $refusal ) {
			return $refusal;
		}

		// What the fix is about to change, read before it runs: the undo puts
		// exactly this back, and the state tells a fix that threw after it
		// saved from one that changed nothing.
		$undo   = $fixable ? self::undo_for( $check_id ) : null;
		$before = $fixable ? self::state_of( $check_id ) : null;

		$outcome = null;
		$failure = null;

		try {
			$outcome = ( new SEO_Analyzer_Fixer() )->fix( $check_id );
		} catch ( \Throwable $e ) {
			// An id the fixer does not know is refused by the fixer itself,
			// with its own reason, before it touches anything.
			$failure = $e;
		}

		if ( $fixable ) {
			// The audit is cached for an hour. The Fix button answers with a
			// fresh run, because a fix the next read cannot see looks like
			// one that failed; drop the cached one so the next read is fresh.
			// A fix can throw after it saved something, so this runs either
			// way.
			( new SEO_Analyzer() )->flush_cache();
		}

		if ( null !== $failure ) {
			// A fix can save its change and then find something else still in
			// the way: the AI crawler rules are saved before the served
			// robots.txt is read again, and a save can fail after writing part
			// of what it was given. The site changed, so this is not a refusal:
			// it is answered as fixed, with what is in the way as the message
			// and the undo for what was saved. Only a fix that changed nothing
			// is an error. The REST route the audit screen uses keeps the
			// fixer's own answer.
			if ( null !== $before && self::state_of( $check_id ) !== $before ) {
				return [
					'fixed'   => true,
					'message' => self::plain_text( $failure->getMessage() ),
					'data'    => self::object_value( [] ),
					'undo'    => $undo,
				];
			}

			return new \WP_Error(
				$fixable ? 'thinkrank_seo_check_fix_failed' : 'thinkrank_seo_check_not_fixable',
				self::plain_text( $failure->getMessage() ),
				[ 'status' => 400 ]
			);
		}

		$data = isset( $outcome['data'] ) && is_array( $outcome['data'] ) ? $outcome['data'] : [];

		return [
			'fixed'   => ! empty( $outcome['fixed'] ),
			'message' => isset( $outcome['message'] ) && is_scalar( $outcome['message'] ) ? (string) $outcome['message'] : '',
			'data'    => self::object_value( $data ),
			'undo'    => $undo,
		];
	}

	/**
	 * The undo for one check, from the values its fix is about to change.
	 *
	 * Read through the same managers the fix and the undo write use, and
	 * shaped to the undo ability's own input schema.
	 *
	 * @param string $check_id Check id.
	 * @return array{ability: string, arguments: array<string, mixed>}|null Null when the check has no undo.
	 */
	private static function undo_for( string $check_id ): ?array {
		switch ( $check_id ) {
			case 'xml_sitemap':
				$settings = ( new Sitemap_Generator() )->get_settings( 'site', null );

				return [
					'ability'   => self::UNDO_ABILITIES['xml_sitemap'],
					'arguments' => [
						'settings' => [
							// The check reads a missing value as on, as the
							// sitemap does, so the undo does too.
							'enabled' => ! empty( $settings['enabled'] ?? true ),
						],
					],
				];

			case 'ai_crawler_access':
				return [
					'ability'   => self::UNDO_ABILITIES['ai_crawler_access'],
					'arguments' => [
						'settings' => [
							// The whole map, as the save stores it. The write
							// replaces the map rather than merging into it, so a
							// crawler the fix set to allow that had no rule
							// before goes back to having none, which is allow.
							// Encoded as `{}` when empty: an empty map is a JSON
							// object here, as the write's schema requires.
							'ai_crawler_rules' => self::object_value( self::crawler_rules() ),
						],
					],
				];
		}

		return null;
	}

	/**
	 * Why a fix is not run on this site, or null when it is.
	 *
	 * The AI crawler fix re-syncs the physical robots.txt after it saves the
	 * rules (SEO_Analyzer_Fixer::fix_ai_crawler_access()). With ThinkRank's
	 * robots.txt management off, crawlers read the robots.txt in the site
	 * root, usually the one blocking them, and the rules the fix saves never
	 * reach it (the sync leaves a file ThinkRank did not write alone, #1122).
	 * The fix is refused here before it changes anything. The REST route the
	 * audit screen uses is unchanged.
	 *
	 * The search visibility fix is refused outside production, with the
	 * fixer's own reason, and without `confirm: true` (#1351). Both answer
	 * before anything is read or written.
	 *
	 * @param string $check_id Check id.
	 * @param bool   $confirm  Whether the caller sent `confirm: true`.
	 * @return \WP_Error|null
	 */
	private static function refusal_for( string $check_id, bool $confirm ): ?\WP_Error {
		if ( 'search_visibility' === $check_id ) {
			if ( ! SEO_Analyzer_Fixer::search_visibility_fix_allowed() ) {
				return new \WP_Error(
					'thinkrank_seo_check_fix_refused',
					SEO_Analyzer_Fixer::search_visibility_refusal(),
					[ 'status' => 409 ]
				);
			}

			if ( ! $confirm ) {
				return new \WP_Error(
					'thinkrank_seo_check_confirmation_required',
					__( 'This fix makes the whole site visible to search engines, and no tool can undo it. Show the person the fix warning, and call again with confirm set to true only if they agree.', 'thinkrank' ),
					[ 'status' => 409 ]
				);
			}

			return null;
		}

		if ( 'ai_crawler_access' !== $check_id || ! file_exists( ABSPATH . 'robots.txt' ) ) {
			return null;
		}

		$settings = ( new Site_Identity_Manager() )->get_settings( 'site', null );
		if ( ! empty( $settings['robots_txt_enabled'] ) ) {
			return null;
		}

		return new \WP_Error(
			'thinkrank_seo_check_fix_refused',
			__( 'ThinkRank is not managing your robots.txt, so crawlers read the robots.txt file in your site root. This fix only changes ThinkRank\'s own crawler rules, which that file does not include, so it was not applied. Edit the file to allow the answer-engine crawlers, or turn on robots.txt management under Essential SEO → Crawling and AI Indexing first.', 'thinkrank' ),
			[ 'status' => 409 ]
		);
	}

	/**
	 * What one fix writes, read the same way before it runs and after it
	 * throws, so the two can be compared.
	 *
	 * @param string $check_id Check id.
	 * @return array<string, mixed>|null Null for a fix this ability does not
	 *                                   know, such as one a filter added.
	 */
	private static function state_of( string $check_id ): ?array {
		switch ( $check_id ) {
			case 'search_visibility':
				// Both switches that keep search engines out, so a fix that
				// turns on ThinkRank's own as well (#967) is covered too.
				$identity = ( new Site_Identity_Manager() )->get_settings( 'site', null );

				return [
					'blog_public'          => (bool) get_option( 'blog_public' ),
					'allow_search_engines' => ! empty( $identity['allow_search_engines'] ?? true ),
				];

			case 'xml_sitemap':
				$settings = ( new Sitemap_Generator() )->get_settings( 'site', null );

				return [ 'enabled' => ! empty( $settings['enabled'] ?? true ) ];

			case 'schema':
				if ( ! class_exists( 'ThinkRank\\SEO\\Schema_Management_System' ) ) {
					return null;
				}

				$settings = ( new Schema_Management_System() )->get_settings( 'site', null );

				return [
					'enabled'              => ! empty( $settings['enabled'] ),
					'auto_generate_schema' => ! empty( $settings['auto_generate_schema'] ),
					'enabled_schema_types' => array_values( (array) ( $settings['enabled_schema_types'] ?? [] ) ),
				];

			case 'image_alt_text':
				// Only images without alt text are filled, so any image the
				// batch wrote lowers this count.
				$stats = ( new Image_SEO_Manager() )->get_media_alt_stats();

				return [ 'missing' => (int) ( $stats['missing'] ?? 0 ) ];

			case 'ai_crawler_access':
				$rules = self::crawler_rules();
				ksort( $rules );

				// The fix rewrites a physical robots.txt after saving the
				// rules, which is a change even when the rules were already
				// what the fix sets.
				$file = ABSPATH . 'robots.txt';

				return [
					'rules' => $rules,
					'file'  => file_exists( $file ) ? (string) md5_file( $file ) : '',
				];

			case 'llms_txt':
				if ( ! class_exists( 'ThinkRank\\SEO\\LLMs_Txt_Manager' ) ) {
					return null;
				}

				$file = ABSPATH . 'llms.txt';

				return [
					'published' => ( new LLMs_Txt_Manager() )->get_published_content(),
					'file'      => file_exists( $file ) ? (string) md5_file( $file ) : '',
				];
		}

		return null;
	}

	/**
	 * The stored AI crawler rules, as a save normalizes them: known crawlers
	 * only, each `allow` or `block`.
	 *
	 * @return array<string, string>
	 */
	private static function crawler_rules(): array {
		$settings = ( new Site_Identity_Manager() )->get_settings( 'site', null );

		return AI_Crawlers::normalize_rules( $settings['ai_crawler_rules'] ?? [] );
	}

	/**
	 * A fixer message as plain text.
	 *
	 * The fixer escapes the messages it throws for HTML. This answer is JSON,
	 * where `&#039;` would reach the person as written.
	 *
	 * @param string $message Message as thrown.
	 * @return string
	 */
	private static function plain_text( string $message ): string {
		return wp_specialchars_decode( $message, ENT_QUOTES );
	}
}
