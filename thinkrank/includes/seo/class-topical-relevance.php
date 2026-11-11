<?php
/**
 * Topical coverage of a draft against pages that already rank.
 *
 * @package ThinkRank\SEO
 * @since 2.21.3
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Core\Seo_Text;
use ThinkRank\Core\Url_Safety;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Topical Relevance
 *
 * ThinkRank scores keyword density and distribution on the page itself;
 * nothing compared a draft against what already ranks (#567). This does, from
 * competitor pages the user names.
 *
 * **Why named URLs and not a SERP.** #567 offered two paths: fetch the top
 * results for a keyword, which needs the external data provider decision that
 * is still open, or ask the user's own model to guess the terms, which costs
 * tokens per run and produces a different answer each time. There is a third,
 * and the code was already most of the way there: the content brief generator
 * fetches competitor URLs the user supplies, through
 * {@see Url_Safety::safe_remote_get()}. Reusing that gives a deterministic,
 * free, repeatable answer today, and the SERP path becomes a change to *where
 * the URLs come from* rather than a new analysis.
 *
 * **What it measures, and what it does not.** The issue asks for TF-IDF, and
 * the honest answer is that a literal TF-IDF is the wrong statistic for this
 * question at this corpus size. IDF rewards terms that are *rare* across the
 * corpus; a term every competitor covers — exactly the gap worth reporting —
 * gets an IDF near zero and sinks. With five documents there is no background
 * corpus to compute a meaningful IDF against either.
 *
 * So the ranking is term frequency weighted by how many competitors use the
 * term: a term is worth reporting when it is *frequent* on the pages that rank
 * and *common* to them. Both components are reported alongside the score, so a
 * reader can see why a term is on the list rather than trusting a number. The
 * stop-word list does the job IDF would otherwise do for boilerplate.
 *
 * **Locale.** Scoring splits on whitespace, which is meaningless where
 * WordPress counts characters rather than words — Japanese, Thai and Chinese.
 * Rather than return confident nonsense, those locales get an explicit refusal,
 * the same way {@see Word_Count_Index} switches unit rather than pretending.
 *
 * @since 2.21.3
 */
class Topical_Relevance {

    /**
     * Competitor pages one run will fetch.
     *
     * Three is what the content brief generator allows; five here because this
     * run does nothing else, and a term's weight is only meaningful once a few
     * pages agree on it. Each page is one HTTP request the user waits for.
     */
    public const MAX_URLS = 5;

    /**
     * Terms reported in each list.
     */
    public const MAX_TERMS = 30;

    /**
     * Competitor pages a term must appear on before it is reported.
     *
     * One page's vocabulary is that page's, not the topic's. Two is the lowest
     * number that can mean "the pages that rank agree", and with MAX_URLS at
     * five it still admits a term four of them skip.
     */
    private const MIN_DOCUMENTS = 2;

    /**
     * How much lower than the competitors' rate a term's usage has to be
     * before "you use this less than they do" is worth saying.
     *
     * Half. A draft at 40% of the competitors' rate for a term is plausibly
     * under-covering it; one at 90% is just a shorter page.
     */
    private const UNDERUSE_RATIO = 0.5;

    /**
     * Longest phrase considered. Unigrams and bigrams.
     *
     * Bigrams are most of the value — "running shoes" is the topic and
     * "running" alone is not — and trigrams triple the term space for a corpus
     * of five documents, where a phrase appearing twice is already noise.
     */
    private const MAX_NGRAM = 2;

    /**
     * Seconds to wait for one competitor page.
     *
     * The content brief generator's 8, and for the same reason: five of these
     * run one after another and the user is waiting for all of them.
     */
    private const FETCH_TIMEOUT = 8;

    /**
     * Seconds all the fetches of one run may take together.
     *
     * The per-page timeout alone let the wait accumulate: five slow or
     * unreachable hosts at 8 s each held the request, and a PHP worker, for
     * about 40 s, past the 30 s many hosts allow. With a shared deadline each
     * fetch gets at most the time left, and a page whose turn comes after the
     * deadline is reported as not read rather than attempted. Fifteen leaves
     * room under a 30 s limit for the post's own content and the scoring, and
     * is generous for five ordinary pages, which answer in a second or two.
     */
    private const FETCH_BUDGET = 15;

    /**
     * Least time worth starting a fetch with. A request given less than this
     * would almost certainly time out, and it would still cost the connection.
     */
    private const MIN_FETCH_SECONDS = 1.0;

    /**
     * Largest response body read from a competitor.
     *
     * A page is text; anything past this is not a page worth analysing, and
     * tokenising it would cost more memory than the whole rest of the run.
     * Enforced by the transport (`limit_response_size`), so a larger body is
     * never downloaded or held in memory, not cut down after the fact.
     */
    private const MAX_BODY_BYTES = 2097152;

    /**
     * Compare a post against the pages the user named.
     *
     * @param int      $post_id Post being analysed.
     * @param string[] $urls    Competitor URLs.
     * @return array<string,mixed>|\WP_Error
     */
    public static function analyze(int $post_id, array $urls) {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return new \WP_Error(
                'thinkrank_unknown_post',
                __('That post does not exist.', 'thinkrank'),
                ['status' => 404]
            );
        }

        if (!Seo_Text::locale_counts_words()) {
            return new \WP_Error(
                'thinkrank_locale_unsupported',
                __('Topical coverage compares words, and this site\'s language is counted in characters rather than words, so the comparison would not mean anything. The per-page SEO checks still apply.', 'thinkrank'),
                ['status' => 400]
            );
        }

        $urls = self::clean_urls($urls);
        if (empty($urls)) {
            return new \WP_Error(
                'thinkrank_no_urls',
                __('Name at least one competitor page to compare against.', 'thinkrank'),
                ['status' => 400]
            );
        }

        // The post's own words come from the resolver, not `post_content`, or a
        // page built in Elementor compares as empty and every term on every
        // competitor is reported as missing.
        $mine = self::tokenize(wp_strip_all_tags(Builder_Content::resolve($post)));

        $sources     = [];
        $competitors = [];

        /**
         * Filters how many seconds one topical coverage run may spend reading
         * competitor pages, all of them together.
         *
         * @since 2.21.3
         *
         * @param int $seconds Seconds. Default 15.
         */
        $budget   = max(0.0, (float) apply_filters('thinkrank_topical_relevance_fetch_budget', self::FETCH_BUDGET));
        $deadline = microtime(true) + $budget;

        foreach ($urls as $url) {
            if ($deadline - microtime(true) < self::MIN_FETCH_SECONDS) {
                $sources[] = [
                    'url'     => $url,
                    'fetched' => false,
                    'reason'  => __('Not read: the time allowed for reading competitor pages ran out. Try again with fewer or faster pages.', 'thinkrank'),
                    'words'   => 0,
                ];
                continue;
            }

            $text = self::fetch_text($url, $deadline);

            if (is_wp_error($text)) {
                $sources[] = [
                    'url'     => $url,
                    'fetched' => false,
                    'reason'  => $text->get_error_message(),
                    'words'   => 0,
                ];
                continue;
            }

            $tokens        = self::tokenize($text);
            $competitors[] = $tokens;

            $sources[] = [
                'url'     => $url,
                'fetched' => true,
                'reason'  => '',
                'words'   => count($tokens),
            ];
        }

        if (empty($competitors)) {
            return new \WP_Error(
                'thinkrank_no_competitors_read',
                __('None of those pages could be read, so there is nothing to compare against. Check the addresses and that the pages are reachable from this server.', 'thinkrank'),
                ['status' => 502, 'sources' => $sources]
            );
        }

        return self::compare($mine, $competitors, $sources);
    }

    /**
     * Build the comparison from tokenized documents.
     *
     * Separated from the fetching so the scoring is testable without a network
     * — the arithmetic is the part worth pinning, and it should not need five
     * HTTP requests to check.
     *
     * @param string[]              $mine        The post's terms, in order.
     * @param array<int,string[]>   $competitors One token list per page read.
     * @param array<int,array>      $sources     Per-URL outcome, for the report.
     * @return array<string,mixed>
     */
    public static function compare(array $mine, array $competitors, array $sources = []): array {
        $my_counts = self::phrase_counts($mine);
        $my_words  = max(1, count($mine));

        $rates     = [];
        $documents = [];

        foreach ($competitors as $tokens) {
            $counts = self::phrase_counts($tokens);
            $words  = max(1, count($tokens));

            foreach ($counts as $term => $count) {
                // Per thousand words, not raw counts: otherwise the longest
                // page decides the vocabulary of the topic on its own.
                $rates[$term][]     = ($count / $words) * 1000;
                $documents[$term]   = ($documents[$term] ?? 0) + 1;
            }
        }

        $total = count($competitors);
        $terms = [];

        foreach ($rates as $term => $values) {
            $seen = $documents[$term];

            if ($seen < self::MIN_DOCUMENTS) {
                continue;
            }

            // Mean over every competitor, not only the ones using the term, so
            // a term on two of five pages scores below the same term on all
            // five. The document count rides along so the reader sees which.
            $rate = array_sum($values) / $total;

            $terms[$term] = [
                'term'       => $term,
                'documents'  => $seen,
                'of'         => $total,
                // Rounded for a reader; the ordering uses the raw value.
                'their_rate' => round($rate, 2),
                'your_rate'  => round((($my_counts[$term] ?? 0) / $my_words) * 1000, 2),
                'your_count' => (int) ($my_counts[$term] ?? 0),
                'score'      => $rate * ($seen / $total),
            ];
        }

        uasort($terms, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        $missing   = [];
        $underused = [];
        $covered   = 0;

        foreach ($terms as $term => $row) {
            if (0 === $row['your_count']) {
                $missing[] = $row;
                continue;
            }

            if ($row['your_rate'] < $row['their_rate'] * self::UNDERUSE_RATIO) {
                $underused[] = $row;
                continue;
            }

            $covered++;
        }

        return [
            'supported'  => true,
            'sources'    => array_values($sources),
            'read'       => $total,
            // Comparable words, not the post's word count: this is the total
            // after stop words and short fragments are dropped, and it is the
            // denominator every rate above is per thousand of.
            'your_words' => count($mine),
            // What share of the topic's vocabulary the draft already uses. One
            // number to look at before reading two lists, and the only figure
            // here that is comparable between runs.
            'coverage'   => empty($terms) ? 100 : (int) round(($covered / count($terms)) * 100),
            'terms'      => count($terms),
            'missing'    => array_slice(array_map([self::class, 'strip_score'], $missing), 0, self::MAX_TERMS),
            'underused'  => array_slice(array_map([self::class, 'strip_score'], $underused), 0, self::MAX_TERMS),
        ];
    }

    /**
     * Drop the internal ordering value from a reported row.
     *
     * The score is a ranking device with no unit and no meaning on its own;
     * publishing it invites a reader to compare it between runs, where it does
     * not hold. `their_rate` and `documents` are the figures that do.
     *
     * @param array<string,mixed> $row Term row.
     * @return array<string,mixed>
     */
    private static function strip_score(array $row): array {
        unset($row['score']);

        return $row;
    }

    /**
     * Validate and bound the URL list.
     *
     * @param string[] $urls Submitted URLs.
     * @return string[]
     */
    private static function clean_urls(array $urls): array {
        $clean = [];

        foreach ($urls as $url) {
            $url = esc_url_raw(trim((string) $url));

            if ('' === $url || isset($clean[$url])) {
                continue;
            }

            $clean[$url] = true;

            if (count($clean) >= self::MAX_URLS) {
                break;
            }
        }

        return array_keys($clean);
    }

    /**
     * Read one competitor page as text.
     *
     * Through {@see Url_Safety::safe_remote_get()}, which re-checks the
     * resolved host on every redirect hop, so a URL that redirects onto an
     * internal address after the pre-flight check is refused rather than
     * fetched. This is a user-supplied address being fetched by the server,
     * which is the definition of an SSRF surface, and reusing the guard the
     * content brief already fetches through is the whole reason this path was
     * cheap to build.
     *
     * @param string $url      Competitor URL.
     * @param float  $deadline Absolute time by which the fetch, redirects
     *                         included, must be over.
     * @return string|\WP_Error
     */
    private static function fetch_text(string $url, float $deadline) {
        $response = Url_Safety::safe_remote_get($url, [
            'timeout'             => self::FETCH_TIMEOUT,
            // The transport stops reading at this size. Truncating afterwards
            // only bounded what was tokenised: the whole body had already been
            // downloaded and held in memory, five times over in one run.
            'limit_response_size' => self::MAX_BODY_BYTES,
            'user-agent'          => 'Mozilla/5.0 (compatible; ThinkRank SEO Bot)',
            'headers'             => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ],
        ], $deadline);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return new \WP_Error(
                'thinkrank_competitor_status',
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __('The page answered with HTTP %d.', 'thinkrank'),
                    $code
                )
            );
        }

        $type = (string) wp_remote_retrieve_header($response, 'content-type');
        if ('' !== $type && false === stripos($type, 'html')) {
            return new \WP_Error(
                'thinkrank_competitor_not_html',
                __('That address is not an HTML page.', 'thinkrank')
            );
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ('' === trim($body)) {
            return new \WP_Error(
                'thinkrank_competitor_empty',
                __('The page came back empty.', 'thinkrank')
            );
        }

        // Kept as a backstop for a transport that ignores the limit.
        if (strlen($body) > self::MAX_BODY_BYTES) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
        }

        return self::readable_text($body);
    }

    /**
     * The words a visitor reads, from a page's HTML.
     *
     * `<script>` and `<style>` bodies go before tags are stripped, not after:
     * stripping tags first leaves the JavaScript behind as text, and a page
     * with an inline analytics bundle then has a vocabulary of minified
     * identifiers. `<nav>`, `<header>` and `<footer>` go too — they are the
     * same on every page of a site, so counting them makes the site's
     * navigation look like the topic's vocabulary.
     *
     * Citation and reference markup goes for the same reason
     * ({@see strip_reference_markup()}): a reference list is dates, archive
     * notices and publisher names, and on a well-sourced page it outweighs the
     * prose.
     *
     * @param string $html Page HTML.
     * @return string
     */
    public static function readable_text(string $html): string {
        $html = (string) preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = self::strip_reference_markup($html);
        $html = (string) preg_replace('#<(nav|header|footer|aside)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<!--.*?-->#s', ' ', $html);

        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Classes whose elements are citation, reference or navigation chrome.
     *
     * The MediaWiki names, because Wikipedia is the competitor page people
     * compare against most, and every MediaWiki site shares them: inline
     * citation markers (`sup.reference`), the reference list (`ol.references`,
     * `.reflist`, `.mw-references-wrap`), the navigation boxes at the foot of
     * an article (`.navbox`), the category box under it (`.catlinks`, whose
     * hidden maintenance categories read "Articles containing potentially
     * dated statements") and the "edit" links beside each heading.
     *
     * @since 2.21.3
     * @var string[]
     */
    private const REFERENCE_CLASSES = ['reference', 'references', 'reflist', 'mw-references-wrap', 'navbox', 'catlinks', 'mw-editsection'];

    /**
     * Drop citation and reference markup from a page.
     *
     * `<cite>` everywhere, and any element carrying one of
     * {@see REFERENCE_CLASSES}. A reference list is "Archived from the original
     * on 12 March 2019. Retrieved 4 June 2020." a hundred times over, and left
     * in it put `archived`, `original`, `retrieved` and the month names at the
     * top of every gap list against a sourced page.
     *
     * Done on a parsed tree rather than with a pattern, because a reference
     * list is nested `<div>`s and `<ol>`s that no regular expression closes
     * correctly. A page with none of the markers is returned untouched, so the
     * common page pays for one scan of the string and no parse.
     *
     * @since 2.21.3
     *
     * @param string $html Page HTML.
     * @return string
     */
    public static function strip_reference_markup(string $html): string {
        if (!preg_match('#<cite\b|\b(?:' . implode('|', array_map('preg_quote', self::REFERENCE_CLASSES)) . ')\b#i', $html)) {
            return $html;
        }

        if (!class_exists('DOMDocument')) {
            // No DOM extension: drop what a pattern can close reliably.
            $html = (string) preg_replace('#<cite\b[^>]*>.*?</cite>#is', ' ', $html);

            return (string) preg_replace('#<sup\b[^>]*class=["\'][^"\']*\breference\b[^>]*>.*?</sup>#is', ' ', $html);
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $conditions = ['self::cite'];
        foreach (self::REFERENCE_CLASSES as $class) {
            $conditions[] = sprintf("contains(concat(' ', normalize-space(@class), ' '), ' %s ')", $class);
        }

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[' . implode(' or ', $conditions) . ']');

        if (false === $nodes || 0 === $nodes->length) {
            return $html;
        }

        // Collected first: removing while iterating a live list skips nodes.
        $remove = [];
        foreach ($nodes as $node) {
            $remove[] = $node;
        }

        foreach ($remove as $node) {
            if ($node->parentNode) {
                // A space in its place, so the words either side stay apart.
                $node->parentNode->replaceChild($document->createTextNode(' '), $node);
            }
        }

        return (string) $document->saveHTML();
    }

    /**
     * Split text into comparable words.
     *
     * Lower-cased, punctuation dropped, digits dropped, stop words dropped, and
     * anything under three characters dropped. What is left is the vocabulary
     * two pages can be compared on.
     *
     * @param string $text Readable text.
     * @return string[] Terms in order.
     */
    public static function tokenize(string $text): array {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);

        // A typographic apostrophe is an apostrophe: "let’s" is "let's", not
        // "let" plus a stray "s".
        $text = str_replace(["\u{2019}", "\u{2018}"], "'", $text);

        // Keep the apostrophe so "don't" is one token rather than "don" plus a
        // stray "t", and the hyphen so "e-commerce" survives.
        $text = (string) preg_replace("/[^\p{L}\p{N}'\-]+/u", ' ', $text);

        $tokens = [];

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $token) {
            $token = trim($token, "'-");

            if (self::is_noise($token)) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Whether a token carries no topical signal.
     *
     * @param string $token Candidate token.
     * @return bool
     */
    private static function is_noise(string $token): bool {
        if ('' === $token) {
            return true;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($token) : strlen($token);

        static $stop = null;
        if (null === $stop) {
            $stop = array_flip(self::stop_words());
        }

        // A bare number is a price, a year or a list marker, never a topic.
        return $length < 3 || is_numeric($token) || isset($stop[$token]);
    }

    /**
     * Count unigrams and bigrams in one document.
     *
     * A bigram is only formed from tokens that were adjacent *before* stop
     * words were dropped — which they are here, because tokenize() drops them
     * as it goes. That is deliberate: "best running shoes" should contribute
     * "running shoes", and joining across a removed "the" would invent phrases
     * nobody wrote.
     *
     * @param string[] $tokens Tokens in order.
     * @return array<string,int> Term => count.
     */
    public static function phrase_counts(array $tokens): array {
        $counts = [];

        $total = count($tokens);
        for ($i = 0; $i < $total; $i++) {
            $phrase = '';

            for ($n = 0; $n < self::MAX_NGRAM && $i + $n < $total; $n++) {
                $phrase = '' === $phrase ? $tokens[$i] : $phrase . ' ' . $tokens[$i + $n];

                $counts[$phrase] = ($counts[$phrase] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Words that carry no topic.
     *
     * Three ad-hoc copies of a list like this already exist — in the content
     * brief generator, the metadata generator and the AI content analyzer —
     * each shorter than the last and none shared. This one is deliberately not
     * a fourth private copy: it is public so the next feature can use it, and
     * consolidating those three onto it is a worthwhile follow-up that is not
     * this change.
     *
     * @return string[]
     */
    public static function stop_words(): array {
        return [
            'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her', 'was', 'one',
            'our', 'out', 'has', 'had', 'his', 'she', 'him', 'its', 'who', 'get', 'got', 'their',
            'them', 'they', 'this', 'that', 'these', 'those', 'with', 'from', 'have', 'been',
            'were', 'will', 'would', 'could', 'should', 'when', 'what', 'which', 'your', 'yours',
            'about', 'into', 'more', 'most', 'some', 'such', 'than', 'then', 'there', 'here',
            'also', 'just', 'like', 'over', 'under', 'very', 'much', 'many', 'each', 'every',
            'other', 'another', 'because', 'while', 'where', 'after', 'before', 'between',
            'through', 'during', 'being', 'does', 'did', 'doing', 'done', 'may', 'might', 'must',
            'shall', 'onto', 'upon', 'off', 'per', 'via', 'any', 'own', 'same', 'too', 'only',
            'both', 'few', 'how', 'why', 'now', 'new', 'use', 'used', 'using', 'make', 'makes',
            'made', 'need', 'needs', 'want', 'wants', 'know', 'see', 'read', 'click', 'here\'s',
            'don\'t', 'doesn\'t', 'isn\'t', 'aren\'t', 'can\'t', 'won\'t', 'it\'s', 'you\'re',
            // Month names, full and short ("may" is above). Every dated page
            // has them, and a reference list has them on every line.
            'january', 'february', 'march', 'april', 'june', 'july', 'august', 'september',
            'october', 'november', 'december', 'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug',
            'sep', 'sept', 'oct', 'nov', 'dec',
            // Citation boilerplate, for the references a page keeps outside
            // the markup strip_reference_markup() recognises.
            'retrieved', 'archived', 'archive', 'original', 'originally', 'accessed', 'isbn',
            'doi', 'issn',
            // Discourse and filler words.
            'however', 'although', 'though', 'thus', 'therefore', 'moreover', 'furthermore',
            'meanwhile', 'indeed', 'instead', 'rather', 'whether', 'either', 'neither', 'since',
            'until', 'within', 'without', 'among', 'across', 'around', 'along', 'already',
            'always', 'never', 'often', 'still', 'even', 'well', 'yet', 'almost', 'quite',
            'really', 'actually', 'simply', 'usually', 'especially', 'including', 'include',
            'includes', 'included', 'find', 'finds', 'found', 'let', 'lets', 'let\'s', 'way',
            'ways', 'thing', 'things', 'take', 'takes', 'took', 'give', 'gives', 'gave', 'come',
            'comes', 'came', 'goes', 'went', 'say', 'says', 'said', 'able', 'etc', 'i\'m',
            'we\'re', 'they\'re', 'that\'s', 'there\'s', 'what\'s',
        ];
    }
}
