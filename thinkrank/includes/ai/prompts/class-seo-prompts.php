<?php
/**
 * SEO Prompts Class
 *
 * Handles SEO-related AI prompts including metadata generation and content analysis.
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
 * SEO Prompts Class
 *
 * Centralized SEO prompt building for consistent AI optimization across all providers.
 * Handles SEO metadata generation and content analysis prompts.
 *
 * @since 1.0.0
 */
class SEO_Prompts {

    /**
     * Build the language directive block for a prompt.
     *
     * Injected near the top of every generation prompt so the model writes in
     * the site/post language instead of defaulting to English — the fix for
     * English/mixed output on non-English sites (issue #234). Empty when no
     * language is resolved, preserving the legacy infer-from-content behavior.
     *
     * @since 1.27.0
     *
     * @param string $language Human-readable target language (may be empty).
     * @return string Directive block, or '' when no language is given.
     */
    private function language_directive(string $language): string {
        if ($language === '') {
            return '';
        }

        return "\n\nLANGUAGE (critical): Write ALL output — every title, description, keyword and any other text — in {$language}. Match the language of the source content. Do NOT translate to English or switch languages.";
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
        $keyword_instruction = $target_keyword ? "Focus on the keyword: \"{$target_keyword}\"" : "Identify the main topic";
        
        return "You are an expert SEO copywriter. Analyze the following {$content_type} content and generate optimized SEO metadata.{$this->language_directive($language)}

{$keyword_instruction}

Content:
{$content}

Generate:
1. SEO Title (50-60 characters, compelling and keyword-optimized)
2. Meta Description (150-160 characters, engaging with call-to-action)
3. Focus Keyword (main keyword for this content)

Requirements:
- Use {$tone} tone
- Include target keyword naturally
- Make titles compelling for clicks
- Write descriptions that encourage clicks
- Count characters (including spaces and punctuation) before answering. The title MUST NOT exceed 60 characters and the description MUST NOT exceed 160 characters — these are hard maximums, not targets. Rewrite shorter if you go over.

Format your response as JSON:
{
  \"title\": \"Your SEO title here\",
  \"description\": \"Your meta description here\",
  \"focus_keyword\": \"main keyword\",
  \"suggestions\": [\"improvement suggestion 1\", \"improvement suggestion 2\"]
}";
    }

    /**
     * Build a focused SEO title improvement prompt.
     *
     * Generates a single improved SEO title that addresses a specific scoring
     * suggestion while following SEO best practices (length, keyword placement,
     * emotional/power words). Returns JSON so every provider parses consistently.
     *
     * @since 1.14.0
     *
     * @param string $content        Post content for context.
     * @param string $current_title  The current SEO title (may be empty).
     * @param string $target_keyword Target/focus keyword (may be empty).
     * @param string $content_type   Type of content (blog_post, page, …).
     * @param string $tone           Desired tone.
     * @param string $suggestion       The suggestion the new title must address.
     * @param string $provider         AI provider.
     * @param array  $sentiment_words  Emotion/sentiment words the title check rewards.
     * @param array  $power_words      Power words the title check rewards.
     * @return string Formatted prompt.
     */
    public function build_title_improvement_prompt(string $content, string $current_title, string $target_keyword, string $content_type, string $tone, string $suggestion, string $provider = 'openai', array $sentiment_words = [], array $power_words = [], string $language = ''): string {
        $keyword_instruction = $target_keyword
            ? "Naturally include the focus keyword: \"{$target_keyword}\"."
            : 'Identify the main topic from the content and lead with it.';
        $current_title_line = $current_title !== ''
            ? "Current SEO title: \"{$current_title}\""
            : 'There is no SEO title yet.';
        $suggestion_line = $suggestion !== ''
            ? "Specifically fix this issue: \"{$suggestion}\"."
            : 'Improve the overall SEO effectiveness of the title.';

        // Bake in the exact word lists ThinkRank validates against, so the
        // generated title actually passes the emotion/sentiment and power-word
        // checks instead of using a synonym we do not recognise.
        $sentiment_line = '';
        if (!empty($sentiment_words)) {
            $list = implode(', ', $sentiment_words);
            $sentiment_line = "\n- MUST contain at least ONE of these emotion/sentiment words verbatim (case-insensitive, may appear inside a larger word): {$list}.";
        }
        $power_line = '';
        if (!empty($power_words)) {
            $list = implode(', ', $power_words);
            $power_line = "\n- Where natural, also include a number or ONE of these power words verbatim: {$list}.";
        }

        return "You are an expert SEO copywriter. Rewrite the SEO title for the following {$content_type} so it ranks and earns clicks.{$this->language_directive($language)}

{$current_title_line}
{$suggestion_line}

Content (for context):
{$content}

Requirements for the new SEO title:
- MUST be between 35 and 60 characters (60 is the hard maximum — never exceed it).
- {$keyword_instruction}
- Use a {$tone} tone and make it compelling and click-worthy.{$sentiment_line}{$power_line}
- Front-load the most important words; do not use clickbait or ALL CAPS.
- Return the title only, with no surrounding quotes.

Format your response as JSON:
{
  \"title\": \"Your improved SEO title here\"
}";
    }

    /**
     * Build a focused meta-description improvement prompt.
     *
     * Generates a single meta description that addresses a specific scoring
     * suggestion while passing ThinkRank's checks (120-160 characters, focus
     * keyword present). Returns JSON for consistent cross-provider parsing.
     *
     * @since 1.14.0
     *
     * @param string $content        Post content for context.
     * @param string $current_desc   The current meta description (may be empty).
     * @param string $target_keyword Target/focus keyword (may be empty).
     * @param string $content_type   Type of content.
     * @param string $tone           Desired tone.
     * @param string $suggestion     The suggestion the description must address.
     * @param string $provider       AI provider.
     * @return string Formatted prompt.
     */
    public function build_meta_description_improvement_prompt(string $content, string $current_desc, string $target_keyword, string $content_type, string $tone, string $suggestion, string $provider = 'openai', string $language = ''): string {
        $keyword_instruction = $target_keyword
            ? "MUST naturally include the focus keyword: \"{$target_keyword}\"."
            : 'Lead with the main topic from the content.';
        $current_desc_line = $current_desc !== ''
            ? "Current meta description: \"{$current_desc}\""
            : 'There is no meta description yet.';
        $suggestion_line = $suggestion !== ''
            ? "Specifically fix this issue: \"{$suggestion}\"."
            : 'Improve the overall SEO effectiveness of the meta description.';

        return "You are an expert SEO copywriter. Write the meta description for the following {$content_type} so it earns clicks from search results.{$this->language_directive($language)}

{$current_desc_line}
{$suggestion_line}

Content (for context):
{$content}

Requirements for the new meta description:
- MUST be between 120 and 160 characters (this length range is mandatory — count characters carefully and never go under 120 or over 160).
- {$keyword_instruction}
- Use a {$tone} tone, summarize the page accurately, and end with a clear call to action.
- Write a single sentence or two of plain text — no quotes, no markup, no line breaks.

Format your response as JSON:
{
  \"description\": \"Your meta description here\"
}";
    }

    /**
     * Build a prompt that explains, in plain language, why a specific SEO score
     * suggestion matters for this particular post and how to resolve it.
     *
     * Powers the "Explain with AI" affordance on each suggestion: instead of the
     * generic, rule-based guidance, the model reads the actual post context and
     * returns a short, encouraging explanation grounded in this content.
     *
     * @since 1.18.0
     *
     * @param string $suggestion     The SEO suggestion to explain.
     * @param string $content        Post content for context.
     * @param string $title          Post/SEO title for context.
     * @param string $target_keyword Focus keyword (may be empty).
     * @param string $content_type   Type of content.
     * @param string $provider       AI provider.
     * @return string Formatted prompt.
     */
    public function build_suggestion_explanation_prompt(string $suggestion, string $content, string $title, string $target_keyword, string $content_type, string $provider = 'openai'): string {
        $title_line = $title !== ''
            ? "Post title: \"{$title}\""
            : 'The post has no title yet.';
        $keyword_line = $target_keyword !== ''
            ? "Focus keyword: \"{$target_keyword}\""
            : 'No focus keyword has been set.';

        return "You are a friendly, expert SEO coach helping a WordPress author improve one {$content_type}.

{$title_line}
{$keyword_line}

The author sees this SEO suggestion:
\"{$suggestion}\"

Content (for context):
{$content}

Explain this suggestion for THIS specific post. In 2-3 short sentences of plain, encouraging language:
1. Why it matters for search rankings or readers (the benefit of fixing it).
2. Concretely what the author should change in this post to resolve it.

Be specific to the content above — reference the actual topic where helpful. Do not restate the suggestion verbatim, do not use jargon without explaining it, and do not use markup, headings, bullet points, or line breaks.

Return JSON only:
{
  \"explanation\": \"Your plain-language explanation here\"
}";
    }

    /**
     * Build a prompt that proposes ONE authoritative external source to cite,
     * so the page gains a dofollow outbound link to a reputable domain.
     *
     * @since 1.14.0
     *
     * @param string $content        Post content for context.
     * @param string $target_keyword Focus keyword (may be empty).
     * @param string $content_type   Type of content.
     * @param string $provider       AI provider.
     * @return string Formatted prompt.
     */
    public function build_dofollow_link_prompt(string $content, string $target_keyword, string $content_type, string $provider = 'openai'): string {
        $topic_line = $target_keyword !== ''
            ? "The {$content_type} focuses on: \"{$target_keyword}\"."
            : "Infer the main topic from the content.";

        return "You are an SEO editor adding ONE authoritative outbound citation to a {$content_type}.

{$topic_line}

Content (for context):
{$content}

Choose ONE real, widely-known, authoritative external source that is genuinely relevant to the topic. Prefer stable URLs on reputable domains that are very unlikely to 404 — for example Wikipedia article pages, official government (.gov) or education (.edu) pages, standards bodies, or the well-known official site of a major organisation. Do NOT invent URLs or use deep/obscure paths.

Return JSON only:
{
  \"url\": \"https://...\",
  \"anchor\": \"2-5 word anchor text\",
  \"sentence\": \"One natural sentence (max 180 characters) that cites the source for further reading. Include the anchor text words somewhere in the sentence.\"
}";
    }

    /**
     * Build a prompt that writes a short, relevant paragraph naturally using the
     * focus keyword a given number of times, to raise keyword density into band.
     *
     * @since 1.14.0
     *
     * @param string $content         Post content for context.
     * @param string $target_keyword  Focus keyword.
     * @param string $content_type    Type of content.
     * @param string $tone            Desired tone.
     * @param int    $keyword_mentions How many times to use the keyword.
     * @param string $provider        AI provider.
     * @return string Formatted prompt.
     */
    public function build_keyword_paragraph_prompt(string $content, string $target_keyword, string $content_type, string $tone, int $keyword_mentions, string $provider = 'openai', int $word_target = 90): string {
        $low = max(60, $word_target - 20);
        $high = $word_target + 20;

        return "You are an expert SEO writer adding a brief closing section to a {$content_type}.

Focus keyword: \"{$target_keyword}\"

Existing content (for context and style):
{$content}

Write a new closing section of about {$low}-{$high} words (one or two short paragraphs) that adds genuine value as a concluding takeaway and flows naturally from the existing content. Use a {$tone} tone. You MUST use the exact focus keyword phrase \"{$target_keyword}\" {$keyword_mentions} times, worked in as naturally as possible. Do not repeat sentences already in the content.

Return JSON only:
{
  \"paragraph\": \"Your new closing section here\"
}";
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
        $title = $metadata['title'] ?? '';
        $description = $metadata['description'] ?? '';
        $focus_keyword = $metadata['focus_keyword'] ?? '';

        $metadata_section = '';
        if (!empty($title) || !empty($description) || !empty($focus_keyword)) {
            $metadata_section = "
Current SEO Metadata:
- Title: {$title}
- Description: {$description}
- Focus Keyword: {$focus_keyword}
";
        }

        return "You are an expert SEO analyst. Analyze the following content for SEO optimization opportunities.

Content:
{$content}
{$metadata_section}

Provide a comprehensive SEO analysis including:
1. SEO Score (1-100 based on current optimization)
2. Content quality assessment
3. Keyword optimization analysis
4. Readability assessment
5. Specific improvement suggestions

Format your response as JSON:
{
  \"seo_score\": 75,
  \"content_analysis\": {
    \"word_count\": 500,
    \"readability\": \"good\",
    \"keyword_density\": \"optimal\",
    \"structure\": \"needs improvement\"
  },
  \"suggestions\": [
    \"Add more subheadings to improve structure\",
    \"Include the focus keyword in the first paragraph\",
    \"Optimize meta description length\"
  ],
  \"strengths\": [
    \"Good content length\",
    \"Clear writing style\"
  ],
  \"weaknesses\": [
    \"Missing focus keyword in title\",
    \"No internal links\"
  ]
}";
    }
}
