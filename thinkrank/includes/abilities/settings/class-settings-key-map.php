<?php
/**
 * Shared setting-key declarations for the settings abilities.
 *
 * @package ThinkRank\Abilities\Settings
 * @since 2.1.1
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The keys each settings category exposes over the abilities layer, and their
 * JSON types.
 *
 * Declared once and shared by the get/update pair for each category, because
 * two hand-maintained allowlists per category is how the gap opened in the
 * first place: site identity exposed 13 of its 54 keys and sitemap 8 of its 20,
 * so an MCP agent could neither read nor set any title template, the whole
 * business/Local SEO block, or the sitemap index toggle — and, since the read
 * ability omitted them silently, had no way to tell its picture was incomplete
 * (#518).
 *
 * AbilitySettingsCoverageTest walks each manager's get_known_setting_keys()
 * against these maps and fails when a category gains a key that is neither
 * exposed here nor listed as deliberately excluded, so the drift cannot recur
 * unnoticed.
 *
 * @since 2.1.1
 */
final class Settings_Key_Map {

	/**
	 * `site_identity` keys that belong to another ability.
	 *
	 * Not gaps: each is already reachable, and exposing it twice would give an
	 * agent two ways to write the same row.
	 *
	 * @var array<string,string> Key => the ability that owns it.
	 */
	public const SITE_IDENTITY_ELSEWHERE = [
		'robots_txt_content'  => 'thinkrank/update-robots-txt',
		'custom_robots_rules' => 'thinkrank/update-robots-txt',
		'knowledge_graph'     => 'thinkrank/update-schema-settings',
		'organization_schema' => 'thinkrank/update-schema-settings',
		'post_title'          => 'thinkrank/update-global-settings',
		'page_title'          => 'thinkrank/update-global-settings',
	];

	/**
	 * `sitemap` keys that are derived state rather than settings.
	 *
	 * @var array<string,string> Key => why it is not exposed.
	 */
	public const SITEMAP_ELSEWHERE = [
		'last_generated'  => 'written by the generator, not a setting',
		'sitemap_urls'    => 'written by the generator, not a setting',
		'selected_preset' => 'UI marker the settings screen maintains for itself',
	];

	/**
	 * JSON schema properties for every exposed `site_identity` key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function site_identity(): array {
		return [
			// Core.
			'enabled'                  => self::boolean( __( 'Whether site identity management is active.', 'thinkrank' ) ),
			'site_name'                => self::string( __( 'Official name of the site, used in titles and schema.', 'thinkrank' ) ),
			'site_description'         => self::string( __( 'Short description of the site.', 'thinkrank' ) ),
			'tagline'                  => self::string( __( 'Site tagline.', 'thinkrank' ) ),
			'alternate_name'           => self::string( __( 'Alternate or former name of the site, published as schema alternateName.', 'thinkrank' ) ),
			'identity_type'            => self::string( __( 'What the site is, e.g. "blog", "business", "portfolio".', 'thinkrank' ) ),
			'represents'               => self::string( __( 'Whether the site represents a "person" or an "organization".', 'thinkrank' ) ),
			'default_meta_description' => self::string( __( 'Meta description used where no more specific one is set.', 'thinkrank' ) ),
			'default_social_image'     => self::string( __( 'URL of the fallback social sharing image.', 'thinkrank' ) ),
			'social_media_accounts'    => [
				'type'        => 'array',
				'description' => __( 'Profile URLs for the site\'s social accounts.', 'thinkrank' ),
				'items'       => [ 'type' => 'string' ],
			],

			// Titles. These are %token% templates; the manager sanitizes them
			// without stripping their tokens.
			'title_template'           => self::string( __( 'Named title layout, e.g. "default", "reverse", "category".', 'thinkrank' ) ),
			'title_separator'          => self::string( __( 'Separator between title parts, e.g. "pipe", "dash".', 'thinkrank' ) ),
			'homepage_title'           => self::template( __( 'the homepage', 'thinkrank' ), '' ),
			'category_title'           => self::template( __( 'category archives', 'thinkrank' ), '%category_title%, %category%' ),
			'tag_title'                => self::template( __( 'tag archives', 'thinkrank' ), '%tag_title%' ),
			'author_title'             => self::template( __( 'author archives', 'thinkrank' ), '%author_name%' ),
			'search_title'             => self::template( __( 'search results pages', 'thinkrank' ), '%search_term%' ),
			'archive_title'            => self::template( __( 'date and other archives', 'thinkrank' ), '%archive_title%' ),

			// Breadcrumbs.
			'breadcrumbs_enabled'      => self::boolean( __( 'Whether breadcrumb markup is generated.', 'thinkrank' ) ),
			'breadcrumb_type'          => self::string( __( 'Breadcrumb structure, e.g. "hierarchical".', 'thinkrank' ) ),
			'breadcrumb_home_text'     => self::string( __( 'Label for the home link in breadcrumbs.', 'thinkrank' ) ),
			'breadcrumb_separator'     => self::string( __( 'Separator drawn between breadcrumb items.', 'thinkrank' ) ),
			'breadcrumb_prefix'        => self::string( __( 'Text shown before the breadcrumb trail.', 'thinkrank' ) ),
			'show_current_page'        => self::boolean( __( 'Whether the current page appears in its own breadcrumb trail.', 'thinkrank' ) ),
			'breadcrumb_use_seo_title' => self::boolean( __( 'Whether breadcrumb labels use the SEO title of a post or term when one is set, instead of its raw title.', 'thinkrank' ) ),

			// Brand imagery.
			'logo_url'                 => self::string( __( 'URL of the site logo.', 'thinkrank' ) ),
			'favicon_url'              => self::string( __( 'URL of the site favicon.', 'thinkrank' ) ),
			'apple_touch_icon_url'     => self::string( __( 'URL of the Apple touch icon.', 'thinkrank' ) ),

			// Homepage hero.
			'hero_title'               => self::string( __( 'Homepage hero heading.', 'thinkrank' ) ),
			'hero_subtitle'            => self::string( __( 'Homepage hero subheading.', 'thinkrank' ) ),
			'hero_cta_text'            => self::string( __( 'Label on the homepage hero call to action.', 'thinkrank' ) ),
			'hero_cta_url'             => self::string( __( 'URL the homepage hero call to action points at.', 'thinkrank' ) ),
			'hero_background_image'    => self::string( __( 'URL of the homepage hero background image.', 'thinkrank' ) ),

			// Business / Local SEO. Feeds LocalBusiness schema.
			'local_seo_enabled'        => self::boolean( __( 'Whether Local SEO output and LocalBusiness schema are enabled.', 'thinkrank' ) ),
			'business_name'            => self::string( __( 'Registered business name.', 'thinkrank' ) ),
			'business_type'            => self::string( __( 'schema.org business type, e.g. "Restaurant", "Store".', 'thinkrank' ) ),
			'business_email'           => self::string( __( 'Public contact email address.', 'thinkrank' ) ),
			'business_phone'           => self::string( __( 'Public contact telephone number.', 'thinkrank' ) ),
			'business_address'         => self::string( __( 'Street address.', 'thinkrank' ) ),
			'business_city'            => self::string( __( 'City or locality.', 'thinkrank' ) ),
			'business_state'           => self::string( __( 'State, province or region.', 'thinkrank' ) ),
			'business_country'         => self::string( __( 'Country.', 'thinkrank' ) ),
			'business_postal_code'     => self::string( __( 'Postal or ZIP code.', 'thinkrank' ) ),
			'business_latitude'        => self::string( __( 'Latitude of the business location, in decimal degrees.', 'thinkrank' ) ),
			'business_longitude'       => self::string( __( 'Longitude of the business location, in decimal degrees.', 'thinkrank' ) ),
			'business_price_range'     => self::string( __( 'Price range indicator, e.g. "$$".', 'thinkrank' ) ),
			'business_hours'           => self::business_hours(),

			// Indexing.
			'allow_search_engines'     => self::boolean( __( 'Whether search engines are allowed to index the site.', 'thinkrank' ) ),
			'robots_txt_enabled'       => self::boolean( __( 'Whether ThinkRank manages robots.txt. Its contents are set with thinkrank/update-robots-txt.', 'thinkrank' ) ),
		];
	}

	/**
	 * JSON schema properties for every exposed `sitemap` key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function sitemap(): array {
		return [
			'enabled'                    => self::boolean( __( 'Whether XML sitemap generation is active.', 'thinkrank' ) ),
			'auto_generate'              => self::boolean( __( 'Whether the sitemap regenerates automatically when content changes.', 'thinkrank' ) ),
			'use_sitemap_index'          => self::boolean( __( 'Whether to publish a sitemap index that links per-type sitemaps, rather than one flat file.', 'thinkrank' ) ),
			'links_per_sitemap'          => [
				'type'        => 'integer',
				'description' => __( 'Maximum URLs per sitemap file before it is split.', 'thinkrank' ),
				'minimum'     => 1,
				'maximum'     => 50000,
			],
			'custom_url_pattern'         => self::string( __( 'Filename pattern for generated sitemaps, e.g. "sitemap-{type}.xml".', 'thinkrank' ) ),
			'enable_styling'             => self::boolean( __( 'Whether an XSL stylesheet is attached so the sitemap is readable in a browser.', 'thinkrank' ) ),
			'ping_search_engines'        => self::boolean( __( 'Whether search engines are notified after the sitemap is regenerated.', 'thinkrank' ) ),

			// What goes in.
			'include_posts'              => self::boolean( __( 'Whether posts are included.', 'thinkrank' ) ),
			'include_pages'              => self::boolean( __( 'Whether pages are included.', 'thinkrank' ) ),
			'include_categories'         => self::boolean( __( 'Whether category archives are included.', 'thinkrank' ) ),
			'include_tags'               => self::boolean( __( 'Whether tag archives are included.', 'thinkrank' ) ),
			'include_images'             => self::boolean( __( 'Whether image entries are included for each URL.', 'thinkrank' ) ),
			'include_featured_images'    => self::boolean( __( 'Whether featured images are included as image entries.', 'thinkrank' ) ),

			// What stays out.
			'exclude_posts'              => self::string( __( 'Comma-separated post IDs to leave out.', 'thinkrank' ) ),
			'exclude_terms'              => self::string( __( 'Comma-separated term IDs to leave out.', 'thinkrank' ) ),
			'exclude_private_posts'      => self::boolean( __( 'Whether privately published posts are left out.', 'thinkrank' ) ),
			'exclude_password_protected' => self::boolean( __( 'Whether password-protected posts are left out.', 'thinkrank' ) ),
		];
	}

	/**
	 * Read the exposed keys out of a manager's stored settings.
	 *
	 * The store keeps everything as strings ("1"/""), so each value is coerced
	 * back to the type the schema advertises before it reaches an agent.
	 *
	 * @param array<string, array<string, mixed>> $properties Schema properties for the category.
	 * @param array<string, mixed>                $stored     Settings as the manager returns them.
	 * @return array<string, mixed> Exposed settings, one entry per declared key.
	 */
	public static function read( array $properties, array $stored ): array {
		$out = [];

		foreach ( $properties as $key => $property ) {
			$value = $stored[ $key ] ?? null;

			switch ( $property['type'] ) {
				case 'boolean':
					$out[ $key ] = (bool) $value;
					break;
				case 'integer':
					$out[ $key ] = (int) $value;
					break;
				case 'array':
				case 'object':
					$out[ $key ] = is_array( $value ) ? $value : [];
					break;
				default:
					$out[ $key ] = (string) ( $value ?? '' );
			}
		}

		return $out;
	}

	/**
	 * Coerce an incoming patch to the declared types, dropping unknown keys.
	 *
	 * Strings are passed through rather than sanitized here: the manager's own
	 * save path already picks the right sanitizer per key, and running
	 * sanitize_text_field() first would strip %date% and %category% out of the
	 * title templates as percent-encoding before it ever got the chance (#521).
	 *
	 * @param array<string, array<string, mixed>> $properties Schema properties for the category.
	 * @param array<string, mixed>                $incoming   Caller-supplied settings.
	 * @return array<string, mixed> Recognized keys only, coerced to type.
	 */
	public static function coerce( array $properties, array $incoming ): array {
		$out = [];

		foreach ( $properties as $key => $property ) {
			if ( ! array_key_exists( $key, $incoming ) ) {
				continue;
			}

			$value = $incoming[ $key ];

			switch ( $property['type'] ) {
				case 'boolean':
					$out[ $key ] = (bool) $value;
					break;
				case 'integer':
					$number = (int) $value;
					if ( isset( $property['minimum'] ) ) {
						$number = max( (int) $property['minimum'], $number );
					}
					if ( isset( $property['maximum'] ) ) {
						$number = min( (int) $property['maximum'], $number );
					}
					$out[ $key ] = $number;
					break;
				case 'array':
				case 'object':
					if ( ! is_array( $value ) ) {
						// A structured key given a scalar is a caller error, not
						// something to flatten into a string and store.
						continue 2;
					}
					$out[ $key ] = $value;
					break;
				default:
					if ( is_array( $value ) ) {
						continue 2;
					}
					$out[ $key ] = (string) $value;
			}
		}

		return $out;
	}

	/**
	 * The opening hours object, one entry per day.
	 *
	 * A real sub-schema rather than string coercion: the value is a map of day
	 * name to {open, close, closed}, and flattening it to a string would make
	 * it unwritable over MCP.
	 *
	 * @return array<string, mixed>
	 */
	private static function business_hours(): array {
		$day = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'open'   => [
					'type'        => 'string',
					'description' => __( 'Opening time as 24-hour HH:MM, or "" when closed.', 'thinkrank' ),
				],
				'close'  => [
					'type'        => 'string',
					'description' => __( 'Closing time as 24-hour HH:MM, or "" when closed.', 'thinkrank' ),
				],
				'closed' => [
					'type'        => 'boolean',
					'description' => __( 'Whether the business is closed all day.', 'thinkrank' ),
				],
			],
		];

		$properties = [];
		foreach ( [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ] as $name ) {
			$properties[ $name ] = $day;
		}

		return [
			'type'                 => 'object',
			'description'          => __( 'Opening hours per day of the week.', 'thinkrank' ),
			'additionalProperties' => false,
			'properties'           => $properties,
		];
	}

	/**
	 * A boolean property.
	 *
	 * @param string $description Translated text describing what the toggle does.
	 * @return array<string, mixed>
	 */
	private static function boolean( string $description ): array {
		return [
			'type'        => 'boolean',
			'description' => $description,
		];
	}

	/**
	 * A string property.
	 *
	 * @param string $description Translated text describing the value.
	 * @return array<string, mixed>
	 */
	private static function string( string $description ): array {
		return [
			'type'        => 'string',
			'description' => $description,
		];
	}

	/**
	 * A %token% title template property.
	 *
	 * The vocabulary here is Site Identity's, which is not the one the Global
	 * SEO per-post-type templates use — %site_title% rather than %title%,
	 * %site_name% rather than %sitename% — so the description spells it out
	 * instead of leaving an agent to guess between the two.
	 *
	 * @param string $what   Translated name of the pages the template titles.
	 * @param string $extra  Comma-separated tags available only on those pages, or ''.
	 * @return array<string, mixed>
	 */
	private static function template( string $what, string $extra ): array {
		$shared = '%site_title%, %site_name%, %site_description%, %tagline%, %sep%, %separator%, %date%';

		$description = '' === $extra
			/* translators: 1: the pages a title template applies to. 2: the list of available variable tags. */
			? sprintf( __( 'Title template for %1$s. Available tags: %2$s.', 'thinkrank' ), $what, $shared )
			/* translators: 1: the pages a title template applies to. 2: tags available everywhere. 3: tags available only on those pages. */
			: sprintf( __( 'Title template for %1$s. Available tags: %2$s, and on these pages also %3$s.', 'thinkrank' ), $what, $shared, $extra );

		return [
			'type'        => 'string',
			'description' => $description,
		];
	}
}
