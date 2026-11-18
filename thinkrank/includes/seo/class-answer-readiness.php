<?php
/**
 * Answer-engine readiness for a single post.
 *
 * @package ThinkRank\SEO
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Answer Readiness
 *
 * The five content-shaped GEO checks, asked of one post instead of a hundred
 * (#677).
 *
 * #658 shipped them as a site audit: "3 of 20 sampled pages open with a direct
 * answer". #677 added the failing post list to that audit, so the ratio became
 * a set of links. What was still missing is the other end — an author editing
 * a page has no way to know that *this* page is one of the failing ones, and
 * the audit is not where they are looking.
 *
 * **One implementation, two callers.** The predicates and thresholds live here
 * and {@see Seo_Analyzer} delegates to them. A second copy would let the site
 * audit and the editor grade the same page differently, which is worse than
 * not shipping the editor half at all: an author who fixes what the panel asks
 * for and watches the audit not move has been told the tool is lying.
 *
 * **It is not part of the SEO score.** The thirteen factors in
 * `SEOScoreCalculator::$scoring_factors` sum to 100 and every stored score is
 * built from them, so inserting a fourteenth silently re-scores every post on
 * the site. This rides beside the score instead, as its own signal. Making it
 * a scored factor is a deliberate, changelogged decision with a version bump
 * and is not this change.
 *
 * **A page with no extractable prose is not graded.** A builder layout, a
 * shortcode-only page, Cart or Checkout leaves nothing to read, so every
 * prose-shaped question answers "no" for it. The audit already skips those
 * rows; grading them here would put a permanent red flag on pages whose author
 * has no way to clear it.
 *
 * @since 2.22.0
 */
class Answer_Readiness {

    /**
     * Word-count window for an opening passage that reads as a direct answer.
     *
     * The AEO convention is a self-contained 40-60 word answer directly under
     * the title. The window is widened at both ends so ordinary good writing
     * passes: below the floor there is no answer to quote, and well above the
     * ceiling the passage is a preamble an engine has to summarize rather than
     * a sentence it can lift.
     */
    public const ANSWER_MIN_WORDS = 20;
    public const ANSWER_MAX_WORDS = 120;

    /**
     * Words below which a page has too little substance to be cited.
     */
    public const DEPTH_MIN_WORDS = 300;

    /**
     * How long a page can go unrevised before it reads as stale to an engine
     * that prefers recent sources.
     */
    public const FRESHNESS_MAX_AGE = 365 * DAY_IN_SECONDS;

    /**
     * Characters that close a question, as a regex character class body:
     * the ASCII `?`, the full-width `？` (U+FF1F), the Arabic `؟` (U+061F),
     * the Greek question mark (U+037E) and the small form `﹖` (U+FE56).
     */
    private const QUESTION_MARKS = '?\x{FF1F}\x{061F}\x{037E}\x{FE56}';

    /**
     * Heading openers that read as a question without a question mark.
     *
     * @var string[]
     */
    private const QUESTION_STARTERS = [
        'what', 'why', 'how', 'when', 'where', 'who', 'which',
        'can', 'do', 'does', 'is', 'are', 'should', 'will',
    ];

    /**
     * The five signals for one post.
     *
     * @since 2.22.0
     *
     * @param string $content  Raw post content. The editor's unsaved content
     *                         when there is one, so the panel moves as the
     *                         author types rather than after a save.
     * @param int    $modified Last modified time, as a Unix timestamp.
     * @return array<string,mixed>
     */
    public static function evaluate(string $content, int $modified): array {
        $text  = self::content_to_text($content);
        $words = self::word_count($text);

        // The same skip the audit applies, for the same reason.
        if ('' === $text) {
            return [
                'applicable' => false,
                'reason'     => __('This page has no extractable text, so there is nothing an answer engine could quote from it. Pages built entirely from a builder layout or a shortcode are not graded here.', 'thinkrank'),
                'passed'     => 0,
                'total'      => 0,
                'checks'     => [],
            ];
        }

        $answer_words = self::word_count(self::opening_passage($content));

        $checks = [
            [
                'id'      => 'direct_answer',
                'label'   => __('Opens with a direct answer', 'thinkrank'),
                'passed'  => self::has_direct_answer($content),
                // `detail` states what was measured and is shown whether the
                // check passed or failed, so it must read correctly both ways.
                // The reason a number is wrong belongs in `fix`, which is only
                // shown on a failure.
                'detail'  => 0 === $answer_words
                    ? __('No opening paragraph before the first heading.', 'thinkrank')
                    : sprintf(
                        /* translators: %d: word count of the opening passage. */
                        _n('Opening passage: %d word.', 'Opening passage: %d words.', $answer_words, 'thinkrank'),
                        $answer_words
                    ),
                'fix'     => sprintf(
                    /* translators: 1: minimum words, 2: maximum words. */
                    __('Open with a paragraph of %1$d to %2$d words that answers the title directly, before any background.', 'thinkrank'),
                    self::ANSWER_MIN_WORDS,
                    self::ANSWER_MAX_WORDS
                ),
            ],
            [
                'id'      => 'question_heading',
                'label'   => __('Has a question-style heading', 'thinkrank'),
                'passed'  => self::has_question_heading($content),
                'detail'  => __('Assistants match a reader\'s question against your headings first.', 'thinkrank'),
                'fix'     => __('Phrase at least one H2 or H3 as the question it answers, such as "How does X work?" rather than "Overview".', 'thinkrank'),
            ],
            [
                'id'      => 'structured',
                'label'   => __('Uses a list or table', 'thinkrank'),
                'passed'  => self::has_structured_content($content),
                'detail'  => __('Steps, comparisons and specifications are extracted far more reliably from a list or table than from prose.', 'thinkrank'),
                'fix'     => __('Break steps, comparisons and specifications out of the paragraphs into list or table blocks.', 'thinkrank'),
            ],
            [
                'id'      => 'depth',
                'label'   => __('Has enough depth to cite', 'thinkrank'),
                'passed'  => self::has_depth($text),
                'detail'  => sprintf(
                    /* translators: %d: word count of the page. */
                    _n('%d word.', '%d words.', $words, 'thinkrank'),
                    $words
                ),
                'fix'     => sprintf(
                    /* translators: %d: minimum words. */
                    __('Under %d words an assistant with a choice of sources rarely quotes the thinnest one. Expand the page so it answers its topic completely, or merge it into one that does.', 'thinkrank'),
                    self::DEPTH_MIN_WORDS
                ),
            ],
            [
                'id'      => 'freshness',
                'label'   => __('Revised within the last year', 'thinkrank'),
                'passed'  => self::is_fresh($modified),
                'detail'  => $modified > 0
                    ? sprintf(
                        /* translators: %s: human-readable time difference, e.g. "3 months". */
                        __('Last revised %s ago.', 'thinkrank'),
                        human_time_diff($modified)
                    )
                    : __('This page has no recorded revision date yet.', 'thinkrank'),
                'fix'     => __('Review the page, update what has changed, and save it so its modified date reflects the revision.', 'thinkrank'),
            ],
        ];

        $passed = 0;
        foreach ($checks as $check) {
            if ($check['passed']) {
                $passed++;
            }
        }

        return [
            'applicable' => true,
            'reason'     => '',
            'passed'     => $passed,
            'total'      => count($checks),
            'checks'     => $checks,
        ];
    }

    /**
     * Whether the opening passage reads as a self-contained answer.
     *
     * @since 2.22.0
     * @param string $content Raw post content.
     * @return bool
     */
    public static function has_direct_answer(string $content): bool {
        $words = self::word_count(self::opening_passage($content));

        return $words >= self::ANSWER_MIN_WORDS && $words <= self::ANSWER_MAX_WORDS;
    }

    /**
     * Whether any H2/H3 in the content reads as a question.
     *
     * @since 2.22.0
     * @param string $content Raw post content.
     * @return bool
     */
    public static function has_question_heading(string $content): bool {
        if (!preg_match_all('/<h[23][^>]*>(.*?)<\/h[23]>/is', $content, $matches)) {
            return false;
        }

        foreach ($matches[1] as $heading) {
            $text = self::content_to_text((string) $heading);

            if ('' === $text) {
                continue;
            }

            if (self::ends_with_question_mark($text)) {
                return true;
            }

            // A question mark is the reliable signal, but plenty of good
            // question headings drop it ("How image search works").
            $first = strtolower((string) strtok($text, " \t\n"));
            if (in_array($first, self::QUESTION_STARTERS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a line of text ends in a question mark, in any script.
     *
     * Not only the ASCII `?`: Chinese and Japanese close a question with the
     * full-width `？` (U+FF1F), Arabic, Persian and Urdu with `؟` (U+061F),
     * and Greek with the Greek question mark (U+037E). `substr($text, -1)`
     * read the last byte of those, so every such heading failed the check
     * (#1631). Trailing whitespace is ignored.
     *
     * @since 2.22.0
     * @param string $text Plain text.
     * @return bool
     */
    public static function ends_with_question_mark(string $text): bool {
        return 1 === preg_match('/[' . self::QUESTION_MARKS . ']\s*$/u', $text);
    }

    /**
     * Whether a string holds a question mark anywhere, from the same set
     * ends_with_question_mark() reads.
     *
     * For the FAQ extraction and the FAQPage filter, which take a heading such
     * as "What is X? A primer" as a question. They tested only the ASCII `?`,
     * so a page the audit counts as Q&A content after #1631 produced no FAQ
     * markup at all (#1686).
     *
     * @since 2.22.0
     * @param string $text Plain text.
     * @return bool
     */
    public static function has_question_mark(string $text): bool {
        return 1 === preg_match('/[' . self::QUESTION_MARKS . ']/u', $text);
    }

    /**
     * Whether the content presents anything as a list or a table.
     *
     * @since 2.22.0
     * @param string $content Raw post content.
     * @return bool
     */
    public static function has_structured_content(string $content): bool {
        return (bool) preg_match('/<(ul|ol|table)[\s>]/i', $content);
    }

    /**
     * Whether there is enough text to be worth quoting.
     *
     * @since 2.22.0
     * @param string $text Plain text of the whole page.
     * @return bool
     */
    public static function has_depth(string $text): bool {
        return self::has_depth_words(self::word_count($text));
    }

    /**
     * Whether a length already counted is enough to be worth quoting.
     *
     * The site audit counts a sampled post through the word-count index, which
     * resolves builder content (#1309), so it holds a number rather than the
     * text; this keeps the threshold in one place for both callers.
     *
     * @since 2.22.0
     * @param int $words Length of the page.
     * @return bool
     */
    public static function has_depth_words(int $words): bool {
        return $words >= self::DEPTH_MIN_WORDS;
    }

    /**
     * Whether the page has been revised recently enough to read as current.
     *
     * @since 2.22.0
     * @param int $modified Last modified time, as a Unix timestamp.
     * @return bool
     */
    public static function is_fresh(int $modified): bool {
        return $modified > 0 && $modified >= (time() - self::FRESHNESS_MAX_AGE);
    }

    /**
     * The stored content a post is graded on.
     *
     * The one answer for the site audit, the editor endpoint and the
     * get-post-seo-checks ability, so all three grade a page from the same
     * markup (#1629). Grading only the predicates alike is not enough: the
     * ability and the endpoint read raw `post_content`, so a builder page whose
     * words live in builder meta came back "not graded" while the audit graded
     * it.
     *
     * Raw markup, not rendered: running `the_content` over every sampled post
     * would fire every shortcode and block renderer on the site, and the
     * structural signals the checks look for survive in the stored markup.
     * Except on a builder page, where they do not: the words and headings are
     * in builder meta and `post_content` is empty, or, on a Bricks page, holds
     * blocks Bricks never renders. Those read through Builder_Content (#892),
     * which only reads stored builder meta when there is nothing in
     * `post_content` to render. Everything else goes through
     * `thinkrank_analyzable_content`, the content ThinkRank analyzes (#1309).
     *
     * @since 2.22.0
     *
     * @param \WP_Post $post Post to grade.
     * @return string Markup to pass to evaluate().
     */
    public static function post_content(\WP_Post $post): string {
        $builder = self::builder_content($post);
        if (null !== $builder) {
            return $builder;
        }

        $raw = (string) $post->post_content;

        /** This filter is documented in includes/seo/class-builder-content.php */
        return (string) apply_filters('thinkrank_analyzable_content', $raw, $post, $raw);
    }

    /**
     * A post's content from its page builder, when that is where it is.
     *
     * Null for every post whose `post_content` is what the visitor reads, so
     * those keep the raw-markup path and never pay for resolution. Guarded so a
     * partial checkout without Builder_Content degrades to the raw markup
     * instead of fataling mid-audit.
     *
     * @since 2.22.0
     *
     * @param \WP_Post $post Post to grade.
     * @return string|null Resolved content, or null to use `post_content`.
     */
    private static function builder_content(\WP_Post $post): ?string {
        if (!class_exists(Builder_Content::class) || [] === Builder_Content::builder_meta_keys()) {
            return null;
        }

        if ('' !== trim((string) $post->post_content)
            && !Builder_Content::bricks_supersedes_post_content((int) $post->ID)
        ) {
            return null;
        }

        try {
            return Builder_Content::resolve($post);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * When the post was last revised, as a Unix timestamp, for is_fresh().
     *
     * The modified date, not the publish date: the freshness fix tells the
     * author to update and save the page, and only the modified date moves
     * when they do. Read from post_modified_gmt, the column the site audit
     * samples, so the editor, the ability and the audit grade one page alike.
     *
     * WordPress leaves post_modified_gmt zeroed on an auto-draft and on a draft
     * inserted once and never updated (wp_insert_post() copies the draft's
     * zero post_date_gmt). Those are the newest posts on the site, so they
     * fall back to post_modified, the local column core always sets, instead
     * of reading as never revised (#1630). A date in the future, which a
     * scheduled post inserted in one call carries, is clamped to now: nothing
     * has been revised later than this moment.
     *
     * @since 2.22.0
     * @param \WP_Post|int $post Post object or ID.
     * @return int 0 when the post or its modified date is missing.
     */
    public static function modified_time($post): int {
        $post = is_object($post) ? $post : get_post((int) $post);
        if (!is_object($post)) {
            return 0;
        }

        $modified = self::gmt_timestamp((string) ($post->post_modified_gmt ?? ''));

        if (0 === $modified) {
            $local = (string) ($post->post_modified ?? '');
            if (self::is_set_date($local)) {
                $modified = self::gmt_timestamp((string) get_gmt_from_date($local));
            }
        }

        return $modified > 0 ? min($modified, time()) : 0;
    }

    /**
     * A GMT MySQL datetime as a Unix timestamp, 0 when it is unset.
     *
     * @since 2.22.0
     * @param string $date `Y-m-d H:i:s` in GMT.
     * @return int
     */
    private static function gmt_timestamp(string $date): int {
        if (!self::is_set_date($date)) {
            return 0;
        }

        $time = strtotime($date . ' UTC');

        return is_int($time) && $time > 0 ? $time : 0;
    }

    /**
     * Whether a MySQL datetime holds a date rather than core's zero value.
     *
     * @since 2.22.0
     * @param string $date MySQL datetime.
     * @return bool
     */
    private static function is_set_date(string $date): bool {
        $date = trim($date);

        return '' !== $date && 0 !== strpos($date, '0000-00-00');
    }

    /**
     * The first block of prose in a post, before any heading.
     *
     * @since 2.22.0
     * @param string $content Raw post content.
     * @return string Plain text of the opening passage.
     */
    public static function opening_passage(string $content): string {
        // Cut at the first heading: everything above it is the intro, and an
        // intro that runs past a heading is not a direct answer either way.
        $chunks = preg_split('/<h[1-6][^>]*>/i', $content, 3) ?: [];
        $intro  = (string) ($chunks[0] ?? $content);

        $passage = self::first_paragraph_text($intro);
        if ('' !== $passage) {
            return $passage;
        }

        $text = self::content_to_text($intro);

        // A page that opens with its heading has nothing above it, so the chunk
        // read so far is empty and the answer sits directly under that heading.
        // Scoring it as a missing opening would fail exactly the pages written
        // as "question, then answer" — the shape this check exists to reward.
        if ('' === trim($text) && isset($chunks[1])) {
            // Drop the heading's own text, which the split left at the head of
            // the next chunk, so a long heading cannot pose as the answer.
            $after = preg_replace('/^.*?<\/h[1-6]>/is', '', (string) $chunks[1], 1);
            $after = null === $after ? (string) $chunks[1] : $after;

            $passage = self::first_paragraph_text($after);
            if ('' !== $passage) {
                return $passage;
            }

            return self::content_to_text($after);
        }

        return $text;
    }

    /**
     * The first paragraph in a fragment with enough words to be a passage
     * rather than a caption or a stray line.
     *
     * Only the first one counts — a three-paragraph intro is exactly the
     * preamble the direct-answer check is looking for.
     *
     * @since 2.22.0
     * @param string $chunk Raw content fragment.
     * @return string Plain text, or '' when the chunk holds no paragraph.
     */
    public static function first_paragraph_text(string $chunk): string {
        $paragraphs = preg_split('/<\/p>|\R{2,}/', $chunk) ?: [];

        foreach ($paragraphs as $paragraph) {
            $candidate = self::content_to_text((string) $paragraph);
            if (self::word_count($candidate) >= 5) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Plain text of a content fragment.
     *
     * @since 2.22.0
     * @param string $content Raw content.
     * @return string
     */
    public static function content_to_text(string $content): string {
        // The reading text Thin Content, the per-post score and the site audit
        // count (#1309): tags become spaces rather than vanishing, block
        // delimiters, scripts and shortcode syntax are dropped, and bytes that
        // are not valid UTF-8 are scrubbed instead of blanking the result.
        return Word_Count_Index::reading_text($content);
    }

    /**
     * Word count of a string of plain text.
     *
     * @since 2.22.0
     * @param string $text Plain text.
     * @return int
     */
    public static function word_count(string $text): int {
        if ('' === $text) {
            return 0;
        }

        // Counted the way Word_Count_Index counts, so the editor's number and
        // the audit's agree: whitespace-separated tokens that hold a letter or
        // a digit, in any script.
        return Word_Count_Index::count_words($text);
    }
}
