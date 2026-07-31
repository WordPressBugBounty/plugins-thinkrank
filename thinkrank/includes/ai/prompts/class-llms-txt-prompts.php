<?php
/**
 * LLMs.txt Prompts Class
 *
 * Handles LLMs.txt generation AI prompts.
 * Preserves exact functionality from original Prompt_Builder implementation.
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
 * LLMs.txt Prompts Class
 *
 * Centralized LLMs.txt prompt building for consistent AI optimization across all providers.
 * Handles LLMs.txt content generation and optimization.
 *
 * @since 1.0.0
 */
class LLMs_Txt_Prompts {

    /**
     * Build LLMs.txt optimization prompt
     *
     * @since 1.0.0
     *
     * @param array $website_data Website data to optimize
     * @param array $options Optimization options
     * @param string $provider AI provider ('openai', 'claude', etc.)
     * @return string Formatted prompt
     */
    public function build_llms_txt_prompt(array $website_data, array $options = [], string $provider = 'openai'): string {
        // Extract and sanitize data
        $data = $this->prepare_llms_txt_data($website_data, $options);
        
        // Get provider-specific prompt introduction
        $intro = $this->get_llms_txt_intro($provider);
        
        // Build the main prompt content
        $prompt_content = $this->build_llms_txt_content($data);
        
        // Get provider-specific output format instructions
        $format_instructions = $this->get_llms_txt_format_instructions($provider);
        
        return $intro . "\n\n" . $prompt_content . "\n\n" . $format_instructions;
    }

    /**
     * Prepare and sanitize LLMs.txt data
     *
     * @since 1.0.0
     *
     * @param array $website_data Raw website data
     * @param array $options Optimization options
     * @return array Prepared data
     */
    private function prepare_llms_txt_data(array $website_data, array $options): array {
        return [
            'site_name' => sanitize_text_field($website_data['site_name'] ?? ''),
            'website_description' => sanitize_textarea_field($website_data['website_description'] ?? ''),
            'key_features' => sanitize_textarea_field($website_data['key_features'] ?? ''),
            'technical_stack' => sanitize_textarea_field($website_data['technical_stack'] ?? ''),
            'development_approach' => sanitize_textarea_field($website_data['development_approach'] ?? ''),
            'business_type' => sanitize_text_field($options['business_type'] ?? 'website'),
            'target_audience' => sanitize_text_field($options['target_audience'] ?? 'general'),
            'tone' => sanitize_text_field($options['tone'] ?? 'professional')
        ];
    }

    /**
     * Get provider-specific LLMs.txt prompt introduction
     *
     * @since 1.0.0
     *
     * @param string $provider AI provider
     * @return string Prompt introduction
     */
    private function get_llms_txt_intro(string $provider): string {
        switch ($provider) {
            case 'claude':
                return "As an expert technical writer, generate comprehensive llms.txt content for this website:";
            
            case 'openai':
            default:
                return "Generate comprehensive llms.txt content for this website:";
        }
    }

    /**
     * Build main LLMs.txt prompt content
     *
     * @since 1.0.0
     *
     * @param array $data Prepared website data
     * @return string Prompt content
     */
    private function build_llms_txt_content(array $data): string {
        return "Website Title: {$data['site_name']}
Website Description: {$data['website_description']}
Key Features: {$data['key_features']}
Business Type: {$data['business_type']}
Target Audience: {$data['target_audience']}
Technical Stack: {$data['technical_stack']}
Development Approach: {$data['development_approach']}
Desired Tone: {$data['tone']}

Create structured content that helps AI assistants understand this website better. Optimize and improve all provided content including:
1. Website title (make it more compelling and SEO-friendly)
2. Clear project overview
3. Key features and functionality
4. Technical architecture details
5. Development guidelines
6. Context for AI assistance

Requirements:
- Make the content informative and well-structured
- Optimize all text for clarity and professionalism
- Ensure the tone matches the specified style: {$data['tone']}
- Target the content for: {$data['target_audience']}
- Focus on {$data['business_type']} context

Focus on creating content that is informative, well-structured, and helpful for AI assistants to understand the website's purpose and functionality.";
    }

    /**
     * Get provider-specific output format instructions
     *
     * @since 1.0.0
     *
     * @param string $provider AI provider
     * @return string Format instructions
     */
    private function get_llms_txt_format_instructions(string $provider): string {
        $base_format = 'Provide the response in this exact JSON format:
{
    "optimized_data": {
        "site_name": "improved website title",
        "website_description": "enhanced description",
        "key_features": "optimized features list",
        "technical_stack": "improved technical details",
        "development_approach": "enhanced development info"
    },
    "suggestions": [
        "additional suggestions for the website"
    ]
}';

        switch ($provider) {
            case 'claude':
                return $base_format . "\n\nEnsure the JSON is valid and properly formatted.";
            
            case 'openai':
            default:
                return $base_format . "\n\nMake sure to return only valid JSON without any additional text or formatting.";
        }
    }
}
