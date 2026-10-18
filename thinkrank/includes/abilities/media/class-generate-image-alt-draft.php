<?php
/**
 * Generate an explicitly consented AI draft without saving alt text.
 *
 * @package ThinkRank\Abilities\Media
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Media;

use ThinkRank\AI\Spend_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate an explicitly consented AI draft without saving alt text.
 *
 * @since 2.17.0
 */
class Generate_Image_Alt_Draft extends Image_Alt_Draft_Ability_Base {

	public function __construct() {
		$this->id          = 'thinkrank/generate-image-alt-draft';
		$this->label       = __( 'Draft ThinkRank Image Alt Text with AI', 'thinkrank' );
		$this->description = __( 'Send one image to the configured vision provider for at most one paid request, without saving alt text. First call get-image-alt-preview, show the cost disclosure and get explicit user consent. Pass its expected metadata fingerprint and confirm_ai_cost: true. No template fallback or automatic retry. A failed or stale result may still incur cost. Review the draft before a separate save; it does not rewrite page content.', 'thinkrank' );
	}

	public function get_annotations() {
		return [ 'readonly' => false, 'destructive' => false, 'idempotent' => false, 'priority' => 2.0, 'openWorldHint' => true ];
	}

	public function get_input_schema() {
		$schema                                 = parent::get_input_schema();
		$schema['required']                      = [ 'attachment_id', 'expected', 'confirm_ai_cost' ];
		$schema['properties']['expected']        = $this->expected_property();
		$schema['properties']['confirm_ai_cost'] = [
			'type'        => 'boolean',
			'enum'        => [ true ],
			'description' => __( 'Set true only after the user agrees to send this image to the AI provider and incur up to one paid request. This is not consent to save the draft.', 'thinkrank' ),
		];
		return $schema;
	}

	public function get_output_schema() {
		$properties = [
			'attachment_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'expected'      => $this->expected_property(),
			'proposed'      => [ 'type' => 'string', 'minLength' => 1 ],
			'alt_source'    => [ 'type' => 'string', 'enum' => [ 'ai' ] ],
			'saved'         => [ 'type' => 'boolean', 'enum' => [ false ] ],
			'ai_budget'     => $this->ai_budget_property(),
		];
		return [ 'type' => 'object', 'additionalProperties' => false, 'required' => array_keys( $properties ), 'properties' => $properties ];
	}

	public function execute( $input ) {
		$input    = (array) $input;
		$expected = $input['expected'] ?? null;
		if ( true !== ( $input['confirm_ai_cost'] ?? null ) ) {
			return new \WP_Error( 'thinkrank_ai_cost_consent_required', __( 'Explicit consent to the AI provider request and its possible cost is required. No draft was generated.', 'thinkrank' ), [ 'status' => 400 ] );
		}
		if ( ! is_string( $expected ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $expected ) ) {
			return new \WP_Error( 'thinkrank_alt_expected_required', __( 'Read get-image-alt-preview and pass its expected metadata fingerprint.', 'thinkrank' ), [ 'status' => 400 ] );
		}
		$id       = $input['attachment_id'] ?? null;
		$snapshot = $this->draft_snapshot( $id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( ! hash_equals( $snapshot['expected'], $expected ) ) {
			return new \WP_Error( 'thinkrank_alt_preview_stale', __( 'The image metadata or template context changed. Read a new preview before generating. No AI request was made.', 'thinkrank' ), [ 'status' => 409 ] );
		}
		$used     = (int) Spend_Guard::status()['used_today'];
		$proposed = $this->manager()->draft_attachment_alt( $id );
		$budget   = $this->manager()->ai_budget_report( $used );
		if ( is_wp_error( $proposed ) ) {
			return new \WP_Error( $proposed->get_error_code(), $proposed->get_error_message(), (array) $proposed->get_error_data() + [ 'saved' => false, 'ai_budget' => $budget ] );
		}
		$fresh = $this->draft_snapshot( $id );
		if ( is_wp_error( $fresh ) || ! hash_equals( $fresh['expected'], $expected ) ) {
			return new \WP_Error(
				'thinkrank_alt_draft_stale',
				__( 'The image metadata changed while AI was generating. The draft was discarded and no alt text was saved. A provider request may have been charged; review a new preview before trying again.', 'thinkrank' ),
				[ 'status' => 409, 'saved' => false, 'ai_budget' => $budget ]
			);
		}
		return [ 'attachment_id' => $id, 'expected' => $expected, 'proposed' => $proposed, 'alt_source' => 'ai', 'saved' => false, 'ai_budget' => $budget ];
	}
}
