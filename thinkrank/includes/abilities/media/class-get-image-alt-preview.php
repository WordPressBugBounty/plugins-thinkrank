<?php
/**
 * Read one image and its free template candidate without saving alt text.
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
 * Read one image and its free template candidate without saving alt text.
 *
 * @since 2.17.0
 */
class Get_Image_Alt_Preview extends Image_Alt_Draft_Ability_Base {

	public function __construct() {
		$this->id          = 'thinkrank/get-image-alt-preview';
		$this->label       = __( 'Preview ThinkRank Image Alt Text', 'thinkrank' );
		$this->description = __( 'Read one image and a free template candidate without saving alt text or calling AI. Template text is a filename/title suggestion, not a visual description. Read the cost disclosure and obtain explicit consent before generate-image-alt-draft. Review the proposed words before any separate save; Media Library alt does not replace alt embedded in page content.', 'thinkrank' );
	}

	public function get_annotations() {
		return [ 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'priority' => 1.0, 'openWorldHint' => false ];
	}

	public function get_output_schema() {
		$properties = [
			'image'    => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array_keys( $this->image_properties() ),
				'properties'           => $this->image_properties(),
			],
			'expected' => $this->expected_property(),
			'template' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'format', 'proposed', 'status' ],
				'properties'           => [
					'format'   => [ 'type' => 'string' ],
					'proposed' => [ 'type' => 'string' ],
					'status'   => [ 'type' => 'string', 'enum' => [ 'candidate', 'empty', 'low_quality' ] ],
				],
			],
			'ai'       => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'available', 'reason', 'max_requests', 'cost_notice', 'budget' ],
				'properties'           => [
					'available'    => [ 'type' => 'boolean' ],
					'reason'       => [ 'type' => 'string' ],
					'max_requests' => [ 'type' => 'integer', 'enum' => [ 1 ] ],
					'cost_notice'  => [ 'type' => 'string' ],
					'budget'       => $this->ai_budget_property(),
				],
			],
			'saved'    => [ 'type' => 'boolean', 'enum' => [ false ] ],
		];
		return [ 'type' => 'object', 'additionalProperties' => false, 'required' => array_keys( $properties ), 'properties' => $properties ];
	}

	public function execute( $input ) {
		$snapshot = $this->draft_snapshot( ( (array) $input )['attachment_id'] ?? null );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$used   = (int) Spend_Guard::status()['used_today'];
		$reason = $this->manager()->ai_alt_unavailable_reason();
		return $snapshot + [
			'ai'    => [
				'available'    => null === $reason,
				'reason'       => $reason ?? '',
				'max_requests' => 1,
				'cost_notice'  => __( 'Generating a draft sends this image to your configured AI provider and can incur one paid request. Price depends on your provider and model; no monetary quote is available. Alt text is not saved. A failed request can still cost money; do not retry automatically.', 'thinkrank' ),
				'budget'       => $this->manager()->ai_budget_report( $used ),
			],
			'saved' => false,
		];
	}
}
