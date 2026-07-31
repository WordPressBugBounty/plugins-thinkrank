<?php
/**
 * AI Metadata Generator
 * 
 * Core functionality for generating SEO metadata using AI
 * 
 * @package ThinkRank\AI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

use ThinkRank\Core\Settings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Metadata Generator Class
 * 
 * Single Responsibility: Generate SEO metadata using AI
 * 
 * @since 1.0.0
 */
class Metadata_Generator {
    
    /**
     * AI Manager instance
     * 
     * @var Manager
     */
    private Manager $ai_manager;
    
    /**
     * Settings instance
     * 
     * @var Settings
     */
    private Settings $settings;
    
    /**
     * Constructor
     * 
     * @param Manager|null $ai_manager AI manager instance
     * @param Settings|null $settings Settings instance
     */
    public function __construct(?Manager $ai_manager = null, ?Settings $settings = null) {
        $this->ai_manager = $ai_manager ?? new Manager();
        $this->settings = $settings ?? Settings::instance();
    }
    
    /**
     * Generate metadata for post content
     * 
     * @param string $content Post content
     * @param array $options Generation options
     * @return array Generated metadata
     * @throws \Exception If generation fails
     */
    public function generate_for_content(string $content, array $options = []): array {
        // Validate content
        if (empty(trim($content))) {
            throw new \Exception('Content cannot be empty');
        }
        
        // Prepare content for AI processing
        $processed_content = $this->prepare_content($content);
        
        // Set default options
        $default_options = [
            'target_keyword' => '',
            'content_type' => 'blog_post',
            'tone' => 'professional',
            'max_title_length' => $this->settings->get('meta_title_length', 60),
            'max_description_length' => $this->settings->get('meta_description_length', 160),
        ];
        
        $options = array_merge($default_options, $options);
        
        // Generate metadata using AI
        $metadata = $this->ai_manager->generate_seo_metadata($processed_content, $options);
        
        // Post-process and validate metadata
        return $this->validate_and_enhance_metadata($metadata, $options);
    }
    
    /**
     * Generate metadata for WordPress post
     * 
     * @param int $post_id Post ID
     * @param array $options Generation options
     * @return array Generated metadata
     * @throws \Exception If generation fails
     */
    public function generate_for_post(int $post_id, array $options = []): array {
        $post = get_post($post_id);
        
        if (!$post) {
            throw new \Exception('Post not found');
        }
        
        // Extract content from post
        $content = $this->extract_post_content($post);
        
        // Add post-specific options
        $options = array_merge($options, [
            'content_type' => $this->determine_content_type($post),
            'post_id' => $post_id,
        ]);
        
        // Generate metadata
        $metadata = $this->generate_for_content($content, $options);
        
        // Save metadata to post meta
        $this->save_post_metadata($post_id, $metadata);
        
        return $metadata;
    }
    
    /**
     * Generate multiple variations of metadata
     * 
     * @param string $content Content to analyze
     * @param array $options Generation options
     * @param int $variations Number of variations to generate
     * @return array Array of metadata variations
     */
    public function generate_variations(string $content, array $options = [], int $variations = 3): array {
        $results = [];
        
        for ($i = 0; $i < $variations; $i++) {
            // Slightly vary the temperature for different results
            $variation_options = array_merge($options, [
                'temperature' => 0.3 + ($i * 0.2), // 0.3, 0.5, 0.7
                'variation' => $i + 1,
            ]);
            
            try {
                $metadata = $this->generate_for_content($content, $variation_options);
                $metadata['variation_id'] = $i + 1;
                $results[] = $metadata;
                
                // Small delay to avoid rate limiting
                if ($i < $variations - 1) {
                    sleep(1);
                }
                
            } catch (\Exception $e) {
                // Skip failed variations silently
            }
        }
        
        return $results;
    }
    
    /**
     * Analyze content and suggest improvements
     * 
     * @param string $content Content to analyze
     * @param array $current_metadata Current metadata
     * @return array Analysis and suggestions
     */
    public function analyze_content(string $content, array $current_metadata = []): array {
        $analysis = [
            'content_length' => str_word_count($content),
            'readability_score' => $this->calculate_readability_score($content),
            'keyword_density' => $this->analyze_keyword_density($content),
            'suggestions' => [],
        ];
        
        // Analyze current metadata if provided
        if (!empty($current_metadata)) {
            $analysis['metadata_analysis'] = $this->analyze_metadata($current_metadata);
        }
        
        // Generate improvement suggestions
        $analysis['suggestions'] = $this->generate_improvement_suggestions($analysis);
        
        return $analysis;
    }
    
    /**
     * Prepare content for AI processing
     * 
     * @param string $content Raw content
     * @return string Processed content
     */
    private function prepare_content(string $content): string {
        // Remove HTML tags
        $content = wp_strip_all_tags($content);
        
        // Remove shortcodes
        $content = strip_shortcodes($content);
        
        // Normalize whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        
        // Trim and limit length for AI processing
        $content = trim($content);
        $max_length = 4000; // Reasonable limit for AI processing
        
        if (strlen($content) > $max_length) {
            $content = substr($content, 0, $max_length) . '...';
        }
        
        return $content;
    }
    
    /**
     * Extract content from WordPress post
     * 
     * @param \WP_Post $post Post object
     * @return string Extracted content
     */
    private function extract_post_content(\WP_Post $post): string {
        $content = $post->post_title . "\n\n" . $post->post_content;
        
        // Add excerpt if available
        if (!empty($post->post_excerpt)) {
            $content = $post->post_title . "\n\n" . $post->post_excerpt . "\n\n" . $post->post_content;
        }
        
        return $content;
    }
    
    /**
     * Determine content type from post
     * 
     * @param \WP_Post $post Post object
     * @return string Content type
     */
    private function determine_content_type(\WP_Post $post): string {
        switch ($post->post_type) {
            case 'page':
                return 'page';
            case 'product':
                return 'product';
            case 'post':
            default:
                return 'blog_post';
        }
    }
    
    /**
     * Validate and enhance generated metadata
     * 
     * @param array $metadata Generated metadata
     * @param array $options Generation options
     * @return array Enhanced metadata
     */
    private function validate_and_enhance_metadata(array $metadata, array $options): array {
        $max_title_length = max(1, (int) $options['max_title_length']);
        $max_description_length = max(1, (int) $options['max_description_length']);

        $metadata['title'] = trim((string) ($metadata['title'] ?? ''));
        $metadata['description'] = trim((string) ($metadata['description'] ?? ''));

        // Validate title length
        if (mb_strlen($metadata['title']) > $max_title_length) {
            $metadata['title'] = $this->truncate_text($metadata['title'], $max_title_length);
            $metadata['title_truncated'] = true;
        }

        // Validate description length
        if (mb_strlen($metadata['description']) > $max_description_length) {
            $metadata['description'] = $this->truncate_text($metadata['description'], $max_description_length);
            $metadata['description_truncated'] = true;
        }

        // Add character counts
        $metadata['title_length'] = mb_strlen($metadata['title']);
        $metadata['description_length'] = mb_strlen($metadata['description']);

        // Calculate optimization score
        $metadata['optimization_score'] = $this->calculate_optimization_score($metadata, $options);

        return $metadata;
    }

    /**
     * Truncate text to a hard character limit, preferring a word boundary
     *
     * The ellipsis counts towards the limit, so the result is never longer
     * than $max_length characters.
     *
     * @param string $text Text to truncate
     * @param int $max_length Maximum length in characters
     * @return string Truncated text
     */
    private function truncate_text(string $text, int $max_length): string {
        if (mb_strlen($text) <= $max_length) {
            return $text;
        }

        // Reserve one character for the ellipsis.
        $truncated = mb_substr($text, 0, $max_length - 1);

        // Cut back to the last word boundary, unless that throws away too much.
        $last_space = mb_strrpos($truncated, ' ');
        if ($last_space !== false && $last_space > (int) ($max_length * 0.6)) {
            $truncated = mb_substr($truncated, 0, $last_space);
        }

        return rtrim($truncated, " \t\n\r\0\x0B,;:-") . '…';
    }

    /**
     * Save metadata to post meta
     * 
     * @param int $post_id Post ID
     * @param array $metadata Metadata to save
     * @return void
     */
    private function save_post_metadata(int $post_id, array $metadata): void {
        update_post_meta($post_id, '_thinkrank_ai_title', $metadata['title']);
        update_post_meta($post_id, '_thinkrank_ai_description', $metadata['description']);
        // Persist via Focus_Keywords so the array + legacy meta stay in sync.
        // Existing keywords are preserved (the AI value seeds only when unset).
        if (empty(\ThinkRank\SEO\Focus_Keywords::get($post_id))) {
            \ThinkRank\SEO\Focus_Keywords::save($post_id, $metadata['focus_keyword']);
        }
        update_post_meta($post_id, '_thinkrank_generated_at', $metadata['generated_at']);
        update_post_meta($post_id, '_thinkrank_metadata_full', $metadata);
    }
    
    /**
     * Calculate basic readability score
     * 
     * @param string $content Content to analyze
     * @return float Readability score
     */
    private function calculate_readability_score(string $content): float {
        $sentences = preg_split('/[.!?]+/', $content);
        $words = str_word_count($content);
        $syllables = $this->count_syllables($content);
        
        if (count($sentences) === 0 || $words === 0) {
            return 0;
        }
        
        // Simplified Flesch Reading Ease formula
        $score = 206.835 - (1.015 * ($words / count($sentences))) - (84.6 * ($syllables / $words));
        
        return max(0, min(100, $score));
    }
    
    /**
     * Count syllables in text (simplified)
     * 
     * @param string $text Text to analyze
     * @return int Syllable count
     */
    private function count_syllables(string $text): int {
        $words = str_word_count($text, 1);
        $syllables = 0;
        
        foreach ($words as $word) {
            $syllables += max(1, preg_match_all('/[aeiouy]+/i', $word));
        }
        
        return $syllables;
    }
    
    /**
     * Analyze keyword density
     * 
     * @param string $content Content to analyze
     * @return array Keyword density analysis
     */
    private function analyze_keyword_density(string $content): array {
        $words = str_word_count(strtolower($content), 1);
        $total_words = count($words);
        $word_counts = array_count_values($words);
        
        // Remove common stop words
        $stop_words = ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'];
        $word_counts = array_diff_key($word_counts, array_flip($stop_words));
        
        // Calculate density for top keywords
        arsort($word_counts);
        $top_keywords = array_slice($word_counts, 0, 10, true);
        
        $density = [];
        foreach ($top_keywords as $word => $count) {
            $density[$word] = round(($count / $total_words) * 100, 2);
        }
        
        return $density;
    }
    
    /**
     * Analyze existing metadata
     * 
     * @param array $metadata Metadata to analyze
     * @return array Analysis results
     */
    private function analyze_metadata(array $metadata): array {
        return [
            'title_length_ok' => strlen($metadata['title'] ?? '') <= 60,
            'description_length_ok' => strlen($metadata['description'] ?? '') <= 160,
            'has_focus_keyword' => !empty($metadata['focus_keyword'] ?? ''),
        ];
    }
    
    /**
     * Generate improvement suggestions
     * 
     * @param array $analysis Content analysis
     * @return array Suggestions
     */
    private function generate_improvement_suggestions(array $analysis): array {
        $suggestions = [];
        
        if ($analysis['content_length'] < 300) {
            $suggestions[] = 'Consider adding more content. Longer content typically performs better in search results.';
        }
        
        if ($analysis['readability_score'] < 60) {
            $suggestions[] = 'Content readability could be improved. Try using shorter sentences and simpler words.';
        }
        
        return $suggestions;
    }
    
    /**
     * Calculate optimization score
     * 
     * @param array $metadata Generated metadata
     * @param array $options Generation options
     * @return int Optimization score (0-100)
     */
    private function calculate_optimization_score(array $metadata, array $options): int {
        $score = 0;
        
        // Title optimization (30 points)
        if (!empty($metadata['title'])) {
            $score += 15;
            if (strlen($metadata['title']) >= 30 && strlen($metadata['title']) <= 60) {
                $score += 15;
            }
        }
        
        // Description optimization (30 points)
        if (!empty($metadata['description'])) {
            $score += 15;
            if (strlen($metadata['description']) >= 120 && strlen($metadata['description']) <= 160) {
                $score += 15;
            }
        }
        
        // Keyword optimization (40 points)
        if (!empty($metadata['focus_keyword'])) {
            $score += 20;
            if (!empty($options['target_keyword']) &&
                stripos($metadata['title'], $options['target_keyword']) !== false) {
                $score += 20;
            }
        }

        return min(100, $score);
    }
}
