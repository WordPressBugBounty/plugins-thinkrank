<?php
/**
 * SEO Score Calculator
 *
 * Advanced SEO scoring system based on 2025 Google ranking factors
 * Implements AI-driven content analysis, searcher engagement signals, and current SEO best practices
 *
 * @package ThinkRank\AI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

use ThinkRank\Core\Database;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO Score Calculator Class
 *
 * Implements 2025 SEO scoring algorithm based on:
 * - Google's Q1 2025 algorithm updates (First Page Sage research)
 * - Satisfying content as #1 ranking factor (23%)
 * - Searcher engagement and intent satisfaction (12%)
 * - Mobile Experience Score (MES) and Core Web Vitals 2.0
 * - Content freshness and niche expertise signals
 *
 * @since 1.0.0
 */
class SEOScoreCalculator {
    
    /**
     * Database instance
     * 
     * @var Database
     */
    private Database $database;
    
    /**
     * 2025 SEO scoring factors (Q1 2025 Google Algorithm)
     * Based on First Page Sage research and Google's latest updates
     *
     * @var array
     */
    private array $scoring_factors = [
        'satisfying_content' => 23,      // #1: Consistent publication of satisfying content
        'title_optimization' => 14,     // #2: Keyword in meta title (looser matching)
        'niche_expertise' => 13,        // #3: Hub & spoke content clusters
        'searcher_engagement' => 12,    // #4: Dwell time, bounce rate, pages/session
        'backlink_authority' => 13,     // #5: Quality backlinks (declining but important)
        'content_freshness' => 6,       // #6: Quarterly content updates
        'mobile_experience' => 5,       // #7: Mobile Experience Score (MES) - NEW 2025
        'trustworthiness' => 4,         // #8: E-E-A-T verification
        'link_diversity' => 3,          // #9: Link distribution across multiple pages
        'core_web_vitals' => 3,         // #10: Page speed + Interaction Readiness
        'site_security' => 2,           // #11: SSL certificate
        'internal_linking' => 1,        // #12: Declining importance
        'technical_factors' => 1,       // #13: Meta descriptions, schema, etc.
    ];
    
    /**
     * Constructor
     * 
     * @param Database $database Database instance
     */
    public function __construct(Database $database) {
        $this->database = $database;
    }
    
    /**
     * Calculate comprehensive modern SEO score.
     *
     * Supports multiple focus keywords: when `$options['target_keywords']` holds
     * more than one keyword the score is computed independently for each and the
     * HIGHEST overall score is returned as the final result, with per-keyword
     * results (`keyword_results`) and OR-combined per-check matches
     * (`keyword_checks`) attached. A single keyword (or the legacy
     * `target_keyword` option) falls through to the single-keyword path.
     *
     * @param array $content_data Content analysis data
     * @param array $metadata Post metadata
     * @param array $options Additional options
     * @return array Complete scoring result
     */
    public function calculate_score(array $content_data, array $metadata, array $options = []): array {
        $keywords = $this->resolve_target_keywords($options, $metadata);

        if (count($keywords) > 1) {
            return $this->calculate_score_multi($content_data, $metadata, $keywords, $options);
        }

        $options['target_keyword'] = $keywords[0] ?? '';
        $result = $this->compute_score($content_data, $metadata, $options);

        // Expose the keyword surface uniformly so consumers can rely on it
        // regardless of how many keywords were supplied.
        $result['target_keywords'] = $keywords;
        if (!empty($keywords)) {
            $result['keyword_results'] = [[
                'keyword' => $keywords[0],
                'overall_score' => $result['overall_score'],
                'grade' => $result['grade'],
            ]];
            $result['keyword_checks'] = $this->analyze_keyword_checks($content_data, $metadata, $keywords);
        }

        return $result;
    }

    /**
     * Resolve the target keyword list from the options array, falling back to
     * the focus keywords carried on the post's metadata.
     *
     * Accepts `target_keywords` (array) or the legacy `target_keyword` (string),
     * trims, drops empties and removes case-insensitive duplicates.
     *
     * Options win over metadata on purpose: the editor scores unsaved keyword
     * edits by passing them explicitly, and that live value must beat whatever
     * is currently persisted. The metadata fallback applies only when the
     * caller mentions no keyword option AT ALL — a caller that passes an empty
     * keyword is deliberately clearing it (the editor does exactly this when
     * the field is emptied), so the stored value must not resurrect it.
     *
     * Without the fallback, every caller that hands over
     * `Metabox_Manager::get_post_metadata()` (the metabox and the MCP scoring
     * abilities) silently scored as if no keyword were set.
     *
     * @param array $options  Scoring options.
     * @param array $metadata Post metadata (may carry focus_keyword(s)).
     * @return string[] Normalized keyword list.
     */
    private function resolve_target_keywords(array $options, array $metadata = []): array {
        $raw = [];
        if (!empty($options['target_keywords']) && is_array($options['target_keywords'])) {
            $raw = $options['target_keywords'];
        } elseif (isset($options['target_keyword']) && $options['target_keyword'] !== '') {
            $raw = [$options['target_keyword']];
        } elseif (!$this->options_mention_keywords($options)) {
            if (!empty($metadata['focus_keywords']) && is_array($metadata['focus_keywords'])) {
                $raw = $metadata['focus_keywords'];
            } elseif (isset($metadata['focus_keyword']) && is_string($metadata['focus_keyword']) && $metadata['focus_keyword'] !== '') {
                $raw = [$metadata['focus_keyword']];
            }
        }

        $seen = [];
        $keywords = [];
        foreach ($raw as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword === '') {
                continue;
            }
            $key = strtolower($keyword);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $keywords[] = $keyword;
        }

        return $keywords;
    }

    /**
     * Whether the caller said anything about keywords — including saying
     * "none". Distinguishes an intentional clear (score with no keyword) from
     * silence (fall back to the post's stored focus keywords).
     *
     * @param array $options Scoring options.
     * @return bool True when a keyword option key is present.
     */
    private function options_mention_keywords(array $options): bool {
        return array_key_exists('target_keywords', $options)
            || array_key_exists('target_keyword', $options);
    }

    /**
     * Score each keyword independently and return the highest-scoring result.
     *
     * @param array    $content_data Content analysis data.
     * @param array    $metadata     Post metadata.
     * @param string[] $keywords     Target keywords (already normalized, 2+).
     * @param array    $options      Additional options.
     * @return array Best scoring result, with per-keyword data attached.
     */
    private function calculate_score_multi(array $content_data, array $metadata, array $keywords, array $options): array {
        $per_keyword = [];
        $best = null;
        $best_keyword = $keywords[0];

        foreach ($keywords as $keyword) {
            $opts = $options;
            unset($opts['target_keywords']);
            $opts['target_keyword'] = $keyword;

            $result = $this->compute_score($content_data, $metadata, $opts);

            $per_keyword[] = [
                'keyword' => $keyword,
                'overall_score' => $result['overall_score'],
                'grade' => $result['grade'],
                'score_breakdown' => $result['score_breakdown'],
            ];

            if ($best === null || $result['overall_score'] > $best['overall_score']) {
                $best = $result;
                $best_keyword = $keyword;
            }
        }

        // Final score = highest individual keyword score. Retain per-keyword
        // results and OR-combined checks so the UI can show both.
        $best['target_keyword'] = $best_keyword;
        $best['target_keywords'] = $keywords;
        $best['keyword_results'] = $per_keyword;
        $best['keyword_checks'] = $this->analyze_keyword_checks($content_data, $metadata, $keywords);

        return $best;
    }

    /**
     * Evaluate per-location keyword checks across ALL focus keywords.
     *
     * Each check (title, meta description, content, image alt, slug) passes when
     * ANY of the focus keywords matches that location.
     *
     * @param array    $content_data Content analysis data.
     * @param array    $metadata     Post metadata.
     * @param string[] $keywords     Target keywords.
     * @return array<string,array{passed:bool,matched_keywords:string[]}>
     */
    private function analyze_keyword_checks(array $content_data, array $metadata, array $keywords): array {
        $title = strtolower((string) ($metadata['title'] ?? $content_data['title'] ?? ''));
        $description = strtolower((string) ($metadata['description'] ?? ''));
        $content = strtolower(wp_strip_all_tags((string) ($content_data['content'] ?? '')));

        $alts = '';
        foreach ((array) ($content_data['images'] ?? []) as $image) {
            $alts .= ' ' . strtolower((string) ($image['alt'] ?? ''));
        }

        // Build a searchable slug haystack from the URL path (hyphens/underscores
        // become spaces so multi-word keywords can match).
        $path = (string) (wp_parse_url((string) ($content_data['url'] ?? ''), PHP_URL_PATH) ?? '');
        $slug = strtolower(str_replace(['-', '_', '/'], ' ', trim($path, '/')));

        $haystacks = [
            'title'            => trim($title),
            'meta_description' => trim($description),
            'content'          => trim($content),
            'image_alt'        => trim($alts),
            'slug'             => trim($slug),
        ];

        $checks = [];
        foreach ($haystacks as $location => $haystack) {
            $matched = [];
            foreach ($keywords as $keyword) {
                $needle = strtolower(trim($keyword));
                if ($needle !== '' && $haystack !== '' && strpos($haystack, $needle) !== false) {
                    $matched[] = $keyword;
                }
            }
            $checks[$location] = [
                'passed' => !empty($matched),
                'matched_keywords' => $matched,
            ];
        }

        return $checks;
    }

    /**
     * Compute the SEO score for a single target keyword.
     *
     * @param array $content_data Content analysis data
     * @param array $metadata Post metadata
     * @param array $options Additional options (expects scalar target_keyword)
     * @return array Complete scoring result
     */
    private function compute_score(array $content_data, array $metadata, array $options = []): array {
        $scores = [];
        $suggestions = [];
        $total_score = 0;

        try {
            // 1. Satisfying Content (23 points) - #1 factor in 2025
            $satisfying_result = $this->score_satisfying_content($content_data, $options['target_keyword'] ?? '', $metadata);
            $scores['satisfying_content'] = $satisfying_result;
            $total_score += $satisfying_result['score'];
            $suggestions = array_merge($suggestions, $satisfying_result['suggestions']);
        } catch (\Exception $e) {
            throw $e;
        }

        try {
            // 2. Title Optimization (14 points) - Looser keyword matching in 2025
            $title_result = $this->score_2025_title_optimization($metadata['title'] ?? '', $options['target_keyword'] ?? '');
            $scores['title_optimization'] = $title_result;
            $total_score += $title_result['score'];
            $suggestions = array_merge($suggestions, $title_result['suggestions']);
        } catch (\Exception $e) {
            throw $e;
        }

        try {
            // 3. Niche Expertise (13 points) - Hub & spoke content clusters
            $expertise_result = $this->score_niche_expertise($content_data, $options['target_keyword'] ?? '');
            $scores['niche_expertise'] = $expertise_result;
            $total_score += $expertise_result['score'];
            $suggestions = array_merge($suggestions, $expertise_result['suggestions']);
        } catch (\Exception $e) {
            throw $e;
        }

        try {
            // 4. Searcher Engagement (12 points) - Dwell time, bounce rate, pages/session
            $engagement_result = $this->score_searcher_engagement($content_data);
            $scores['searcher_engagement'] = $engagement_result;
            $total_score += $engagement_result['score'];
            $suggestions = array_merge($suggestions, $engagement_result['suggestions']);
        } catch (\Exception $e) {
            throw $e;
        }

        try {
            // 5. Backlink Authority (13 points) - Quality backlinks
            $backlink_result = $this->score_backlink_authority($content_data);
            $scores['backlink_authority'] = $backlink_result;
            $total_score += $backlink_result['score'];
            $suggestions = array_merge($suggestions, $backlink_result['suggestions']);
        } catch (\Exception $e) {
            throw $e;
        }

        // 6. Content Freshness (6 points) - Quarterly updates priority
        $freshness_result = $this->score_content_freshness($content_data);
        $scores['content_freshness'] = $freshness_result;
        $total_score += $freshness_result['score'];
        $suggestions = array_merge($suggestions, $freshness_result['suggestions']);

        // 7. Mobile Experience (5 points) - NEW: Mobile Experience Score (MES)
        $mobile_result = $this->score_mobile_experience($content_data);
        $scores['mobile_experience'] = $mobile_result;
        $total_score += $mobile_result['score'];
        $suggestions = array_merge($suggestions, $mobile_result['suggestions']);

        // 8. Trustworthiness (4 points) - E-E-A-T verification
        $trust_result = $this->score_trustworthiness($content_data, $metadata);
        $scores['trustworthiness'] = $trust_result;
        $total_score += $trust_result['score'];
        $suggestions = array_merge($suggestions, $trust_result['suggestions']);

        // 9. Link Diversity (3 points) - Multiple pages with backlinks
        $diversity_result = $this->score_link_diversity($content_data);
        $scores['link_diversity'] = $diversity_result;
        $total_score += $diversity_result['score'];
        $suggestions = array_merge($suggestions, $diversity_result['suggestions']);

        // 10. Core Web Vitals (3 points) - Interaction Readiness + CLS 2.0
        $vitals_result = $this->score_core_web_vitals($content_data);
        $scores['core_web_vitals'] = $vitals_result;
        $total_score += $vitals_result['score'];
        $suggestions = array_merge($suggestions, $vitals_result['suggestions']);

        // 11. Site Security (2 points) - SSL certificate
        $security_result = $this->score_site_security($content_data);
        $scores['site_security'] = $security_result;
        $total_score += $security_result['score'];
        $suggestions = array_merge($suggestions, $security_result['suggestions']);

        // 12. Internal Linking (1 point) - Declining importance
        $internal_result = $this->score_internal_linking($content_data);
        $scores['internal_linking'] = $internal_result;
        $total_score += $internal_result['score'];
        $suggestions = array_merge($suggestions, $internal_result['suggestions']);

        // 13. Technical Factors (1 point) - Meta descriptions, schema, etc.
        $technical_result = $this->score_technical_factors($content_data, $metadata, $options);
        $scores['technical_factors'] = $technical_result;
        $total_score += $technical_result['score'];
        $suggestions = array_merge($suggestions, $technical_result['suggestions']);

        try {
            $prioritized_suggestions = $this->prioritize_suggestions($suggestions);
            $grade = $this->get_grade_from_score($total_score);

            return [
                'overall_score' => min(100, $total_score),
                'score_breakdown' => $scores,
                'suggestions' => $prioritized_suggestions,
                'grade' => $grade,
                // Readability + content-quality labels so persisted scores
                // (e.g. bulk-analyzed on import) populate the post-list columns
                // without a manual re-analyze. The REST endpoint still overrides
                // these with live-editor values when the metabox provides them.
                'readability_score' => $this->format_readability_label($content_data),
                'content_quality' => $this->derive_content_quality($content_data),
                'calculated_at' => current_time('mysql'),
                'algorithm_version' => '2025.2',
                'algorithm_source' => 'First Page Sage Q1 2025 Research',
                'factors_count' => count($scores),
            ];
        } catch (\Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Score satisfying content - #1 factor in 2025 (23 points)
     * Google tests content to see if it satisfies search intent
     *
     * @param array $content_data Content analysis data
     * @param string $target_keyword Target keyword
     * @param array $metadata Post metadata (title, description)
     * @return array Scoring result
     */
    private function score_satisfying_content(array $content_data, string $target_keyword, array $metadata = []): array {
        $score = 0;
        $max_score = $this->scoring_factors['satisfying_content'];
        $suggestions = [];

        $content = $content_data['content'] ?? '';
        $word_count = $content_data['word_count'] ?? 0;
        $meta_description = (string) ($metadata['description'] ?? '');

        // Content depth and comprehensiveness (8 points). Tiers softened so a
        // genuinely useful post is not capped the way the old 2000-word gate did
        // (Rank Math awards full content credit well below 2000 words).
        if ($word_count >= 1500) {
            $score += 8;
        } elseif ($word_count >= 1000) {
            $score += 7;
            $suggestions[] = 'Consider expanding content to 1500+ words for more comprehensive coverage';
        } elseif ($word_count >= 600) {
            $score += 5;
            $suggestions[] = 'Content is adequate - aim for 1000+ words for stronger topic depth';
        } elseif ($word_count >= 300) {
            $score += 3;
            $suggestions[] = 'Content is thin - aim for 600+ words minimum';
        } else {
            $score += 1;
            $suggestions[] = 'Content too shallow - Google prioritizes comprehensive, satisfying content';
        }

        // Keyword presence & placement (8 points) - deterministic, replaces the
        // old literal-phrase intent heuristic ("what is"/"because"). Measures
        // signals the editor actually controls: body (3), first paragraph (3),
        // meta description (2) - the last mirrors Rank Math's "keyword in meta
        // description" basic-SEO check.
        if (empty($target_keyword)) {
            $score += 4; // Benefit of the doubt when no focus keyword is set.
            $suggestions[] = 'Set a focus keyword so content relevance can be measured';
        } else {
            if ($this->keyword_in_content($content, $target_keyword)) {
                $score += 3;
            } else {
                $suggestions[] = "Use the focus keyword '{$target_keyword}' in the body content";
            }
            if ($this->keyword_in_first_paragraph($content, $target_keyword)) {
                $score += 3;
            } else {
                $suggestions[] = "Mention '{$target_keyword}' near the start of the content (first paragraph)";
            }
            if ($this->keyword_in_meta($meta_description, $target_keyword)) {
                $score += 2;
            } else {
                $suggestions[] = "Include the focus keyword '{$target_keyword}' in the meta description";
            }
        }

        // Content structure & value (7 points) - reuses the deterministic
        // content-quality signal (length, paragraph length, subheading
        // distribution) instead of the noisy sentence-length variety heuristic.
        $quality = $this->derive_content_quality_score($content_data); // 0-100
        $score += (int) round(($quality / 100) * 7);

        if ($quality < 60) {
            $suggestions[] = 'Improve content structure - break up long paragraphs and add subheadings';
        }

        return [
            'score' => $score,
            'max_score' => $max_score,
            'suggestions' => $suggestions,
            'details' => [
                'word_count' => $word_count,
                'keyword_in_content' => !empty($target_keyword) && $this->keyword_in_content($content, $target_keyword),
                'keyword_in_first_paragraph' => !empty($target_keyword) && $this->keyword_in_first_paragraph($content, $target_keyword),
                'keyword_in_meta_description' => !empty($target_keyword) && $this->keyword_in_meta($meta_description, $target_keyword),
                'content_quality_score' => $quality,
                'content_depth' => $this->assess_content_depth_2025($word_count),
            ]
        ];
    }

    /**
     * Assess content depth for 2025 standards
     *
     * @param int $word_count Word count
     * @return string Depth assessment
     */
    private function assess_content_depth_2025(int $word_count): string {
        if ($word_count >= 3000) return 'Comprehensive';
        if ($word_count >= 2000) return 'Detailed';
        if ($word_count >= 1200) return 'Adequate';
        if ($word_count >= 800) return 'Basic';
        return 'Insufficient';
    }

    /**
     * Score 2025 title optimization with looser keyword matching
     *
     * @param string $title Post title
     * @param string $target_keyword Target keyword
     * @return array Scoring result
     */
    private function score_2025_title_optimization(string $title, string $target_keyword): array {
        $score = 0;
        $max_score = $this->scoring_factors['title_optimization'];
        $suggestions = [];

        if (empty($title)) {
            $suggestions[] = 'Add a compelling, click-worthy title that matches search intent';
            return ['score' => 0, 'max_score' => $max_score, 'suggestions' => $suggestions];
        }

        $title_length = mb_strlen($title);

        // 2025 length optimization (6 points). 60 characters is the recommended
        // maximum for best SERP visibility before Google truncates the title.
        if ($title_length >= 35 && $title_length <= 60) {
            $score += 6;
        } elseif ($title_length >= 25 && $title_length <= 75) {
            $score += 4;
            $suggestions[] = 'Optimize title length to 35-60 characters for better SERP visibility';
        } else {
            $score += 1;
            $suggestions[] = $title_length < 25 ?
                'Title too short - aim for 35-60 characters' :
                'Title too long - risk truncation in search results';
        }

        // Looser keyword matching (6 points) - 2025 update
        if (!empty($target_keyword)) {
            $title_lower = strtolower($title);
            $keyword_lower = strtolower($target_keyword);

            // Exact match
            if (strpos($title_lower, $keyword_lower) !== false) {
                $score += 6;
            } else {
                // Check for semantic variations (2025 improvement)
                $semantic_match = $this->check_semantic_keyword_match($title, $target_keyword);
                if ($semantic_match) {
                    $score += 5; // Almost full credit for semantic match
                    $suggestions[] = 'Good semantic keyword usage - Google now recognizes keyword variations';
                } else {
                    // Check for partial keyword match
                    $keyword_parts = explode(' ', $keyword_lower);
                    $partial_matches = 0;
                    foreach ($keyword_parts as $part) {
                        if (strpos($title_lower, $part) !== false) {
                            $partial_matches++;
                        }
                    }

                    if ($partial_matches > 0) {
                        $score += round(($partial_matches / count($keyword_parts)) * 4);
                        $suggestions[] = "Include more parts of target keyword '{$target_keyword}' in title";
                    } else {
                        $suggestions[] = "Include target keyword '{$target_keyword}' or related terms in title";
                    }
                }
            }
        } else {
            $score += 2; // Partial credit
            $suggestions[] = 'Set a target keyword to optimize title effectiveness';
        }

        // Title readability (2 points) - mirrors Rank Math's title checks for a
        // number/power word (drives CTR) and emotional sentiment.
        $has_number = (bool) preg_match('/\d/', $title);
        $has_power_word = $this->title_has_power_word($title);
        $has_sentiment = $this->title_has_sentiment_word($title);

        if ($has_number || $has_power_word) {
            $score += 1;
        } else {
            $suggestions[] = 'Add a number or a power word to the title to boost click-through rate';
        }
        if ($has_sentiment) {
            $score += 1;
        } else {
            $suggestions[] = 'Use an emotional/sentiment word in the title to make it more compelling';
        }

        return [
            'score' => $score,
            'max_score' => $max_score,
            'suggestions' => $suggestions,
            'details' => [
                'title_length' => $title_length,
                'optimal_range' => '35-60 characters',
                'keyword_present' => !empty($target_keyword) && strpos(strtolower($title), strtolower($target_keyword)) !== false,
                'semantic_match' => !empty($target_keyword) ? $this->check_semantic_keyword_match($title, $target_keyword) : false,
                'has_number_or_power_word' => $has_number || $has_power_word,
                'has_sentiment_word' => $has_sentiment,
            ]
        ];
    }

    // Placeholder methods for remaining 2025 factors

    private function score_niche_expertise(array $content_data, string $target_keyword): array {
        $max_score = $this->scoring_factors['niche_expertise'];
        $suggestions = [];

        // Without a focus keyword we cannot measure topical coverage; award
        // partial credit rather than capping the ceiling with a placeholder.
        if (empty($target_keyword)) {
            return [
                'score' => 7,
                'max_score' => $max_score,
                'suggestions' => ['Set a focus keyword and use it in subheadings and the URL for stronger topical signals'],
                'details' => ['expertise_level' => 'Unmeasured (no focus keyword)'],
            ];
        }

        $score = 0;
        $content = (string) ($content_data['content'] ?? '');
        $headings = (array) ($content_data['headings'] ?? []);
        $images = (array) ($content_data['images'] ?? []);
        $slug = strtolower((string) ($content_data['slug'] ?? ''));

        // Keyword in a subheading (4 points).
        if ($this->keyword_in_subheadings($headings, $target_keyword)) {
            $score += 4;
        } else {
            $suggestions[] = "Include '{$target_keyword}' in at least one subheading (H2-H6)";
        }

        // Keyword density in a healthy band (4 points). Rank Math treats
        // ~0.5%-2.5% as optimal; reward in-band, partial when present but thin.
        $density = $this->keyword_density($content, $target_keyword);
        if ($density >= 0.5 && $density <= 2.5) {
            $score += 4;
        } elseif ($density > 0) {
            $score += 2;
            $suggestions[] = $density > 2.5
                ? 'Keyword density is high - reduce repetition to avoid over-optimization'
                : 'Keyword density is low - use the focus keyword a little more often';
        } else {
            $suggestions[] = "Use the focus keyword '{$target_keyword}' in the content";
        }

        // URL optimization (3 points): keyword in slug (2) + a reasonably short
        // URL (1). Rank Math flags overly long URLs, so reward concise slugs.
        $keyword_slug = str_replace(' ', '-', strtolower($target_keyword));
        $keyword_in_slug = $slug !== '' && (strpos($slug, $keyword_slug) !== false || strpos(str_replace('-', '', $slug), str_replace('-', '', $keyword_slug)) !== false);
        if ($keyword_in_slug) {
            $score += 2;
        } else {
            $suggestions[] = 'Include the focus keyword in the URL slug';
        }

        // A slug under ~75 chars keeps the URL clean and fully visible in SERPs.
        $slug_length = strlen($slug);
        if ($slug === '' || $slug_length <= 75) {
            $score += 1;
        } else {
            $suggestions[] = 'Shorten the URL slug - long URLs are harder to read and share';
        }

        // Keyword in image alt text (2 points) - mirrors Rank Math's
        // "keyword in image alt" check. When the post has no images the check
        // does not apply, so award the points (benefit of the doubt) rather
        // than capping the ceiling for legitimately image-less posts.
        if (empty($images)) {
            $score += 2;
            $suggestions[] = 'Add a relevant image with the focus keyword in its alt text';
        } elseif ($this->keyword_in_alt($images, $target_keyword)) {
            $score += 2;
        } else {
            $suggestions[] = 'Include the focus keyword in at least one image alt attribute';
        }

        return [
            'score' => $score,
            'max_score' => $max_score,
            'suggestions' => $suggestions,
            'details' => [
                'keyword_in_subheading' => $this->keyword_in_subheadings($headings, $target_keyword),
                'keyword_density' => round($density, 2),
                'keyword_in_slug' => $keyword_in_slug,
                'slug_length' => $slug_length,
                'keyword_in_image_alt' => $this->keyword_in_alt($images, $target_keyword),
            ],
        ];
    }

    private function score_searcher_engagement(array $content_data): array {
        $max_score = $this->scoring_factors['searcher_engagement'];

        // Engagement (dwell time / bounce) is off-page, so estimate it from the
        // on-page signals that drive it: readability (half) + content structure
        // (half). This raises the old readability-only floor.
        $readability = (float) ($content_data['readability_score'] ?? 50);
        $structure = (float) $this->derive_content_quality_score($content_data);

        $readability_pts = ($readability / 100) * ($max_score / 2);
        $structure_pts = ($structure / 100) * ($max_score / 2);
        $score = (int) round($readability_pts + $structure_pts);

        return [
            'score' => $score,
            'max_score' => $max_score,
            'suggestions' => $score < ($max_score * 0.7)
                ? ['Improve readability and structure (shorter sentences, subheadings, shorter paragraphs)']
                : [],
            'details' => ['engagement_estimate' => round(($score / $max_score) * 100, 1) . '%'],
        ];
    }

    private function score_backlink_authority(array $content_data): array {
        $max_score = $this->scoring_factors['backlink_authority'];
        $external_links = (int) ($content_data['external_links'] ?? 0);
        $dofollow_links = (int) ($content_data['external_dofollow_links'] ?? 0);

        // Backlinks are off-page and cannot be read from post content. We use
        // the only on-page proxy available - whether the content cites external
        // sources - and avoid hard-penalizing posts for something outside the
        // editor's control (the old external_links*3 formula needed 5 outbound
        // links just to reach full marks, dragging nearly every post down).
        // Dofollow links pass equity, so they earn full credit (Rank Math's
        // "external dofollow link" check); nofollow-only citations earn less.
        $suggestions = [];
        if ($dofollow_links >= 2) {
            $score = $max_score;
        } elseif ($dofollow_links === 1) {
            $score = (int) round($max_score * 0.85);
        } elseif ($external_links > 0) {
            // Cites sources but every external link is nofollow.
            $score = (int) round($max_score * 0.75);
            $suggestions[] = 'Add at least one dofollow link to an authoritative external source';
        } else {
            $score = (int) round($max_score * 0.6);
            $suggestions[] = 'Cite authoritative external sources, and build quality backlinks to this page';
        }

        return [
            'score' => $score,
            'max_score' => $max_score,
            'suggestions' => $suggestions,
            'details' => [
                'external_links' => $external_links,
                'external_dofollow_links' => $dofollow_links,
            ],
        ];
    }
    private function score_trustworthiness(array $content_data, array $metadata): array {
        // E-E-A-T is an off-page/site-wide signal we cannot reliably measure
        // from a single post. Award full credit (benefit of the doubt) rather
        // than a fixed partial that silently caps every post's ceiling.
        return [
            'score' => $this->scoring_factors['trustworthiness'],
            'max_score' => $this->scoring_factors['trustworthiness'],
            'suggestions' => ['Add author credentials, citations, and contact information to reinforce trustworthiness'],
            'details' => ['trust_level' => 'Assumed adequate'],
        ];
    }

    private function score_link_diversity(array $content_data): array {
        // Off-page link distribution; not measurable per post. Full credit.
        return [
            'score' => $this->scoring_factors['link_diversity'],
            'max_score' => $this->scoring_factors['link_diversity'],
            'suggestions' => [],
            'details' => ['diversity_level' => 'Assumed adequate'],
        ];
    }
    private function score_site_security(array $content_data): array {
        $max_score = $this->scoring_factors['site_security'];
        $ssl = function_exists('is_ssl') ? is_ssl() : true;

        return [
            'score' => $ssl ? $max_score : 0,
            'max_score' => $max_score,
            'suggestions' => $ssl ? [] : ['Serve the site over HTTPS (install an SSL certificate)'],
            'details' => ['ssl_enabled' => $ssl, 'security_level' => $ssl ? 'Good' : 'Insecure'],
        ];
    }
    private function check_semantic_keyword_match(string $text, string $keyword): bool {
        // Simple semantic matching - can be enhanced with AI/NLP
        $keyword_parts = explode(' ', strtolower($keyword));
        $text_lower = strtolower($text);
        
        $matches = 0;
        foreach ($keyword_parts as $part) {
            if (strpos($text_lower, $part) !== false) {
                $matches++;
            }
        }
        
        // Consider it a semantic match if 70% of keyword parts are present
        return ($matches / count($keyword_parts)) >= 0.7;
    }
    private function prioritize_suggestions(array $suggestions): array {
        $prioritized = [];
        
        foreach ($suggestions as $suggestion) {
            $priority = $this->determine_suggestion_priority($suggestion);
            $prioritized[] = [
                'text' => $suggestion,
                'priority' => $priority,
                'impact' => $this->estimate_impact($suggestion),
                'effort' => $this->estimate_effort($suggestion),
            ];
        }
        
        // Sort by priority (High > Medium > Low)
        usort($prioritized, function($a, $b) {
            $priority_order = ['High' => 3, 'Medium' => 2, 'Low' => 1];
            return $priority_order[$b['priority']] - $priority_order[$a['priority']];
        });
        
        return $prioritized;
    }
    
    /**
     * Determine suggestion priority based on content
     * 
     * @param string $suggestion Suggestion text
     * @return string Priority level
     */
    private function determine_suggestion_priority(string $suggestion): string {
        $high_priority_keywords = ['title', 'keyword', 'content quality', 'heading'];
        $medium_priority_keywords = ['meta description', 'internal link', 'readability'];
        
        $suggestion_lower = strtolower($suggestion);
        
        foreach ($high_priority_keywords as $keyword) {
            if (strpos($suggestion_lower, $keyword) !== false) {
                return 'High';
            }
        }
        
        foreach ($medium_priority_keywords as $keyword) {
            if (strpos($suggestion_lower, $keyword) !== false) {
                return 'Medium';
            }
        }
        
        return 'Low';
    }
    
    /**
     * Estimate impact of implementing suggestion
     * 
     * @param string $suggestion Suggestion text
     * @return string Impact level
     */
    private function estimate_impact(string $suggestion): string {
        // Simple heuristic - can be enhanced with ML
        if (strpos(strtolower($suggestion), 'title') !== false) return 'High';
        if (strpos(strtolower($suggestion), 'content') !== false) return 'High';
        if (strpos(strtolower($suggestion), 'keyword') !== false) return 'Medium';
        return 'Low';
    }
    
    /**
     * Estimate effort required to implement suggestion
     *
     * @param string $suggestion Suggestion text
     * @return string Effort level
     */
    private function estimate_effort(string $suggestion): string {
        // Simple heuristic - can be enhanced with ML
        if (strpos(strtolower($suggestion), 'rewrite') !== false) return 'High';
        if (strpos(strtolower($suggestion), 'add') !== false) return 'Medium';
        if (strpos(strtolower($suggestion), 'optimize') !== false) return 'Medium';
        return 'Low';
    }
    private function calculate_topic_relevance(string $content, string $target_keyword): float {
        if (empty($content) || empty($target_keyword)) {
            return 0.0;
        }

        $content_lower = strtolower(wp_strip_all_tags($content));
        $keyword_lower = strtolower($target_keyword);

        // Calculate keyword and semantic term frequency
        $keyword_count = substr_count($content_lower, $keyword_lower);
        $word_count = $this->calculate_word_count_js_style($content_lower);

        if ($word_count === 0) {
            return 0.0;
        }

        // Base relevance from keyword presence
        $keyword_density = ($keyword_count / $word_count) * 100;
        $base_relevance = min(1.0, $keyword_density / 2.0); // Optimal around 1-2%

        // Boost for semantic variations
        $semantic_boost = $this->calculate_semantic_boost($content_lower, $keyword_lower);

        return min(1.0, $base_relevance + $semantic_boost);
    }

    /**
     * Calculate semantic boost for related terms
     *
     * @param string $content Content text (lowercase)
     * @param string $keyword Target keyword (lowercase)
     * @return float Semantic boost (0-0.3)
     */
    private function calculate_semantic_boost(string $content, string $keyword): float {
        // Simple semantic term detection - can be enhanced with NLP
        $semantic_terms = $this->get_semantic_terms($keyword);
        $boost = 0.0;

        foreach ($semantic_terms as $term) {
            if (strpos($content, $term) !== false) {
                $boost += 0.05; // Small boost per semantic term
            }
        }

        return min(0.3, $boost); // Cap at 30% boost
    }

    /**
     * Get semantic terms for a keyword
     *
     * @param string $keyword Target keyword
     * @return array Semantic terms
     */
    private function get_semantic_terms(string $keyword): array {
        // Simple semantic term generation - can be enhanced with AI/NLP
        $terms = [];

        // Add plural/singular variations
        if (substr($keyword, -1) === 's') {
            $terms[] = rtrim($keyword, 's');
        } else {
            $terms[] = $keyword . 's';
        }

        // Add common related terms based on keyword
        $keyword_lower = strtolower($keyword);

        // SEO-related terms
        if (strpos($keyword_lower, 'seo') !== false) {
            $terms = array_merge($terms, ['optimization', 'search engine', 'ranking', 'visibility']);
        }

        // WordPress-related terms
        if (strpos($keyword_lower, 'wordpress') !== false) {
            $terms = array_merge($terms, ['wp', 'plugin', 'theme', 'cms']);
        }

        return $terms;
    }

    /**
     * Keyword density (%) of the target keyword across the plain-text body.
     *
     * @param string $content        Raw/HTML content.
     * @param string $target_keyword Target keyword.
     * @return float Density percentage (0 when no keyword/content).
     */
    private function keyword_density(string $content, string $target_keyword): float {
        if (empty($content) || empty($target_keyword)) {
            return 0.0;
        }
        $plain = strtolower(wp_strip_all_tags($content));
        $word_count = $this->calculate_word_count_js_style($plain);
        if ($word_count === 0) {
            return 0.0;
        }
        $occurrences = substr_count($plain, strtolower($target_keyword));
        return ($occurrences / $word_count) * 100;
    }

    /**
     * Whether the target keyword (or a semantic variation) appears in the body.
     *
     * @param string $content        Raw/HTML content.
     * @param string $target_keyword Target keyword.
     * @return bool
     */
    private function keyword_in_content(string $content, string $target_keyword): bool {
        if (empty($content) || empty($target_keyword)) {
            return false;
        }
        $plain = strtolower(wp_strip_all_tags($content));
        if (strpos($plain, strtolower($target_keyword)) !== false) {
            return true;
        }
        return $this->check_semantic_keyword_match($plain, $target_keyword);
    }

    /**
     * Whether the keyword appears early (first paragraph / first ~10% of words).
     *
     * @param string $content        Raw/HTML content.
     * @param string $target_keyword Target keyword.
     * @return bool
     */
    private function keyword_in_first_paragraph(string $content, string $target_keyword): bool {
        if (empty($content) || empty($target_keyword)) {
            return false;
        }
        $plain = strtolower(wp_strip_all_tags($content));
        $words = preg_split('/\s+/', trim($plain), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $window = array_slice($words, 0, max(50, (int) ceil(count($words) * 0.1)));
        return strpos(implode(' ', $window), strtolower($target_keyword)) !== false;
    }

    /**
     * Whether the keyword appears in any subheading (H2–H6).
     *
     * @param array  $headings       Extracted headings (each with 'level' + 'text').
     * @param string $target_keyword Target keyword.
     * @return bool
     */
    private function keyword_in_subheadings(array $headings, string $target_keyword): bool {
        if (empty($target_keyword)) {
            return false;
        }
        $keyword_lower = strtolower($target_keyword);
        foreach ($headings as $heading) {
            if ((int) ($heading['level'] ?? 0) < 2) {
                continue;
            }
            $text = strtolower((string) ($heading['text'] ?? ''));
            if ($text !== '' && strpos($text, $keyword_lower) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Days elapsed since a MySQL datetime string, or null when unparseable.
     *
     * @param string $datetime MySQL datetime (e.g. post_modified).
     * @return int|null
     */
    private function days_since(string $datetime): ?int {
        $datetime = trim($datetime);
        if ($datetime === '' || strpos($datetime, '0000-00-00') === 0) {
            return null;
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return null;
        }
        $now = function_exists('current_time') ? (int) current_time('timestamp') : time();
        return (int) floor(($now - $ts) / 86400);
    }

    /**
     * Whether the keyword appears in the meta description.
     *
     * @param string $meta_description Meta description text.
     * @param string $target_keyword  Target keyword.
     * @return bool
     */
    private function keyword_in_meta(string $meta_description, string $target_keyword): bool {
        if ($meta_description === '' || $target_keyword === '') {
            return false;
        }
        return strpos(strtolower($meta_description), strtolower($target_keyword)) !== false;
    }

    /**
     * Whether the keyword appears in any image alt text.
     *
     * @param array  $images         Images (each with an 'alt' key).
     * @param string $target_keyword Target keyword.
     * @return bool
     */
    private function keyword_in_alt(array $images, string $target_keyword): bool {
        if ($target_keyword === '') {
            return false;
        }
        $keyword_lower = strtolower($target_keyword);
        foreach ($images as $image) {
            $alt = strtolower((string) ($image['alt'] ?? ''));
            if ($alt !== '' && strpos($alt, $keyword_lower) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the title contains a common power word (CTR booster).
     *
     * @param string $title Post title.
     * @return bool
     */
    private function title_has_power_word(string $title): bool {
        $title_lower = strtolower($title);
        foreach (self::get_title_power_words() as $word) {
            if (strpos($title_lower, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The power words rewarded by the title check. Single source of truth so the
     * AI title improver can require the generated title to actually contain one.
     *
     * @return string[]
     */
    public static function get_title_power_words(): array {
        return [
            'ultimate', 'essential', 'complete', 'proven', 'guide', 'best', 'top',
            'free', 'easy', 'simple', 'quick', 'fast', 'powerful', 'secret', 'expert',
            'effective', 'amazing', 'incredible', 'exclusive', 'definitive', 'step-by-step',
        ];
    }

    /**
     * The emotion/sentiment words rewarded by the title check. Single source of
     * truth shared with the AI title improver so a generated title satisfies the
     * same validation.
     *
     * @return string[]
     */
    public static function get_title_sentiment_words(): array {
        return [
            // Positive
            'great', 'good', 'better', 'awesome', 'love', 'win', 'boost', 'improve',
            'success', 'smart', 'brilliant', 'perfect', 'happy', 'beautiful',
            // Negative (drives clicks too)
            'avoid', 'mistake', 'worst', 'stop', 'never', 'bad', 'wrong', 'fail',
            'danger', 'warning', 'painful', 'ugly',
        ];
    }

    /**
     * Whether the title carries an emotional/sentiment word (positive or
     * negative), which Rank Math rewards for higher engagement.
     *
     * @param string $title Post title.
     * @return bool
     */
    private function title_has_sentiment_word(string $title): bool {
        $title_lower = strtolower($title);
        foreach (self::get_title_sentiment_words() as $word) {
            if (strpos($title_lower, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Assess content depth based on word count
     *
     * @param int $word_count Word count
     * @return string Depth assessment
     */
    private function assess_content_depth(int $word_count): string {
        if ($word_count >= 2000) return 'Comprehensive';
        if ($word_count >= 1000) return 'Detailed';
        if ($word_count >= 500) return 'Moderate';
        if ($word_count >= 300) return 'Basic';
        return 'Insufficient';
    }
    private function score_content_freshness(array $content_data): array {
        $max_score = $this->scoring_factors['content_freshness'];
        $suggestions = [];

        // Derive freshness from the real last-modified date when available
        // (live editing has no stored date yet -> treat as fresh).
        $days = $this->days_since((string) ($content_data['post_modified'] ?? ''));

        if ($days === null || $days <= 180) {
            $score = $max_score;
            $status = 'Current';
        } elseif ($days <= 365) {
            $score = (int) round($max_score * 0.66);
            $status = 'Aging';
            $suggestions[] = 'Content is 6-12 months old - review and refresh it for better freshness signals';
        } else {
            $score = (int) round($max_score * 0.33);
            $status = 'Stale';
            $suggestions[] = 'Content is over a year old - update it to maintain freshness signals';
        }

        return [
            'score' => $score,
            'max_score' => $max_score,
            'suggestions' => $suggestions,
            'details' => [
                'freshness_status' => $status,
                'days_since_modified' => $days,
            ],
        ];
    }
    /**
     * Resolve a post's content into something worth analyzing.
     *
     * Delegates to Builder_Content, which knows where each page builder keeps
     * its text. Kept as the historical entry point for existing callers.
     *
     * @since 1.23.0
     *
     * @param \WP_Post $post Post being analyzed.
     * @return string Content to analyze.
     */
    public static function resolve_analyzable_content(\WP_Post $post): string {
        if (!class_exists('\ThinkRank\SEO\Builder_Content')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-builder-content.php';
        }

        return \ThinkRank\SEO\Builder_Content::resolve($post);
    }

    /**
     * Resolve editor-supplied live content into something worth analyzing.
     *
     * @since 1.23.0
     *
     * @param string   $live_content Markup supplied by the editor.
     * @param \WP_Post $post         Post the markup belongs to.
     * @return string Content to analyze.
     */
    public static function resolve_live_content(string $live_content, \WP_Post $post): string {
        if (!class_exists('\ThinkRank\SEO\Builder_Content')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-builder-content.php';
        }

        return \ThinkRank\SEO\Builder_Content::resolve_markup($live_content, $post);
    }

    public function analyze_post_content(int $post_id): array {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $content = self::resolve_analyzable_content($post);
        $title = $post->post_title;

        // Extract headings from content
        $headings = $this->extract_headings($content);

        // Count words using JavaScript-compatible method
        $plain_text = wp_strip_all_tags($content);
        $word_count = $this->calculate_word_count_js_style($plain_text);

        // Calculate readability
        $readability_score = $this->calculate_readability_score($content);

        // Count links
        $internal_links = $this->count_internal_links($content);
        $external_links = $this->count_external_links($content);
        $external_dofollow_links = $this->count_external_dofollow_links($content);

        // Analyze images
        $images = $this->analyze_images($content);

        // Get URL
        $url = get_permalink($post_id);

        return [
            'content' => $content,
            'title' => $title,
            'headings' => $headings,
            'word_count' => $word_count,
            'readability_score' => $readability_score,
            'internal_links' => $internal_links,
            'external_links' => $external_links,
            'external_dofollow_links' => $external_dofollow_links,
            'images' => $images,
            'url' => $url,
            'slug' => $post->post_name,
            'post_modified' => $post->post_modified,
            'schema_present' => $this->detect_schema_present($content) || $this->thinkrank_global_schema_active($post->post_type),
        ];
    }

    /**
     * Build the human-readable readability label (mirrors the editor's
     * calculateReadabilityScore: "<level> (<flesch>)") from analyzed content.
     *
     * @param array $content_data Output of analyze_post_content()/analyze_live_content()
     * @return string Readability label, e.g. "Standard (62)"
     */
    private function format_readability_label(array $content_data): string {
        if ((int) ($content_data['word_count'] ?? 0) === 0) {
            return 'No content';
        }

        $rounded = (int) round((float) ($content_data['readability_score'] ?? 0));

        if ($rounded >= 90) {
            $level = 'Very Easy';
        } elseif ($rounded >= 80) {
            $level = 'Easy';
        } elseif ($rounded >= 70) {
            $level = 'Fairly Easy';
        } elseif ($rounded >= 60) {
            $level = 'Standard';
        } elseif ($rounded >= 50) {
            $level = 'Fairly Difficult';
        } elseif ($rounded >= 30) {
            $level = 'Difficult';
        } else {
            $level = 'Very Difficult';
        }

        return "{$level} ({$rounded})";
    }

    /**
     * Derive the content-quality label (mirrors the editor's
     * calculateContentQuality: word count + long-paragraph + subheading scoring)
     * so persisted scores carry a non-null quality value.
     *
     * @param array $content_data Output of analyze_post_content()/analyze_live_content()
     * @return string One of: No content, Good, OK, Needs improvement
     */
    private function derive_content_quality(array $content_data): string {
        $word_count = (int) ($content_data['word_count'] ?? 0);
        if ($word_count === 0) {
            return 'No content';
        }

        $final = $this->derive_content_quality_score($content_data);

        if ($final >= 80) {
            return 'Good';
        }
        if ($final >= 50) {
            return 'OK';
        }

        return 'Needs improvement';
    }

    /**
     * Numeric content-quality score (0-100): word count + long-paragraph +
     * subheading distribution. Shared by the quality label and the
     * satisfying-content factor so both stay in sync.
     *
     * @param array $content_data Output of analyze_post_content()/analyze_live_content()
     * @return int Quality score 0-100.
     */
    private function derive_content_quality_score(array $content_data): int {
        $word_count = (int) ($content_data['word_count'] ?? 0);
        if ($word_count === 0) {
            return 0;
        }

        $content = (string) ($content_data['content'] ?? '');
        $score = 0;

        // 1. Word count (industry standard: 300+ words).
        if ($word_count >= 600) {
            $score += 100;
        } elseif ($word_count >= 300) {
            $score += 50;
        }

        // 2. Long paragraphs (flag paragraphs over 150 words).
        $long_paragraphs = 0;
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $content, $matches)) {
            foreach ($matches[1] as $paragraph) {
                if ($this->calculate_word_count_js_style(wp_strip_all_tags($paragraph)) > 150) {
                    $long_paragraphs++;
                }
            }
        }
        if ($long_paragraphs === 0) {
            $score += 100;
        } elseif ($long_paragraphs <= 2) {
            $score += 50;
        }

        // 3. Subheading distribution (H2–H6, ~one per 300 words).
        $subheadings = 0;
        foreach ((array) ($content_data['headings'] ?? []) as $heading) {
            if ((int) ($heading['level'] ?? 0) >= 2) {
                $subheadings++;
            }
        }
        $expected = (int) floor($word_count / 300);
        if ($subheadings > 0 && $subheadings >= $expected) {
            $score += 100;
        } elseif ($subheadings > 0) {
            $score += 50;
        }

        return (int) round($score / 3);
    }

    /**
     * Extract headings from content
     *
     * @param string $content Content HTML
     * @return array Array of headings with levels
     */
    private function extract_headings(string $content): array {
        $headings = [];

        // Match H1-H6 tags
        if (preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $headings[] = [
                    'level' => (int)$match[1],
                    'text' => wp_strip_all_tags($match[2]),
                ];
            }
        }

        return $headings;
    }

    /**
     * Calculate readability score using Flesch Reading Ease
     *
     * @param string $content Content text
     * @return float Readability score
     */
    private function calculate_readability_score(string $content): float {
        $text = wp_strip_all_tags($content);

        if (empty($text)) {
            return 0;
        }

        // Count sentences (approximate)
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $sentence_count = count($sentences);

        // Count words
        $word_count = $this->calculate_word_count_js_style(wp_strip_all_tags($text));

        // Count syllables (approximate)
        $syllable_count = $this->count_syllables($text);

        if ($sentence_count === 0 || $word_count === 0) {
            return 0;
        }

        // Flesch Reading Ease formula
        $score = 206.835 - (1.015 * ($word_count / $sentence_count)) - (84.6 * ($syllable_count / $word_count));

        return max(0, min(100, $score));
    }

    /**
     * Count syllables in text (approximate)
     *
     * @param string $text Text to analyze
     * @return int Syllable count
     */
    private function count_syllables(string $text): int {
        $words = preg_split('/\s+/', trim(strtolower(wp_strip_all_tags($text))), -1, PREG_SPLIT_NO_EMPTY);
        $syllables = 0;

        foreach ($words as $word) {
            $syllables += max(1, preg_match_all('/[aeiouy]+/', $word));
        }

        return $syllables;
    }

    /**
     * Count internal links in content
     *
     * @param string $content Content HTML
     * @return int Internal link count
     */
    private function count_internal_links(string $content): int {
        $site_url = get_site_url();
        $count = 0;

        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                if (strpos($url, $site_url) !== false || strpos($url, '/') === 0) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Count external links in content
     *
     * @param string $content Content HTML
     * @return int External link count
     */
    private function count_external_links(string $content): int {
        $site_url = get_site_url();
        $count = 0;

        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                if (strpos($url, 'http') === 0 && strpos($url, $site_url) === false) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Count external links that pass link equity (not rel="nofollow").
     * Mirrors Rank Math's "external dofollow link" check.
     *
     * @param string $content Content HTML
     * @return int External dofollow link count
     */
    private function count_external_dofollow_links(string $content): int {
        $site_url = get_site_url();
        $count = 0;

        if (preg_match_all('/<a\b[^>]*>/i', $content, $matches)) {
            foreach ($matches[0] as $tag) {
                if (!preg_match('/href=["\']([^"\']+)["\']/i', $tag, $href)) {
                    continue;
                }
                $url = $href[1];
                $is_external = strpos($url, 'http') === 0 && strpos($url, $site_url) === false;
                if (!$is_external) {
                    continue;
                }
                if (preg_match('/rel=["\'][^"\']*\bnofollow\b[^"\']*["\']/i', $tag)) {
                    continue;
                }
                $count++;
            }
        }

        return $count;
    }

    /**
     * Analyze images in content
     *
     * @param string $content Content HTML
     * @return array Image analysis data
     */
    /**
     * Detect structured data embedded directly in the content (JSON-LD script
     * blocks or microdata attributes). Site-wide schema injected at render time
     * is a separate feature and intentionally out of scope here.
     *
     * @param string $content Raw/HTML content.
     * @return bool
     */
    private function detect_schema_present(string $content): bool {
        if ($content === '') {
            return false;
        }
        return stripos($content, 'application/ld+json') !== false
            || stripos($content, 'itemscope') !== false
            || stripos($content, 'itemtype') !== false;
    }

    /**
     * Whether ThinkRank's Global SEO schema output is active for a post type.
     *
     * ThinkRank injects JSON-LD at render time (wp_head) when a schema type is
     * configured for the post type, so a post can have valid structured data
     * even when none is embedded in the post body. The score credits this so the
     * "add structured data" suggestion reflects ThinkRank's own schema engine.
     *
     * @param string $post_type Post type slug.
     * @return bool
     */
    private function thinkrank_global_schema_active(string $post_type): bool {
        if ($post_type === '') {
            return false;
        }
        $all_settings = get_option('thinkrank_global_seo_settings', []);
        return !empty($all_settings[$post_type]['schema_type']);
    }

    private function analyze_images(string $content): array {
        $images = [];

        if (preg_match_all('/<img[^>]+>/i', $content, $matches)) {
            foreach ($matches[0] as $img_tag) {
                $alt = '';
                if (preg_match('/alt=["\']([^"\']*)["\']/', $img_tag, $alt_match)) {
                    $alt = $alt_match[1];
                }

                $src = '';
                if (preg_match('/src=["\']([^"\']*)["\']/', $img_tag, $src_match)) {
                    $src = $src_match[1];
                }

                $images[] = [
                    'src' => $src,
                    'alt' => $alt,
                ];
            }
        }

        return $images;
    }

    /**
     * Save SEO score to database
     *
     * @param int $post_id Post ID
     * @param int $user_id User ID
     * @param array $score_data Score data
     * @return int|false Score ID or false on failure
     */
    public function save_score(int $post_id, int $user_id, array $score_data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_seo_scores';

        // Prepare data for insertion
        $insert_data = [
            'post_id' => $post_id,
            'user_id' => $user_id,
            'overall_score' => $score_data['overall_score'],
            'score_breakdown' => json_encode($score_data['score_breakdown']),
            'suggestions' => json_encode($score_data['suggestions']),
            'grade' => $score_data['grade'],
            'algorithm_version' => $score_data['algorithm_version'] ?? '2024.1',
            'calculated_at' => $score_data['calculated_at'],
            'created_at' => current_time('mysql'),
        ];

        $format = [
            '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s'
        ];

        // Add readability_score if provided
        if (isset($score_data['readability_score'])) {
            $insert_data['readability_score'] = $score_data['readability_score'];
            $format[] = '%s';
        }

        // Add content_quality if provided
        if (isset($score_data['content_quality'])) {
            $insert_data['content_quality'] = $score_data['content_quality'];
            $format[] = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO score storage requires direct database access
        $result = $wpdb->insert(
            $table_name,
            $insert_data,
            $format
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get score history for a post
     *
     * @param int $post_id Post ID
     * @param int $limit Number of scores to retrieve
     * @return array Score history
     */
    public function get_score_history(int $post_id, int $limit = 10): array {
        global $wpdb;

        // Get table name and escape it properly (table names cannot be parameterized)
        $table_name = esc_sql($wpdb->prefix . 'thinkrank_seo_scores');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO score history requires direct database access
        $results = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly escaped using esc_sql()
                "SELECT * FROM `{$table_name}` WHERE post_id = %d ORDER BY created_at DESC LIMIT %d",
                $post_id,
                $limit
            ),
            ARRAY_A
        );

        // Decode JSON fields
        foreach ($results as &$result) {
            $result['score_breakdown'] = json_decode($result['score_breakdown'], true);
            $result['suggestions'] = json_decode($result['suggestions'], true);
        }

        return $results ?: [];
    }

    /**
     * Get latest score for a post
     *
     * @param int $post_id Post ID
     * @return array|null Latest score data
     */
    public function get_latest_score(int $post_id): ?array {
        $history = $this->get_score_history($post_id, 1);
        return !empty($history) ? $history[0] : null;
    }

    /**
     * Score mobile experience - NEW 2025 factor (5 points)
     *
     * @param array $content_data Content analysis data
     * @return array Scoring result
     */
    private function score_mobile_experience(array $content_data): array {
        // Mobile experience is theme/site-level, not controlled by post content.
        // Award full credit (benefit of the doubt) instead of a fixed partial
        // that caps every post's ceiling.
        return [
            'score' => $this->scoring_factors['mobile_experience'],
            'max_score' => $this->scoring_factors['mobile_experience'],
            'suggestions' => ['Ensure mobile-first design and fast loading on mobile devices'],
            'details' => ['mobile_score' => 'Assumed adequate'],
        ];
    }

    /**
     * Score core web vitals - 2025 version (3 points)
     *
     * @param array $content_data Content analysis data
     * @return array Scoring result
     */
    private function score_core_web_vitals(array $content_data): array {
        // Core Web Vitals are a runtime/performance signal, not derivable from
        // post content. Award full credit (benefit of the doubt) rather than a
        // fixed partial that caps every post's ceiling.
        return [
            'score' => $this->scoring_factors['core_web_vitals'],
            'max_score' => $this->scoring_factors['core_web_vitals'],
            'suggestions' => ['Optimize Core Web Vitals: LCP, INP, and CLS for better user experience'],
            'details' => ['vitals_status' => 'Assumed adequate'],
        ];
    }

    /**
     * Score internal linking - declining importance (1 point)
     *
     * @param array $content_data Content analysis data
     * @return array Scoring result
     */
    private function score_internal_linking(array $content_data): array {
        $internal_links = $content_data['internal_links'] ?? 0;
        $score = $internal_links > 0 ? 1 : 0;

        return [
            'score' => $score,
            'max_score' => $this->scoring_factors['internal_linking'],
            'suggestions' => $score === 0 ? ['Add relevant internal links to other pages on your site'] : [],
            'details' => ['internal_links_count' => $internal_links]
        ];
    }

    /**
     * Score technical factors (1 point)
     *
     * @param array $content_data Content analysis data
     * @param array $metadata Post metadata
     * @param array $options Additional options
     * @return array Scoring result
     */
    private function score_technical_factors(array $content_data, array $metadata, array $options): array {
        $score = 0;
        $suggestions = [];

        // Meta description check
        $meta_desc = $metadata['description'] ?? '';
        if (!empty($meta_desc) && mb_strlen($meta_desc) >= 120 && mb_strlen($meta_desc) <= 160) {
            $score += 0.5;
        } else {
            $suggestions[] = 'Add a compelling meta description (120-160 characters)';
        }

        // Schema markup check (simplified)
        if (!empty($content_data['schema_present'])) {
            $score += 0.5;
        } else {
            $suggestions[] = 'Consider adding structured data (schema markup)';
        }

        return [
            'score' => $score,
            'max_score' => $this->scoring_factors['technical_factors'],
            'suggestions' => $suggestions,
            'details' => [
                'meta_description_length' => mb_strlen($meta_desc),
                'schema_present' => !empty($content_data['schema_present'])
            ]
        ];
    }

    /**
     * Get grade from score
     *
     * @param mixed $score Numeric score
     * @return string Letter grade
     */
    private function get_grade_from_score($score): string {
        $score = (int) $score; // Ensure it's an integer

        if ($score >= 95) return 'A+';
        if ($score >= 90) return 'A';
        if ($score >= 85) return 'A-';
        if ($score >= 80) return 'B+';
        if ($score >= 75) return 'B';
        if ($score >= 70) return 'B-';
        if ($score >= 65) return 'C+';
        if ($score >= 60) return 'C';
        if ($score >= 55) return 'C-';
        if ($score >= 45) return 'D+';
        if ($score >= 35) return 'D';
        return 'F';
    }

    /**
     * Analyze live content from editor (not saved to database yet)
     * Same as analyze_post_content but uses provided content instead of saved content
     *
     * @param string $live_content Live content from editor
     * @param int $post_id Post ID for metadata
     * @return array Content analysis data
     */
    public function analyze_live_content(string $live_content, int $post_id): array {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        // Resolve the live string the same way stored content is resolved. On a
        // builder page the editor hands over raw builder markup (the block
        // editor cannot render blocks it has no client-side registration for),
        // which analyzed as-is reads as zero words — the reason a Divi page
        // could show a correct saved score beside a live panel still claiming
        // "No content".
        $content = self::resolve_live_content($live_content, $post);
        $title = $post->post_title;

        // Extract headings from content
        $headings = $this->extract_headings($content);

        // Count words using JavaScript-compatible method
        $plain_text = wp_strip_all_tags($content);
        $word_count = $this->calculate_word_count_js_style($plain_text);

        // Calculate readability
        $readability_score = $this->calculate_readability_score($content);

        // Count links
        $internal_links = $this->count_internal_links($content);
        $external_links = $this->count_external_links($content);
        $external_dofollow_links = $this->count_external_dofollow_links($content);

        // Analyze images
        $images = $this->analyze_images($content);

        // Get URL
        $url = get_permalink($post_id);

        return [
            'content' => $content,
            'title' => $title,
            'headings' => $headings,
            'word_count' => $word_count,
            'readability_score' => $readability_score,
            'internal_links' => $internal_links,
            'external_links' => $external_links,
            'external_dofollow_links' => $external_dofollow_links,
            'images' => $images,
            'url' => $url,
            'slug' => $post->post_name,
            'post_modified' => $post->post_modified,
            'schema_present' => $this->detect_schema_present($content) || $this->thinkrank_global_schema_active($post->post_type),
        ];
    }

    /**
     * Calculate word count using JavaScript-compatible method
     * Matches the logic in contentAnalysis.js for consistency
     *
     * @param string $text Text to count words in
     * @return int Word count
     */
    private function calculate_word_count_js_style(string $text): int {
        if (empty($text)) {
            return 0;
        }

        // Match JavaScript: trim, split by whitespace, filter empty
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return count($words);
    }

    /**
     * Get existing score data for a post from database
     *
     * @param int $post_id Post ID
     * @return array|null Existing score data or null if not found
     */
    public function get_existing_score_data(int $post_id): ?array {
        global $wpdb;

        // Get table name and escape it properly (table names cannot be parameterized)
        $table_name = esc_sql($wpdb->prefix . 'thinkrank_seo_scores');

        // Get the most recent score for this post
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO score retrieval requires direct database access
        $result = $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly escaped using esc_sql()
            "SELECT * FROM `{$table_name}`
             WHERE post_id = %d
             ORDER BY calculated_at DESC
             LIMIT 1",
            $post_id
        ), ARRAY_A);

        if (!$result) {
            return null;
        }

        // Decode JSON data (stored with json_encode)
        $score_breakdown = json_decode($result['score_breakdown'], true);
        $suggestions = json_decode($result['suggestions'], true);

        // Format the data to match the expected structure
        return [
            'overall_score' => (int) $result['overall_score'],
            'grade' => $result['grade'],
            'score_breakdown' => $score_breakdown,
            'suggestions' => $suggestions ?: [],
            'target_keyword' => null, // Not stored in database, will be provided by frontend
            'calculated_at' => $result['calculated_at'],
            'score_id' => $result['id']
        ];
    }
}
