<?php
/**
 * Abilities registrar.
 *
 * @package ThinkRank\Abilities
 */

declare(strict_types=1);

namespace ThinkRank\Abilities;

use ThinkRank\Abilities\Analysis\Get_Post_Seo_Checks;
use ThinkRank\Abilities\Analysis\Get_Term_Seo_Checks;
use ThinkRank\Abilities\Content\Get_Post_Seo;
use ThinkRank\Abilities\Content\Get_Term_Seo;
use ThinkRank\Abilities\Content\List_Content_Items;
use ThinkRank\Abilities\Content\List_Content_Types;
use ThinkRank\Abilities\Content\Update_Post_Seo;
use ThinkRank\Abilities\Content\Update_Term_Seo;
use ThinkRank\Abilities\Content\Generate_Content_Brief;
use ThinkRank\Abilities\Content\Get_Pillar_Content;
use ThinkRank\Abilities\Content\Update_Pillar_Content;
use ThinkRank\Abilities\Import\Detect_Import_Sources;
use ThinkRank\Abilities\Import\Get_Import_Status;
use ThinkRank\Abilities\Import\Run_Seo_Import;
use ThinkRank\Abilities\Settings\Get_Global_Settings;
use ThinkRank\Abilities\Settings\Get_Robots_Txt;
use ThinkRank\Abilities\Settings\Get_Sitemap_Settings;
use ThinkRank\Abilities\Settings\Update_Global_Settings;
use ThinkRank\Abilities\Settings\Update_Robots_Txt;
use ThinkRank\Abilities\Settings\Update_Sitemap_Settings;
use ThinkRank\Abilities\Settings\Get_Schema_Settings;
use ThinkRank\Abilities\Settings\Update_Schema_Settings;
use ThinkRank\Abilities\Settings\Get_Site_Identity_Settings;
use ThinkRank\Abilities\Settings\Update_Site_Identity_Settings;
use ThinkRank\Abilities\Settings\Get_Social_Meta_Settings;
use ThinkRank\Abilities\Settings\Update_Social_Meta_Settings;
use ThinkRank\Abilities\Settings\Get_Image_Seo_Settings;
use ThinkRank\Abilities\Settings\Update_Image_Seo_Settings;
use ThinkRank\Abilities\Settings\Get_Llms_Txt_Settings;
use ThinkRank\Abilities\Settings\Update_Llms_Txt_Settings;
use ThinkRank\Abilities\Settings\Get_Robots_Meta_Settings;
use ThinkRank\Abilities\Settings\Update_Robots_Meta_Settings;
use ThinkRank\Abilities\Settings\Get_Instant_Indexing_Settings;
use ThinkRank\Abilities\Settings\Update_Instant_Indexing_Settings;
use ThinkRank\Abilities\Settings\Get_Author_Archives_Settings;
use ThinkRank\Abilities\Settings\Update_Author_Archives_Settings;
use ThinkRank\Abilities\Settings\Get_Email_Report_Settings;
use ThinkRank\Abilities\Settings\Update_Email_Report_Settings;
use ThinkRank\Abilities\Settings\Send_Email_Report_Test;
use ThinkRank\Abilities\Settings\Get_Social_Platforms_Settings;
use ThinkRank\Abilities\Settings\Update_Social_Platforms_Settings;
use ThinkRank\Abilities\Indexing\Submit_Urls_To_Index;
use ThinkRank\Abilities\Indexing\Get_Instant_Indexing_History;
use ThinkRank\Abilities\Analysis\Get_Llms_Txt_Status;
use ThinkRank\Abilities\Analysis\Generate_Llms_Txt;
use ThinkRank\Abilities\Analysis\Publish_Llms_Txt;
use ThinkRank\Abilities\Analysis\Get_Seo_Analytics_Data;
use ThinkRank\Abilities\Analysis\Get_Seo_Insights;
use ThinkRank\Abilities\Analysis\Get_Seo_Opportunities;
use ThinkRank\Abilities\Analysis\Get_Seo_Score;
use ThinkRank\Abilities\Analysis\Get_Seo_Analyzer;
use ThinkRank\Abilities\Analysis\Run_Seo_Analyzer;
use ThinkRank\Abilities\Analysis\Get_Performance_Data;
use ThinkRank\Abilities\Analysis\Get_Integrations_Status;
use ThinkRank\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers ThinkRank abilities with the WordPress Abilities API.
 *
 * A single admin toggle (`enable_mcp`) gates the abilities registration. The
 * abilities are served to AI clients by ThinkRank's own MCP server
 * ({@see \ThinkRank\Mcp\Mcp_Server}), which reads the abilities registry as
 * its tool catalog — the bundled MCP Adapter is no longer used. The Abilities
 * API itself ships in `dependencies/` and no-ops gracefully when missing.
 */
class Abilities_Registrar {

	/**
	 * Initialize the registrar (called by the plugin's component container).
	 *
	 * @return void
	 */
	public function init() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Single admin toggle (enable_mcp) gates the abilities registration.
		add_filter( 'thinkrank_abilities_api_enabled', [ $this, 'is_mcp_enabled' ] );

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Whether the MCP integration is enabled via the admin setting.
	 *
	 * Used as the callback for the `thinkrank_abilities_api_enabled` filter so
	 * the same toggle controls the abilities registration and the MCP server.
	 *
	 * @return bool
	 */
	public function is_mcp_enabled() {
		return (bool) Settings::instance()->get( 'enable_mcp', false );
	}

	/**
	 * Register the ThinkRank ability category.
	 *
	 * @return void
	 */
	public function register_category() {
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'thinkrank' ) ) {
			return;
		}

		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				'thinkrank',
				[
					'label'       => __( 'ThinkRank', 'thinkrank' ),
					'description' => __( 'SEO settings, metadata, and analysis abilities powered by ThinkRank.', 'thinkrank' ),
				]
			);
		}
	}

	/**
	 * Register ThinkRank abilities.
	 *
	 * @return void
	 */
	public function register_abilities() {
		if ( ! Ability_Base::abilities_enabled() ) {
			return;
		}

		$abilities = [
			new List_Content_Types(),
			new List_Content_Items(),
			new Get_Global_Settings(),
			new Update_Global_Settings(),
			new Get_Post_Seo(),
			new Update_Post_Seo(),
			new Get_Term_Seo(),
			new Update_Term_Seo(),
			new Get_Post_Seo_Checks(),
			new Get_Term_Seo_Checks(),
			new Get_Robots_Txt(),
			new Update_Robots_Txt(),
			new Get_Sitemap_Settings(),
			new Update_Sitemap_Settings(),
			new Get_Schema_Settings(),
			new Update_Schema_Settings(),
			new Get_Site_Identity_Settings(),
			new Update_Site_Identity_Settings(),
			new Get_Social_Meta_Settings(),
			new Update_Social_Meta_Settings(),
			new Get_Image_Seo_Settings(),
			new Update_Image_Seo_Settings(),
			new Get_Llms_Txt_Settings(),
			new Update_Llms_Txt_Settings(),
			new Get_Robots_Meta_Settings(),
			new Update_Robots_Meta_Settings(),
			new Get_Instant_Indexing_Settings(),
			new Update_Instant_Indexing_Settings(),
			new Get_Author_Archives_Settings(),
			new Update_Author_Archives_Settings(),
			new Get_Email_Report_Settings(),
			new Update_Email_Report_Settings(),
			new Send_Email_Report_Test(),
			new Get_Social_Platforms_Settings(),
			new Update_Social_Platforms_Settings(),
			new Submit_Urls_To_Index(),
			new Get_Instant_Indexing_History(),
			new Get_Llms_Txt_Status(),
			new Generate_Llms_Txt(),
			new Publish_Llms_Txt(),
			new Get_Seo_Analytics_Data(),
			new Get_Seo_Insights(),
			new Get_Seo_Opportunities(),
			new Get_Seo_Score(),
			new Get_Seo_Analyzer(),
			new Run_Seo_Analyzer(),
			new Get_Performance_Data(),
			new Get_Integrations_Status(),
			new Generate_Content_Brief(),
			new Get_Pillar_Content(),
			new Update_Pillar_Content(),
			new Detect_Import_Sources(),
			new Get_Import_Status(),
			new Run_Seo_Import(),
		];

		$abilities = apply_filters( 'thinkrank_register_abilities', $abilities );

		foreach ( $abilities as $ability ) {
			if ( ! $ability instanceof Ability_Base ) {
				continue;
			}

			if ( ! $ability->meets_capability_policy() || ! $ability->is_enabled() ) {
				continue;
			}

			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability->get_id() ) ) {
				continue;
			}

			$ability->register();
		}
	}
}
