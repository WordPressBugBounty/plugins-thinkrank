<?php
/**
 * Site Identity Prompts Class
 *
 * Handles site identity optimization AI prompts.
 * Preserves exact functionality from original OpenAI and Claude client implementations.
 *
 * @package ThinkRank
 * @subpackage AI\Prompts
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\AI\Prompts;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Site Identity Prompts Class
 *
 * Centralized site identity prompt building for consistent AI optimization across all providers.
 * Handles site name, description, tagline, and meta description optimization.
 *
 * @since 1.0.0
 */
class Site_Identity_Prompts {

    /**
     * Build site identity optimization prompt
     *
     * @since 1.0.0
     *
     * @param array $site_data Site data to optimize
     * @param string $business_type Business type
     * @param string $target_audience Target audience
     * @param string $tone Desired tone
     * @param string $provider AI provider
     * @return string Formatted prompt
     */
    public function build_site_identity_prompt(array $site_data, string $business_type, string $target_audience, string $tone, string $provider = 'openai'): string {
        $current_name = $site_data['site_name'] ?? '';
        $current_description = $site_data['site_description'] ?? '';
        $current_tagline = $site_data['tagline'] ?? '';
        $current_meta_description = $site_data['default_meta_description'] ?? '';

        // Provider-specific introduction
        $intro = '';
        switch ($provider) {
            case 'claude':
                $intro = "As an expert SEO consultant, analyze and optimize this site identity for SEO and user engagement:";
                break;
            case 'openai':
            default:
                $intro = "Analyze and optimize this site identity for SEO and user engagement:";
                break;
        }

        return "{$intro}

Current Site Information:
- Site Name: \"{$current_name}\"
- Site Description: \"{$current_description}\"
- Tagline: \"{$current_tagline}\"
- Default Meta Description: \"{$current_meta_description}\"

Business Context:
- Business Type: {$business_type}
- Target Audience: {$target_audience}
- Desired Tone: {$tone}

Please provide optimization recommendations in this exact JSON format:
{
    \"optimized_data\": {
        \"site_name\": \"optimized site name (if improvement needed)\",
        \"site_description\": \"optimized description (150-160 chars, SEO-friendly)\",
        \"tagline\": \"optimized tagline (concise, memorable)\",
        \"default_meta_description\": \"optimized default meta description (150-160 chars, SEO-friendly)\"
    },
    \"analysis\": \"Brief analysis of current identity and optimization rationale\",
    \"suggestions\": [
        \"Specific actionable suggestion 1\",
        \"Specific actionable suggestion 2\",
        \"Specific actionable suggestion 3\"
    ],
    \"score\": 85
}

Focus on:
1. SEO optimization (keywords, length, clarity)
2. Brand consistency and memorability
3. Target audience appeal
4. Search engine visibility
5. User engagement potential

Only suggest changes if they provide clear improvements. If current content is already well-optimized, indicate so in the analysis.";
    }
}
