<?php
/**
 * Question Coverage — which sub-questions of a topic a post answers (#723).
 *
 * For a post's focus keyword this builds the list of questions searchers break
 * the topic into, then checks the post against each one:
 *
 * 1. Question set. 8 to 15 AI-generated sub-questions across fixed facets,
 *    cached per keyword + locale for 30 days. When Search Console is
 *    connected, up to 10 real queries this page already earns impressions for
 *    (last 90 days, at least 10 impressions, sharing a head term with the
 *    keyword) are added and labelled as such, so the list is partly measured
 *    rather than wholly generated.
 * 2. Coverage. The post is split into sections at its H2/H3 headings and the
 *    AI judges every question against them: answered (and by which section),
 *    partial, or missing (and where a section would fit). Cached per question
 *    set + content, so re-running after an edit re-judges coverage without
 *    regenerating the questions.
 * 3. The last result is kept on the post for the editor and for
 *    get-question-coverage.
 *
 * The AI and Search Console are injected as callables, so every branch is
 * unit-testable without a provider.
 *
 * @package ThinkRank\AI
 * @since 2.21.2
 */

declare(strict_types=1);

namespace ThinkRank\AI;

use ThinkRank\AI\Prompts\Question_Coverage_Prompts;
use ThinkRank\SEO\Builder_Content;
use ThinkRank\SEO\Focus_Keywords;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds and judges a post's question coverage.
 */
final class Question_Coverage_Analyzer {

    /**
     * Post meta holding the last result.
     */
    public const META_KEY = '_thinkrank_question_coverage';

    /**
     * Question sets are reused this long for the same keyword + locale.
     */
    public const QUESTIONS_TTL = 30 * DAY_IN_SECONDS;

    /**
     * Coverage verdicts are reused this long for the same questions + content.
     */
    public const COVERAGE_TTL = 30 * DAY_IN_SECONDS;

    /**
     * A page's Search Console queries are re-fetched at most daily.
     */
    public const GSC_TTL = DAY_IN_SECONDS;

    /**
     * Bounds on the generated set.
     */
    public const MIN_QUESTIONS = 8;
    public const MAX_QUESTIONS = 15;

    /**
     * Search Console queries added at most, and the impressions floor.
     */
    public const MAX_GSC_QUESTIONS = 10;
    public const MIN_GSC_IMPRESSIONS = 10;

    /**
     * Text sent for judging: per section, and for the whole page. Long guides
     * keep the start of each section, which is where an answer usually sits.
     */
    private const SECTION_CHARS = 1500;
    private const PAGE_CHARS    = 16000;

    /**
     * Statuses a verdict may carry.
     */
    public const STATUSES = ['answered', 'partial', 'missing'];

    /**
     * Words that never count as a head term of a keyword.
     */
    private const STOP_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'for', 'to', 'of', 'in', 'on', 'at', 'by', 'with', 'from', 'is', 'are',
        'how', 'what', 'why', 'when', 'where', 'which', 'who', 'best', 'top', 'vs', 'under', 'over', 'your',
        'you', 'my', 'do', 'does', 'can', 'near', 'me', 'guide', 'review', 'reviews',
    ];

    /**
     * AI completion: fn(string $prompt, string $action, int $max_tokens): array{text:string,tokens:int}.
     *
     * @var callable
     */
    private $complete;

    /**
     * Search Console rows for a URL: fn(string $url): array{status:string,rows:array}.
     *
     * @var callable
     */
    private $search_console;

    /**
     * Tokens spent by the current run.
     *
     * @var int
     */
    private $tokens = 0;

    /**
     * @param callable|null $complete       AI completion; defaults to the AI Manager.
     * @param callable|null $search_console Page query rows; defaults to the connected Search Console.
     */
    public function __construct(?callable $complete = null, ?callable $search_console = null) {
        $this->complete       = $complete ?? [self::class, 'default_complete'];
        $this->search_console = $search_console ?? [self::class, 'default_search_console'];
    }

    // -- Public API ----------------------------------------------------------

    /**
     * Build and judge the question set for a post.
     *
     * @param int                 $post_id Post.
     * @param array<string,mixed> $options {
     *     @type string $content           Live editor content to judge instead of the stored post.
     *     @type string $keyword           Keyword to use instead of the stored focus keyword.
     *     @type bool   $refresh_questions Regenerate the AI questions even when cached.
     * }
     * @return array<string,mixed>|\WP_Error
     */
    public function analyze(int $post_id, array $options = []) {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('thinkrank_qc_no_post', __('That post does not exist.', 'thinkrank'), ['status' => 404]);
        }

        $keyword = $this->keyword_for($post_id, $options);
        if ('' === $keyword) {
            return self::no_keyword_error();
        }

        $sections = $this->sections_for($post, $options);
        if ([] === $sections) {
            return new \WP_Error(
                'thinkrank_qc_no_content',
                __('This post has no text yet. Write the content first, then check which questions it answers.', 'thinkrank'),
                ['status' => 400]
            );
        }

        $this->tokens = 0;
        $language     = Language_Resolver::resolve($post_id);
        $locale       = Language_Resolver::resolve_locale($post_id);

        try {
            $generated = $this->generated_questions($keyword, $locale, $language, (string) $post->post_title, !empty($options['refresh_questions']));
        } catch (\Exception $e) {
            return new \WP_Error('thinkrank_qc_ai_failed', $e->getMessage(), ['status' => 502]);
        }

        $gsc       = $this->search_console_questions($post_id, $keyword);
        $questions = self::merge_questions($generated['questions'], $gsc['questions']);

        try {
            $verdicts = $this->coverage($questions, $sections, $language);
        } catch (\Exception $e) {
            return new \WP_Error('thinkrank_qc_ai_failed', $e->getMessage(), ['status' => 502]);
        }

        $result = self::build_result($post_id, $keyword, $questions, $sections, $verdicts['results']);

        $result['language']   = $language;
        $result['gsc']        = [
            'status' => $gsc['status'],
            'count'  => count($gsc['questions']),
        ];
        $result['cache']      = [
            'questions' => $generated['cached'] ? 'hit' : 'miss',
            'coverage'  => $verdicts['cached'] ? 'hit' : 'miss',
        ];
        $result['tokens_used'] = $this->tokens;

        update_post_meta($post_id, self::META_KEY, $result);

        /**
         * Fires after a post's question coverage is computed.
         *
         * ThinkRank Pro keeps coverage history from here.
         *
         * @since 2.21.2
         *
         * @param int                 $post_id Post.
         * @param array<string,mixed> $result  The stored result.
         */
        do_action('thinkrank_question_coverage_analyzed', $post_id, $result);

        return $result;
    }

    /**
     * The last stored result for a post, or null.
     *
     * @param int $post_id Post.
     * @return array<string,mixed>|null
     */
    public static function stored(int $post_id): ?array {
        $stored = get_post_meta($post_id, self::META_KEY, true);
        return is_array($stored) && isset($stored['questions']) ? $stored : null;
    }

    /**
     * What a run would cost, before it spends anything.
     *
     * @param int                 $post_id Post.
     * @param array<string,mixed> $options As analyze().
     * @return array<string,mixed>|\WP_Error
     */
    public function estimate(int $post_id, array $options = []) {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('thinkrank_qc_no_post', __('That post does not exist.', 'thinkrank'), ['status' => 404]);
        }

        $keyword = $this->keyword_for($post_id, $options);
        if ('' === $keyword) {
            return self::no_keyword_error();
        }

        $sections         = $this->sections_for($post, $options);
        $questions_cached = is_array(get_transient(self::questions_cache_key($keyword, Language_Resolver::resolve_locale($post_id))));

        return [
            'keyword'          => $keyword,
            'sections'         => count($sections),
            'questions_cached' => $questions_cached,
            'tokens'           => self::estimate_tokens($sections, !$questions_cached),
        ];
    }

    /**
     * Draft a section answering one question, for the author to review.
     *
     * Never written to the post here: the editor shows the draft and the
     * author decides whether it goes in.
     *
     * @param int    $post_id  Post.
     * @param string $question Question to answer.
     * @param string $content  Live editor content, or '' for the stored post.
     * @return array{heading:string,html:string,tokens_used:int}|\WP_Error
     */
    public function draft_section(int $post_id, string $question, string $content = '') {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('thinkrank_qc_no_post', __('That post does not exist.', 'thinkrank'), ['status' => 404]);
        }
        $question = trim(wp_strip_all_tags($question));
        if ('' === $question) {
            return new \WP_Error('thinkrank_qc_no_question', __('Choose a question to draft a section for.', 'thinkrank'), ['status' => 400]);
        }

        $sections = $this->sections_for($post, ['content' => $content]);
        $context  = '';
        foreach ($sections as $section) {
            $context .= ('' !== $section['heading'] ? $section['heading'] . ': ' : '') . $section['text'] . "\n";
        }
        $context = mb_substr($context, 0, 4000);

        $this->tokens = 0;
        $prompt       = Question_Coverage_Prompts::draft_section(
            $question,
            Focus_Keywords::get_primary($post_id),
            (string) $post->post_title,
            $context,
            Language_Resolver::resolve($post_id)
        );

        try {
            $data = $this->ask_json(
                $prompt,
                'Question Coverage: draft section',
                3000,
                static function (array $json): bool {
                    return '' !== trim((string) ($json['heading'] ?? '')) && !empty($json['paragraphs']) && is_array($json['paragraphs']);
                }
            );
        } catch (\Exception $e) {
            return new \WP_Error('thinkrank_qc_ai_failed', $e->getMessage(), ['status' => 502]);
        }

        $heading = sanitize_text_field((string) $data['heading']);
        $html    = '<h2>' . esc_html($heading) . '</h2>';
        foreach ($data['paragraphs'] as $paragraph) {
            $paragraph = trim(sanitize_text_field((string) $paragraph));
            if ('' !== $paragraph) {
                $html .= "\n<p>" . esc_html($paragraph) . '</p>';
            }
        }

        return [
            'heading'     => $heading,
            'html'        => $html,
            'tokens_used' => $this->tokens,
        ];
    }

    // -- Question set --------------------------------------------------------

    /**
     * The AI-generated questions, from cache when possible.
     *
     * @param string $keyword  Focus keyword.
     * @param string $locale   Locale (cache key).
     * @param string $language Language name (prompt).
     * @param string $title    Post title.
     * @param bool   $refresh  Ignore the cache.
     * @return array{questions:array<int,array<string,mixed>>,cached:bool}
     * @throws \Exception When the AI gives no usable list.
     */
    private function generated_questions(string $keyword, string $locale, string $language, string $title, bool $refresh): array {
        $key = self::questions_cache_key($keyword, $locale);

        if (!$refresh) {
            $cached = get_transient($key);
            if (is_array($cached) && count($cached) >= 1) {
                return [
                    'questions' => $cached,
                    'cached'    => true,
                ];
            }
        }

        $data = $this->ask_json(
            Question_Coverage_Prompts::fan_out($keyword, $title, $language),
            'Question Coverage: questions',
            3000,
            static function (array $json): bool {
                return count(self::normalize_generated($json)) >= 3;
            }
        );

        $questions = self::normalize_generated($data);
        set_transient($key, $questions, self::QUESTIONS_TTL);

        return [
            'questions' => $questions,
            'cached'    => false,
        ];
    }

    /**
     * Turn the model's list into clean, deduplicated questions.
     *
     * @param array<string,mixed> $json Decoded answer.
     * @return array<int,array{id:string,question:string,facet:string,source:string}>
     */
    public static function normalize_generated(array $json): array {
        $out  = [];
        $seen = [];
        foreach ((array) ($json['questions'] ?? []) as $item) {
            $text  = is_array($item) ? (string) ($item['question'] ?? '') : (string) $item;
            $text  = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)) ?? '');
            $facet = is_array($item) ? sanitize_key((string) ($item['facet'] ?? '')) : '';
            $norm  = self::normalize_text($text);

            if ('' === $text || '' === $norm || isset($seen[$norm]) || mb_strlen($text) > 200) {
                continue;
            }
            $seen[$norm] = true;
            $out[]       = [
                'id'       => 'ai-' . substr(md5($norm), 0, 8),
                'question' => $text,
                'facet'    => in_array($facet, Question_Coverage_Prompts::FACETS, true) ? $facet : 'other',
                'source'   => 'ai',
            ];
            if (count($out) >= self::MAX_QUESTIONS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Real queries this page earns impressions for, as questions.
     *
     * @param int    $post_id Post.
     * @param string $keyword Focus keyword.
     * @return array{status:string,questions:array<int,array<string,mixed>>}
     */
    private function search_console_questions(int $post_id, string $keyword): array {
        $url = (string) get_permalink($post_id);
        if ('' === $url || 'publish' !== get_post_status($post_id)) {
            return [
                'status'    => 'not_published',
                'questions' => [],
            ];
        }

        $key    = 'thinkrank_qc_gsc_' . md5($url);
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['status'])) {
            $fetched = $cached;
        } else {
            $fetched = call_user_func($this->search_console, $url);
            $fetched = is_array($fetched) && isset($fetched['status']) ? $fetched : ['status' => 'error', 'rows' => []];
            if ('error' !== $fetched['status']) {
                set_transient($key, $fetched, self::GSC_TTL);
            }
        }

        return [
            'status'    => (string) $fetched['status'],
            'questions' => self::gsc_questions((array) ($fetched['rows'] ?? []), $keyword),
        ];
    }

    /**
     * Filter Search Console rows to queries about this topic.
     *
     * A query qualifies with at least MIN_GSC_IMPRESSIONS impressions and at
     * least one of the keyword's head terms, so a page that also ranks for
     * an unrelated term does not drag that term into its coverage.
     *
     * @param array<int,array<string,mixed>> $rows    Rows with keys[0] = query.
     * @param string                         $keyword Focus keyword.
     * @return array<int,array<string,mixed>>
     */
    public static function gsc_questions(array $rows, string $keyword): array {
        $terms = self::head_terms($keyword);
        if ([] === $terms) {
            return [];
        }

        usort($rows, static function ($a, $b): int {
            return (int) ($b['impressions'] ?? 0) <=> (int) ($a['impressions'] ?? 0);
        });

        $out = [];
        foreach ($rows as $row) {
            $query       = trim((string) ($row['keys'][0] ?? ''));
            $impressions = (int) ($row['impressions'] ?? 0);
            if ('' === $query || $impressions < self::MIN_GSC_IMPRESSIONS) {
                continue;
            }
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];
            if ([] === array_intersect($terms, $words)) {
                continue;
            }
            $out[] = [
                'id'          => 'gsc-' . substr(md5(self::normalize_text($query)), 0, 8),
                'question'    => $query,
                'facet'       => 'search_console',
                'source'      => 'search_console',
                'impressions' => $impressions,
                'clicks'      => (int) ($row['clicks'] ?? 0),
            ];
            if (count($out) >= self::MAX_GSC_QUESTIONS) {
                break;
            }
        }
        return $out;
    }

    /**
     * The words of a keyword that carry its topic.
     *
     * @param string $keyword Keyword.
     * @return string[]
     */
    public static function head_terms(string $keyword): array {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($keyword)) ?: [];
        $terms = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOP_WORDS, true) || ctype_digit($word)) {
                continue;
            }
            $terms[] = $word;
        }
        return array_values(array_unique($terms));
    }

    /**
     * Generated questions first, then Search Console queries not already
     * asked in other words.
     *
     * @param array<int,array<string,mixed>> $generated AI questions.
     * @param array<int,array<string,mixed>> $gsc       Search Console questions.
     * @return array<int,array<string,mixed>>
     */
    public static function merge_questions(array $generated, array $gsc): array {
        $seen = [];
        $out  = [];
        foreach (array_merge($generated, $gsc) as $question) {
            $norm = self::normalize_text((string) $question['question']);
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $out[]       = $question;
        }
        return $out;
    }

    // -- Coverage -------------------------------------------------------------

    /**
     * Verdicts for a question set against the page, from cache when possible.
     *
     * @param array<int,array<string,mixed>> $questions Questions.
     * @param array<int,array<string,mixed>> $sections  Sections.
     * @param string                         $language  Language name.
     * @return array{results:array<string,array<string,mixed>>,cached:bool}
     * @throws \Exception When the AI gives no usable verdicts.
     */
    private function coverage(array $questions, array $sections, string $language): array {
        $key    = self::coverage_cache_key($questions, $sections);
        $cached = get_transient($key);
        if (is_array($cached) && [] !== $cached) {
            return [
                'results' => $cached,
                'cached'  => true,
            ];
        }

        $ids      = array_column($questions, 'id');
        $trimmed  = self::trim_sections($sections);
        $data     = $this->ask_json(
            Question_Coverage_Prompts::coverage($questions, $trimmed, $language),
            'Question Coverage: coverage',
            6000,
            static function (array $json) use ($ids): bool {
                return count(self::normalize_verdicts($json, $ids)) >= max(1, (int) floor(count($ids) / 2));
            }
        );

        $results = self::normalize_verdicts($data, $ids);
        set_transient($key, $results, self::COVERAGE_TTL);

        return [
            'results' => $results,
            'cached'  => false,
        ];
    }

    /**
     * Index the model's verdicts by question id, with unknown statuses read as
     * missing and section numbers kept only when they exist.
     *
     * @param array<string,mixed> $json Decoded answer.
     * @param string[]            $ids  Question ids asked about.
     * @return array<string,array{status:string,section:?int,after:?int,reason:string}>
     */
    public static function normalize_verdicts(array $json, array $ids): array {
        $out = [];
        foreach ((array) ($json['results'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            if (!in_array($id, $ids, true) || isset($out[$id])) {
                continue;
            }
            $status = strtolower(trim((string) ($item['status'] ?? '')));
            if (!in_array($status, self::STATUSES, true)) {
                $status = 'missing';
            }
            $out[$id] = [
                'status'  => $status,
                'section' => isset($item['section']) && is_numeric($item['section']) ? (int) $item['section'] : null,
                'after'   => isset($item['after']) && is_numeric($item['after']) ? (int) $item['after'] : null,
                'reason'  => mb_substr(sanitize_text_field((string) ($item['reason'] ?? '')), 0, 240),
            ];
        }
        return $out;
    }

    /**
     * The result the editor and the abilities read.
     *
     * @param int                                   $post_id   Post.
     * @param string                                $keyword   Keyword.
     * @param array<int,array<string,mixed>>        $questions Questions.
     * @param array<int,array<string,mixed>>        $sections  Sections.
     * @param array<string,array<string,mixed>>     $verdicts  Verdicts by id.
     * @return array<string,mixed>
     */
    public static function build_result(int $post_id, string $keyword, array $questions, array $sections, array $verdicts): array {
        $headings = [];
        foreach ($sections as $section) {
            $headings[(int) $section['index']] = (string) $section['heading'];
        }

        $counts = array_fill_keys(self::STATUSES, 0);
        $rows   = [];
        foreach ($questions as $question) {
            $verdict = $verdicts[$question['id']] ?? [
                'status'  => 'missing',
                'section' => null,
                'after'   => null,
                'reason'  => '',
            ];
            $status  = $verdict['status'];
            $section = 'missing' !== $status && null !== $verdict['section'] && array_key_exists($verdict['section'], $headings)
                ? $verdict['section'] : null;
            $after   = 'answered' !== $status && null !== $verdict['after'] && ( -1 === $verdict['after'] || array_key_exists($verdict['after'], $headings) )
                ? $verdict['after'] : null;

            $counts[$status]++;
            $rows[] = array_merge(
                $question,
                [
                    'status'        => $status,
                    'section_index' => $section,
                    // '' means the introduction, before the first heading.
                    'section'       => null === $section ? null : $headings[$section],
                    'after_index'   => $after,
                    'after'         => null === $after ? null : ( -1 === $after ? '' : $headings[$after] ),
                    'reason'        => (string) $verdict['reason'],
                ]
            );
        }

        return [
            'post_id'      => $post_id,
            'keyword'      => $keyword,
            'generated_at' => gmdate('c'),
            'questions'    => $rows,
            'counts'       => $counts + ['total' => count($rows)],
            'sections'     => array_values(array_map(static function ($section): array {
                return [
                    'index'   => (int) $section['index'],
                    'heading' => (string) $section['heading'],
                    'level'   => (int) $section['level'],
                ];
            }, $sections)),
        ];
    }

    // -- Sections -------------------------------------------------------------

    /**
     * Split HTML into sections at its H2 and H3 headings.
     *
     * Text before the first heading is the introduction (heading ''). Script,
     * style and block comments are dropped; a heading with no text after it is
     * kept, because "the page has a heading for this but says nothing" is
     * itself a coverage finding.
     *
     * @param string $html Post HTML.
     * @return array<int,array{index:int,heading:string,level:int,text:string,words:int}>
     */
    public static function split_sections(string $html): array {
        $html = (string) preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('/<!--.*?-->/s', ' ', $html);

        $parts = preg_split('#(<h([23])\b[^>]*>.*?</h\2>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return [];
        }

        $sections = [];
        $heading  = '';
        $level    = 0;
        $buffer   = '';

        $flush = static function () use (&$sections, &$heading, &$level, &$buffer): void {
            $text = self::plain_text($buffer);
            if ('' === $text && '' === $heading) {
                return;
            }
            $sections[] = [
                'index'   => count($sections),
                'heading' => $heading,
                'level'   => $level,
                'text'    => $text,
                'words'   => '' === $text ? 0 : count(preg_split('/\s+/u', $text) ?: []),
            ];
        };

        $count = count($parts);
        for ($i = 0; $i < $count; $i++) {
            $part = $parts[$i];
            if (preg_match('#^<h([23])\b[^>]*>(.*?)</h\1>$#is', $part, $m)) {
                $flush();
                $heading = self::plain_text($m[2]);
                $level   = (int) $m[1];
                $buffer  = '';
                $i++; // Skip the captured level digit.
                continue;
            }
            $buffer .= $part;
        }
        $flush();

        return $sections;
    }

    /**
     * Sections of a post, from the live editor content when given, otherwise
     * from what the visitor actually reads (builders included).
     *
     * @param \WP_Post            $post    Post.
     * @param array<string,mixed> $options Options; `content` is the editor's.
     * @return array<int,array<string,mixed>>
     */
    private function sections_for(\WP_Post $post, array $options): array {
        $live = isset($options['content']) ? (string) $options['content'] : '';
        $html = '' !== trim($live)
            ? Builder_Content::resolve_markup($live, $post)
            : Builder_Content::resolve($post);

        return self::split_sections($html);
    }

    /**
     * Cap the text sent for judging.
     *
     * @param array<int,array<string,mixed>> $sections Sections.
     * @return array<int,array<string,mixed>>
     */
    public static function trim_sections(array $sections): array {
        $budget = self::PAGE_CHARS;
        $out    = [];
        foreach ($sections as $section) {
            $text           = mb_substr((string) $section['text'], 0, min(self::SECTION_CHARS, max(0, $budget)));
            $budget        -= mb_strlen($text);
            $section['text'] = $text;
            $out[]          = $section;
        }
        return $out;
    }

    // -- Caching and cost ------------------------------------------------------

    /**
     * Cache key of a question set: keyword + locale.
     *
     * @param string $keyword Keyword.
     * @param string $locale  Locale.
     * @return string
     */
    public static function questions_cache_key(string $keyword, string $locale): string {
        return 'thinkrank_qc_q_' . md5(self::normalize_text($keyword) . '|' . $locale);
    }

    /**
     * Cache key of a coverage verdict: the question set + the page text.
     *
     * @param array<int,array<string,mixed>> $questions Questions.
     * @param array<int,array<string,mixed>> $sections  Sections.
     * @return string
     */
    public static function coverage_cache_key(array $questions, array $sections): string {
        $q = array_map(static fn ($question) => $question['id'] . ':' . $question['question'], $questions);
        $s = array_map(static fn ($section) => $section['heading'] . "\n" . $section['text'], $sections);
        return 'thinkrank_qc_c_' . md5((string) wp_json_encode([$q, $s]));
    }

    /**
     * Rough token cost of a run: about four characters a token, plus the
     * fixed prompt and the expected answer.
     *
     * @param array<int,array<string,mixed>> $sections           Sections.
     * @param bool                           $generate_questions Whether the question set must be generated.
     * @return int
     */
    public static function estimate_tokens(array $sections, bool $generate_questions): int {
        $chars = 0;
        foreach (self::trim_sections($sections) as $section) {
            $chars += mb_strlen((string) $section['heading']) + mb_strlen((string) $section['text']) + 8;
        }
        $coverage = (int) ceil($chars / 4) + 900 + 1500;
        $fan_out  = $generate_questions ? 350 + 900 : 0;

        return $coverage + $fan_out;
    }

    // -- AI plumbing ------------------------------------------------------------

    /**
     * Ask for JSON, retrying once when the answer does not parse or does not
     * pass $valid.
     *
     * @param string   $prompt     Prompt.
     * @param string   $action     Usage label.
     * @param int      $max_tokens Output ceiling.
     * @param callable $valid      fn(array $json): bool.
     * @return array<string,mixed>
     * @throws \Exception When both attempts fail.
     */
    private function ask_json(string $prompt, string $action, int $max_tokens, callable $valid): array {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $answer        = call_user_func($this->complete, $prompt, $action, $max_tokens);
            $this->tokens += (int) ($answer['tokens'] ?? 0);
            $json          = self::decode_json((string) ($answer['text'] ?? ''));
            if (null !== $json && $valid($json)) {
                return $json;
            }
        }
        throw new \Exception(esc_html__('The AI did not return a usable answer. Please try again.', 'thinkrank'));
    }

    /**
     * Decode the JSON object in a model's answer: tolerates code fences and
     * prose around it, and a trailing comma before a closing bracket.
     *
     * @param string $text Raw answer.
     * @return array<string,mixed>|null
     */
    public static function decode_json(string $text): ?array {
        $text  = (string) preg_replace('/^\s*```(?:json)?|```\s*$/mi', '', $text);
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if (false === $start || false === $end || $end <= $start) {
            return null;
        }
        $candidate = substr($text, $start, $end - $start + 1);
        $decoded   = json_decode($candidate, true);
        if (!is_array($decoded)) {
            $decoded = json_decode((string) preg_replace('/,\s*([\]}])/', '$1', $candidate), true);
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Default completion: the configured provider, through the AI Manager.
     *
     * @param string $prompt     Prompt.
     * @param string $action     Usage label.
     * @param int    $max_tokens Output ceiling.
     * @return array{text:string,tokens:int}
     * @throws \Exception When AI is unavailable.
     */
    public static function default_complete(string $prompt, string $action, int $max_tokens): array {
        return (new Manager())->complete_text($prompt, $action, $max_tokens);
    }

    /**
     * Default Search Console lookup: this page's queries, last 90 days.
     *
     * @param string $url Page URL.
     * @return array{status:string,rows:array<int,array<string,mixed>>}
     */
    public static function default_search_console(string $url): array {
        $settings = \ThinkRank\Core\Settings::instance();
        $connected = '' !== (string) $settings->get('google_access_token', '')
            || '' !== (string) $settings->get('google_search_console_api_key', '');
        if (!$connected || !class_exists('\ThinkRank\SEO\Analytics_Manager')) {
            return [
                'status' => 'not_connected',
                'rows'   => [],
            ];
        }

        try {
            $manager = new \ThinkRank\SEO\Analytics_Manager();
            // No property picked yet reads as not connected: asking Google
            // with an empty property fails, and an `error` is never cached,
            // so every analysis would pay for the same refused call.
            if (!$manager->has_search_console_property()) {
                return [
                    'status' => 'not_connected',
                    'rows'   => [],
                ];
            }
            $client  = $manager->get_search_console_client();
            if (null === $client) {
                return [
                    'status' => 'not_connected',
                    'rows'   => [],
                ];
            }
            $end  = gmdate('Y-m-d', strtotime('-2 days'));
            $rows = $client->get_search_performance_by_dates(
                $manager->get_property_url(),
                gmdate('Y-m-d', strtotime('-91 days')),
                $end,
                250,
                ['query'],
                [
                    [
                        'dimension'  => 'page',
                        'operator'   => 'equals',
                        'expression' => $url,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'rows'   => [],
            ];
        }

        return [
            'status' => [] === $rows ? 'no_rows' : 'ok',
            'rows'   => $rows,
        ];
    }

    // -- Helpers -------------------------------------------------------------------

    /**
     * The keyword for a run: the explicit one, else the stored focus keyword.
     *
     * @param int                 $post_id Post.
     * @param array<string,mixed> $options Options.
     * @return string
     */
    private function keyword_for(int $post_id, array $options): string {
        $keyword = trim(sanitize_text_field((string) ($options['keyword'] ?? '')));
        return '' !== $keyword ? $keyword : trim(Focus_Keywords::get_primary($post_id));
    }

    /**
     * The error a run without a keyword returns.
     *
     * @return \WP_Error
     */
    private static function no_keyword_error(): \WP_Error {
        return new \WP_Error(
            'thinkrank_qc_no_keyword',
            __('Set a focus keyword first. Question Coverage builds its questions from it.', 'thinkrank'),
            ['status' => 400]
        );
    }

    /**
     * Strip tags and collapse whitespace.
     *
     * @param string $html HTML.
     * @return string
     */
    private static function plain_text(string $html): string {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Lowercased, punctuation-free form used to spot duplicates.
     *
     * @param string $text Text.
     * @return string
     */
    private static function normalize_text(string $text): string {
        $text = mb_strtolower(trim($text));
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }
}
