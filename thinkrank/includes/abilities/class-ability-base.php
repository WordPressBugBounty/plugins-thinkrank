<?php
/**
 * Ability base class.
 *
 * @package ThinkRank\Abilities
 */

declare(strict_types=1);

namespace ThinkRank\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Base ability implementation for ThinkRank.
 *
 * Each ThinkRank MCP ability extends this class, providing an input/output JSON
 * schema and an execute() body. Abilities are registered with the WordPress
 * Abilities API and exposed to AI clients through the MCP server.
 */
abstract class Ability_Base {
	/**
	 * Minimum capability allowed for ThinkRank abilities.
	 */
	private const MIN_CAPABILITY = 'manage_options';

	/**
	 * Unique ability identifier.
	 *
	 * @var string
	 */
	protected $id = '';

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	protected $label = '';

	/**
	 * Ability description.
	 *
	 * @var string
	 */
	protected $description = '';

	/**
	 * Ability category.
	 *
	 * @var string
	 */
	protected $category = 'thinkrank';

	/**
	 * Required WordPress capability.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Get the JSON Schema for ability input.
	 *
	 * @return array<string, mixed>
	 */
	abstract public function get_input_schema();

	/**
	 * Get the JSON Schema for ability output.
	 *
	 * @return array<string, mixed>
	 */
	abstract public function get_output_schema();

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	abstract public function execute( $input );

	/**
	 * Check whether abilities are enabled.
	 *
	 * @return bool
	 */
	public static function abilities_enabled() {
		return (bool) apply_filters( 'thinkrank_abilities_api_enabled', true );
	}

	/**
	 * Check whether this ability can be registered and executed.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) apply_filters( 'thinkrank_ability_enabled', self::abilities_enabled(), $this->id, $this );
	}

	/**
	 * Permission callback for the abilities API.
	 *
	 * @return bool
	 */
	public function permission_callback() {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		return current_user_can( $this->capability );
	}

	/**
	 * Enforce ThinkRank's current admin capability policy.
	 *
	 * @return bool
	 */
	public function meets_capability_policy() {
		return self::MIN_CAPABILITY === $this->capability;
	}

	/**
	 * MCP-compatible annotations for this ability.
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => false,
			'idempotent'    => false,
			'priority'      => 2.0,
			'openWorldHint' => false,
		];
	}

	/**
	 * Wrapper around execute() with action hooks.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_wrapper( $input ) {
		do_action( 'thinkrank_before_ability_execute', $this->id, $input );

		$output = $this->execute( $input );

		do_action( 'thinkrank_after_ability_execute', $this->id, $input, $output );

		return $output;
	}

	/**
	 * Register the ability with the WordPress Abilities API.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			$this->id,
			[
				'label'               => $this->label,
				'description'         => $this->description,
				'category'            => $this->category,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'permission_callback' => [ $this, 'permission_callback' ],
				'execute_callback'    => [ $this, 'execute_wrapper' ],
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => $this->get_annotations(),
					'mcp'          => [
						'public' => false,
					],
				],
			]
		);
	}

	/**
	 * Get the ability ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}
}
