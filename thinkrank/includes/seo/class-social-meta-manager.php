<?php
/**
 * Social Meta Manager Class
 *
 * Universal social media meta tag generation and optimization for all platforms.
 * Implements 2025 social media SEO best practices with real Open Graph and Twitter Card
 * specifications, social image optimization, and platform-specific handling.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Social Meta Manager Class
 *
 * Generates and validates social media meta tags for all supported platforms.
 * Provides context-aware social meta generation with image optimization and
 * platform-specific meta tag handling.
 *
 * @since 1.0.0
 */
class Social_Meta_Manager extends Abstract_SEO_Manager {

    /**
     * Supported social media platforms with their specifications
     *
     * @since 1.0.0
     * @var array
     */
    private array $supported_platforms = [
        'facebook' => [
            'og_required' => ['og:title', 'og:type', 'og:image', 'og:url'],
            'og_recommended' => ['og:description', 'og:site_name', 'og:locale'],
            'og_optional' => ['og:updated_time', 'og:see_also', 'og:video', 'og:audio'],
            'image_min_width' => 600,
            'image_min_height' => 315,
            'image_recommended_ratio' => 1.91,
            'title_max_length' => 60,
            'description_max_length' => 160
        ],
        'twitter' => [
            'card_types' => ['summary', 'summary_large_image', 'app', 'player'],
            'required' => ['twitter:card', 'twitter:title'],
            'recommended' => ['twitter:description', 'twitter:image', 'twitter:site'],
            'optional' => ['twitter:creator', 'twitter:player', 'twitter:app:name'],
            'image_min_width' => 300,
            'image_min_height' => 157,
            'image_max_size' => 5242880, // 5MB
            'title_max_length' => 70,
            'description_max_length' => 200
        ],
        'linkedin' => [
            'og_required' => ['og:title', 'og:type', 'og:image', 'og:url'],
            'og_recommended' => ['og:description'],
            'image_min_width' => 1200,
            'image_min_height' => 627,
            'image_recommended_ratio' => 1.91,
            'title_max_length' => 70,
            'description_max_length' => 160
        ],
        'pinterest' => [
            'required' => ['og:title', 'og:type', 'og:image', 'og:url'],
            'recommended' => ['og:description', 'og:site_name'],
            'image_min_width' => 600,
            'image_min_height' => 900,
            'image_recommended_ratio' => 0.67, // 2:3 ratio
            'title_max_length' => 100,
            'description_max_length' => 500
        ],
        'whatsapp' => [
            'og_required' => ['og:title', 'og:type', 'og:image', 'og:url'],
            'og_recommended' => ['og:description'],
            'image_min_width' => 300,
            'image_min_height' => 200,
            'title_max_length' => 65,
            'description_max_length' => 160
        ]
    ];

    /**
     * Open Graph types with their specifications
     *
     * @since 1.0.0
     * @var array
     */
    private array $og_types = [
        'website' => ['og:title', 'og:type', 'og:image', 'og:url'],
        'article' => ['og:title', 'og:type', 'og:image', 'og:url', 'article:author', 'article:published_time'],
        'product' => ['og:title', 'og:type', 'og:image', 'og:url', 'product:price:amount', 'product:price:currency'],
        'video' => ['og:title', 'og:type', 'og:image', 'og:url', 'og:video', 'video:duration'],
        'music' => ['og:title', 'og:type', 'og:image', 'og:url', 'music:duration', 'music:album'],
        'book' => ['og:title', 'og:type', 'og:image', 'og:url', 'book:author', 'book:isbn'],
        'profile' => ['og:title', 'og:type', 'og:image', 'og:url', 'profile:first_name', 'profile:last_name']
    ];

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct('social_meta');
    }

    /**
     * Generate Open Graph tags for content
     *
     * @since 1.0.0
     *
     * @param array  $data     Content data for OG generation
     * @param string $context  Context type ('site', 'post', 'page', etc.)
     * @param string $platform Target platform ('facebook', 'linkedin', etc.)
     * @return array Generated Open Graph tags
     */
    public function generate_og_tags(array $data, string $context = 'site', string $platform = 'facebook'): array {
        $platform = strtolower($platform);
        if (!isset($this->supported_platforms[$platform])) {
            $platform = 'facebook'; // Default fallback
        }

        $platform_spec = $this->supported_platforms[$platform];
        $og_tags = [];

        // Determine OG type based on context
        $og_type = $this->determine_og_type($context, $data);
        $og_tags['og:type'] = $og_type;

        // Required OG tags
        // og:title must render the full resolved Open Graph title. The 60-char
        // cap in optimize_title_for_platform() is an SEO-title recommendation for
        // search results and does not apply to the og:title social tag, so use the
        // resolved title verbatim (falling back to the site name when empty).
        $og_tags['og:title'] = ($data['title'] ?? '') !== '' ? $data['title'] : get_bloginfo('name');
        $og_tags['og:url'] = $data['url'] ?? $this->get_current_url();
        
        // Image handling with optimization
        if (!empty($data['image'])) {
            $optimized_image = $this->optimize_image_for_platform($data['image'], $platform);
            $og_tags['og:image'] = $optimized_image['url'];
            // Emit og:image:secure_url for https images (parity with the basic
            // emitter), so crawlers that prefer the secure URL get it.
            if (!empty($optimized_image['url']) && strpos((string) $optimized_image['url'], 'https://') === 0) {
                $og_tags['og:image:secure_url'] = $optimized_image['url'];
            }
            if (!empty($optimized_image['width'])) {
                $og_tags['og:image:width'] = $optimized_image['width'];
            }
            if (!empty($optimized_image['height'])) {
                $og_tags['og:image:height'] = $optimized_image['height'];
            }
            if (!empty($optimized_image['type'])) {
                $og_tags['og:image:type'] = $optimized_image['type'];
            }
            if (!empty($optimized_image['alt'])) {
                $og_tags['og:image:alt'] = $optimized_image['alt'];
            }
        } else {
            // Fallback to site default image
            $default_image = $this->get_default_social_image();
            if ($default_image) {
                $og_tags['og:image'] = $default_image;
            }
        }

        // Recommended OG tags
        if (!empty($data['description'])) {
            $og_tags['og:description'] = $this->optimize_description_for_platform($data['description'], $platform);
        }

        $og_tags['og:site_name'] = $data['site_name'] ?? get_bloginfo('name');
        $og_tags['og:locale'] = $data['locale'] ?? $this->get_og_locale();

        // Context-specific OG tags
        $og_tags = $this->add_context_specific_og_tags($og_tags, $context, $data, $og_type);

        // Platform-specific optimizations
        $og_tags = $this->apply_platform_specific_optimizations($og_tags, $platform, $data);

        return $og_tags;
    }

    /**
     * Generate Twitter Card tags for content
     *
     * @since 1.0.0
     *
     * @param array  $data    Content data for Twitter Card generation
     * @param string $context Context type ('site', 'post', 'page', etc.)
     * @return array Generated Twitter Card tags
     */
    public function generate_twitter_tags(array $data, string $context = 'site'): array {
        $twitter_tags = [];
        
        // Determine card type based on content
        $card_type = $this->determine_twitter_card_type($data, $context);
        $twitter_tags['twitter:card'] = $card_type;

        // Required tags. Prefer a per-post Twitter-specific title, falling back
        // to the resolved og:title/SEO title. Render it in full — the length cap
        // in optimize_title_for_platform() is an SEO-title recommendation for
        // search results, not a rule for the twitter:title social tag.
        $twitter_title = ($data['twitter_title'] ?? '') !== '' ? $data['twitter_title'] : ($data['title'] ?? '');
        $twitter_tags['twitter:title'] = $twitter_title !== '' ? $twitter_title : get_bloginfo('name');

        // Recommended tags
        if (!empty($data['description'])) {
            $twitter_tags['twitter:description'] = $this->optimize_description_for_platform($data['description'], 'twitter');
        }

        // Image handling - prioritize Twitter-specific image
        $twitter_image = !empty($data['twitter_image']) ? $data['twitter_image'] : $data['image'];
        if (!empty($twitter_image)) {
            $optimized_image = $this->optimize_image_for_platform($twitter_image, 'twitter');
            $twitter_tags['twitter:image'] = $optimized_image['url'];
            if (!empty($optimized_image['alt'])) {
                $twitter_tags['twitter:image:alt'] = $optimized_image['alt'];
            }
        }

        // Site and creator information
        $twitter_site = $this->get_twitter_site_handle();
        if ($twitter_site) {
            $twitter_tags['twitter:site'] = $twitter_site;
        }

        $twitter_creator = $this->get_twitter_creator_handle();
        if ($twitter_creator) {
            $twitter_tags['twitter:creator'] = $twitter_creator;
        } elseif (!empty($data['author']['twitter'])) {
            // Fallback to author data if available
            $twitter_tags['twitter:creator'] = $data['author']['twitter'];
        }

        // Card-specific tags
        $twitter_tags = $this->add_twitter_card_specific_tags($twitter_tags, $card_type, $data, $context);

        return $twitter_tags;
    }

    /**
     * Optimize social image for platform requirements
     *
     * @since 1.0.0
     *
     * @param string $image_url Image URL to optimize
     * @param string $platform  Target platform
     * @return array Optimized image data
     */
    public function optimize_social_image(string $image_url, string $platform): array {
        return $this->optimize_image_for_platform($image_url, $platform);
    }

    /**
     * Generate social media preview data
     *
     * @since 1.0.0
     *
     * @param array  $data     Content data
     * @param string $platform Target platform
     * @return array Preview data for the platform
     */
    public function preview_social_post(array $data, string $platform): array {
        $preview = [
            'platform' => $platform,
            'valid' => false,
            'preview_url' => '',
            'title' => '',
            'description' => '',
            'image' => '',
            'warnings' => [],
            'suggestions' => []
        ];

        switch ($platform) {
            case 'facebook':
            case 'linkedin':
                $og_tags = $this->generate_og_tags($data, 'post', $platform);
                $preview = $this->generate_og_preview($og_tags, $platform, $preview);
                break;
            case 'twitter':
                $twitter_tags = $this->generate_twitter_tags($data, 'post');
                $preview = $this->generate_twitter_preview($twitter_tags, $preview);
                break;
            case 'pinterest':
                $og_tags = $this->generate_og_tags($data, 'post', $platform);
                $preview = $this->generate_pinterest_preview($og_tags, $preview);
                break;
            default:
                $preview['warnings'][] = 'Unsupported platform for preview generation';
        }

        return $preview;
    }

    /**
     * Validate SEO settings (implements interface)
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @param string $context Optional context for focused validation
     * @return array Validation results
     */
    public function validate_settings(array $settings, string $context = 'all'): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        // Add detailed field validation breakdown
        $field_details = $this->get_detailed_field_validation($settings, $context);
        $validation['field_details'] = $field_details;

        // Convert field details to validation format
        foreach ($field_details as $field) {
            switch ($field['status']) {
                case 'error':
                    $validation['errors'][] = $field['label'];
                    $validation['valid'] = false;
                    $validation['score'] -= 15;
                    break;
                case 'warning':
                    $validation['warnings'][] = $field['label'];
                    $validation['score'] -= 8;
                    break;
                case 'suggestion':
                    $validation['suggestions'][] = $field['label'];
                    $validation['score'] -= 3;
                    break;
            }
        }

        // Ensure score doesn't go below 0
        $validation['score'] = max(0, $validation['score']);


        // Validate general enabled setting (Social Media tab format)
        if (isset($settings['enabled'])) {
            // Convert string booleans to actual booleans
            if (is_string($settings['enabled'])) {
                $settings['enabled'] = filter_var($settings['enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            if (!is_bool($settings['enabled'])) {
                $validation['errors'][] = 'enabled must be a boolean value';
                $validation['valid'] = false;
            }
        }

        // Validate Open Graph settings (Social Media tab format)
        if (isset($settings['enable_open_graph'])) {
            // Convert string booleans to actual booleans
            if (is_string($settings['enable_open_graph'])) {
                $settings['enable_open_graph'] = filter_var($settings['enable_open_graph'], FILTER_VALIDATE_BOOLEAN);
            }
            if (!is_bool($settings['enable_open_graph'])) {
                $validation['errors'][] = 'enable_open_graph must be a boolean value';
                $validation['valid'] = false;
            }
        }

        // Validate Twitter Cards settings (Social Media tab format)
        if (isset($settings['enable_twitter_cards'])) {
            // Convert string booleans to actual booleans
            if (is_string($settings['enable_twitter_cards'])) {
                $settings['enable_twitter_cards'] = filter_var($settings['enable_twitter_cards'], FILTER_VALIDATE_BOOLEAN);
            }
            if (!is_bool($settings['enable_twitter_cards'])) {
                $validation['errors'][] = 'enable_twitter_cards must be a boolean value';
                $validation['valid'] = false;
            }
        }

        // Validate Twitter settings (only when relevant)
        if ($context === 'all' || $context === 'twitter' || $context === 'twitter-cards') {
            // Validate Twitter username format
            if (isset($settings['twitter_username']) && !empty($settings['twitter_username'])) {
                $username = trim($settings['twitter_username']);

                // Remove @ if present (we store without @, add @ in output)
                $clean_username = ltrim($username, '@');

                if (empty($clean_username)) {
                    $validation['errors'][] = 'twitter_username cannot be empty';
                    $validation['valid'] = false;
                } elseif (strlen($clean_username) > 15) {
                    $validation['errors'][] = 'twitter_username must be 15 characters or less';
                    $validation['valid'] = false;
                } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $clean_username)) {
                    $validation['errors'][] = 'twitter_username can only contain letters, numbers, and underscores';
                    $validation['valid'] = false;
                }

                // Update the setting to store without @ for consistency
                $settings['twitter_username'] = $clean_username;
            }

            // Validate Twitter creator format
            if (isset($settings['twitter_creator']) && !empty($settings['twitter_creator'])) {
                $creator = trim($settings['twitter_creator']);

                // Remove @ if present (we store without @, add @ in output)
                $clean_creator = ltrim($creator, '@');

                if (empty($clean_creator)) {
                    $validation['errors'][] = 'twitter_creator cannot be empty';
                    $validation['valid'] = false;
                } elseif (strlen($clean_creator) > 15) {
                    $validation['errors'][] = 'twitter_creator must be 15 characters or less';
                    $validation['valid'] = false;
                } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $clean_creator)) {
                    $validation['errors'][] = 'twitter_creator can only contain letters, numbers, and underscores';
                    $validation['valid'] = false;
                }

                // Update the setting to store without @ for consistency
                $settings['twitter_creator'] = $clean_creator;
            }

            // Validate Twitter card type
            if (isset($settings['twitter_card_type'])) {
                $valid_types = ['summary', 'summary_large_image', 'app', 'player'];
                if (!in_array($settings['twitter_card_type'], $valid_types, true)) {
                    $validation['errors'][] = 'twitter_card_type must be one of: ' . implode(', ', $valid_types);
                    $validation['valid'] = false;
                }
            }
        }

        // Validate OG type
        if (isset($settings['og_type'])) {
            $valid_types = ['website', 'article', 'book', 'profile', 'music.song', 'music.album', 'video.movie', 'video.episode'];
            if (!in_array($settings['og_type'], $valid_types, true)) {
                $validation['warnings'][] = 'og_type should be one of the standard Open Graph types for best compatibility';
            }
        }

        // Validate image dimensions
        if (isset($settings['og_image_width']) && (!is_numeric($settings['og_image_width']) || $settings['og_image_width'] < 200)) {
            $validation['warnings'][] = 'og_image_width should be at least 200 pixels for optimal social sharing';
        }

        if (isset($settings['og_image_height']) && (!is_numeric($settings['og_image_height']) || $settings['og_image_height'] < 200)) {
            $validation['warnings'][] = 'og_image_height should be at least 200 pixels for optimal social sharing';
        }

        // Validate description length
        if (isset($settings['max_description_length']) && (!is_numeric($settings['max_description_length']) || $settings['max_description_length'] < 50 || $settings['max_description_length'] > 300)) {
            $validation['warnings'][] = 'max_description_length should be between 50 and 300 characters';
        }

        // Legacy validation for backward compatibility (only when legacy fields are actually present)
        if (isset($settings['og_enabled'])) {
            // Convert string booleans to actual booleans
            if (is_string($settings['og_enabled'])) {
                $settings['og_enabled'] = filter_var($settings['og_enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            if (!is_bool($settings['og_enabled'])) {
                $validation['errors'][] = 'og_enabled must be a boolean value';
                $validation['valid'] = false;
            }
        }

        if (isset($settings['twitter_enabled'])) {
            // Convert string booleans to actual booleans
            if (is_string($settings['twitter_enabled'])) {
                $settings['twitter_enabled'] = filter_var($settings['twitter_enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            if (!is_bool($settings['twitter_enabled'])) {
                $validation['errors'][] = 'twitter_enabled must be a boolean value';
                $validation['valid'] = false;
            }
        }

        // Validate Twitter card type
        if (isset($settings['twitter_card_type'])) {
            $valid_card_types = $this->supported_platforms['twitter']['card_types'];
            if (!in_array($settings['twitter_card_type'], $valid_card_types, true)) {
                $validation['errors'][] = 'Invalid Twitter card type. Must be one of: ' . implode(', ', $valid_card_types);
                $validation['valid'] = false;
            }
        }

        // Validate default image
        if (isset($settings['default_image']) && !empty($settings['default_image'])) {
            if (!filter_var($settings['default_image'], FILTER_VALIDATE_URL)) {
                $validation['errors'][] = 'default_image must be a valid URL';
                $validation['valid'] = false;
            } else {
                // Check image dimensions and format
                $image_validation = $this->validate_social_image($settings['default_image']);
                if (!$image_validation['valid']) {
                    $validation['warnings'] = array_merge($validation['warnings'], $image_validation['warnings']);
                }
            }
        }

        // Validate Twitter site handle
        if (isset($settings['twitter_site']) && !empty($settings['twitter_site'])) {
            if (!preg_match('/^@[a-zA-Z0-9_]{1,15}$/', $settings['twitter_site'])) {
                $validation['errors'][] = 'twitter_site must be a valid Twitter handle (e.g., @username)';
                $validation['valid'] = false;
            }
        }

        // Validate custom OG tags
        if (isset($settings['custom_og_tags']) && !empty($settings['custom_og_tags'])) {
            if (!is_array($settings['custom_og_tags'])) {
                $validation['errors'][] = 'custom_og_tags must be an array';
                $validation['valid'] = false;
            } else {
                foreach ($settings['custom_og_tags'] as $property => $content) {
                    if (!is_string($property) || !is_string($content)) {
                        $validation['errors'][] = 'Custom OG tags must have string property names and content';
                        $validation['valid'] = false;
                        break;
                    }
                }
            }
        }

        // Validate platform verification codes / IDs (Instagram, TikTok, YouTube,
        // WhatsApp, Facebook App ID, Pinterest) against the shared format rules.
        // Single source of truth — also enforced on the Social Platforms REST save
        // path via self::validate_platform_field(). See get_platform_field_validation_rules().
        foreach (self::get_platform_field_validation_rules() as $field_key => $rule) {
            if (isset($settings[$field_key]) && $settings[$field_key] !== '') {
                $error = self::validate_platform_field($field_key, $settings[$field_key]);
                if ($error !== null) {
                    $validation['errors'][] = $error;
                    $validation['valid'] = false;
                }
            }
        }

        return $validation;
    }

    /**
     * Format-validation rules for social platform verification codes / IDs.
     *
     * Single source of truth for the per-field format constraints, shared by
     * validate_settings() and the Social Platforms REST endpoint
     * (ThinkRank\API\Social_Platforms_Endpoint) so a value that is accepted on
     * one path is accepted on the other. These mirror the `pattern` entries in
     * get_settings_schema(); sanitization strips unsafe characters but does not
     * enforce shape, so these rules back it with real validation.
     *
     * @since 1.14.0
     *
     * @return array<string, array{pattern: string, message: string}> Map of field key => rule.
     */
    public static function get_platform_field_validation_rules(): array {
        return [
            'facebook_app_id' => [
                'pattern' => '/^[0-9]+$/',
                'message' => 'Facebook App ID must contain digits only.',
            ],
            'pinterest_site_verification' => [
                'pattern' => '/^[a-f0-9]{32}$/',
                'message' => 'Pinterest site verification must be a 32-character hexadecimal string.',
            ],
            'instagram_verification' => [
                'pattern' => '/^[a-zA-Z0-9_-]{20,}$/',
                'message' => 'Instagram verification must be at least 20 characters (letters, numbers, underscore, dash).',
            ],
            'tiktok_verification' => [
                'pattern' => '/^[a-zA-Z0-9_-]{20,}$/',
                'message' => 'TikTok verification must be at least 20 characters (letters, numbers, underscore, dash).',
            ],
            'youtube_channel_id' => [
                'pattern' => '/^UC[a-zA-Z0-9_-]{22}$/',
                'message' => 'YouTube channel ID must start with "UC" followed by 22 characters (letters, numbers, underscore, dash).',
            ],
            'whatsapp_business_id' => [
                'pattern' => '/^[0-9]{10,15}$/',
                'message' => 'WhatsApp Business ID must be 10-15 digits.',
            ],
        ];
    }

    /**
     * Validate a single social platform field value against its shared format rule.
     *
     * Returns null for fields with no format rule (e.g. facebook_admins) and for
     * empty values, so callers can validate an arbitrary settings map and only
     * act on genuine format violations.
     *
     * @since 1.14.0
     *
     * @param string $key   Field key.
     * @param mixed  $value Field value.
     * @return string|null  Error message when the value violates the format, null otherwise.
     */
    public static function validate_platform_field(string $key, $value): ?string {
        $rules = self::get_platform_field_validation_rules();

        if (!isset($rules[$key]) || $value === '' || $value === null) {
            return null;
        }

        if (!preg_match($rules[$key]['pattern'], (string) $value)) {
            return $rules[$key]['message'];
        }

        return null;
    }

    /**
     * Get detailed field validation breakdown
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @param string $context Context for specific validation
     * @return array Detailed field validation results
     */
    private function get_detailed_field_validation(array $settings, string $context = 'all'): array {
        $field_details = [];

        // Return tab-specific validation based on context
        switch ($context) {
            case 'open-graph':
                return $this->validate_open_graph_fields($settings);
            case 'twitter-cards':
                return $this->validate_twitter_fields($settings);
            default:
                // For 'all' or unknown context, return only Social Media fields (Open Graph + Twitter)
                $field_details = array_merge($field_details, $this->validate_open_graph_fields($settings));
                $field_details = array_merge($field_details, $this->validate_twitter_fields($settings));
                return $field_details;
        }
    }

    /**
     * Validate Open Graph fields
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Open Graph field validation results
     */
    private function validate_open_graph_fields(array $settings): array {
        $field_details = [];

        // Open Graph Enabled
        if (!empty($settings['enable_open_graph'])) {
            $field_details[] = [
                'field' => 'enable_open_graph',
                'label' => 'Open Graph is enabled for social media sharing.',
                'status' => 'valid',
                'icon' => '✓'
            ];

            // Site Name validation
            if (!empty($settings['og_site_name'])) {
                if (strlen($settings['og_site_name']) <= 60) {
                    $field_details[] = [
                        'field' => 'og_site_name',
                        'label' => 'Site name is properly configured for Open Graph.',
                        'status' => 'valid',
                        'icon' => '✓'
                    ];
                } else {
                    $field_details[] = [
                        'field' => 'og_site_name',
                        'label' => 'Site name is longer than 60 characters, may be truncated.',
                        'status' => 'warning',
                        'icon' => '⚠'
                    ];
                }
            } else {
                $field_details[] = [
                    'field' => 'og_site_name',
                    'label' => 'Site name is required for Open Graph.',
                    'status' => 'error',
                    'icon' => '✗'
                ];
            }

            // Description validation
            if (!empty($settings['og_description'])) {
                $length = strlen($settings['og_description']);
                if ($length >= 120 && $length <= 160) {
                    $field_details[] = [
                        'field' => 'og_description',
                        'label' => 'Description is properly configured for social sharing.',
                        'status' => 'valid',
                        'icon' => '✓'
                    ];
                } else {
                    $field_details[] = [
                        'field' => 'og_description',
                        'label' => 'Description length could be optimized (120-160 characters recommended).',
                        'status' => 'warning',
                        'icon' => '⚠'
                    ];
                }
            } else {
                $field_details[] = [
                    'field' => 'og_description',
                    'label' => 'Description is recommended for better social sharing.',
                    'status' => 'warning',
                    'icon' => '⚠'
                ];
            }

            // Content Type validation
            if (!empty($settings['og_type'])) {
                $field_details[] = [
                    'field' => 'og_type',
                    'label' => 'Content type is configured for Open Graph.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'og_type',
                    'label' => 'Content type should be specified.',
                    'status' => 'suggestion',
                    'icon' => '⚠'
                ];
            }

            // Default Image validation
            if (!empty($settings['default_og_image'])) {
                if (filter_var($settings['default_og_image'], FILTER_VALIDATE_URL)) {
                    $field_details[] = [
                        'field' => 'default_og_image',
                        'label' => 'Default Open Graph image is configured.',
                        'status' => 'valid',
                        'icon' => '✓'
                    ];
                } else {
                    $field_details[] = [
                        'field' => 'default_og_image',
                        'label' => 'Default Open Graph image URL appears invalid.',
                        'status' => 'warning',
                        'icon' => '⚠'
                    ];
                }
            } else {
                $field_details[] = [
                    'field' => 'default_og_image',
                    'label' => 'Default image is recommended for social sharing.',
                    'status' => 'suggestion',
                    'icon' => '⚠'
                ];
            }

        } else {
            $field_details[] = [
                'field' => 'enable_open_graph',
                'label' => 'Open Graph is disabled. Enable for better social media sharing.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        return $field_details;
    }

    /**
     * Validate Twitter fields
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Twitter field validation results
     */
    private function validate_twitter_fields(array $settings): array {
        $field_details = [];

        // Twitter Cards Enabled
        if (!empty($settings['enable_twitter_cards'])) {
            $field_details[] = [
                'field' => 'enable_twitter_cards',
                'label' => 'Twitter Cards are enabled for Twitter sharing.',
                'status' => 'valid',
                'icon' => '✓'
            ];

            // Twitter Username validation
            if (!empty($settings['twitter_username'])) {
                $username = trim($settings['twitter_username']);
                $clean_username = ltrim($username, '@');

                if (strlen($clean_username) <= 15 && preg_match('/^[A-Za-z0-9_]+$/', $clean_username)) {
                    $field_details[] = [
                        'field' => 'twitter_username',
                        'label' => 'Twitter username is properly formatted.',
                        'status' => 'valid',
                        'icon' => '✓'
                    ];
                } else {
                    $field_details[] = [
                        'field' => 'twitter_username',
                        'label' => 'Twitter username format is invalid (max 15 chars, letters/numbers/underscore only).',
                        'status' => 'error',
                        'icon' => '✗'
                    ];
                }
            } else {
                $field_details[] = [
                    'field' => 'twitter_username',
                    'label' => 'Twitter username recommended for better attribution.',
                    'status' => 'suggestion',
                    'icon' => '⚠'
                ];
            }

            // Twitter Creator validation
            if (!empty($settings['twitter_creator'])) {
                $creator = trim($settings['twitter_creator']);
                $clean_creator = ltrim($creator, '@');

                if (strlen($clean_creator) <= 15 && preg_match('/^[A-Za-z0-9_]+$/', $clean_creator)) {
                    $field_details[] = [
                        'field' => 'twitter_creator',
                        'label' => 'Twitter creator is properly formatted.',
                        'status' => 'valid',
                        'icon' => '✓'
                    ];
                } else {
                    $field_details[] = [
                        'field' => 'twitter_creator',
                        'label' => 'Twitter creator format is invalid (max 15 chars, letters/numbers/underscore only).',
                        'status' => 'error',
                        'icon' => '✗'
                    ];
                }
            } else {
                $field_details[] = [
                    'field' => 'twitter_creator',
                    'label' => 'Twitter creator recommended for content attribution.',
                    'status' => 'suggestion',
                    'icon' => '⚠'
                ];
            }

            // Card Type validation
            $valid_card_types = ['summary', 'summary_large_image', 'app', 'player'];
            if (!empty($settings['twitter_card_type']) && in_array($settings['twitter_card_type'], $valid_card_types)) {
                $field_details[] = [
                    'field' => 'twitter_card_type',
                    'label' => 'Twitter Card type is properly configured.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'twitter_card_type',
                    'label' => 'Valid Twitter Card type is required.',
                    'status' => 'error',
                    'icon' => '✗'
                ];
            }

            // Default Twitter Image validation
            if (!empty($settings['default_twitter_image'])) {
                if (filter_var($settings['default_twitter_image'], FILTER_VALIDATE_URL)) {
                    $field_details[] = [
                        'field' => 'default_twitter_image',
                        'label' => 'Default Twitter Card image is configured.',
                        'status' => 'valid',
                        'icon' => '✓'
                    ];
                } else {
                    $field_details[] = [
                        'field' => 'default_twitter_image',
                        'label' => 'Default Twitter Card image URL appears invalid.',
                        'status' => 'warning',
                        'icon' => '⚠'
                    ];
                }
            } else {
                $field_details[] = [
                    'field' => 'default_twitter_image',
                    'label' => 'Default image recommended for Twitter sharing.',
                    'status' => 'suggestion',
                    'icon' => '⚠'
                ];
            }

        } else {
            $field_details[] = [
                'field' => 'enable_twitter_cards',
                'label' => 'Twitter Cards are disabled. Enable for better Twitter sharing.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        return $field_details;
    }


    /**
     * Get output data for frontend rendering (implements interface)
     *
     * @since 1.0.0
     *
     * @param string      $context_type   The context type
     * @param int|null    $context_id     Optional. Context ID
     * @param string|null $fallback_title       Optional. Effective SEO title to
     *                                           use when no per-post Open Graph
     *                                           title override is set, so
     *                                           rendered output mirrors the
     *                                           document title and the Social
     *                                           metabox preview.
     * @param string|null $fallback_description Optional. Effective meta
     *                                           description, used as the
     *                                           Open Graph description fallback
     *                                           for the same parity reason.
     * @return array Output data ready for frontend rendering
     */
    public function get_output_data(string $context_type, ?int $context_id, ?string $fallback_title = null, ?string $fallback_description = null): array {
        // Memoize per request: the OG, Twitter and platform wp_head callbacks
        // each call this with the same arguments, so the extraction work +
        // Site_Identity_Manager instantiation would otherwise run three times.
        $cache_key = $context_type . ':' . ($context_id ?? 0) . ':' . md5((string) $fallback_title . '|' . (string) $fallback_description);
        if (isset($this->output_data_cache[$cache_key])) {
            return $this->output_data_cache[$cache_key];
        }

        $settings = $this->get_settings($context_type, $context_id);
        $output = [
            'og_tags' => [],
            'twitter_tags' => [],
            'meta_tags' => [],
            'platform_tags' => [],
            // Per-feature flags so each emitter can honor its own toggle. The
            // aggregate `enabled` (true if either is on) is kept for callers
            // that only care whether social output ran at all.
            'og_enabled' => false,
            'twitter_enabled' => false,
            'enabled' => false,
        ];

        // Check if individual social features are enabled
        $og_enabled = !empty($settings['enable_open_graph'] ?? $settings['og_enabled'] ?? false);
        $twitter_enabled = !empty($settings['enable_twitter_cards'] ?? $settings['twitter_enabled'] ?? false);
        $output['og_enabled'] = $og_enabled;
        $output['twitter_enabled'] = $twitter_enabled;

        if (!$og_enabled && !$twitter_enabled) {
            $this->output_data_cache[$cache_key] = $output;
            return $output;
        }

        $output['enabled'] = true;

        // Extract content data
        $content_data = $this->extract_social_content_data($context_type, $context_id, $settings, $fallback_title, $fallback_description);

        // Generate Open Graph tags if enabled
        if ($og_enabled) {
            $output['og_tags'] = $this->generate_og_tags($content_data, $context_type);

            // Add custom OG tags
            if (!empty($settings['custom_og_tags'])) {
                $output['og_tags'] = array_merge($output['og_tags'], $settings['custom_og_tags']);
            }
        }

        // Generate Twitter Card tags if enabled
        if ($twitter_enabled) {
            $output['twitter_tags'] = $this->generate_twitter_tags($content_data, $context_type);
        }

        // Generate platform-specific meta tags
        $output['platform_tags'] = $this->generate_platform_meta_tags($settings);

        // Convert to meta tag format for HTML output
        $output['meta_tags'] = $this->convert_to_meta_tags($output['og_tags'], $output['twitter_tags'], $output['platform_tags']);

        $this->output_data_cache[$cache_key] = $output;
        return $output;
    }

    /**
     * Request-scoped memoization of get_output_data() keyed by context + title/desc.
     *
     * @var array<string, array>
     */
    private array $output_data_cache = [];

    /**
     * Site-wide default image keys inherited by every other context.
     *
     * @since 1.24.1
     * @var string[]
     */
    private const INHERITED_SITE_KEYS = [
        'default_og_image',
        'default_twitter_image',
        'default_image',
    ];

    /**
     * Get settings for a context, inheriting the site-wide default images
     *
     * Two corrections over the generic lookup:
     *
     * 1. Site-wide settings are always stored with context_id 0, but the
     *    frontend maps a homepage request to the `site` context while still
     *    passing the queried object ID (non-zero on a static front page). That
     *    looked up `site`/<page ID>, matched no row and silently fell back to
     *    the schema defaults, so a configured default OG image never rendered
     *    on a static front page. Normalize the ID away for `site`.
     * 2. `default_og_image` / `default_twitter_image` only exist in the site
     *    context, so post/page and archive contexts had nothing to fall back to
     *    when a post had no featured image — og:image dropped to the site logo
     *    or vanished entirely. Inherit those keys when the context has no value
     *    of its own.
     *
     * @since 1.24.1
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return array Settings with site-wide image defaults applied
     */
    public function get_settings(string $context_type, ?int $context_id = null): array {
        if ($context_type === 'site') {
            $context_id = null;
        }

        $settings = parent::get_settings($context_type, $context_id);

        if ($context_type === 'site') {
            return $settings;
        }

        $site_settings = parent::get_settings('site', null);
        foreach (self::INHERITED_SITE_KEYS as $key) {
            if (empty($settings[$key]) && !empty($site_settings[$key])) {
                $settings[$key] = $site_settings[$key];
            }
        }

        return $settings;
    }

    /**
     * Get default settings for a context type (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get defaults for
     * @return array Default settings array
     */
    public function get_default_settings(string $context_type): array {
        // Get WordPress site defaults
        $site_name = get_bloginfo('name');
        $site_description = get_bloginfo('description');

        $defaults = [
            // Open Graph settings
            'enable_open_graph' => true,
            'og_site_name' => $site_name,
            'og_description' => $site_description,
            'og_type' => 'website',
            'og_locale' => 'en_US',
            'default_og_image' => '',
            'og_image_width' => 1200,
            'og_image_height' => 630,

            // Twitter Cards settings
            'enable_twitter_cards' => true,
            'twitter_username' => '',
            'twitter_creator' => '',
            'twitter_card_type' => 'summary_large_image',
            'default_twitter_image' => '',

            // Facebook settings
            'facebook_app_id' => '',
            'facebook_admins' => '',

            // LinkedIn settings
            'enable_linkedin' => false,

            // Pinterest settings
            'enable_pinterest' => false,
            'pinterest_site_verification' => '',

            // Instagram settings
            'enable_instagram' => false,
            'instagram_verification' => '',

            // TikTok settings
            'enable_tiktok' => false,
            'tiktok_verification' => '',

            // YouTube settings
            'enable_youtube' => false,
            'youtube_channel_id' => '',

            // WhatsApp Business settings
            'enable_whatsapp' => false,
            'whatsapp_business_id' => '',

            // Advanced settings
            'auto_generate_descriptions' => true,
            'fallback_to_excerpt' => true,
            'strip_html_tags' => true,
            'max_description_length' => 160,

            // Legacy format for backward compatibility (only when needed)
            'custom_og_tags' => [],
            'image_optimization' => true,
            'auto_generate' => true
        ];

        // Context-specific defaults
        switch ($context_type) {
            case 'site':
                $defaults['og_type'] = 'website';
                break;
            case 'post':
                $defaults['og_type'] = 'article';
                break;
            case 'page':
                $defaults['og_type'] = 'website';
                break;
            case 'product':
                $defaults['og_type'] = 'product';
                break;
        }

        return $defaults;
    }

    /**
     * Get settings schema definition (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get schema for
     * @return array Settings schema definition
     */
    public function get_settings_schema(string $context_type): array {
        return [
            'enable_open_graph' => [
                'type' => 'boolean',
                'title' => 'Enable Open Graph',
                'description' => 'Generate Open Graph meta tags for social media sharing',
                'default' => true
            ],
            'og_site_name' => [
                'type' => 'string',
                'title' => 'Site Name',
                'description' => 'The name of your website for Open Graph',
                'maxLength' => 60,
                'default' => get_bloginfo('name')
            ],
            'og_description' => [
                'type' => 'string',
                'title' => 'Site Description',
                'description' => 'Default description for Open Graph tags',
                'maxLength' => 160,
                'default' => get_bloginfo('description')
            ],
            'og_type' => [
                'type' => 'string',
                'title' => 'Open Graph Type',
                'description' => 'The type of content for Open Graph',
                'enum' => ['website', 'article', 'book', 'profile'],
                'default' => 'website'
            ],
            'og_locale' => [
                'type' => 'string',
                'title' => 'Locale',
                'description' => 'The locale for Open Graph tags',
                'default' => 'en_US'
            ],
            'default_og_image' => [
                'type' => 'string',
                'title' => 'Default Open Graph Image',
                'description' => 'Default image URL for Open Graph tags',
                'format' => 'uri',
                'default' => ''
            ],
            'og_image_width' => [
                'type' => 'integer',
                'title' => 'Image Width',
                'description' => 'Default width for Open Graph images',
                'minimum' => 200,
                'default' => 1200
            ],
            'og_image_height' => [
                'type' => 'integer',
                'title' => 'Image Height',
                'description' => 'Default height for Open Graph images',
                'minimum' => 200,
                'default' => 630
            ],
            'enable_twitter_cards' => [
                'type' => 'boolean',
                'title' => 'Enable Twitter Cards',
                'description' => 'Generate Twitter Card meta tags for Twitter sharing',
                'default' => true
            ],
            'twitter_username' => [
                'type' => 'string',
                'title' => 'Twitter Username',
                'description' => 'Twitter username for the site (without @, e.g., username)',
                'pattern' => '^[a-zA-Z0-9_]{1,15}$',
                'default' => ''
            ],
            'twitter_creator' => [
                'type' => 'string',
                'title' => 'Twitter Creator',
                'description' => 'Content creator Twitter username (without @, e.g., creator_username)',
                'pattern' => '^[a-zA-Z0-9_]{1,15}$',
                'default' => ''
            ],
            'twitter_card_type' => [
                'type' => 'string',
                'title' => 'Twitter Card Type',
                'description' => 'The type of Twitter Card to generate',
                'enum' => $this->supported_platforms['twitter']['card_types'],
                'default' => 'summary_large_image'
            ],
            'og_type' => [
                'type' => 'string',
                'title' => 'Open Graph Type',
                'description' => 'The Open Graph type for this content',
                'enum' => array_keys($this->og_types),
                'default' => $this->get_default_settings($context_type)['og_type']
            ],
            'default_image' => [
                'type' => 'string',
                'title' => 'Default Social Image',
                'description' => 'Default image URL for social media sharing',
                'format' => 'uri',
                'default' => ''
            ],
            'twitter_site' => [
                'type' => 'string',
                'title' => 'Twitter Site Handle',
                'description' => 'Twitter handle for the site (e.g., @username)',
                'pattern' => '^@[a-zA-Z0-9_]{1,15}$',
                'default' => ''
            ],
            'default_twitter_image' => [
                'type' => 'string',
                'title' => 'Default Twitter Image',
                'description' => 'Default image URL for Twitter Cards',
                'format' => 'uri',
                'default' => ''
            ],
            'facebook_app_id' => [
                'type' => 'string',
                'title' => 'Facebook App ID',
                'description' => 'Facebook App ID for analytics and insights',
                'pattern' => '^[0-9]+$',
                'default' => ''
            ],
            'facebook_admins' => [
                'type' => 'string',
                'title' => 'Facebook Admins',
                'description' => 'Comma-separated list of Facebook admin user IDs',
                'default' => ''
            ],
            'enable_linkedin' => [
                'type' => 'boolean',
                'title' => 'Enable LinkedIn',
                'description' => 'Enable LinkedIn-specific optimizations',
                'default' => false
            ],
            'enable_pinterest' => [
                'type' => 'boolean',
                'title' => 'Enable Pinterest',
                'description' => 'Enable Pinterest-specific optimizations',
                'default' => false
            ],
            'pinterest_site_verification' => [
                'type' => 'string',
                'title' => 'Pinterest Site Verification',
                'description' => 'Pinterest site verification meta tag content',
                'pattern' => '^[a-f0-9]{32}$',
                'default' => ''
            ],
            'enable_instagram' => [
                'type' => 'boolean',
                'title' => 'Enable Instagram',
                'description' => 'Enable Instagram-specific optimizations',
                'default' => false
            ],
            'instagram_verification' => [
                'type' => 'string',
                'title' => 'Instagram Site Verification',
                'description' => 'Instagram Business account verification code',
                'pattern' => '^[a-zA-Z0-9_-]{20,}$',
                'default' => ''
            ],
            'enable_tiktok' => [
                'type' => 'boolean',
                'title' => 'Enable TikTok',
                'description' => 'Enable TikTok-specific optimizations',
                'default' => false
            ],
            'tiktok_verification' => [
                'type' => 'string',
                'title' => 'TikTok Site Verification',
                'description' => 'TikTok for Business verification code',
                'pattern' => '^[a-zA-Z0-9_-]{20,}$',
                'default' => ''
            ],
            'enable_youtube' => [
                'type' => 'boolean',
                'title' => 'Enable YouTube',
                'description' => 'Enable YouTube-specific optimizations',
                'default' => false
            ],
            'youtube_channel_id' => [
                'type' => 'string',
                'title' => 'YouTube Channel ID',
                'description' => 'YouTube channel ID for content attribution',
                'pattern' => '^UC[a-zA-Z0-9_-]{22}$',
                'default' => ''
            ],
            'enable_whatsapp' => [
                'type' => 'boolean',
                'title' => 'Enable WhatsApp Business',
                'description' => 'Enable WhatsApp Business optimizations',
                'default' => false
            ],
            'whatsapp_business_id' => [
                'type' => 'string',
                'title' => 'WhatsApp Business ID',
                'description' => 'WhatsApp Business account ID',
                'pattern' => '^[0-9]{10,15}$',
                'default' => ''
            ],
            'auto_generate_descriptions' => [
                'type' => 'boolean',
                'title' => 'Auto-generate Descriptions',
                'description' => 'Automatically generate descriptions from content',
                'default' => true
            ],
            'fallback_to_excerpt' => [
                'type' => 'boolean',
                'title' => 'Fallback to Excerpt',
                'description' => 'Use post excerpt as fallback for descriptions',
                'default' => true
            ],
            'strip_html_tags' => [
                'type' => 'boolean',
                'title' => 'Strip HTML Tags',
                'description' => 'Remove HTML tags from generated descriptions',
                'default' => true
            ],
            'max_description_length' => [
                'type' => 'integer',
                'title' => 'Max Description Length',
                'description' => 'Maximum length for generated descriptions',
                'minimum' => 50,
                'maximum' => 300,
                'default' => 160
            ],
            'custom_og_tags' => [
                'type' => 'object',
                'title' => 'Custom Open Graph Tags',
                'description' => 'Additional custom Open Graph meta tags',
                'default' => []
            ],
            'image_optimization' => [
                'type' => 'boolean',
                'title' => 'Enable Image Optimization',
                'description' => 'Optimize images for social media platforms',
                'default' => true
            ],
            'auto_generate' => [
                'type' => 'boolean',
                'title' => 'Auto-generate Tags',
                'description' => 'Automatically generate social meta tags from content',
                'default' => true
            ]
        ];
    }

    /**
     * Extract content data for social meta generation
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @param array    $settings     Social meta settings
     * @return array Extracted content data
     */
    private function extract_social_content_data(string $context_type, ?int $context_id, array $settings, ?string $fallback_title = null, ?string $fallback_description = null): array {
        $data = [
            'title' => '',
            'description' => '',
            'url' => '',
            'image' => '',
            'twitter_title' => '', // Separate field for a Twitter-specific title
            'twitter_image' => '', // Separate field for Twitter-specific images
            'type' => 'website',
            'author' => [],
            'published_time' => '',
            'modified_time' => '',
            'site_name' => $settings['og_site_name'] ?? get_bloginfo('name')
        ];

        if ($context_type === 'site') {
            // Site-wide data with Social Media tab settings priority.
            //
            // og:title priority: an explicitly-configured OG Site Name wins;
            // otherwise mirror the effective SEO <title> (what search shows),
            // then the blogname. og_site_name is always present because it is
            // merged from a default equal to the blogname, so a value matching
            // the blogname is treated as "not explicitly overridden" and we fall
            // through to the passed effective SEO title. (og:site_name itself is
            // set separately from og_site_name and is unaffected.)
            $blogname     = get_bloginfo('name');
            $og_site_name = $settings['og_site_name'] ?? '';
            if ($og_site_name !== '' && $og_site_name !== $blogname) {
                $data['title'] = $og_site_name;
            } elseif ($fallback_title !== null && $fallback_title !== '') {
                $data['title'] = $fallback_title;
            } else {
                $data['title'] = $blogname;
            }
            $data['description'] = $settings['og_description'] ?? get_bloginfo('description');
            // Use home_url('/') so og:url matches the homepage canonical
            // (class-seo-manager.php) and the WebSite schema, which both include
            // the trailing slash. A bare home_url() would key a different URL in
            // social caches than the canonical.
            $data['url'] = home_url('/');
            $data['type'] = $settings['og_type'] ?? 'website';
            $data['locale'] = $settings['og_locale'] ?? null;

            // Set Open Graph image with proper fallback
            $data['image'] = $this->get_og_image_for_context($settings, null);

            // Set Twitter image with proper fallback
            $data['twitter_image'] = $this->get_twitter_image_for_context($settings, null);
        } elseif ($context_id && in_array($context_type, ['post', 'page', 'product'], true)) {
            // Post-specific data
            $post = get_post($context_id);
            if ($post) {
                // Per-post Open Graph overrides from the metabox Social tab take
                // precedence over the derived title/description/image. These read
                // the same meta keys the metabox save handler and the import
                // migrator write to, so manual edits and migrated data flow
                // through one path. Title/description may hold variable tags
                // (e.g. %title%), resolved here to match output_basic_og_tags().
                $og_title_override = \ThinkRank\SEO\Pattern_Resolver::resolve_value(
                    (string) get_post_meta($post->ID, '_thinkrank_og_title', true),
                    $post->ID
                );
                $og_description_override = \ThinkRank\SEO\Pattern_Resolver::resolve_value(
                    (string) get_post_meta($post->ID, '_thinkrank_og_description', true),
                    $post->ID
                );
                $og_image_override = get_post_meta($post->ID, '_thinkrank_og_image', true);

                // Per-post Twitter-specific title override (metabox Social tab).
                // Twitter Cards fall back to the og:title when this is empty, so
                // only capture it here; the fallback is applied in
                // generate_twitter_tags().
                $data['twitter_title'] = \ThinkRank\SEO\Pattern_Resolver::resolve_value(
                    (string) get_post_meta($post->ID, '_thinkrank_twitter_title', true),
                    $post->ID
                );

                // Title priority: per-post OG override > effective SEO title
                // (document <title> / metabox preview) > post title.
                if ($og_title_override !== '') {
                    $data['title'] = $og_title_override;
                } elseif ($fallback_title !== null && $fallback_title !== '') {
                    $data['title'] = $fallback_title;
                } else {
                    $data['title'] = get_the_title($post);
                }
                // Description priority: per-post OG override > effective meta
                // description (meta tag / metabox preview) > derived excerpt.
                if ($og_description_override !== '') {
                    $data['description'] = $og_description_override;
                } elseif ($fallback_description !== null && $fallback_description !== '') {
                    $data['description'] = $fallback_description;
                } else {
                    $data['description'] = $this->get_social_description($post);
                }
                $data['url'] = get_permalink($post);
                $data['type'] = $settings['og_type'] ?? $this->determine_og_type($context_type, []);
                $data['published_time'] = get_the_date('c', $post);
                $data['modified_time'] = get_the_modified_date('c', $post);
                $data['post_id'] = $post->ID;

                // Author data
                $author_id = $post->post_author;
                $data['author'] = [
                    'name' => get_the_author_meta('display_name', $author_id),
                    'url' => get_author_posts_url($author_id),
                    'twitter' => get_the_author_meta('twitter', $author_id)
                ];

                // Set Open Graph image: per-post override first, then the
                // featured-image/default fallback chain.
                $data['image'] = $og_image_override !== ''
                    ? $og_image_override
                    : $this->get_og_image_for_context($settings, $post);

                // Set Twitter image with proper fallback
                $data['twitter_image'] = $this->get_twitter_image_for_context($settings, $post);
            }
        } else {
            // Archive-style contexts (category, tag, author, search, date).
            // They carry no object of their own, but the site-wide default
            // image still applies — without this they fell through to the raw
            // logo/site-icon fallback in generate_og_tags() and ignored a
            // configured default OG image.
            $data['image'] = $this->get_og_image_for_context($settings, null);
            $data['twitter_image'] = $this->get_twitter_image_for_context($settings, null);
        }

        return $data;
    }

    /**
     * Get Open Graph image for context with proper fallback
     *
     * @since 1.0.0
     *
     * @param array         $settings Social meta settings
     * @param \WP_Post|null $post     Optional. Post object for post-specific images
     * @return string Image URL or empty string
     */
    private function get_og_image_for_context(array $settings, ?\WP_Post $post = null): string {
        // For posts, check featured image first
        if ($post) {
            $image_id = get_post_thumbnail_id($post);
            if ($image_id) {
                $image_data = wp_get_attachment_image_src($image_id, 'large');
                if (!empty($image_data[0])) {
                    return $image_data[0];
                }
            }
        }

        // Fallback to configured Open Graph image
        if (!empty($settings['default_og_image'])) {
            return $settings['default_og_image'];
        }

        // Final fallback to generic default image
        if (!empty($settings['default_image'])) {
            return $settings['default_image'];
        }

        // Last-resort fallback to the site logo / site icon. Resolving it here
        // (rather than late inside generate_og_tags()) writes it back to the
        // shared $data['image'], so generate_twitter_tags() and
        // determine_twitter_card_type() see the same image: twitter:image is
        // emitted and the card is promoted to summary_large_image, and the
        // image is routed through optimize_image_for_platform() so
        // og:image:width/height/type/alt companions are produced.
        return $this->get_default_social_image();
    }

    /**
     * Get Twitter image for context with proper fallback
     *
     * @since 1.0.0
     *
     * @param array         $settings Social meta settings
     * @param \WP_Post|null $post     Optional. Post object for post-specific images
     * @return string Image URL or empty string
     */
    private function get_twitter_image_for_context(array $settings, ?\WP_Post $post = null): string {
        // For posts, check for post-specific Twitter image meta first (if implemented)
        if ($post) {
            // Check for post-specific Twitter image meta (future enhancement)
            $post_twitter_image = get_post_meta($post->ID, '_thinkrank_twitter_image', true);
            if (!empty($post_twitter_image)) {
                return $post_twitter_image;
            }

            // Check featured image as fallback for posts
            $image_id = get_post_thumbnail_id($post);
            if ($image_id) {
                $image_data = wp_get_attachment_image_src($image_id, 'large');
                if (!empty($image_data[0])) {
                    return $image_data[0];
                }
            }
        }

        // Prioritize Twitter-specific default image
        if (!empty($settings['default_twitter_image'])) {
            return $settings['default_twitter_image'];
        }

        // Fallback to Open Graph default image
        if (!empty($settings['default_og_image'])) {
            return $settings['default_og_image'];
        }

        // Final fallback to generic default image
        if (!empty($settings['default_image'])) {
            return $settings['default_image'];
        }

        return '';
    }

    /**
     * Get social media description for post
     *
     * @since 1.0.0
     *
     * @param \WP_Post $post Post object
     * @return string Social media description
     */
    private function get_social_description(\WP_Post $post): string {
        // Try excerpt first
        $description = get_the_excerpt($post);

        // If no excerpt, generate from content
        if (empty($description)) {
            $content = wp_strip_all_tags($post->post_content);
            $description = wp_trim_words($content, 30, '...');
        }

        // If still empty, use site description
        if (empty($description)) {
            $description = get_bloginfo('description');
        }

        return $description;
    }

    /**
     * Determine Open Graph type based on context
     *
     * @since 1.0.0
     *
     * @param string $context Context type
     * @param array  $data    Content data
     * @return string OG type
     */
    private function determine_og_type(string $context, array $data): string {
        switch ($context) {
            case 'post':
                return 'article';
            case 'product':
                return 'product';
            case 'page':
            case 'site':
            default:
                return 'website';
        }
    }

    /**
     * Determine Twitter Card type based on content
     *
     * @since 1.0.0
     *
     * @param array  $data    Content data
     * @param string $context Context type
     * @return string Twitter Card type
     */
    private function determine_twitter_card_type(array $data, string $context): string {
        // Use a large-image card when a Twitter image will actually be emitted.
        // twitter:image resolves to the twitter-specific image first, then the OG
        // image, so key the card type off the same precedence.
        $twitter_image = !empty($data['twitter_image']) ? $data['twitter_image'] : ($data['image'] ?? '');
        return !empty($twitter_image) ? 'summary_large_image' : 'summary';
    }

    /**
     * Optimize title for platform requirements
     *
     * @since 1.0.0
     *
     * @param string $title    Original title
     * @param string $platform Target platform
     * @return string Optimized title
     */
    private function optimize_title_for_platform(string $title, string $platform): string {
        if (empty($title)) {
            return get_bloginfo('name');
        }

        $max_length = $this->supported_platforms[$platform]['title_max_length'] ?? 60;

        // Multibyte-safe: byte-based substr() would split characters in
        // CJK/Bengali/accented titles (WP ships an mbstring fallback).
        if (mb_strlen($title) <= $max_length) {
            return $title;
        }

        // Truncate at word boundary
        $truncated = wp_trim_words($title, 10, '');
        if (mb_strlen($truncated) <= $max_length) {
            return $truncated;
        }

        // Hard truncate if necessary
        return mb_substr($title, 0, $max_length - 3) . '...';
    }

    /**
     * Optimize description for platform requirements
     *
     * @since 1.0.0
     *
     * @param string $description Original description
     * @param string $platform    Target platform
     * @return string Optimized description
     */
    private function optimize_description_for_platform(string $description, string $platform): string {
        if (empty($description)) {
            return get_bloginfo('description');
        }

        $max_length = $this->supported_platforms[$platform]['description_max_length'] ?? 160;

        // Multibyte-safe: byte-based substr() would split characters in
        // CJK/Bengali/accented descriptions (WP ships an mbstring fallback).
        if (mb_strlen($description) <= $max_length) {
            return $description;
        }

        // Truncate at word boundary
        $truncated = wp_trim_words($description, 25, '');
        if (mb_strlen($truncated) <= $max_length) {
            return $truncated;
        }

        // Hard truncate if necessary
        return mb_substr($description, 0, $max_length - 3) . '...';
    }

    /**
     * Optimize image for platform requirements
     *
     * @since 1.0.0
     *
     * @param string $image_url Image URL
     * @param string $platform  Target platform
     * @return array Optimized image data
     */
    private function optimize_image_for_platform(string $image_url, string $platform): array {
        $image_data = [
            'url' => $image_url,
            'width' => '',
            'height' => '',
            'alt' => '',
            'type' => '',
            'valid' => false,
            'warnings' => []
        ];

        if (empty($image_url)) {
            return $image_data;
        }

        // Get image metadata
        $attachment_id = attachment_url_to_postid($image_url);
        if ($attachment_id) {
            $image_meta = wp_get_attachment_metadata($attachment_id);
            $image_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $mime_type = get_post_mime_type($attachment_id);

            if ($image_meta && isset($image_meta['width'], $image_meta['height'])) {
                $width  = (int) $image_meta['width'];
                $height = (int) $image_meta['height'];

                $image_data['alt'] = $image_alt ?: '';
                $image_data['type'] = $mime_type ?: '';

                // SVGs and other vector uploads store 0x0 metadata. Dimension
                // checks are meaningless there and dividing by 0 is fatal.
                if ($width > 0 && $height > 0) {
                    $image_data['width'] = $width;
                    $image_data['height'] = $height;

                    // Validate against platform requirements
                    $platform_spec = $this->supported_platforms[$platform] ?? [];
                    $min_width = $platform_spec['image_min_width'] ?? 300;
                    $min_height = $platform_spec['image_min_height'] ?? 200;

                    if ($width >= $min_width && $height >= $min_height) {
                        $image_data['valid'] = true;
                    } else {
                        $image_data['warnings'][] = "Image dimensions ({$width}x{$height}) are below recommended minimum ({$min_width}x{$min_height}) for {$platform}";
                    }

                    // Check aspect ratio if specified
                    if (isset($platform_spec['image_recommended_ratio'])) {
                        $actual_ratio = $width / $height;
                        $recommended_ratio = $platform_spec['image_recommended_ratio'];
                        $ratio_tolerance = 0.1;

                        if (abs($actual_ratio - $recommended_ratio) > $ratio_tolerance) {
                            $image_data['warnings'][] = sprintf(
                                "Image aspect ratio (%.2f) differs from recommended ratio (%.2f) for %s",
                                $actual_ratio,
                                $recommended_ratio,
                                $platform
                            );
                        }
                    }
                }
            }
        }

        return $image_data;
    }

    /**
     * Get default social image
     *
     * @since 1.0.0
     *
     * @return string Default social image URL
     */
    private function get_default_social_image(): string {
        // Try custom logo first
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_data = wp_get_attachment_image_src($custom_logo_id, 'large');
            if ($logo_data) {
                return $logo_data[0];
            }
        }

        // Try site icon
        $site_icon_id = get_option('site_icon');
        if ($site_icon_id) {
            $icon_data = wp_get_attachment_image_src($site_icon_id, 'large');
            if ($icon_data) {
                return $icon_data[0];
            }
        }

        return '';
    }

    /**
     * Get Open Graph locale
     *
     * @since 1.0.0
     *
     * @return string OG locale
     */
    private function get_og_locale(): string {
        /**
         * Filter the locale used for og:locale.
         *
         * Defaults to get_locale(), which only tracks the active language once
         * that language's translation files are installed — on a multilingual
         * site without them every translated URL still reports the default
         * locale. The multilingual integration answers with the locale its
         * provider reports for the language actually being viewed.
         *
         * @since 1.23.0
         *
         * @param string $locale Locale for the current request.
         */
        $locale = (string) apply_filters('thinkrank_og_locale', get_locale());

        // Convert WordPress locale to OG locale format for the few that differ
        // from the xx_YY form (e.g. bare 'ja').
        $og_locale_map = [
            'ja' => 'ja_JP',
        ];

        if (isset($og_locale_map[$locale])) {
            return $og_locale_map[$locale];
        }

        // Fall back to the actual site locale (normalized to xx_YY) rather than
        // mislabeling every unmapped language as en_US.
        if (preg_match('/^[a-z]{2,3}_[A-Z]{2}$/', $locale)) {
            return $locale;
        }

        // Bare language code (e.g. 'nl') → best-effort xx_XX.
        if (preg_match('/^([a-z]{2,3})$/', $locale, $m)) {
            return $m[1] . '_' . strtoupper($m[1]);
        }

        return 'en_US';
    }

    /**
     * Get current URL
     *
     * @since 1.0.0
     *
     * @return string Current URL
     */
    private function get_current_url(): string {
        if (is_admin()) {
            return home_url();
        }

        global $wp;
        return home_url(add_query_arg([], $wp->request));
    }

    /**
     * Get Twitter site handle
     *
     * @since 1.0.0
     *
     * @return string Twitter site handle
     */
    private function get_twitter_site_handle(): string {
        $settings = $this->get_settings('site');
        $username = $settings['twitter_username'] ?? '';

        if (empty($username)) {
            return '';
        }

        // Ensure username starts with @ for meta tag output
        return '@' . ltrim($username, '@');
    }

    /**
     * Get Twitter creator handle
     *
     * @since 1.0.0
     *
     * @return string Twitter creator handle
     */
    private function get_twitter_creator_handle(): string {
        $settings = $this->get_settings('site');
        $creator = $settings['twitter_creator'] ?? '';

        if (empty($creator)) {
            return '';
        }

        // Ensure creator starts with @ for meta tag output
        return '@' . ltrim($creator, '@');
    }

    /**
     * Add context-specific Open Graph tags
     *
     * @since 1.0.0
     *
     * @param array  $og_tags OG tags array
     * @param string $context Context type
     * @param array  $data    Content data
     * @param string $og_type OG type
     * @return array Updated OG tags
     */
    private function add_context_specific_og_tags(array $og_tags, string $context, array $data, string $og_type): array {
        switch ($og_type) {
            case 'article':
                if (!empty($data['author']['name'])) {
                    $og_tags['article:author'] = $data['author']['name'];
                }
                if (!empty($data['published_time'])) {
                    $og_tags['article:published_time'] = $data['published_time'];
                }
                if (!empty($data['modified_time'])) {
                    $og_tags['article:modified_time'] = $data['modified_time'];
                }
                // article:section — the post's primary category (parity with the
                // basic emitter, which the active path previously omitted).
                if (!empty($data['post_id'])) {
                    $categories = get_the_category((int) $data['post_id']);
                    if (!empty($categories) && !is_wp_error($categories)) {
                        $og_tags['article:section'] = $categories[0]->name;
                    }
                }
                break;
            case 'product':
                // Product-specific tags would be added here
                // This could be extended with price, availability, etc.
                break;
        }

        // Add local business Open Graph tags if business data is available
        $og_tags = $this->add_local_business_og_tags($og_tags, $data);

        return $og_tags;
    }

    /**
     * Add local business Open Graph tags
     *
     * @since 1.0.0
     *
     * @param array $og_tags OG tags array
     * @param array $data    Content data
     * @return array Updated OG tags with local business information
     */
    private function add_local_business_og_tags(array $og_tags, array $data): array {
        // Get business data from Site Identity settings
        $business_data = $this->get_local_business_data();

        if (empty($business_data) || empty($business_data['business_name'])) {
            return $og_tags;
        }

        // Add business contact data Open Graph tags
        if (!empty($business_data['business_address'])) {
            $og_tags['business:contact_data:street_address'] = $business_data['business_address'];
        }

        if (!empty($business_data['business_city'])) {
            $og_tags['business:contact_data:locality'] = $business_data['business_city'];
        }

        if (!empty($business_data['business_state'])) {
            $og_tags['business:contact_data:region'] = $business_data['business_state'];
        }

        if (!empty($business_data['business_postal_code'])) {
            $og_tags['business:contact_data:postal_code'] = $business_data['business_postal_code'];
        }

        if (!empty($business_data['business_country'])) {
            $og_tags['business:contact_data:country_name'] = $business_data['business_country'];
        }

        if (!empty($business_data['business_phone'])) {
            $og_tags['business:contact_data:phone_number'] = $business_data['business_phone'];
        }

        if (!empty($business_data['business_email'])) {
            $og_tags['business:contact_data:email'] = $business_data['business_email'];
        }

        // Add business hours if available
        if (!empty($business_data['business_hours']) && is_array($business_data['business_hours'])) {
            $formatted_hours = $this->format_business_hours_for_og($business_data['business_hours']);
            if (!empty($formatted_hours)) {
                $og_tags['business:hours'] = $formatted_hours;
            }
        }

        // Add business website
        if (!empty($business_data['business_website'])) {
            $og_tags['business:contact_data:website'] = $business_data['business_website'];
        }

        // Add coordinates if available
        if (!empty($business_data['business_latitude']) && !empty($business_data['business_longitude'])) {
            $og_tags['place:location:latitude'] = $business_data['business_latitude'];
            $og_tags['place:location:longitude'] = $business_data['business_longitude'];
        }

        return $og_tags;
    }

    /**
     * Get local business data from Site Identity settings
     *
     * @since 1.0.0
     *
     * @return array Business data array
     */
    private function get_local_business_data(): array {
        // Try to get Site Identity Manager
        if (!class_exists('ThinkRank\\SEO\\Site_Identity_Manager')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-site-identity-manager.php';
        }

        $site_identity_manager = new \ThinkRank\SEO\Site_Identity_Manager();
        $settings = $site_identity_manager->get_settings('site');

        // Only return data if local SEO is enabled
        if (empty($settings['local_seo_enabled'])) {
            return [];
        }

        return [
            'business_name' => $settings['business_name'] ?? '',
            'business_address' => $settings['business_address'] ?? '',
            'business_city' => $settings['business_city'] ?? '',
            'business_state' => $settings['business_state'] ?? '',
            'business_postal_code' => $settings['business_postal_code'] ?? '',
            'business_country' => $settings['business_country'] ?? '',
            'business_phone' => $settings['business_phone'] ?? '',
            'business_email' => $settings['business_email'] ?? '',
            'business_hours' => $settings['business_hours'] ?? [],
            'business_website' => $settings['business_website'] ?? home_url(),
            'business_latitude' => $settings['business_latitude'] ?? '',
            'business_longitude' => $settings['business_longitude'] ?? ''
        ];
    }

    /**
     * Format business hours for Open Graph tags
     *
     * @since 1.0.0
     *
     * @param array $business_hours Business hours array
     * @return string Formatted hours string
     */
    private function format_business_hours_for_og(array $business_hours): string {
        $formatted_days = [];

        $day_names = [
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday',
            'sunday' => 'Sunday'
        ];

        foreach ($day_names as $day => $display_name) {
            if (isset($business_hours[$day]) && !empty($business_hours[$day])) {
                $day_data = $business_hours[$day];

                if (!empty($day_data['closed']) || empty($day_data['open']) || empty($day_data['close'])) {
                    $formatted_days[] = $display_name . ': Closed';
                } else {
                    $formatted_days[] = $display_name . ': ' . $day_data['open'] . ' - ' . $day_data['close'];
                }
            }
        }

        return implode('; ', $formatted_days);
    }

    /**
     * Apply platform-specific optimizations
     *
     * @since 1.0.0
     *
     * @param array  $og_tags  OG tags array
     * @param string $platform Target platform
     * @param array  $data     Content data
     * @return array Optimized OG tags
     */
    private function apply_platform_specific_optimizations(array $og_tags, string $platform, array $data): array {
        $settings = $this->get_settings('site');

        switch ($platform) {
            case 'linkedin':
                // LinkedIn prefers professional content
                if ($settings['enable_linkedin'] ?? false) {
                    if (isset($og_tags['og:description'])) {
                        $og_tags['og:description'] = $this->make_description_professional($og_tags['og:description']);
                    }
                    // Add LinkedIn-specific type if appropriate
                    if ($og_tags['og:type'] === 'article') {
                        $og_tags['article:author'] = $data['author'] ?? '';
                    }
                }
                break;

            case 'pinterest':
                // Pinterest prefers descriptive content
                if ($settings['enable_pinterest'] ?? false) {
                    if (isset($og_tags['og:description'])) {
                        $og_tags['og:description'] = $this->make_description_descriptive($og_tags['og:description']);
                    }
                    // Pinterest prefers article type for rich pins
                    if (in_array($og_tags['og:type'], ['website', 'blog'])) {
                        $og_tags['og:type'] = 'article';
                    }
                }
                break;

            case 'instagram':
                // Instagram optimizations
                if ($settings['enable_instagram'] ?? false) {
                    // Instagram prefers square or vertical images
                    if (isset($og_tags['og:description'])) {
                        $og_tags['og:description'] = $this->make_description_engaging($og_tags['og:description']);
                    }
                }
                break;

            case 'tiktok':
                // TikTok optimizations
                if ($settings['enable_tiktok'] ?? false) {
                    // TikTok prefers short, catchy descriptions
                    if (isset($og_tags['og:description'])) {
                        $og_tags['og:description'] = $this->make_description_catchy($og_tags['og:description']);
                    }
                }
                break;
        }

        return $og_tags;
    }

    /**
     * Make description more professional for LinkedIn
     *
     * @since 1.0.0
     *
     * @param string $description Original description
     * @return string Professional description
     */
    private function make_description_professional(string $description): string {
        // Remove casual language and emojis, add professional tone
        $description = preg_replace('/[^\w\s\.,!?-]/', '', $description);

        // Add professional keywords if not present
        $professional_words = ['insights', 'expertise', 'professional', 'industry', 'business'];
        if (!preg_match('/\b(' . implode('|', $professional_words) . ')\b/i', $description)) {
            $description = 'Professional insights: ' . $description;
        }

        return $description;
    }

    /**
     * Make description more descriptive for Pinterest
     *
     * @since 1.0.0
     *
     * @param string $description Original description
     * @return string Descriptive description
     */
    private function make_description_descriptive(string $description): string {
        // Pinterest users love detailed, searchable descriptions
        $descriptive_words = ['discover', 'explore', 'learn', 'find', 'ideas'];

        if (!preg_match('/\b(' . implode('|', $descriptive_words) . ')\b/i', $description)) {
            $description = 'Discover ' . lcfirst($description);
        }

        return $description;
    }

    /**
     * Make description engaging for Instagram
     *
     * @since 1.0.0
     *
     * @param string $description Original description
     * @return string Engaging description
     */
    private function make_description_engaging(string $description): string {
        // Instagram prefers engaging, visual language
        $engaging_words = ['amazing', 'stunning', 'beautiful', 'incredible', 'inspiring'];

        if (!preg_match('/\b(' . implode('|', $engaging_words) . ')\b/i', $description)) {
            $description = '✨ ' . $description;
        }

        return $description;
    }

    /**
     * Make description catchy for TikTok
     *
     * @since 1.0.0
     *
     * @param string $description Original description
     * @return string Catchy description
     */
    private function make_description_catchy(string $description): string {
        // TikTok prefers short, catchy descriptions
        $catchy_words = ['viral', 'trending', 'must-see', 'epic', 'mind-blowing'];

        // Limit to 100 characters for TikTok
        if (strlen($description) > 100) {
            $description = substr($description, 0, 97) . '...';
        }

        if (!preg_match('/\b(' . implode('|', $catchy_words) . ')\b/i', $description)) {
            $description = '🔥 ' . $description;
        }

        return $description;
    }

    /**
     * Add Twitter Card specific tags
     *
     * @since 1.0.0
     *
     * @param array  $twitter_tags Twitter tags array
     * @param string $card_type    Card type
     * @param array  $data         Content data
     * @param string $context      Context type
     * @return array Updated Twitter tags
     */
    private function add_twitter_card_specific_tags(array $twitter_tags, string $card_type, array $data, string $context): array {
        // The content extractor only produces summary / summary_large_image
        // cards, so the player/app card variants were dead code that read keys
        // (video_url, app_name, …) the extractor never sets. Kept as a filterable
        // extension point for add-ons that do populate richer card data.
        return apply_filters('thinkrank_twitter_card_specific_tags', $twitter_tags, $card_type, $data, $context);
    }

    /**
     * Generate Open Graph preview
     *
     * @since 1.0.0
     *
     * @param array  $og_tags  OG tags
     * @param string $platform Platform name
     * @param array  $preview  Preview array
     * @return array Updated preview
     */
    private function generate_og_preview(array $og_tags, string $platform, array $preview): array {
        $preview['title'] = $og_tags['og:title'] ?? '';
        $preview['description'] = $og_tags['og:description'] ?? '';
        $preview['image'] = $og_tags['og:image'] ?? '';
        $preview['preview_url'] = $og_tags['og:url'] ?? '';

        // Validate required fields
        $required_fields = ['og:title', 'og:type', 'og:image', 'og:url'];
        $missing_fields = [];

        foreach ($required_fields as $field) {
            if (empty($og_tags[$field])) {
                $missing_fields[] = $field;
            }
        }

        if (empty($missing_fields)) {
            $preview['valid'] = true;
        } else {
            $preview['warnings'][] = 'Missing required fields: ' . implode(', ', $missing_fields);
        }

        // Platform-specific validation
        $platform_spec = $this->supported_platforms[$platform] ?? [];
        if (isset($platform_spec['title_max_length']) && strlen($preview['title']) > $platform_spec['title_max_length']) {
            $preview['warnings'][] = "Title exceeds {$platform} maximum length of {$platform_spec['title_max_length']} characters";
        }

        return $preview;
    }

    /**
     * Generate Twitter preview
     *
     * @since 1.0.0
     *
     * @param array $twitter_tags Twitter tags
     * @param array $preview      Preview array
     * @return array Updated preview
     */
    private function generate_twitter_preview(array $twitter_tags, array $preview): array {
        $preview['title'] = $twitter_tags['twitter:title'] ?? '';
        $preview['description'] = $twitter_tags['twitter:description'] ?? '';
        $preview['image'] = $twitter_tags['twitter:image'] ?? '';

        // Validate required fields
        $required_fields = ['twitter:card', 'twitter:title'];
        $missing_fields = [];

        foreach ($required_fields as $field) {
            if (empty($twitter_tags[$field])) {
                $missing_fields[] = $field;
            }
        }

        if (empty($missing_fields)) {
            $preview['valid'] = true;
        } else {
            $preview['warnings'][] = 'Missing required fields: ' . implode(', ', $missing_fields);
        }

        // Twitter-specific validation
        $twitter_spec = $this->supported_platforms['twitter'];
        if (strlen($preview['title']) > $twitter_spec['title_max_length']) {
            $preview['warnings'][] = "Title exceeds Twitter maximum length of {$twitter_spec['title_max_length']} characters";
        }

        return $preview;
    }

    /**
     * Generate Pinterest preview
     *
     * @since 1.0.0
     *
     * @param array $og_tags OG tags
     * @param array $preview Preview array
     * @return array Updated preview
     */
    private function generate_pinterest_preview(array $og_tags, array $preview): array {
        $preview['title'] = $og_tags['og:title'] ?? '';
        $preview['description'] = $og_tags['og:description'] ?? '';
        $preview['image'] = $og_tags['og:image'] ?? '';
        $preview['preview_url'] = $og_tags['og:url'] ?? '';

        // Validate required fields for Pinterest
        $required_fields = ['og:title', 'og:image', 'og:url'];
        $missing_fields = [];

        foreach ($required_fields as $field) {
            if (empty($og_tags[$field])) {
                $missing_fields[] = $field;
            }
        }

        if (empty($missing_fields)) {
            $preview['valid'] = true;
        } else {
            $preview['warnings'][] = 'Missing required fields: ' . implode(', ', $missing_fields);
        }

        // Pinterest-specific validation and suggestions
        $pinterest_spec = $this->supported_platforms['pinterest'] ?? [];

        // Title length validation
        if (isset($pinterest_spec['title_max_length']) && strlen($preview['title']) > $pinterest_spec['title_max_length']) {
            $preview['warnings'][] = "Title exceeds Pinterest maximum length of {$pinterest_spec['title_max_length']} characters";
        }

        // Description length validation
        if (isset($pinterest_spec['description_max_length']) && strlen($preview['description']) > $pinterest_spec['description_max_length']) {
            $preview['warnings'][] = "Description exceeds Pinterest maximum length of {$pinterest_spec['description_max_length']} characters";
        }

        // Image dimension suggestions for Pinterest
        if (!empty($preview['image'])) {
            $image_data = $this->optimize_image_for_platform($preview['image'], 'pinterest');
            if (!empty($image_data['width']) && !empty($image_data['height'])) {
                $aspect_ratio = $image_data['width'] / $image_data['height'];

                // Pinterest prefers vertical images (2:3 ratio is optimal)
                if ($aspect_ratio > 1) {
                    $preview['suggestions'][] = 'Pinterest performs better with vertical images (2:3 aspect ratio recommended)';
                } elseif ($aspect_ratio < 0.6) {
                    $preview['suggestions'][] = 'Image is very tall - consider a 2:3 aspect ratio for optimal Pinterest performance';
                }

                // Minimum size recommendations
                if ($image_data['width'] < 600) {
                    $preview['suggestions'][] = 'Pinterest recommends images at least 600px wide for better quality';
                }
            }
        }

        return $preview;
    }

    /**
     * Generate platform-specific meta tags
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array
     * @return array Platform-specific meta tags
     */
    private function generate_platform_meta_tags(array $settings): array {
        $platform_tags = [];

        // Facebook meta tags
        if (!empty($settings['facebook_app_id'])) {
            $platform_tags['fb:app_id'] = $settings['facebook_app_id'];
        }

        if (!empty($settings['facebook_admins'])) {
            $platform_tags['fb:admins'] = $settings['facebook_admins'];
        }

        // Pinterest site verification
        if (!empty($settings['pinterest_site_verification'])) {
            $platform_tags['pinterest-site-verification'] = $settings['pinterest_site_verification'];
        }

        // Instagram verification (if enabled)
        if (!empty($settings['instagram_verification'])) {
            $platform_tags['instagram-site-verification'] = $settings['instagram_verification'];
        }

        // TikTok verification (if enabled)
        if (!empty($settings['tiktok_verification'])) {
            $platform_tags['tiktok-site-verification'] = $settings['tiktok_verification'];
        }

        // YouTube channel verification (if enabled)
        if (!empty($settings['youtube_channel_id'])) {
            $platform_tags['youtube-channel-id'] = $settings['youtube_channel_id'];
        }

        // WhatsApp Business verification (if enabled)
        if (!empty($settings['whatsapp_business_id'])) {
            $platform_tags['whatsapp-business-id'] = $settings['whatsapp_business_id'];
        }

        return $platform_tags;
    }

    /**
     * Convert OG, Twitter, and Platform tags to HTML meta tags
     *
     * @since 1.0.0
     *
     * @param array $og_tags       Open Graph tags
     * @param array $twitter_tags  Twitter tags
     * @param array $platform_tags Platform-specific tags
     * @return array HTML meta tags
     */
    private function convert_to_meta_tags(array $og_tags, array $twitter_tags, array $platform_tags = []): array {
        $meta_tags = [];

        // Convert OG tags
        foreach ($og_tags as $property => $content) {
            if (!empty($content)) {
                $meta_tags[] = [
                    'property' => esc_attr($property),
                    'content' => esc_attr($content)
                ];
            }
        }

        // Convert Twitter tags
        foreach ($twitter_tags as $name => $content) {
            if (!empty($content)) {
                $meta_tags[] = [
                    'name' => esc_attr($name),
                    'content' => esc_attr($content)
                ];
            }
        }

        // Convert Platform tags
        foreach ($platform_tags as $name => $content) {
            if (!empty($content)) {
                // Determine if it should be property or name attribute
                if (strpos($name, 'fb:') === 0) {
                    // Facebook tags use property attribute
                    $meta_tags[] = [
                        'property' => esc_attr($name),
                        'content' => esc_attr($content)
                    ];
                } else {
                    // Other platform tags use name attribute
                    $meta_tags[] = [
                        'name' => esc_attr($name),
                        'content' => esc_attr($content)
                    ];
                }
            }
        }

        return $meta_tags;
    }

    /**
     * Validate social image
     *
     * @since 1.0.0
     *
     * @param string $image_url Image URL to validate
     * @return array Validation results
     */
    private function validate_social_image(string $image_url): array {
        $validation = [
            'valid' => false,
            'warnings' => [],
            'suggestions' => []
        ];

        if (empty($image_url)) {
            $validation['warnings'][] = 'No image provided';
            return $validation;
        }

        // Check if URL is valid
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            $validation['warnings'][] = 'Invalid image URL';
            return $validation;
        }

        // Get image metadata if it's a local attachment
        $attachment_id = attachment_url_to_postid($image_url);
        if ($attachment_id) {
            $image_meta = wp_get_attachment_metadata($attachment_id);
            $meta_width  = isset($image_meta['width']) ? (int) $image_meta['width'] : 0;
            $meta_height = isset($image_meta['height']) ? (int) $image_meta['height'] : 0;

            // SVGs and other vector uploads store 0x0 metadata — skip the
            // dimension/ratio checks instead of dividing by 0.
            if ($image_meta && $meta_width > 0 && $meta_height > 0) {
                // Check minimum dimensions for major platforms
                $min_width = 600; // Facebook minimum
                $min_height = 315; // Facebook minimum

                if ($meta_width >= $min_width && $meta_height >= $min_height) {
                    $validation['valid'] = true;
                } else {
                    $validation['warnings'][] = "Image dimensions ({$meta_width}x{$meta_height}) are below recommended minimum ({$min_width}x{$min_height})";
                }

                // Check file size
                $file_path = get_attached_file($attachment_id);
                if ($file_path && file_exists($file_path)) {
                    $file_size = filesize($file_path);
                    $max_size = 5 * 1024 * 1024; // 5MB

                    if ($file_size > $max_size) {
                        $validation['warnings'][] = 'Image file size exceeds 5MB, may not display on some platforms';
                    }
                }

                // Check aspect ratio
                $aspect_ratio = $meta_width / $meta_height;
                if ($aspect_ratio < 1.5 || $aspect_ratio > 2.5) {
                    $validation['suggestions'][] = 'Consider using an image with 1.91:1 aspect ratio for optimal display';
                }
            }
        } else {
            $validation['suggestions'][] = 'External images may not be optimized for social media platforms';
        }

        return $validation;
    }
}
