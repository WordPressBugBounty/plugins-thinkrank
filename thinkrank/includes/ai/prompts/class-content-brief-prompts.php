<?php
/**
 * Content Brief Prompts Class
 *
 * Handles content brief generation AI prompts.
 * Preserves exact functionality from original Content Brief Generator implementation.
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
 * Content Brief Prompts Class
 *
 * Centralized content brief prompt building for consistent AI optimization across all providers.
 * Handles comprehensive content brief generation with SEO, competitor analysis, and content strategy.
 *
 * @since 1.0.0
 */
class Content_Brief_Prompts {

    /**
     * Build content brief generation prompt
     *
     * @since 1.0.0
     *
     * @param array $target_keywords Primary and secondary keywords
     * @param string $content_type Type of content
     * @param string $target_audience Target audience
     * @param string $content_length Desired content length
     * @param string $tone Content tone
     * @param string $competitor_analysis Pre-analyzed competitor content (or empty string)
     * @param string $additional_context Additional context
     * @param string $provider AI provider
     * @return string Generated prompt
     */
    public function build_content_brief_prompt(
        array $target_keywords,
        string $content_type,
        string $target_audience,
        string $content_length,
        string $tone,
        string $competitor_analysis,
        string $additional_context,
        string $provider = 'openai',
        string $language = ''
    ): string {
        $primary_keyword = $target_keywords[0] ?? '';
        $secondary_keywords = array_slice($target_keywords, 1);

        $word_count_map = [
            'short' => '500-800 words',
            'medium' => '1000-1500 words',
            'long' => '2000+ words'
        ];

        $prompt = "Generate a comprehensive content brief for a {$content_type} targeting \"{$primary_keyword}\" with these specifications:\n\n";
        if ($language !== '') {
            // Keep the whole brief in the site/post language instead of
            // defaulting to English on non-English sites (issue #234).
            $prompt .= "LANGUAGE (critical): Write the ENTIRE brief — outline, titles, descriptions, keywords and every other piece of text — in {$language}. Do NOT translate to English or switch languages.\n\n";
        }
        $prompt .= "Target Keywords: " . implode(', ', $target_keywords) . "\n";
        $prompt .= "Content Type: {$content_type}\n";
        $prompt .= "Target Audience: {$target_audience}\n";
        $prompt .= "Desired Length: {$word_count_map[$content_length]}\n";
        $prompt .= "Tone: {$tone}\n";

        if (!empty($competitor_analysis)) {
            $prompt .= "Competitor Analysis:\n" . $competitor_analysis . "\n";
        }

        if (!empty($additional_context)) {
            $prompt .= "Additional Context: {$additional_context}\n";
        }

        $prompt .= "\nPlease provide a comprehensive, structured response with:\n\n";

        $prompt .= "1. CONTENT OUTLINE:\n";
        $prompt .= "   Create a detailed H1-H3 structure with:\n";
        $prompt .= "   - Clear, SEO-optimized headings\n";
        $prompt .= "   - Word count estimates for each section\n";
        $prompt .= "   - 2-3 key points to cover under each heading\n";
        $prompt .= "   - Relevant keywords to include in each section\n\n";

        $prompt .= "2. SEO METADATA PACKAGE:\n";
        $prompt .= "   TITLES:\n";
        $prompt .= "   - 3-5 compelling title suggestions (50-60 characters)\n";
        $prompt .= "   META DESCRIPTIONS:\n";
        $prompt .= "   - 3-4 meta description variations (150-160 characters)\n";
        $prompt .= "   - Include primary keyword and compelling CTA in each\n";
        $prompt .= "   URL SLUGS:\n";
        $prompt .= "   - 3 SEO-friendly URL slug suggestions (lowercase, hyphens)\n";
        $prompt .= "   FOCUS KEYWORD ANALYSIS:\n";
        $prompt .= "   - Primary keyword placement recommendations\n";
        $prompt .= "   - Secondary keyword integration strategy\n";
        $prompt .= "   - Keyword density guidelines (1-2% for primary)\n";
        $prompt .= "   RELATED KEYWORDS:\n";
        $prompt .= "   - 8-12 LSI and semantic keywords to naturally include\n";
        $prompt .= "   - Long-tail keyword variations\n";
        $prompt .= "   INTERNAL LINKING:\n";
        $prompt .= "   - Specific internal linking opportunities\n\n";

        $prompt .= "3. SOCIAL MEDIA & SCHEMA MARKUP:\n";
        $prompt .= "   SOCIAL MEDIA META TAGS:\n";
        $prompt .= "   - Open Graph title (55 characters max)\n";
        $prompt .= "   - Open Graph description (125 characters max)\n";
        $prompt .= "   - Twitter Card title (70 characters max)\n";
        $prompt .= "   - Twitter Card description (125 characters max)\n";
        $prompt .= "   SCHEMA MARKUP SUGGESTIONS:\n";
        $prompt .= "   - Recommended schema types (Article, FAQ, HowTo, etc.)\n";
        $prompt .= "   - Key schema properties to include\n";
        $prompt .= "   - FAQ schema questions if applicable\n\n";

        $prompt .= "4. VISUAL CONTENT STRATEGY:\n";
        $prompt .= "   IMAGE RECOMMENDATIONS:\n";
        $prompt .= "   - 5-8 specific image types needed\n";
        $prompt .= "   - Alt text suggestions for each image type\n";
        $prompt .= "   - Image placement recommendations\n";
        $prompt .= "   INFOGRAPHIC OPPORTUNITIES:\n";
        $prompt .= "   - Data visualization suggestions\n";
        $prompt .= "   - Process diagrams or flowcharts\n\n";

        $prompt .= "5. COMPETITOR ANALYSIS & CONTENT GAPS:\n";
        if (!empty($competitor_urls)) {
            $prompt .= "   Based on the competitor analysis above:\n";
            $prompt .= "   - Specific content gaps in competitor articles\n";
            $prompt .= "   - How to improve upon competitor headings and structure\n";
            $prompt .= "   - Topics competitors cover poorly or miss entirely\n";
            $prompt .= "   - Opportunities to create more comprehensive content\n";
        } else {
            $prompt .= "   - What competitors typically miss in this topic area\n";
            $prompt .= "   - Unique angles or perspectives to take\n";
            $prompt .= "   - Questions readers have that aren't being answered\n";
        }
        $prompt .= "\n";

        $prompt .= "6. CALL-TO-ACTION SUGGESTIONS:\n";
        $prompt .= "   - 3-5 compelling CTAs appropriate for the content type\n";
        $prompt .= "   - Placement recommendations within the content\n\n";

        $prompt .= "7. WRITING GUIDELINES:\n";
        $prompt .= "   - Tone and style recommendations\n";
        $prompt .= "   - Key messages to emphasize\n";
        $prompt .= "   - Content format suggestions (lists, tables, etc.)\n\n";

        $prompt .= "8. FULL ARTICLE DRAFT:\n";
        $prompt .= "   Write the complete, publish-ready article body ({$word_count_map[$content_length]}) that follows the outline above.\n";
        $prompt .= "   - Format as clean HTML using <h2>/<h3> headings, <p>, <ul>/<ol>, and <blockquote>. Do NOT include an <h1> (the post title is the H1).\n";
        $prompt .= "   - Write in a {$tone} tone for a {$target_audience} audience, naturally weaving in the target and related keywords.\n";
        $prompt .= "   - Cover every outline section with real prose (no placeholders) and finish with a relevant call to action.\n\n";

        $prompt .= "IMPORTANT: Respond with a valid JSON object using this exact structure:\n\n";
        $prompt .= "{\n";
        $prompt .= "  \"title_suggestions\": [\"Title 1\", \"Title 2\", \"Title 3\", \"Title 4\", \"Title 5\"],\n";
        $prompt .= "  \"meta_descriptions\": [\"Meta 1\", \"Meta 2\", \"Meta 3\", \"Meta 4\"],\n";
        $prompt .= "  \"url_slugs\": [\"slug-1\", \"slug-2\", \"slug-3\"],\n";
        $prompt .= "  \"outline\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"heading\": \"H1: Main Title\",\n";
        $prompt .= "      \"level\": 1,\n";
        $prompt .= "      \"word_count\": 50,\n";
        $prompt .= "      \"key_points\": [\"Point 1\", \"Point 2\", \"Point 3\"],\n";
        $prompt .= "      \"keywords\": [\"keyword1\", \"keyword2\"]\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"focus_keyword_analysis\": {\n";
        $prompt .= "    \"primary_placement\": [\"H1 title\", \"First paragraph\", \"3-4 headings\"],\n";
        $prompt .= "    \"secondary_integration\": [\"Use secondary keywords naturally throughout content\", \"Focus on user intent and semantic relevance\"],\n";
        $prompt .= "    \"density_guidelines\": [\"Primary keyword: 1-2%\", \"Secondary keywords: 0.5-1%\"]\n";
        $prompt .= "  },\n";
        $prompt .= "  \"related_keywords\": [\"keyword1\", \"keyword2\", \"keyword3\"],\n";
        $prompt .= "  \"internal_linking\": [\"Link suggestion 1\", \"Link suggestion 2\"],\n";
        $prompt .= "  \"social_media\": {\n";
        $prompt .= "    \"open_graph\": {\n";
        $prompt .= "      \"title\": \"OG Title (55 chars max)\",\n";
        $prompt .= "      \"description\": \"OG Description (125 chars max)\"\n";
        $prompt .= "    },\n";
        $prompt .= "    \"twitter_card\": {\n";
        $prompt .= "      \"title\": \"Twitter Title (70 chars max)\",\n";
        $prompt .= "      \"description\": \"Twitter Description (125 chars max)\"\n";
        $prompt .= "    }\n";
        $prompt .= "  },\n";
        $prompt .= "  \"schema_markup\": {\n";
        $prompt .= "    \"recommended_types\": [\"Article\", \"HowTo\", \"FAQ\"],\n";
        $prompt .= "    \"key_properties\": [\"headline\", \"description\", \"author\"],\n";
        $prompt .= "    \"faq_questions\": [\"Question 1?\", \"Question 2?\"]\n";
        $prompt .= "  },\n";
        $prompt .= "  \"visual_content\": {\n";
        $prompt .= "    \"image_recommendations\": [\n";
        $prompt .= "      {\n";
        $prompt .= "        \"type\": \"Featured Image\",\n";
        $prompt .= "        \"description\": \"Professional image description\",\n";
        $prompt .= "        \"alt_text\": \"Alt text suggestion\"\n";
        $prompt .= "      }\n";
        $prompt .= "    ],\n";
        $prompt .= "    \"infographic_opportunities\": [\"Data visualization 1\", \"Process diagram 2\"]\n";
        $prompt .= "  },\n";
        $prompt .= "  \"competitor_analysis\": {\n";
        $prompt .= "    \"content_gaps\": [\"Gap 1\", \"Gap 2\"],\n";
        $prompt .= "    \"unique_angles\": [\"Angle 1\", \"Angle 2\"],\n";
        $prompt .= "    \"unanswered_questions\": [\"Question 1\", \"Question 2\"]\n";
        $prompt .= "  },\n";
        $prompt .= "  \"call_to_actions\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"text\": \"CTA text\",\n";
        $prompt .= "      \"placement\": \"End of post\"\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"writing_guidelines\": {\n";
        $prompt .= "    \"tone_recommendations\": [\"Professional\", \"Informative\", \"Actionable\"],\n";
        $prompt .= "    \"key_messages\": [\"Message 1\", \"Message 2\"],\n";
        $prompt .= "    \"format_suggestions\": [\"Bullet points\", \"Short paragraphs\", \"Clear headings\"]\n";
        $prompt .= "  },\n";
        $prompt .= "  \"content_body\": \"<h2>Section heading</h2><p>The complete article written out in clean HTML, following the outline...</p>\"\n";
        $prompt .= "}\n\n";
        $prompt .= "Respond ONLY with valid JSON. Do not include any text before or after the JSON object.";

        return $prompt;
    }
}
