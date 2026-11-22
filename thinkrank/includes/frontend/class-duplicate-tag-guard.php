<?php
/**
 * Duplicate Tag Guard: take foreign copies of ThinkRank's tags out of the head.
 *
 * @package ThinkRank\Frontend
 * @since 2.23.0
 */

declare(strict_types=1);

namespace ThinkRank\Frontend;

use ThinkRank\SEO\Duplicate_Tags\Duplicate_Tag_Detector;
use ThinkRank\SEO\Duplicate_Tags\Guard_Settings;
use ThinkRank\SEO\Duplicate_Tags\Head_Tags;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Duplicate Tag Guard
 *
 * When a family is switched on, buffers the page until `</head>`, removes the
 * foreign copies of tags ThinkRank printed ({@see Head_Tags::strip()}), and
 * lets the rest of the page through untouched (#733).
 *
 * The scope is deliberately narrow:
 *
 * - nothing happens unless a family is on — no buffer, so the page is byte
 *   for byte what it would be without this class;
 * - front-end HTML page requests only: never admin, AJAX, cron, REST, feeds,
 *   robots.txt, trackbacks, the Customizer preview, or a response whose
 *   Content-Type is not HTML;
 * - the head only: once `</head>` has gone past, every later chunk is passed
 *   through as it comes, so the body is never read, and a theme that flushes
 *   early still streams;
 * - `THINKRANK_DISABLE_DUPLICATE_TAG_GUARD` turns it off whatever the
 *   settings say, for a host to use when a page misbehaves.
 *
 * It writes nothing on a page view. What it removed goes into a comment
 * before `</head>` ({@see Head_Tags::marker()}) — counts, not content — which
 * the analyzer's check reads back from its own fetch of the page.
 *
 * @since 2.23.0
 */
class Duplicate_Tag_Guard {

    public const KILL_SWITCH = 'THINKRANK_DISABLE_DUPLICATE_TAG_GUARD';

    /**
     * Most bytes held while waiting for `</head>`. A head is a few kilobytes;
     * a page with no `</head>` in its first megabyte has none worth reading.
     */
    private const MAX_HOLD = 1048576;

    /**
     * Buffer chunk size. Without one, PHP hands the buffer to filter() only
     * when it ends, so the whole page would be held until shutdown and
     * neither the release at `</head>` nor MAX_HOLD could happen. With one,
     * filter() sees the output every few kilobytes as it is written.
     */
    public const CHUNK_SIZE = 4096;

    /**
     * Switches.
     *
     * @var Guard_Settings
     */
    private Guard_Settings $settings;

    /**
     * Families on for this request.
     *
     * @var string[]
     */
    private array $families = [];

    /**
     * The document title WordPress printed from ThinkRank's answer.
     *
     * @var string
     */
    private string $title = '';

    /**
     * Output held until `</head>`.
     *
     * @var string
     */
    private string $pending = '';

    /**
     * Whether the head has been dealt with.
     *
     * @var bool
     */
    private bool $done = false;

    /**
     * @param Guard_Settings|null $settings Settings (injected in tests).
     */
    public function __construct(?Guard_Settings $settings = null) {
        $this->settings = $settings ?? new Guard_Settings();
    }

    /**
     * Wire up hooks.
     *
     * @return void
     */
    public function init(): void {
        // Last on template_redirect, after anything that redirects or exits:
        // a buffer started earlier would sit under responses that never
        // render a page.
        add_action('template_redirect', [$this, 'start'], PHP_INT_MAX);

        // Anything that changes what a page prints makes the last check stale.
        foreach (['activated_plugin', 'deactivated_plugin', 'switch_theme', 'upgrader_process_complete', 'thinkrank_duplicate_tag_guard_saved'] as $hook) {
            add_action($hook, [Duplicate_Tag_Detector::class, 'flush_cache'], 10, 0);
        }
    }

    /**
     * Start the buffer, when there is something to do on this request.
     *
     * @return void
     */
    public function start(): void {
        if (!self::applies()) {
            return;
        }

        $this->families = $this->settings->enabled();
        if ([] === $this->families) {
            return;
        }

        add_action('wp_head', [$this, 'capture_title'], 0);
        ob_start([$this, 'filter'], self::CHUNK_SIZE);
    }

    /**
     * Remember the title WordPress is about to print, which is ThinkRank's.
     * Themes without title-tag support print their own and WordPress prints
     * none, so there is no title of ours to keep.
     *
     * @return void
     */
    public function capture_title(): void {
        if (current_theme_supports('title-tag')) {
            $this->title = (string) wp_get_document_title();
        }
    }

    /**
     * Output handler.
     *
     * @param string $buffer Chunk.
     * @param int    $phase  PHP_OUTPUT_HANDLER_* flags.
     * @return string
     */
    public function filter(string $buffer, int $phase = PHP_OUTPUT_HANDLER_FINAL): string {
        if ($this->done) {
            return $buffer;
        }

        // Someone discarded the buffer: what was held goes with it.
        if ($phase & PHP_OUTPUT_HANDLER_CLEAN) {
            $this->pending = '';
            return '';
        }

        $this->pending .= $buffer;

        if (!self::is_html_response()) {
            return $this->release($this->pending);
        }

        $end = Head_Tags::head_end($this->pending);
        if (null === $end) {
            $final = (bool) ($phase & PHP_OUTPUT_HANDLER_FINAL);

            return $final || strlen($this->pending) > self::MAX_HOLD ? $this->release($this->pending) : '';
        }

        $head = substr($this->pending, 0, $end);
        $rest = substr($this->pending, $end);

        return $this->release(self::process($head, $this->families, $this->title) . $rest);
    }

    /**
     * Strip a head and, when anything went, leave the count before `</head>`.
     *
     * @param string   $head      Head markup, ending with `</head>`.
     * @param string[] $families  Families to act on.
     * @param string   $our_title ThinkRank's document title.
     * @return string
     */
    public static function process(string $head, array $families, string $our_title): string {
        $result = Head_Tags::strip($head, $families, $our_title);
        if ([] === $result['removed']) {
            return $result['html'];
        }

        $html  = $result['html'];
        $close = strripos($html, '</head');

        return false === $close
            ? $html
            : substr($html, 0, $close) . Head_Tags::marker($result['removed']) . substr($html, $close);
    }

    /**
     * Whether this request renders a front-end page the guard may touch.
     *
     * @return bool
     */
    public static function applies(): bool {
        if (defined(self::KILL_SWITCH) && constant(self::KILL_SWITCH)) {
            return false;
        }

        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }

        return !(is_feed() || is_robots() || is_trackback() || is_customize_preview());
    }

    /**
     * Hand back what was held, and pass everything after it straight through.
     *
     * @param string $output Output.
     * @return string
     */
    private function release(string $output): string {
        $this->done    = true;
        $this->pending = '';

        return $output;
    }

    /**
     * Whether the response is HTML. A template may send XML or JSON with its
     * own Content-Type; without one, PHP sends text/html.
     *
     * @return bool
     */
    private static function is_html_response(): bool {
        foreach (headers_list() as $header) {
            if (0 === stripos($header, 'content-type:')) {
                return false !== stripos($header, 'text/html') || false !== stripos($header, 'application/xhtml+xml');
            }
        }

        return true;
    }
}
