<?php
/**
 * AI Prompt Builder Class
 *
 * Coordinator class that delegates to specialized prompt classes.
 * Provides consistent interface while organizing prompts by type.
 * Supports LLMs.txt, SEO, Site Identity, Homepage, and other AI-powered features.
 *
 * @package ThinkRank
 * @subpackage AI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use ThinkRank\AI\Prompts\SEO_Prompts;
use ThinkRank\AI\Prompts\Site_Identity_Prompts;
use ThinkRank\AI\Prompts\Homepage_Prompts;
use ThinkRank\AI\Prompts\LLMs_Txt_Prompts;
use ThinkRank\AI\Prompts\Content_Brief_Prompts;

/**
 * AI Prompt Builder Class
 *
 * Coordinator class that delegates to specialized prompt classes.
 * Provides consistent interface for all AI optimization prompts.
 *
 * @since 1.0.0
 */
class Prompt_Builder {

    /**
     * SEO Prompts instance
     *
     * @since 1.0.0
     * @var SEO_Prompts|null
     */
    private ?SEO_Prompts $seo_prompts = null;

    /**
     * Site Identity Prompts instance
     *
     * @since 1.0.0
     * @var Site_Identity_Prompts|null
     */
    private ?Site_Identity_Prompts $site_identity_prompts = null;

    /**
     * Homepage Prompts instance
     *
     * @since 1.0.0
     * @var Homepage_Prompts|null
     */
    private ?Homepage_Prompts $homepage_prompts = null;

    /**
     * LLMs.txt Prompts instance
     *
     * @since 1.0.0
     * @var LLMs_Txt_Prompts|null
     */
    private ?LLMs_Txt_Prompts $llms_txt_prompts = null;

    /**
     * Content Brief Prompts instance
     *
     * @since 1.0.0
     * @var Content_Brief_Prompts|null
     */
    private ?Content_Brief_Prompts $content_brief_prompts = null;

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
        return $this->get_llms_txt_prompts()->build_llms_txt_prompt($website_data, $options, $provider);
    }

    /**
     * Build SEO metadata optimization prompt
     *
     * @since 1.0.0
     *
     * @param string $content Content to optimize
     * @param string $target_keyword Target keyword
     * @param string $content_type Type of content
     * @param string $tone Desired tone
     * @param string $provider AI provider
     * @param string $language Target output language (empty = infer from content)
     * @return string Formatted prompt
     */
    public function build_seo_prompt(string $content, string $target_keyword, string $content_type, string $tone, string $provider = 'openai', string $language = ''): string {
        return $this->get_seo_prompts()->build_seo_prompt($content, $target_keyword, $content_type, $tone, $provider, $language);
    }

    /**
     * Build a focused SEO title improvement prompt.
     *
     * @since 1.14.0
     *
     * @param string $content        Post content for context.
     * @param string $current_title  The current SEO title.
     * @param string $target_keyword Target/focus keyword.
     * @param string $content_type   Type of content.
     * @param string $tone           Desired tone.
     * @param string $suggestion       The suggestion the new title must address.
     * @param string $provider         AI provider.
     * @param array  $sentiment_words  Emotion/sentiment words the title check rewards.
     * @param array  $power_words      Power words the title check rewards.
     * @param string $language          Target output language (empty = infer from content)
     * @return string Formatted prompt
     */
    public function build_title_improvement_prompt(string $content, string $current_title, string $target_keyword, string $content_type, string $tone, string $suggestion, string $provider = 'openai', array $sentiment_words = [], array $power_words = [], string $language = ''): string {
        return $this->get_seo_prompts()->build_title_improvement_prompt($content, $current_title, $target_keyword, $content_type, $tone, $suggestion, $provider, $sentiment_words, $power_words, $language);
    }

    /**
     * Build a focused meta-description improvement prompt.
     *
     * @since 1.14.0
     *
     * @param string $content        Post content for context.
     * @param string $current_desc   The current meta description.
     * @param string $target_keyword Target/focus keyword.
     * @param string $content_type   Type of content.
     * @param string $tone           Desired tone.
     * @param string $suggestion     The suggestion the description must address.
     * @param string $provider       AI provider.
     * @param string $language        Target output language (empty = infer from content)
     * @return string Formatted prompt
     */
    public function build_meta_description_improvement_prompt(string $content, string $current_desc, string $target_keyword, string $content_type, string $tone, string $suggestion, string $provider = 'openai', string $language = ''): string {
        return $this->get_seo_prompts()->build_meta_description_improvement_prompt($content, $current_desc, $target_keyword, $content_type, $tone, $suggestion, $provider, $language);
    }

    /**
     * Build a prompt that explains why a specific SEO suggestion matters for a
     * given post and how to resolve it (the "Explain with AI" copilot action).
     *
     * @since 1.18.0
     *
     * @param string $suggestion     The SEO suggestion to explain.
     * @param string $content        Post content for context.
     * @param string $title          Post/SEO title for context.
     * @param string $target_keyword Focus keyword (may be empty).
     * @param string $content_type   Type of content.
     * @param string $provider       AI provider.
     * @return string Formatted prompt
     */
    public function build_suggestion_explanation_prompt(string $suggestion, string $content, string $title, string $target_keyword, string $content_type, string $provider = 'openai'): string {
        return $this->get_seo_prompts()->build_suggestion_explanation_prompt($suggestion, $content, $title, $target_keyword, $content_type, $provider);
    }

    /**
     * Build a prompt proposing one authoritative external source to cite.
     *
     * @since 1.14.0
     *
     * @param string $content        Post content for context.
     * @param string $target_keyword Focus keyword.
     * @param string $content_type   Type of content.
     * @param string $provider       AI provider.
     * @return string Formatted prompt
     */
    public function build_dofollow_link_prompt(string $content, string $target_keyword, string $content_type, string $provider = 'openai'): string {
        return $this->get_seo_prompts()->build_dofollow_link_prompt($content, $target_keyword, $content_type, $provider);
    }

    /**
     * Build a prompt for a short keyword-rich closing paragraph.
     *
     * @since 1.14.0
     *
     * @param string $content          Post content for context.
     * @param string $target_keyword   Focus keyword.
     * @param string $content_type     Type of content.
     * @param string $tone             Desired tone.
     * @param int    $keyword_mentions How many times to use the keyword.
     * @param string $provider         AI provider.
     * @return string Formatted prompt
     */
    public function build_keyword_paragraph_prompt(string $content, string $target_keyword, string $content_type, string $tone, int $keyword_mentions, string $provider = 'openai', int $word_target = 90): string {
        return $this->get_seo_prompts()->build_keyword_paragraph_prompt($content, $target_keyword, $content_type, $tone, $keyword_mentions, $provider, $word_target);
    }

    /**
     * Build content analysis prompt
     *
     * @since 1.0.0
     *
     * @param string $content Content to analyze
     * @param array $metadata Existing metadata
     * @param string $provider AI provider
     * @return string Formatted prompt
     */
    public function build_analysis_prompt(string $content, array $metadata, string $provider = 'openai'): string {
        return $this->get_seo_prompts()->build_analysis_prompt($content, $metadata, $provider);
    }

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
        return $this->get_site_identity_prompts()->build_site_identity_prompt($site_data, $business_type, $target_audience, $tone, $provider);
    }

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
     * @return string Formatted prompt
     */
    public function build_homepage_meta_prompt(array $content_data, string $business_type, string $target_audience, string $tone, array $context = [], string $provider = 'openai'): string {
        return $this->get_homepage_prompts()->build_homepage_meta_prompt($content_data, $business_type, $target_audience, $tone, $context, $provider);
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
     * @return string Formatted prompt
     */
    public function build_homepage_hero_prompt(array $hero_data, string $business_type, string $target_audience, string $tone, array $context = [], string $provider = 'openai'): string {
        return $this->get_homepage_prompts()->build_homepage_hero_prompt($hero_data, $business_type, $target_audience, $tone, $context, $provider);
    }

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
        return $this->get_content_brief_prompts()->build_content_brief_prompt(
            $target_keywords,
            $content_type,
            $target_audience,
            $content_length,
            $tone,
            $competitor_analysis,
            $additional_context,
            $provider,
            $language
        );
    }

    /**
     * Get SEO Prompts instance
     *
     * @since 1.0.0
     *
     * @return SEO_Prompts SEO Prompts instance
     */
    private function get_seo_prompts(): SEO_Prompts {
        if (!$this->seo_prompts) {
            // Ensure SEO Prompts is loaded
            if (!class_exists('ThinkRank\\AI\\Prompts\\SEO_Prompts')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/ai/prompts/class-seo-prompts.php';
            }
            $this->seo_prompts = new SEO_Prompts();
        }
        return $this->seo_prompts;
    }

    /**
     * Get Site Identity Prompts instance
     *
     * @since 1.0.0
     *
     * @return Site_Identity_Prompts Site Identity Prompts instance
     */
    private function get_site_identity_prompts(): Site_Identity_Prompts {
        if (!$this->site_identity_prompts) {
            // Ensure Site Identity Prompts is loaded
            if (!class_exists('ThinkRank\\AI\\Prompts\\Site_Identity_Prompts')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/ai/prompts/class-site-identity-prompts.php';
            }
            $this->site_identity_prompts = new Site_Identity_Prompts();
        }
        return $this->site_identity_prompts;
    }

    /**
     * Get Homepage Prompts instance
     *
     * @since 1.0.0
     *
     * @return Homepage_Prompts Homepage Prompts instance
     */
    private function get_homepage_prompts(): Homepage_Prompts {
        if (!$this->homepage_prompts) {
            // Ensure Homepage Prompts is loaded
            if (!class_exists('ThinkRank\\AI\\Prompts\\Homepage_Prompts')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/ai/prompts/class-homepage-prompts.php';
            }
            $this->homepage_prompts = new Homepage_Prompts();
        }
        return $this->homepage_prompts;
    }

    /**
     * Get LLMs.txt Prompts instance
     *
     * @since 1.0.0
     *
     * @return LLMs_Txt_Prompts LLMs.txt Prompts instance
     */
    private function get_llms_txt_prompts(): LLMs_Txt_Prompts {
        if (!$this->llms_txt_prompts) {
            // Ensure LLMs.txt Prompts is loaded
            if (!class_exists('ThinkRank\\AI\\Prompts\\LLMs_Txt_Prompts')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/ai/prompts/class-llms-txt-prompts.php';
            }
            $this->llms_txt_prompts = new LLMs_Txt_Prompts();
        }
        return $this->llms_txt_prompts;
    }

    /**
     * Get Content Brief Prompts instance
     *
     * @since 1.0.0
     *
     * @return Content_Brief_Prompts Content Brief Prompts instance
     */
    private function get_content_brief_prompts(): Content_Brief_Prompts {
        if (!$this->content_brief_prompts) {
            // Ensure Content Brief Prompts is loaded
            if (!class_exists('ThinkRank\\AI\\Prompts\\Content_Brief_Prompts')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/ai/prompts/class-content-brief-prompts.php';
            }
            $this->content_brief_prompts = new Content_Brief_Prompts();
        }
        return $this->content_brief_prompts;
    }
}
