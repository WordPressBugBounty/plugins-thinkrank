<?php
/**
 * Homepage Prompts Class
 *
 * Handles homepage-related AI prompts including meta content and hero section optimization.
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
 * Homepage Prompts Class
 *
 * Centralized homepage prompt building for consistent AI optimization across all providers.
 * Handles homepage meta content and hero section optimization.
 *
 * @since 1.0.0
 */
class Homepage_Prompts {

    /**
     * Build homepage meta optimization prompt
     *
     * @since 1.0.0
     *
     * @param array $content_data Meta content data
     * @param string $business_type Business type
     * @param string $target_audience Target audience
     * @param string $tone Content tone
     * @param array $context Additional context information
     * @param string $provider AI provider
     * @return string Optimization prompt
     */
    public function build_homepage_meta_prompt(array $content_data, string $business_type, string $target_audience, string $tone, array $context = [], string $provider = 'openai'): string {
        $current_title = $content_data['title'] ?? '';
        $current_meta_description = $content_data['meta_description'] ?? '';

        // Extract context information
        $site_name = $context['site_name'] ?? 'the website';
        $site_url = $context['site_url'] ?? '';
        $hero_title = $context['hero_title'] ?? '';
        $hero_subtitle = $context['hero_subtitle'] ?? '';

        $context_info = '';
        if (!empty($site_name) && $site_name !== 'the website') {
            $context_info .= "- Website Name: {$site_name}\n";
        }
        if (!empty($hero_title)) {
            $context_info .= "- Hero Title: {$hero_title}\n";
        }
        if (!empty($hero_subtitle)) {
            $context_info .= "- Hero Subtitle: {$hero_subtitle}\n";
        }

        return "As an expert SEO consultant, analyze and optimize this homepage meta content for SEO and user engagement:

Current Meta Information:
- Title: \"{$current_title}\"
- Meta Description: \"{$current_meta_description}\"

Website Context:
{$context_info}
Business Context:
- Business Type: {$business_type}
- Target Audience: {$target_audience}
- Desired Tone: {$tone}

Please provide optimization recommendations in this exact JSON format:
{
    \"optimized_data\": {
        \"title\": \"optimized title (50-60 chars, SEO-friendly)\",
        \"meta_description\": \"optimized meta description (150-160 chars, SEO-friendly)\"
    },
    \"analysis\": \"Brief analysis of current meta content and optimization rationale\",
    \"suggestions\": [
        \"Specific actionable suggestion 1\",
        \"Specific actionable suggestion 2\",
        \"Specific actionable suggestion 3\"
    ],
    \"score\": 85
}

Focus on:
1. SEO optimization (keywords, length, clarity)
2. Click-through rate optimization
3. Target audience appeal
4. Search engine visibility
5. User engagement potential

Only suggest changes if they provide clear improvements. If current content is already well-optimized, indicate so in the analysis.";
    }

    /**
     * Build homepage hero optimization prompt
     *
     * @since 1.0.0
     *
     * @param array $hero_data Hero content data
     * @param string $business_type Business type
     * @param string $target_audience Target audience
     * @param string $tone Content tone
     * @param array $context Additional context information
     * @param string $provider AI provider
     * @return string Optimization prompt
     */
    public function build_homepage_hero_prompt(array $hero_data, string $business_type, string $target_audience, string $tone, array $context = [], string $provider = 'openai'): string {
        $current_title = $hero_data['hero_title'] ?? '';
        $current_subtitle = $hero_data['hero_subtitle'] ?? '';
        $current_cta_text = $hero_data['hero_cta_text'] ?? '';
        $current_cta_url = $hero_data['hero_cta_url'] ?? '';

        // Extract context information
        $site_name = $context['site_name'] ?? 'the website';
        $site_url = $context['site_url'] ?? '';
        $homepage_title = $context['homepage_title'] ?? '';
        $homepage_meta_description = $context['homepage_meta_description'] ?? '';

        $context_info = '';
        if (!empty($site_name) && $site_name !== 'the website') {
            $context_info .= "- Website Name: {$site_name}\n";
        }
        if (!empty($homepage_title)) {
            $context_info .= "- Homepage Title: {$homepage_title}\n";
        }
        if (!empty($homepage_meta_description)) {
            $context_info .= "- Homepage Description: {$homepage_meta_description}\n";
        }

        return "As an expert conversion optimization specialist, analyze and optimize this homepage hero section for conversions and engagement:

Current Hero Section:
- Hero Title: \"{$current_title}\"
- Hero Subtitle: \"{$current_subtitle}\"
- CTA Text: \"{$current_cta_text}\"
- CTA URL: \"{$current_cta_url}\"

Website Context:
{$context_info}
Business Context:
- Business Type: {$business_type}
- Target Audience: {$target_audience}
- Desired Tone: {$tone}

Please provide optimization recommendations in this exact JSON format:
{
    \"optimized_data\": {
        \"hero_title\": \"optimized hero title (compelling, clear value proposition)\",
        \"hero_subtitle\": \"optimized hero subtitle (supporting details, benefits)\",
        \"hero_cta_text\": \"optimized CTA text (action-oriented, compelling)\"
    },
    \"analysis\": \"Brief analysis of current hero section and optimization rationale\",
    \"suggestions\": [
        \"Specific actionable suggestion 1\",
        \"Specific actionable suggestion 2\",
        \"Specific actionable suggestion 3\"
    ],
    \"score\": 85
}

Focus on:
1. Conversion optimization (clear value proposition, compelling CTA text)
2. User engagement and clarity
3. Target audience appeal
4. Brand consistency
5. Action-oriented messaging

Note: Do not generate or suggest URLs. Focus only on optimizing the text content.

Only suggest changes if they provide clear improvements. If current content is already well-optimized, indicate so in the analysis.";
    }
}
