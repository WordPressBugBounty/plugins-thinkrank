<?php
/**
 * Page-builder content extraction.
 *
 * SEO analysis reads `post_content`, which is only the real text on a classic
 * post. Page builders keep the words somewhere else, and every server-side
 * scoring path — bulk analysis, the post-list SEO Overview column, the MCP
 * abilities, cron reports — saw an empty page as a result:
 *
 *  - Oxygen / Breakdance leave `post_content` completely EMPTY and store the
 *    node tree in postmeta. Nothing to render, nothing to strip: the analyzer
 *    reported "No content" on pages with well over a thousand visible words.
 *  - Elementor does the same via `_elementor_data`.
 *  - Divi 5 and Gutenberg do store block markup in `post_content`, but Divi
 *    keeps module text inside the block's JSON attributes — inside an HTML
 *    comment, which tag stripping removes wholesale.
 *  - Divi 4 and other shortcode builders keep text in shortcode attributes.
 *
 * Extraction reads the builder's own stored data rather than invoking its
 * render engine. Rendering an Oxygen page outside a front-end request is slow,
 * stateful and can fatal in an admin context, whereas the stored tree is just
 * JSON — cheap, side-effect free and safe to touch during a bulk run.
 *
 * @package ThinkRank\SEO
 * @since 1.23.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the analyzable content of a post, whatever built it.
 */
class Builder_Content {

    /**
     * Post meta keys that hold builder data, in priority order.
     *
     * Several generations of the same builder are listed on purpose: Oxygen 6
     * is Breakdance under the hood (`_breakdance_data`), while earlier Oxygen
     * releases used `_oxygen_data` or the shortcode-based
     * `ct_builder_shortcodes`. A site can only have one of them.
     *
     * @var string[]
     */
    private const BUILDER_META_KEYS = [
        '_breakdance_data',        // Oxygen 6+ / Breakdance
        '_oxygen_data',            // Oxygen (earlier releases)
        'ct_builder_shortcodes',   // Oxygen classic
        '_elementor_data',         // Elementor
        // Beaver Builder. Published layout first: `_fl_builder_draft` holds
        // unsaved changes and would score content the visitor cannot see.
        // Both are arrays of stdClass nodes, which is why the walker below
        // has to treat objects like arrays (#449).
        '_fl_builder_data',        // Beaver Builder (published)
        '_fl_builder_draft',       // Beaver Builder (unsaved changes)
    ];

    /**
     * Bricks' content-area meta key, used when Bricks itself isn't loaded.
     *
     * Bricks exposes `BRICKS_DB_PAGE_CONTENT` and renames the underlying key
     * between generations (it gained the `_2` suffix in 1.7.3), so the
     * constant is authoritative and this literal is only the fallback for the
     * contexts where it is undefined — Bricks is a theme, so on an admin or
     * CLI request against a site that has since switched themes the constant
     * is simply not there while the post meta still is.
     *
     * Bricks stores three areas — header, content and footer. Only the content
     * area belongs to the post being scored; the header and footer areas live
     * on Bricks' own template posts and would double-count site chrome into
     * every page's word count, so they are deliberately not read here.
     *
     * @since 2.2.1
     * @var string
     */
    private const BRICKS_CONTENT_META_KEY = '_bricks_page_content_2';

    /**
     * Bricks' per-post editor-mode meta key, used when Bricks isn't loaded.
     *
     * @since 2.2.1
     * @var string
     */
    private const BRICKS_EDITOR_MODE_META_KEY = '_bricks_editor_mode';

    /**
     * Bricks' components option, used when Bricks itself isn't loaded.
     *
     * @since 2.2.1
     * @var string
     */
    private const BRICKS_COMPONENTS_OPTION = 'bricks_components';

    /**
     * Bricks' element that renders the post's own `post_content`.
     *
     * A Bricks page normally discards `post_content` entirely, which is why
     * anything left there is invisible. Dropping this element onto the canvas
     * is the one way an author puts it back on the page, so its presence flips
     * `post_content` from stale leftovers to content the visitor reads.
     *
     * @since 2.3.1
     * @var string
     */
    private const BRICKS_POST_CONTENT_ELEMENT = 'post-content';

    /**
     * Resolved Bricks trees for this request, keyed by post ID.
     *
     * Rendering one page asks for the tree about twenty times — every
     * description, every schema node, the FAQ guard — and resolving it is not
     * free. `bricks_content_source()` clears `Bricks\Database::$active_templates`
     * before asking Bricks which content template applies, which defeats
     * Bricks' own early-return and re-runs its whole template-condition engine;
     * `expand_bricks_components()` then walks the tree again. Measured on a
     * Bricks page with no content of its own, that was ten full runs of the
     * rules engine per request.
     *
     * Per-request only, and only ever read back within one page render — a
     * request that writes Bricks content does not also render it.
     *
     * @since 2.3.1
     * @var array<int,array<int,mixed>>
     */
    private static array $bricks_trees = [];

    /**
     * JSON keys whose values are user-visible text.
     *
     * Builder trees mix content with configuration, so a blind string sweep
     * would count CSS classes and option slugs as words. Matching on the key
     * keeps the word count honest.
     *
     * @var string[]
     */
    private const CONTENT_KEYS = [
        'text', 'title', 'subtitle', 'heading', 'subheading', 'content',
        'description', 'caption', 'excerpt', 'label', 'value', 'html',
        'editor', 'quote', 'answer', 'question', 'body', 'button_text',
    ];

    /**
     * JSON keys whose values hold a link destination.
     *
     * Builders store a link's destination in a structured field separate from
     * its label, either as a bare URL string or as a `{ url: … }` object.
     * Neither shape survives a text sweep — the key is not content and a bare
     * URL contains no `<` — so no `<a>` tag reached the link counters.
     *
     * @var string[]
     */
    private const URL_KEYS = [
        'link', 'url', 'href', 'link_url', 'button_link', 'permalink', 'link_to',
    ];

    /**
     * JSON keys whose values hold an embedded video's source.
     *
     * A builder's video widget keeps its destination in a provider-specific
     * field — Elementor picks `youtube_url`, `vimeo_url`, `dailymotion_url` or
     * `hosted_url` according to the chosen source type — none of which is a
     * link field or a content field, so a video on a builder page reached the
     * analyzers as nothing at all.
     *
     * These are deliberately kept out of URL_KEYS. A video is an embed, not an
     * outbound link: rendering one as `<a href>` would add a spurious external
     * link to every page carrying a video and skew the link counts. They are
     * reconstructed as `<iframe>`/`<video>` instead, which the video detector
     * recognises and the link and image counters ignore.
     *
     * @since 2.3.1
     * @var string[]
     */
    private const VIDEO_KEYS = [
        'youtube_url', 'vimeo_url', 'dailymotion_url', 'videopress_url',
        'hosted_url', 'video_url', 'video_src', 'video_link',
    ];

    /**
     * File extensions that mean a video source is a file, not a provider page.
     *
     * @since 2.3.1
     * @var string[]
     */
    private const VIDEO_FILE_EXTENSIONS = ['mp4', 'webm', 'ogv', 'mov', 'm4v'];

    /**
     * Source keys to trust for a declared video source type.
     *
     * A widget keeps one field per provider and does not clear the others when
     * the author switches source: an Elementor video moved from YouTube to Self
     * Hosted still carries the earlier `youtube_url`. Reading whichever key
     * turns up first then emits the video the author replaced. The widget says
     * which one it is actually playing, so that is read first and the flat key
     * sweep is only the fallback for a builder that declares nothing.
     *
     * @since 2.3.1
     * @var array<string,string[]>
     */
    private const VIDEO_KEYS_BY_TYPE = [
        'youtube'     => ['youtube_url'],
        'vimeo'       => ['vimeo_url'],
        'dailymotion' => ['dailymotion_url'],
        'videopress'  => ['videopress_url'],
        'hosted'      => ['hosted_url', 'video_url', 'video_src', 'video_link'],
        'media'       => ['hosted_url', 'video_url', 'video_src', 'video_link'],
        'file'        => ['hosted_url', 'video_url', 'video_src', 'video_link'],
        'self_hosted' => ['hosted_url', 'video_url', 'video_src', 'video_link'],
    ];

    /**
     * Keys a builder uses to name which video source a widget is playing.
     *
     * @since 2.3.1
     * @var string[]
     */
    private const VIDEO_TYPE_KEYS = ['video_type', 'videotype', 'video_source', 'source_type'];

    /**
     * JSON keys whose values hold an image, as a URL string or `{ url, alt }`.
     *
     * @var string[]
     */
    private const IMAGE_KEYS = [
        'image', 'src', 'image_url', 'background_image', 'bg_image', 'photo',
    ];

    /**
     * JSON keys that carry a heading level for the node's text.
     *
     * A builder heading's text is collected (its key is in CONTENT_KEYS) and so
     * counts toward the word count, but it arrives as bare text with no `<h2>`
     * wrapper — which is why heading-structure checks saw none.
     *
     * @var string[]
     */
    private const HEADING_TAG_KEYS = [
        // Lower-cased on both sides of the comparison, so `headingtag` is the
        // camelCase `headingTag` Bricks uses throughout its own controls and
        // which ThinkRank's Bricks elements declare. Without it their section
        // headings counted as body copy and never reached heading structure.
        'header_size', 'heading_tag', 'headingtag', 'html_tag', 'title_tag', 'tag', 'level', 'size',
    ];

    /**
     * Keys whose value is alternative text for a sibling image.
     *
     * @var string[]
     */
    private const ALT_KEYS = ['alt', 'alt_text', 'image_alt', 'title'];

    /**
     * Resolve the content worth analyzing for a post.
     *
     * @param \WP_Post $post Post being analyzed.
     * @return string HTML/text to analyze.
     */
    public static function resolve(\WP_Post $post): string {
        $raw = (string) $post->post_content;

        // A page built in Gutenberg and then switched to Bricks keeps its old
        // blocks in `post_content` forever — Bricks never clears them, and
        // never renders them either. Resolving that first meant the stale draft
        // beat the tree the visitor actually reads, and it did not stop at the
        // score: the same string becomes the meta description, og:description,
        // twitter:description and the schema description. Starting from nothing
        // sends the resolution straight to Bricks' storage, which is where this
        // page's words are (#651).
        //
        // Only for the stored path. `resolve_markup()` is also called with live
        // editor content, and the Bricks panel's resolver reads the canvas —
        // discarding that would replace what the author is typing with the last
        // save.
        if (self::bricks_supersedes_post_content((int) $post->ID)) {
            $raw = '';
        }

        return self::resolve_markup($raw, $post);
    }

    /**
     * Whether Bricks renders this post and throws its `post_content` away.
     *
     * True means anything still stored in `post_content` is invisible: it is
     * not on the page, so it must not be scored, described or published as
     * structured data. False covers both a post Bricks does not own and a
     * Bricks page that puts `post_content` back with a Post Content element.
     *
     * @since 2.3.1
     *
     * @param int $post_id Post being resolved.
     * @return bool
     */
    public static function bricks_supersedes_post_content(int $post_id): bool {
        $tree = self::bricks_tree($post_id);

        if (empty($tree)) {
            return false;
        }

        foreach ($tree as $element) {
            if (is_array($element)
                && self::BRICKS_POST_CONTENT_ELEMENT === ($element['name'] ?? null)
            ) {
                return false;
            }
        }

        return !self::bricks_tree_prints_post_content($tree);
    }

    /**
     * Whether a Bricks tree prints the body through a dynamic-data tag.
     *
     * The Post Content element is not the only way back onto the page: Bricks'
     * `{post_content}` tag renders the same thing from inside an ordinary text
     * element, and a single-post template written that way is a common shape.
     * Missing it would mean the post's real body is discarded everywhere —
     * scoring, the meta/og/twitter descriptions, the schema description — for a
     * page that is displaying it.
     *
     * Matched over the encoded tree rather than per setting, because the tag can
     * sit in any string field of any element and Bricks allows modifiers after
     * the name (`{post_content:...}`).
     *
     * @since 2.3.1
     *
     * @param array $tree Bricks element tree.
     * @return bool
     */
    private static function bricks_tree_prints_post_content(array $tree): bool {
        $encoded = wp_json_encode($tree);

        return is_string($encoded) && false !== stripos($encoded, '{post_content');
    }

    /**
     * The post's content as the visitor actually receives it.
     *
     * `post_content` for everything except a Bricks page that discards it, and
     * there the Bricks tree's text. Descriptions are derived from a post's body
     * in half a dozen places; every one of them wants this rather than the raw
     * column (#651).
     *
     * @since 2.3.1
     *
     * @param \WP_Post $post Post being described.
     * @return string
     */
    public static function visible_content(\WP_Post $post): string {
        $superseding = self::superseding_content($post);

        return '' !== $superseding ? $superseding : (string) $post->post_content;
    }

    /**
     * Replacement body text for a post whose `post_content` does not render.
     *
     * Empty for every ordinary post, which is what makes this safe to call from
     * paths that already handle excerpts their own way: they keep that handling
     * and only a Bricks page is diverted.
     *
     * @since 2.3.1
     *
     * @param \WP_Post $post Post being described.
     * @return string Visible body text, or '' when `post_content` is fine.
     */
    public static function superseding_content(\WP_Post $post): string {
        if (!self::bricks_supersedes_post_content((int) $post->ID)) {
            return '';
        }

        $bricks = self::from_bricks((int) $post->ID);

        return self::is_blank($bricks) ? '' : $bricks;
    }

    /**
     * Body text to derive a description from, when the usual source is wrong.
     *
     * A hand-written excerpt is the author's own summary and is correct however
     * the page is built, so it yields '' here and the caller's normal
     * `get_the_excerpt()` path keeps it. Only a Bricks page with no excerpt —
     * where core would derive one from discarded `post_content` — gets diverted.
     *
     * @since 2.3.1
     *
     * @param \WP_Post $post Post being described.
     * @return string Text to summarize, or '' to leave the caller's path alone.
     */
    public static function superseding_excerpt_source(\WP_Post $post): string {
        if ('' !== trim((string) $post->post_excerpt)) {
            return '';
        }

        return self::superseding_content($post);
    }

    /**
     * The Bricks element tree that renders for a post.
     *
     * Public because what Bricks puts on the page is not only a scoring
     * question: the schema graph has to know whether a Bricks element already
     * publishes the page's FAQ before adding one of its own (#649, #650).
     *
     * Flat, in Bricks' own storage shape — `expand_bricks_components()`
     * appends component definitions to the same list rather than nesting them,
     * so one `foreach` reaches every element.
     *
     * @since 2.3.1
     *
     * @param int $post_id Post being resolved.
     * @return array<int,mixed> Elements, or [] when Bricks renders nothing here.
     */
    public static function bricks_tree(int $post_id): array {
        if (array_key_exists($post_id, self::$bricks_trees)) {
            return self::$bricks_trees[$post_id];
        }

        self::$bricks_trees[$post_id] = self::resolve_bricks_tree($post_id);

        return self::$bricks_trees[$post_id];
    }

    /**
     * Discard the resolved-tree memo. Test seam.
     *
     * @since 2.3.1
     * @return void
     */
    public static function flush_bricks_cache(): void {
        self::$bricks_trees = [];
    }

    /**
     * Read and resolve a post's Bricks tree, ignoring the memo.
     *
     * @since 2.3.1
     *
     * @param int $post_id Post being resolved.
     * @return array<int,mixed>
     */
    private static function resolve_bricks_tree(int $post_id): array {
        if (!self::bricks_owns_post($post_id)) {
            return [];
        }

        $source = self::bricks_content_source($post_id);
        if (!$source) {
            return [];
        }

        $stored = get_post_meta($source, self::bricks_meta_key(), true);

        if (is_string($stored)) {
            $stored = '' === trim($stored) ? null : json_decode($stored, true);
        }

        if (!is_array($stored) || empty($stored)) {
            return [];
        }

        return self::expand_bricks_components($stored);
    }

    /**
     * Resolve an arbitrary chunk of editor markup for the given post.
     *
     * The editor sends its live content to the scorer so an author sees their
     * unsaved edits reflected. On a builder page that live string is the raw
     * builder markup — the block editor hands over Divi's
     * `<!-- wp:divi/... -->` comments verbatim, because it cannot render
     * blocks it has no client-side registration for. Analyzed as-is it reads
     * as zero words, which is how a Divi page could show a correct saved score
     * while the live Content Analysis panel next to it still said
     * "No content".
     *
     * Running the live string through the same chain as stored content keeps
     * both paths honest, and falling through to the post's builder storage
     * covers builders (Oxygen) whose editor content is empty to begin with.
     *
     * @since 1.23.0
     *
     * @param string   $raw  Markup to analyze.
     * @param \WP_Post $post Post the markup belongs to.
     * @return string Content to analyze.
     */
    public static function resolve_markup(string $raw, \WP_Post $post): string {
        $content = self::render_post_content($raw);

        // Block markup that renders to nothing usually means the builder that
        // owns those blocks did not register them in this context — Divi 5
        // loads its module library lazily per-request, so in CLI, REST, admin
        // and block-editor requests do_blocks() yields an empty string while
        // the words sit right there in the block attributes. Read them
        // directly.
        if (self::is_blank($content)) {
            $from_blocks = self::from_block_attributes($raw);
            if (!self::is_blank($from_blocks)) {
                $content = $from_blocks;
            }
        }

        // Only reach for builder storage when the markup yielded nothing — a
        // classic post must never pay for this.
        if (self::is_blank($content)) {
            $builder = self::from_builder_meta((int) $post->ID);
            if (!self::is_blank($builder)) {
                $content = $builder;
            }
        }

        // A resolution that collapsed to nothing is worse than the raw markup.
        if (self::is_blank($content) && !self::is_blank($raw)) {
            $content = $raw;
        }

        /**
         * Filter the content ThinkRank analyzes for a post.
         *
         * Use this to teach ThinkRank about a builder it does not know, or to
         * override extraction for one it does.
         *
         * @since 1.23.0
         *
         * @param string   $content Resolved content.
         * @param \WP_Post $post    Post being analyzed.
         * @param string   $raw     Markup this resolution started from.
         */
        return (string) apply_filters('thinkrank_analyzable_content', $content, $post, $raw);
    }

    /**
     * Render blocks and shortcodes found in post_content.
     *
     * Best-effort: a third-party block that fatals must not take the whole
     * score down with it.
     *
     * @param string $raw Raw post content.
     * @return string Rendered content.
     */
    private static function render_post_content(string $raw): string {
        if ('' === trim($raw)) {
            return '';
        }

        $content = $raw;

        try {
            if (function_exists('has_blocks') && function_exists('do_blocks') && has_blocks($raw)) {
                $content = do_blocks($raw);
            }

            // Block output can itself contain shortcodes, so this runs either way.
            if (function_exists('do_shortcode') && strpos($content, '[') !== false) {
                $content = do_shortcode($content);
            }
        } catch (\Throwable $e) {
            return $raw;
        }

        return self::is_blank($content) ? $raw : $content;
    }

    /**
     * Extract text from the attributes of parsed blocks.
     *
     * @param string $raw Raw post content containing block markup.
     * @return string Collected text, or '' when nothing was found.
     */
    private static function from_block_attributes(string $raw): string {
        if (!function_exists('parse_blocks') || !function_exists('has_blocks') || !has_blocks($raw)) {
            return '';
        }

        try {
            $blocks = parse_blocks($raw);
        } catch (\Throwable $e) {
            return '';
        }

        $attrs = [];
        $collect = static function (array $items) use (&$collect, &$attrs): void {
            foreach ($items as $block) {
                if (!empty($block['attrs']) && is_array($block['attrs'])) {
                    $attrs[] = $block['attrs'];
                }
                if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                    $collect($block['innerBlocks']);
                }
            }
        };
        $collect($blocks);

        return empty($attrs) ? '' : self::text_from_tree($attrs);
    }

    /**
     * Everything Bricks contributes to this post's analyzable content.
     *
     * Bricks is the only builder here that needs more than a meta key, on
     * three counts:
     *
     *  - It leaves its stored tree behind when a post is switched back to the
     *    block editor, so an editor-mode gate has to run first or ThinkRank
     *    scores markup the visitor never sees — the same failure
     *    `_fl_builder_draft` was ordered against in #449.
     *  - A post's content can live on ANOTHER post. Bricks' Templates feature
     *    assigns a content template by condition, and a page using one stores
     *    nothing of its own; reading only the page's meta scores it blank
     *    while the visitor reads a full page.
     *  - Its stored text carries dynamic-data tags and internal element names
     *    that never reach the rendered page.
     *
     * @since 2.2.1
     *
     * @param int $post_id Post being resolved.
     * @return string Extracted text, or '' when Bricks has nothing for it.
     */
    private static function from_bricks(int $post_id): string {
        $tree = self::bricks_tree($post_id);

        if (empty($tree)) {
            return '';
        }

        return self::strip_bricks_dynamic_tags(
            self::text_from_tree(self::without_bricks_element_labels($tree))
        );
    }

    /**
     * Whether Bricks — not the block editor — renders this post.
     *
     * Bricks writes `bricks` or `wordpress` into its editor-mode meta as the
     * author toggles between the two, and never clears the content it stored
     * for the other mode. Only the `wordpress` value is disqualifying: an
     * absent value is the normal state for a post Bricks built and never
     * toggled. This follows Bricks' own `Helpers::render_with_bricks()`, which
     * bails on exactly that one value.
     *
     * It deliberately does not match it exactly: the comparison here is
     * case-insensitive, where Bricks' is strict. Bricks 2.3.12 only ever writes
     * the value lowercase, so the two agree on everything Bricks itself
     * stores; they part company only on a value some other integration wrote.
     * The two shipping today disagree about the casing — SureRank compares
     * against `'WordPress'`, AIOSEO against `'bricks'` — and of the two ways to
     * be wrong about `'WordPress'`, blocking costs a score on a page that has
     * one, while allowing scores stale content the visitor never sees, which is
     * the failure this gate exists to prevent.
     *
     * @since 2.2.1
     *
     * @param int $post_id Post being resolved.
     * @return bool
     */
    private static function bricks_owns_post(int $post_id): bool {
        $mode = get_post_meta($post_id, self::bricks_editor_mode_key(), true);

        // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Bricks' own stored meta value, lower-cased for the comparison.
        return !(is_string($mode) && 'wordpress' === strtolower(trim($mode)));
    }

    /**
     * The post whose Bricks tree actually renders for this post.
     *
     * Usually the post itself. When it stores nothing of its own, Bricks falls
     * back to whichever content template's conditions match, and that template
     * is a separate post carrying the words the visitor reads.
     *
     * Resolution is delegated to Bricks rather than reimplemented: template
     * conditions are a whole rules engine (post IDs, types, taxonomies,
     * archives), and a second implementation would drift from it. Bricks
     * answers through statics, so they are saved and restored around the call —
     * `set_active_templates()` returns early once populated, and on a
     * front-end request Bricks has already populated it for the page being
     * served. Clobbering that would corrupt the render in progress.
     *
     * Best-effort by design: any failure returns the post's own data, which is
     * exactly today's behaviour.
     *
     * @since 2.2.1
     *
     * @param int $post_id Post being resolved.
     * @return int Post ID holding the Bricks tree, or 0 when there is none.
     */
    private static function bricks_content_source(int $post_id): int {
        $own = get_post_meta($post_id, self::bricks_meta_key(), true);
        if ((is_array($own) && !empty($own)) || (is_string($own) && '' !== trim($own))) {
            return $post_id;
        }

        if (!class_exists('\\Bricks\\Database')
            || !method_exists('\\Bricks\\Database', 'set_active_templates')
        ) {
            return 0;
        }

        // `set_active_templates()` writes TWO statics — `$active_templates` and,
        // when a header template resolves, `$header_position`. Both are saved,
        // and both are restored in `finally` rather than on the happy path: a
        // throw part-way through (a third-party hook on
        // `bricks/database/content_type`, `bricks/builder/data_post_id` or
        // `bricks/active_templates` is enough) must not leave Bricks' render
        // state holding this lookup's values. Restoring only after a clean
        // return is what the `catch` below would otherwise skip.
        $has_header_position = property_exists('\\Bricks\\Database', 'header_position');
        $saved_templates = \Bricks\Database::$active_templates;
        $saved_header_position = $has_header_position ? \Bricks\Database::$header_position : null;

        try {
            \Bricks\Database::$active_templates = [];
            \Bricks\Database::set_active_templates($post_id);
            $template = (int) (\Bricks\Database::$active_templates['content'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        } finally {
            \Bricks\Database::$active_templates = $saved_templates;
            if ($has_header_position) {
                \Bricks\Database::$header_position = $saved_header_position;
            }
        }

        // A template that is the post itself adds nothing over the empty read
        // above, and would otherwise recurse conceptually.
        return $template === $post_id ? 0 : $template;
    }

    /**
     * Splice component definitions into the tree.
     *
     * A Bricks component keeps its markup in the `bricks_components` option,
     * not on the page. The page stores only an instance: an element carrying
     * `cid` and, usually, empty `settings`. Walking the page alone therefore
     * found no words at all, and a page built entirely from components scored
     * blank — the same failure as a page built from a content template.
     *
     * Confirmed on Bricks 2.3.12: `Bricks\Frontend::render_data()` renders the
     * component's copy from an instance this walker extracted '' from.
     *
     * The definition is read straight from the option rather than through
     * `Bricks\Helpers::get_component_instance()`. That helper resolves an
     * instance's property overrides, which would be better, but it reads
     * `Bricks\Database::$global_data['components']` — populated once per
     * request, and empty in the admin and CLI contexts where bulk scoring
     * runs. Refreshing it would mean writing to Bricks' live render state, the
     * same hazard the template resolver is careful to avoid, and gating on it
     * would make a page score differently in wp-admin than on the front end.
     * Reading the stored definition is consistent everywhere.
     *
     * The trade-off: an instance that overrides a component property is scored
     * with the component's authored copy rather than the override. That is the
     * text the component renders by default, and it is much closer than the
     * nothing this returned before.
     *
     * @since 2.2.1
     *
     * @param array $tree Bricks content area.
     * @return array Tree with component elements spliced in after each instance.
     */
    private static function expand_bricks_components(array $tree): array {
        $expanded = [];
        $open = [];

        $walk = static function (array $elements, int $depth) use (&$walk, &$expanded, &$open): void {
            foreach ($elements as $element) {
                $expanded[] = $element;

                if (!is_array($element) || empty($element['cid']) || !is_string($element['cid'])) {
                    continue;
                }

                $cid = $element['cid'];

                // A component nested inside its own definition would recurse
                // forever; the depth cap covers deep but legitimate nesting.
                if (isset($open[$cid]) || $depth > 4) {
                    continue;
                }

                $children = self::bricks_component_elements($cid);
                if (empty($children)) {
                    continue;
                }

                // Re-entrant per branch, not per page: the guard is released
                // after the walk so a second instance further along the page
                // still expands, rather than being mistaken for recursion.
                //
                // That does NOT double the word count — `text_from_tree()`
                // ends in `array_unique()`, which collapses a repeated
                // component's copy the same way it collapses a value repeated
                // across responsive breakpoints. Expanding both instances is
                // about not silently dropping the second one's structure.
                $open[$cid] = true;
                $walk($children, $depth + 1);
                unset($open[$cid]);
            }
        };

        $walk($tree, 0);

        return $expanded;
    }

    /**
     * The stored elements of one Bricks component.
     *
     * @since 2.2.1
     *
     * @param string $cid Component id held by an instance element.
     * @return array Component elements, or [] when it cannot be resolved.
     */
    private static function bricks_component_elements(string $cid): array {
        $components = get_option(self::bricks_constant('BRICKS_DB_COMPONENTS', self::BRICKS_COMPONENTS_OPTION), []);

        if (!is_array($components)) {
            return [];
        }

        foreach ($components as $component) {
            $component = self::as_children($component);
            if (null === $component) {
                continue;
            }

            if (isset($component['id']) && $component['id'] === $cid && !empty($component['elements'])) {
                return is_array($component['elements']) ? $component['elements'] : [];
            }
        }

        return [];
    }

    /**
     * Drop each Bricks element's internal name before the tree is walked.
     *
     * A Bricks element carries an optional top-level `label` — the nickname an
     * author types in the Structure panel to find it again ("Hero headline",
     * "CTA row"). It is builder chrome and is never rendered, but `label` is in
     * CONTENT_KEYS because it is real content for other builders' form fields,
     * so it was being counted as page copy.
     *
     * Only the element's own `label` is removed. A `label` inside `settings`
     * is a rendered field label and stays.
     *
     * @since 2.2.1
     *
     * @param array $tree Bricks content area.
     * @return array Tree with element nicknames removed.
     */
    private static function without_bricks_element_labels(array $tree): array {
        foreach ($tree as $index => $element) {
            if (is_array($element) && isset($element['id'], $element['label'])) {
                unset($tree[$index]['label']);
            }
        }

        return $tree;
    }

    /**
     * Remove Bricks dynamic-data tags from extracted text.
     *
     * Bricks stores `{post_title}`, `{post_meta:price}`, `{echo:my_fn}` and the
     * like verbatim and resolves them when it renders. Extraction reads the
     * stored tree, so without this the placeholders were counted as words, and
     * a heading whose text is `{post_title}` reported the literal token as its
     * heading text.
     *
     * The pattern is deliberately narrower than Bricks' own
     * (`/{([\wÀ-ÖØ-öø-ÿ\-\s\.\/:\(\)...]+)}/u`), which also matches braces
     * containing spaces. Bricks only substitutes tags that resolve to a
     * registered provider and leaves anything else on the page as literal text,
     * so the broad pattern would delete prose the visitor can actually read.
     * Matching only tag-shaped tokens keeps every real sentence and still
     * removes every placeholder — the same trade-off SureRank makes.
     *
     * @since 2.2.1
     *
     * @param string $text Extracted text.
     * @return string Text with placeholders removed.
     */
    private static function strip_bricks_dynamic_tags(string $text): string {
        $stripped = preg_replace('/\{[a-z0-9_][a-z0-9_:\-\.]*\}/i', '', $text);

        if (null === $stripped) {
            return $text;
        }

        // Collapse the runs of spaces a removed tag leaves mid-sentence,
        // without touching the newlines that separate collected nodes.
        $tidied = preg_replace('/[ \t]{2,}/', ' ', $stripped);

        return null === $tidied ? $stripped : $tidied;
    }

    /**
     * Bricks' content-area meta key, preferring Bricks' own constant.
     *
     * @since 2.2.1
     *
     * @return string
     */
    private static function bricks_meta_key(): string {
        return self::bricks_constant('BRICKS_DB_PAGE_CONTENT', self::BRICKS_CONTENT_META_KEY);
    }

    /**
     * Bricks' editor-mode meta key, preferring Bricks' own constant.
     *
     * @since 2.2.1
     *
     * @return string
     */
    private static function bricks_editor_mode_key(): string {
        return self::bricks_constant('BRICKS_DB_EDITOR_MODE', self::BRICKS_EDITOR_MODE_META_KEY);
    }

    /**
     * Read one of Bricks' key-name constants, falling back to the literal.
     *
     * @since 2.2.1
     *
     * @param string $name     Constant name.
     * @param string $fallback Key to use when the constant is unavailable.
     * @return string
     */
    private static function bricks_constant(string $name, string $fallback): string {
        if (defined($name)) {
            $value = constant($name);
            if (is_string($value) && '' !== trim($value)) {
                return $value;
            }
        }

        return $fallback;
    }

    /**
     * Pull text out of whichever builder stored this post.
     *
     * @param int $post_id Post ID.
     * @return string Extracted text, or '' when no builder data was found.
     */
    private static function from_builder_meta(int $post_id): string {
        // Bricks first: it is the only builder whose content can live on
        // another post, and the only one gated on an editor mode.
        $bricks = self::from_bricks($post_id);
        if (!self::is_blank($bricks)) {
            return $bricks;
        }

        foreach (self::BUILDER_META_KEYS as $key) {
            $stored = get_post_meta($post_id, $key, true);

            if (is_string($stored) && '' !== trim($stored)) {
                $decoded = json_decode($stored, true);

                // JSON node tree (Breakdance/Oxygen 6, Elementor).
                if (is_array($decoded)) {
                    $text = self::text_from_tree($decoded);
                    if (!self::is_blank($text)) {
                        return $text;
                    }
                    continue;
                }

                // Shortcode tree (Oxygen classic).
                if (strpos($stored, '[') !== false && function_exists('do_shortcode')) {
                    try {
                        $rendered = do_shortcode($stored);
                    } catch (\Throwable $e) {
                        $rendered = $stored;
                    }
                    if (!self::is_blank($rendered)) {
                        return $rendered;
                    }
                }

                continue;
            }

            // Some builders store an already-decoded tree — an array for most,
            // an array of objects for Beaver Builder (#449).
            $tree = self::as_children($stored);
            if (null !== $tree) {
                $text = self::text_from_tree($tree);
                if (!self::is_blank($text)) {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * A node's children, whether it stores them as an array or an object.
     *
     * The walker used to return immediately on `!is_array($node)`, so an
     * object node was dropped along with its entire subtree — silently, as
     * `''`, which the caller reads as "this builder stored nothing" rather
     * than "this walker cannot read this shape".
     *
     * Beaver Builder stores `_fl_builder_data` as an array of stdClass nodes,
     * each with a stdClass `settings` object, so every node would have been
     * dropped and adding its meta key alone would have looked like it worked
     * and changed nothing. Not BB-specific: any builder storing objects hits
     * this, and that shape will come up again (#449).
     *
     * @since 2.1.0
     *
     * @param mixed $node Candidate node.
     * @return array<string|int,mixed>|null Traversable children, or null.
     */
    private static function as_children($node): ?array {
        if (is_array($node)) {
            return $node;
        }

        // Deliberately not is_object(): a builder can store a value object
        // (DateTime, a WP_Post) whose properties are not content, and
        // get_object_vars() on those yields noise. stdClass is what the
        // JSON/serialize round-trip produces, which is the shape we want.
        if ($node instanceof \stdClass) {
            return get_object_vars($node);
        }

        return null;
    }

    /**
     * Walk a builder node tree and collect the user-visible text.
     *
     * Values are joined with block-level markup so downstream heading, link and
     * image detection keeps working on the result.
     *
     * @param array $tree Decoded builder tree.
     * @return string Collected HTML.
     */
    private static function text_from_tree(array $tree): string {
        $collected = [];

        // Strings already represented inside reconstructed markup, so the plain
        // sweep below doesn't emit a link label or heading a second time and
        // double it in the word count.
        $consumed = [];

        // Pass 1 — rebuild <a>, <img> and <hN> from node *shape*. This has to
        // happen per node rather than per leaf: a link's label and its
        // destination are separate sibling fields, so once the tree is
        // flattened to leaves the pairing is gone.
        $reconstruct = static function ($node) use (&$reconstruct, &$collected, &$consumed): void {
            $node = self::as_children($node);
            if (null === $node) {
                return;
            }

            $markup = self::markup_for_node($node, $consumed);
            if ('' !== $markup) {
                $collected[] = $markup;
            }

            foreach ($node as $child_key => $child) {
                // A `link` / `image` sub-object is a destination descriptor the
                // parent has already folded into its markup. Descending into it
                // would emit the same URL a second time as a bare link, and
                // would turn an image's own `url` field into a spurious <a>.
                if (is_string($child_key)
                    && (in_array(strtolower($child_key), self::URL_KEYS, true)
                        || in_array(strtolower($child_key), self::IMAGE_KEYS, true)
                        || in_array(strtolower($child_key), self::VIDEO_KEYS, true))
                ) {
                    continue;
                }

                $reconstruct($child);
            }
        };
        $reconstruct($tree);

        // Pass 2 — remaining visible text.
        $walk = static function ($node, $key = null) use (&$walk, &$collected, &$consumed): void {
            $children = self::as_children($node);
            if (null !== $children) {
                foreach ($children as $child_key => $child) {
                    $walk($child, is_string($child_key) ? $child_key : $key);
                }
                return;
            }

            if (!is_string($node) || '' === trim($node)) {
                return;
            }

            // Already inside a reconstructed tag.
            if (in_array($node, $consumed, true)) {
                return;
            }

            $is_content_key = is_string($key)
                && in_array(strtolower($key), self::CONTENT_KEYS, true);

            // Markup is content wherever it appears; bare strings only count
            // when their key says they are content, so slugs and class names
            // stay out of the word count.
            if ($is_content_key || strpos($node, '<') !== false) {
                $collected[] = $node;
            }
        };

        $walk($tree);

        if (empty($collected)) {
            return '';
        }

        // De-duplicate: builder trees often repeat a value across responsive
        // breakpoints, which would otherwise multiply the word count.
        $collected = array_unique($collected);

        return implode("\n", $collected);
    }

    /**
     * The video source a node is actually playing, if any.
     *
     * @since 2.3.1
     *
     * @param array $node Builder node.
     * @return string Video source, or '' when the node carries none.
     */
    private static function video_from(array $node): string {
        foreach ($node as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            if (!in_array(strtolower($key), self::VIDEO_TYPE_KEYS, true)) {
                continue;
            }

            $keys = self::VIDEO_KEYS_BY_TYPE[strtolower(trim($value))] ?? null;
            if (null === $keys) {
                continue;
            }

            // A recognised video_type settles it, including when that
            // provider's own field is empty. Falling through to the flat sweep
            // there handed back whichever sibling key happened to come first in
            // node order — the stale youtube_url left behind after switching
            // the widget to a hosted file, which is exactly what keying on the
            // declared type is meant to prevent.
            $declared = self::url_from($node, $keys);

            return self::is_video_source($declared) ? $declared : '';
        }

        $url = self::url_from($node, self::VIDEO_KEYS);

        return self::is_video_source($url) ? $url : '';
    }

    /**
     * Whether a value can be a video source.
     *
     * `looks_like_url()` also accepts `#anchor`, `mailto:` and `tel:`, which a
     * link node may legitimately hold but a video cannot: `<iframe src="#top">`
     * is not a video and would reach a video sitemap as one.
     *
     * @since 2.3.1
     *
     * @param string $url Candidate source.
     * @return bool
     */
    private static function is_video_source(string $url): bool {
        return '' !== $url
            && (1 === preg_match('#^(https?:)?//#i', $url) || str_starts_with($url, '/'));
    }

    /**
     * Whether a video source points at a file rather than a provider page.
     *
     * @since 2.3.1
     *
     * @param string $url Video source.
     * @return bool
     */
    private static function is_video_file(string $url): bool {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::VIDEO_FILE_EXTENSIONS, true);
    }

    /**
     * Rebuild the HTML a single builder node represents, if any.
     *
     * Looks only at the node's own fields (plus one level of nesting, because
     * builders commonly wrap a destination as `{ url: … }`). Returns an empty
     * string for the vast majority of nodes, which are layout or configuration.
     *
     * Any leaf string folded into the returned markup is appended to $consumed
     * so the plain-text sweep doesn't count it twice.
     *
     * @param array $node     Builder node.
     * @param array $consumed Collects strings represented in the returned markup.
     * @return string Reconstructed HTML, or '' when the node carries none.
     */
    private static function markup_for_node(array $node, array &$consumed): string {
        $text = self::first_value($node, self::CONTENT_KEYS);
        $url = self::url_from($node, self::URL_KEYS);
        $image = self::image_from($node);
        $video = self::video_from($node);
        $tag = self::heading_tag_from($node);

        $parts = [];

        // Video: an embed shape rather than a link, so the video detector can
        // see it while the link counters do not mistake it for an outbound
        // link. A file source becomes <video src>, anything else an <iframe>,
        // matching how the builder itself renders the two cases.
        if ('' !== $video) {
            $parts[] = self::is_video_file($video)
                ? sprintf('<video src="%s"></video>', esc_url_raw($video))
                : sprintf('<iframe src="%s"></iframe>', esc_url_raw($video));
        }

        // Image: alt text matters as much as the tag, since alt checks run over
        // whatever this returns.
        if ('' !== $image['url']) {
            $alt = '' !== $image['alt'] ? $image['alt'] : (string) self::first_value($node, self::ALT_KEYS);
            if ('' !== $alt) {
                $consumed[] = $alt;
            }
            $parts[] = sprintf(
                '<img src="%s" alt="%s" />',
                esc_url_raw($image['url']),
                htmlspecialchars($alt, ENT_QUOTES)
            );
        }

        if ('' !== $text) {
            $inner = $text;

            if ('' !== $url) {
                $consumed[] = $text;
                $inner = sprintf('<a href="%s">%s</a>', esc_url_raw($url), $text);
            }

            if ('' !== $tag) {
                $consumed[] = $text;
                $parts[] = sprintf('<%1$s>%2$s</%1$s>', $tag, $inner);
            } elseif ('' !== $url) {
                $parts[] = $inner;
            }
        } elseif ('' !== $url) {
            // A destination with no label still counts as a link for link
            // checks; the URL doubles as its anchor text.
            $parts[] = sprintf('<a href="%1$s">%1$s</a>', esc_url_raw($url));
        }

        return implode("\n", $parts);
    }

    /**
     * First non-empty scalar value under any of the given keys.
     *
     * @param array    $node Builder node.
     * @param string[] $keys Candidate keys.
     * @return string Trimmed value, or '' when none match.
     */
    private static function first_value(array $node, array $keys): string {
        foreach ($node as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (in_array(strtolower($key), $keys, true) && '' !== trim($value)) {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * Link destination held by a node, as a bare string or a `{ url: … }` object.
     *
     * @param array    $node Builder node.
     * @param string[] $keys Candidate keys.
     * @return string URL, or '' when the node holds none.
     */
    private static function url_from(array $node, array $keys): string {
        foreach ($node as $key => $value) {
            if (!is_string($key) || !in_array(strtolower($key), $keys, true)) {
                continue;
            }

            if (is_string($value) && self::looks_like_url($value)) {
                return trim($value);
            }

            // Elementor and Breakdance both nest the destination one level down.
            $nested_values = self::as_children($value);
            if (null !== $nested_values) {
                foreach ($nested_values as $nested_key => $nested) {
                    if (is_string($nested_key)
                        && in_array(strtolower($nested_key), ['url', 'href', 'permalink'], true)
                        && is_string($nested)
                        && self::looks_like_url($nested)
                    ) {
                        return trim($nested);
                    }
                }
            }
        }

        return '';
    }

    /**
     * Image URL and alt text held by a node.
     *
     * @param array $node Builder node.
     * @return array{url:string,alt:string}
     */
    private static function image_from(array $node): array {
        foreach ($node as $key => $value) {
            if (!is_string($key) || !in_array(strtolower($key), self::IMAGE_KEYS, true)) {
                continue;
            }

            if (is_string($value) && self::looks_like_url($value)) {
                return ['url' => trim($value), 'alt' => ''];
            }

            $nested_values = self::as_children($value);
            if (null !== $nested_values) {
                $url = '';
                $alt = '';
                foreach ($nested_values as $nested_key => $nested) {
                    if (!is_string($nested_key) || !is_string($nested)) {
                        continue;
                    }
                    $nested_key = strtolower($nested_key);
                    if ('' === $url && in_array($nested_key, ['url', 'src'], true) && self::looks_like_url($nested)) {
                        $url = trim($nested);
                    }
                    if ('' === $alt && in_array($nested_key, self::ALT_KEYS, true)) {
                        $alt = trim($nested);
                    }
                }
                if ('' !== $url) {
                    return ['url' => $url, 'alt' => $alt];
                }
            }
        }

        return ['url' => '', 'alt' => ''];
    }

    /**
     * Heading tag a node asks for, normalised to h1–h6.
     *
     * Accepts both the `h2` form and a bare level like `2`.
     *
     * @param array $node Builder node.
     * @return string Tag name, or '' when the node is not a heading.
     */
    private static function heading_tag_from(array $node): string {
        foreach ($node as $key => $value) {
            if (!is_string($key) || !in_array(strtolower($key), self::HEADING_TAG_KEYS, true)) {
                continue;
            }

            if (is_string($value) && preg_match('/^h([1-6])$/i', trim($value), $m)) {
                return 'h' . $m[1];
            }

            // A bare level only counts under a key that unambiguously means one;
            // `size` and `tag` carry values like "large" or "div" far more often.
            if (is_numeric($value)
                && in_array(strtolower($key), ['level'], true)
                && (int) $value >= 1 && (int) $value <= 6
            ) {
                return 'h' . (int) $value;
            }
        }

        return '';
    }

    /**
     * Whether a string is plausibly a link or asset destination.
     *
     * Deliberately permissive about relative paths — builders store internal
     * links that way — but rejects the option slugs and CSS values that make up
     * most of a builder tree.
     *
     * @param string $value Candidate.
     * @return bool
     */
    private static function looks_like_url(string $value): bool {
        $value = trim($value);

        if ('' === $value || strlen($value) > 2048) {
            return false;
        }

        if (preg_match('#^(https?:)?//#i', $value) || str_starts_with($value, '/')) {
            return true;
        }

        // Protocol-ish destinations a link node can legitimately hold.
        return (bool) preg_match('#^(mailto:|tel:|\#)#i', $value);
    }

    /**
     * Whether a value carries nothing worth analyzing.
     *
     * Readable text is the usual signal, but not the only one: a page can be
     * made entirely of media. A builder section holding just a gallery
     * reconstructs to `<img>` tags and one holding just a video widget to a
     * single `<iframe>` — both strip to an empty string, so a text-only test
     * discarded them here and the page fell through to the next builder key,
     * then to the raw markup, and finally reported as having no content at all.
     *
     * Comments are dropped before the tag test: the raw markup this class falls
     * back to on a builder page is unrendered block comments, which must stay
     * blank rather than be mistaken for reconstructed media.
     *
     * @param string $value Candidate content.
     * @return bool
     */
    private static function is_blank(string $value): bool {
        if ('' !== trim(wp_strip_all_tags($value))) {
            return false;
        }

        $without_comments = (string) preg_replace('~<!--.*?-->~s', '', $value);

        return 1 !== preg_match('~<(?:a|img|iframe|video|source)\b~i', $without_comments);
    }
}
