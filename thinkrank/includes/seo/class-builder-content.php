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
     * Several generations of the same builder are listed on purpose. Oxygen 6
     * is Breakdance under the hood and writes the same tree, under its own
     * prefix: Breakdance keeps it in `_breakdance_data`, Oxygen 6 in
     * `_oxygen_data` (the key is `__bdox('_meta_prefix') . 'data'`, and the
     * prefix is `_oxygen_` under Oxygen). Both store it inside a
     * `tree_json_string` envelope, see unwrap_tree_envelope(). Earlier Oxygen
     * releases used the shortcode-based `ct_builder_shortcodes` and its JSON
     * sibling. A site can only have one of them.
     *
     * @var string[]
     */
    private const BUILDER_META_KEYS = [
        '_breakdance_data',        // Breakdance
        '_oxygen_data',            // Oxygen 6+ (Breakdance engine, Oxygen prefix)
        // Oxygen classic. 4.x writes the tree as JSON to `ct_builder_json`
        // while still keeping `ct_builder_shortcodes`. A post carrying only
        // the JSON key used to match no key at all and fall through to an
        // empty `post_content`, which reads as a one-word page (#776).
        //
        // Oxygen 4.8.3 then renamed every `ct_*` post meta key to `_ct_*`
        // (`oxygen_vsb_update_4_8_3()` runs `oxy_prefix_meta_keys()` on
        // upgrade, and `oxy_get_post_meta()` only reads the prefixed name
        // from then on). A current Oxygen classic site therefore has only the
        // underscored keys, which nothing here listed, so every one of its
        // pages resolved as empty. The prefixed keys come first because they
        // are what Oxygen itself reads; the bare ones cover a site that has
        // not run the migration (or reverted it with `?unprefix_meta`).
        //
        // These four are not read in this loop: from_oxygen_classic() pairs
        // each JSON key with its shortcode sibling so the two forms can be
        // compared. They are listed here because this list is also what the
        // word-count index watches and what FAQ detection scans.
        '_ct_builder_json',        // Oxygen classic 4.8.3+ (JSON tree)
        'ct_builder_json',         // Oxygen classic 4.0-4.8.2 (JSON tree)
        '_ct_builder_shortcodes',  // Oxygen classic 4.8.3+ (shortcode tree)
        'ct_builder_shortcodes',   // Oxygen classic < 4.8.3 (shortcode tree)
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
     * Bricks' Heading element, and the tag it renders when none is stored.
     *
     * Bricks leaves a setting out of storage while it equals its default, so a
     * Heading left on its default tag is stored with no `tag` at all. Bricks
     * 2.4.1 renders it as `h3` (`Element_Heading::$tag`, overridable by the
     * active theme style's `tag`), and the walker, which only wraps text whose
     * node names a tag, read it as body copy (#908).
     *
     * @since 2.15.0
     * @var string
     */
    private const BRICKS_HEADING_ELEMENT = 'heading';

    /**
     * Tag a Bricks Heading renders when neither it nor a theme style sets one.
     *
     * @since 2.15.0
     * @var string
     */
    private const BRICKS_HEADING_DEFAULT_TAG = 'h3';

    /**
     * Tag an Elementor Heading widget renders when `header_size` is not stored.
     *
     * Elementor saves `settings.toJSON({ remove: ['default'] })`, so a heading
     * left on its default size has no `header_size` in `_elementor_data`, and
     * that default is `h2` (#908).
     *
     * @since 2.15.0
     * @var string
     */
    private const ELEMENTOR_HEADING_DEFAULT_TAG = 'h2';

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
        // Oxygen classic keeps an element's copy in `options.ct_content`
        // (headline, text block, rich text, link and button labels). It is the
        // field Oxygen's own serializer moves between the tags when it writes
        // shortcodes (`parse_components_tree()`), and the one Relevanssi and
        // Oxygen's WPML integration read. Missing from this list, the walker
        // kept only copy that happened to contain markup: a page of plain
        // headings and paragraphs lost almost all of its words.
        'ct_content',
        // Oxygen's composite elements keep their copy under `options.original`
        // instead, one key per field. Taken from the list Oxygen itself treats
        // as text when it serializes (`$options_to_encode`); the numeric price
        // fields and the progress bar's right-hand percentage are left out.
        'testimonial_text', 'testimonial_author', 'testimonial_author_info',
        'icon_box_heading', 'icon_box_text',
        'pricing_box_package_title', 'pricing_box_package_subtitle', 'pricing_box_content',
        'progress_bar_left_text',
    ];

    /**
     * Oxygen classic's storage generations, as JSON key => shortcode key.
     *
     * @since 2.10.0
     * @var array<string,string>
     */
    private const OXYGEN_CLASSIC_KEYS = [
        '_ct_builder_json' => '_ct_builder_shortcodes',
        'ct_builder_json'  => 'ct_builder_shortcodes',
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
     * The global post and every global `setup_postdata()` writes.
     *
     * Rendering points them at the post being analyzed, then puts each one
     * back exactly as it was, unset included (#860).
     *
     * @since 2.12.0
     * @var string[]
     */
    private const POSTDATA_GLOBALS = [
        'post', 'id', 'authordata', 'currentday', 'currentmonth',
        'page', 'pages', 'multipage', 'more', 'numpages',
    ];

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
    /**
     * The builder meta keys, for callers that need to inspect the raw storage
     * rather than the text extracted from it.
     *
     * The SEO Analyzer reads these to answer "is there a ThinkRank FAQ element
     * on this post?", which is a question about the stored tree, not about the
     * words in it (#686).
     *
     * @since 2.7.0
     * @return string[]
     */
    public static function builder_meta_keys(): array {
        return self::BUILDER_META_KEYS;
    }

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

        return self::expand_bricks_components(self::bricks_render_order($stored));
    }

    /**
     * A flat Bricks element list, in the order Bricks renders it.
     *
     * Bricks stores one flat list and links it with `parent` and `children`
     * ids. `Frontend::render_data()` renders the root elements in list order
     * and each element's children in the order of its `children` array, so a
     * child's position in the list says nothing about where it appears on the
     * page. Walking the list as stored put a section's contents wherever they
     * happened to be saved (#907).
     *
     * Anything the walk does not reach (an orphan, a cycle) keeps its stored
     * position after the rest, so no copy is dropped.
     *
     * @since 2.15.0
     *
     * @param array $elements Flat Bricks element list.
     * @return array The same elements, in render order.
     */
    private static function bricks_render_order(array $elements): array {
        $by_id = [];
        foreach ($elements as $index => $element) {
            $id = is_array($element) ? ($element['id'] ?? null) : null;
            if (is_scalar($id) && '' !== (string) $id && !isset($by_id[(string) $id])) {
                $by_id[(string) $id] = $index;
            }
        }

        if (empty($by_id)) {
            return $elements;
        }

        $ordered = [];
        $placed = [];

        $place = static function ($index) use (&$place, &$ordered, &$placed, $elements, $by_id): void {
            if (isset($placed[$index])) {
                return;
            }

            $placed[$index] = true;
            $ordered[] = $elements[$index];

            $children = is_array($elements[$index]) ? ($elements[$index]['children'] ?? []) : [];
            if (!is_array($children)) {
                return;
            }

            foreach ($children as $child_id) {
                if (is_scalar($child_id) && isset($by_id[(string) $child_id])) {
                    $place($by_id[(string) $child_id]);
                }
            }
        };

        foreach ($elements as $index => $element) {
            $parent = is_array($element) ? ($element['parent'] ?? null) : null;
            if (empty($parent) || !is_scalar($parent) || !isset($by_id[(string) $parent])) {
                $place($index);
            }
        }

        foreach ($elements as $index => $element) {
            if (!isset($placed[$index])) {
                $ordered[] = $element;
            }
        }

        return $ordered;
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
        $content = self::render_post_content($raw, $post);

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
     * Runs as the post's own context, as it would on the front end. Admin and
     * REST requests have no current post, so a shortcode reading
     * `get_the_ID()` got nothing, and one looping a related-posts query left
     * the global post on the last of them: its `wp_reset_postdata()` goes back
     * to the main query's post, and there is none. On the Classic Editor this
     * runs after the form prints its hidden `post_ID` and before the title and
     * editor, which then showed the related post, and Update saved it over the
     * original (#860).
     *
     * Secondary queries also run with front-end statuses. In wp-admin core
     * marks every `WP_Query` as an admin query and, when no `post_status` is
     * set, adds the statuses the admin post list shows, draft among them, so
     * a related-posts shortcode listed drafts the front end never shows and
     * the editor-load analysis disagreed with REST and the page (#902).
     *
     * The main query points at the post too. Restoring the globals afterwards
     * (#860) did not reach between shortcodes: a related-posts loop's own
     * `wp_reset_postdata()` still found no post on the main query, so every
     * later shortcode in the same render saw the last looped post (#903).
     *
     * @param string   $raw  Raw post content.
     * @param \WP_Post $post Post the content belongs to.
     * @return string Rendered content.
     */
    private static function render_post_content(string $raw, \WP_Post $post): string {
        if ('' === trim($raw)) {
            return '';
        }

        $content  = $raw;
        $previous = self::snapshot_post_globals();

        // After pre_get_posts core reads `is_admin` only to add the admin
        // list's statuses when none were asked for, so queries that set
        // `post_status`, and the main query, are untouched.
        $front_end_statuses = static function ($query): void {
            if ($query instanceof \WP_Query && !$query->is_main_query()) {
                $query->is_admin = false;
            }
        };

        // `wp_reset_postdata()` returns to the main query's post, which admin
        // and REST requests do not have. Restored in finally, null included.
        $main_query      = (isset($GLOBALS['wp_query']) && $GLOBALS['wp_query'] instanceof \WP_Query) ? $GLOBALS['wp_query'] : null;
        $main_query_post = $main_query ? $main_query->post : null;

        try {
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Made current for the render, restored in finally.
            $GLOBALS['post'] = $post;
            add_action('pre_get_posts', $front_end_statuses, PHP_INT_MIN);
            if ($main_query) {
                $main_query->post = $post;
            }

            // Fires `the_post`, which these paths never fired before: admin,
            // REST and cron analysis had no current post at all. That is the
            // same signal the front-end loop sends and it is what makes
            // `get_the_ID()` work inside a shortcode, but it is a new call on a
            // path that runs in bulk — the word-count index resolves every post
            // it visits — so a theme that counts views on `the_post` will count
            // them during indexing. Accepted deliberately: without it a
            // shortcode cannot resolve its own post, which is the bug (#860).
            if (function_exists('setup_postdata')) {
                setup_postdata($post);
            }

            if (function_exists('has_blocks') && function_exists('do_blocks') && has_blocks($raw)) {
                $content = do_blocks($raw);
            }

            // Block output can itself contain shortcodes, so this runs either way.
            if (function_exists('do_shortcode') && strpos($content, '[') !== false) {
                $content = do_shortcode($content);
            }
        } catch (\Throwable $e) {
            return $raw;
        } finally {
            if ($main_query) {
                $main_query->post = $main_query_post;
            }
            remove_action('pre_get_posts', $front_end_statuses, PHP_INT_MIN);
            self::restore_post_globals($previous);
        }

        return self::is_blank($content) ? $raw : $content;
    }

    /**
     * The post globals as they are now; a global that is unset has no key.
     *
     * @since 2.12.0
     *
     * @return array<string,mixed>
     */
    private static function snapshot_post_globals(): array {
        $snapshot = [];

        foreach (self::POSTDATA_GLOBALS as $name) {
            if (array_key_exists($name, $GLOBALS)) {
                $snapshot[$name] = $GLOBALS[$name];
            }
        }

        return $snapshot;
    }

    /**
     * Put the post globals back as snapshot_post_globals() found them.
     *
     * Assigned directly rather than through `setup_postdata()`: there may have
     * been no post to set up, and re-running it would fire `the_post` again.
     *
     * @since 2.12.0
     *
     * @param array<string,mixed> $snapshot From snapshot_post_globals().
     * @return void
     */
    private static function restore_post_globals(array $snapshot): void {
        foreach (self::POSTDATA_GLOBALS as $name) {
            if (array_key_exists($name, $snapshot)) {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own globals, put back as they were.
                $GLOBALS[$name] = $snapshot[$name];
            } else {
                unset($GLOBALS[$name]);
            }
        }
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
            self::text_from_tree(self::with_bricks_heading_tags(self::without_bricks_element_labels($tree)))
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
                return is_array($component['elements']) ? self::bricks_render_order($component['elements']) : [];
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
     * Give each Bricks Heading the tag it renders with when none is stored.
     *
     * Applied on the Bricks path only. Most other builders' text nodes carry no
     * tag because they are not headings, so a generic "text without a tag is a
     * heading" rule in heading_tag_from() would turn every paragraph into one.
     *
     * A `tag` of `custom` is left alone: the element then renders its
     * `customTag`, which is not necessarily a heading.
     *
     * @since 2.15.0
     *
     * @param array $tree Bricks content area.
     * @return array Tree with each untagged Heading's default tag filled in.
     */
    private static function with_bricks_heading_tags(array $tree): array {
        $default = null;

        foreach ($tree as $index => $element) {
            if (!is_array($element) || self::BRICKS_HEADING_ELEMENT !== ($element['name'] ?? null)) {
                continue;
            }

            $settings = $element['settings'] ?? [];
            if (!is_array($settings)) {
                continue;
            }

            $tag = $settings['tag'] ?? '';
            if (is_string($tag) && '' !== trim($tag)) {
                continue;
            }

            if (null === $default) {
                $default = self::bricks_default_heading_tag();
            }

            $settings['tag'] = $default;
            $tree[$index]['settings'] = $settings;
        }

        return $tree;
    }

    /**
     * The tag Bricks gives a Heading that does not set one.
     *
     * The active theme style can change it. Bricks only loads theme styles for
     * a front-end render, so in admin, REST and CLI requests this is the
     * element's own default.
     *
     * @since 2.15.0
     *
     * @return string Heading tag, h1 to h6.
     */
    private static function bricks_default_heading_tag(): string {
        if (class_exists('\\Bricks\\Theme_Styles')
            && method_exists('\\Bricks\\Theme_Styles', 'get_setting_by_key')
        ) {
            try {
                $styled = \Bricks\Theme_Styles::get_setting_by_key(self::BRICKS_HEADING_ELEMENT, 'tag');
            } catch (\Throwable $e) {
                $styled = null;
            }

            if (is_string($styled) && preg_match('/^h[1-6]$/i', trim($styled))) {
                return strtolower(trim($styled));
            }
        }

        return self::BRICKS_HEADING_DEFAULT_TAG;
    }

    /**
     * Give each Elementor Heading widget its default `header_size` if unstored.
     *
     * @since 2.15.0
     *
     * @param array $elements Decoded `_elementor_data`.
     * @return array The same tree, with untagged Heading widgets tagged.
     */
    private static function with_elementor_heading_tags(array $elements): array {
        foreach ($elements as $index => $element) {
            if (!is_array($element)) {
                continue;
            }

            if ('heading' === ($element['widgetType'] ?? null)) {
                $settings = $element['settings'] ?? [];
                if (is_array($settings)) {
                    $size = $settings['header_size'] ?? '';
                    if (!is_string($size) || '' === trim($size)) {
                        $settings['header_size'] = self::ELEMENTOR_HEADING_DEFAULT_TAG;
                        $element['settings'] = $settings;
                    }
                }
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $element['elements'] = self::with_elementor_heading_tags($element['elements']);
            }

            $elements[$index] = $element;
        }

        return $elements;
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
            if (self::is_oxygen_classic_key($key)) {
                // Resolved as a pair, once, at the first of its keys.
                if ('_ct_builder_json' !== $key) {
                    continue;
                }

                $oxygen = self::from_oxygen_classic($post_id);
                if (!self::is_blank($oxygen)) {
                    return $oxygen;
                }
                continue;
            }

            $stored = get_post_meta($post_id, $key, true);

            if (is_string($stored) && '' !== trim($stored)) {
                $decoded = json_decode($stored, true);
                if ('_elementor_data' === $key && is_array($decoded)) {
                    $decoded = self::with_elementor_heading_tags($decoded);
                }

                // JSON node tree (Breakdance/Oxygen 6, Elementor).
                if (is_array($decoded)) {
                    $text = self::text_from_tree(self::unwrap_tree_envelope($decoded));
                    if (!self::is_blank($text)) {
                        return $text;
                    }
                    continue;
                }

                continue;
            }

            // Some builders store an already-decoded tree — an array for most,
            // an array of objects for Beaver Builder (#449).
            $tree = self::as_children($stored);
            if (null !== $tree) {
                $tree = self::unwrap_tree_envelope($tree);
                $text = self::text_from_tree($tree);
                if (!self::is_blank($text)) {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * The node tree inside a Breakdance / Oxygen 6 storage envelope.
     *
     * Neither builder stores its tree directly. The meta value is
     * `{"tree_json_string": "<the tree, JSON-encoded again>"}`, so one
     * json_decode() yields the envelope, not the tree. Walked as a tree, the
     * envelope is a single string leaf: kept whole as "content" when any
     * element held rich text (the encoded JSON then reached scoring, the
     * get-post-content ability and Markdown for AI), dropped when none did,
     * leaving the page empty (#905).
     *
     * An envelope whose inner string does not decode returns an empty tree,
     * never the string: handing the raw JSON back to the walker would bring
     * the JSON-as-content failure back on corrupt data. Anything that is not
     * an envelope is returned unchanged, so a bare tree still resolves.
     *
     * @since 2.15.0
     *
     * @param array $decoded Decoded meta value.
     * @return array The node tree.
     */
    private static function unwrap_tree_envelope(array $decoded): array {
        if (!array_key_exists('tree_json_string', $decoded)) {
            return $decoded;
        }

        $inner = is_string($decoded['tree_json_string'])
            ? json_decode($decoded['tree_json_string'], true)
            : $decoded['tree_json_string'];

        if (is_array($inner)) {
            return $inner;
        }

        // Re-serialised envelopes can carry the tree as an object.
        $inner = self::as_children($inner);

        return null !== $inner ? $inner : [];
    }

    /**
     * Whether a meta key is one of Oxygen classic's storage keys.
     *
     * @since 2.10.0
     *
     * @param string $key Meta key.
     * @return bool
     */
    private static function is_oxygen_classic_key(string $key): bool {
        return isset(self::OXYGEN_CLASSIC_KEYS[$key]) || in_array($key, self::OXYGEN_CLASSIC_KEYS, true);
    }

    /**
     * Text of an Oxygen classic page, from whichever stored form holds more.
     *
     * Oxygen 4.x keeps the same tree twice: as JSON, and as the shortcodes it
     * used before 4.0. The JSON is preferred because it carries copy the
     * shortcode form hides (a composite element's text is base64-encoded
     * inside `ct_options`, which is configuration and stripped). It is not
     * trusted blindly, though. Reading `ct_builder_json` first once meant a
     * key missing from CONTENT_KEYS silently threw the page away while the
     * shortcode copy sat unread next to it, because a non-empty JSON result
     * stopped the search. Comparing the two means the next such gap costs
     * nothing: the richer form wins.
     *
     * A generation is only read as a pair. The prefixed keys are what Oxygen
     * 4.8.3+ reads, so an unprefixed leftover next to them is stale.
     *
     * @since 2.10.0
     *
     * @param int $post_id Post ID.
     * @return string Extracted text, or '' when Oxygen classic stored nothing.
     */
    private static function from_oxygen_classic(int $post_id): string {
        foreach (self::OXYGEN_CLASSIC_KEYS as $json_key => $shortcode_key) {
            $json       = get_post_meta($post_id, $json_key, true);
            $shortcodes = get_post_meta($post_id, $shortcode_key, true);

            $from_json = '';
            if (is_string($json) && '' !== trim($json)) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    // `[oxygen data="..."]` is a dynamic-data placeholder
                    // Oxygen fills at render time. The shortcode path drops it
                    // with every other tag, so it goes here too or the two
                    // forms would disagree on the same page.
                    $from_json = (string) preg_replace(
                        '/\[oxygen\b[^\]]*\]/i',
                        ' ',
                        self::text_from_tree($decoded)
                    );
                }
            }

            $from_shortcodes = '';
            if (is_string($shortcodes) && strpos($shortcodes, '[') !== false) {
                $from_shortcodes = self::text_from_shortcodes($shortcodes);
            }

            if (self::is_blank($from_json) && self::is_blank($from_shortcodes)) {
                continue;
            }

            return self::visible_word_count($from_json) >= self::visible_word_count($from_shortcodes)
                ? $from_json
                : $from_shortcodes;
        }

        return '';
    }

    /**
     * Rough count of the words a visitor would read in extracted text.
     *
     * Only used to compare two extractions of the same page, so it needs to
     * be consistent rather than locale-exact.
     *
     * @since 2.10.0
     *
     * @param string $text Extracted text or markup.
     * @return int
     */
    private static function visible_word_count(string $text): int {
        $plain = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));

        return '' === $plain ? 0 : count(explode(' ', $plain));
    }

    /**
     * Shortcode attributes that carry copy a visitor reads.
     *
     * An allow-list, not a deny-list. Oxygen Classic tags carry far more
     * attributes than they do copy — `id`, `class`, `selector`, `url`,
     * `ct_options` and friends — and a deny-list silently admits every
     * attribute a future builder release invents, which is how markup ends up
     * being counted as prose.
     *
     * @var string[]
     */
    private const SHORTCODE_TEXT_ATTRIBUTES = [
        'text',
        'content',
        'heading',
        'title',
        'subtitle',
        'label',
        'caption',
        'description',
        'alt',
        'button_text',
        'link_text',
    ];

    /**
     * Extract readable text from a shortcode tree, without rendering it.
     *
     * Oxygen Classic is the only builder whose storage is shortcodes rather
     * than JSON, and the previous implementation handed the string to
     * `do_shortcode()`. That silently depends on Oxygen having registered its
     * `ct_*` handlers in the current request — which it has on a front-end
     * view, and has not during bulk analysis, the post-list column, cron or
     * REST/MCP. With no handlers registered `do_shortcode()` returns its input
     * unchanged, so the raw shortcode source was scored as if it were the
     * page's prose: `[ct_section`, `id="section-1"` and the rest counted toward
     * the word count, while the actual copy sitting in `text="..."` attributes
     * was never counted at all (#776).
     *
     * `strip_shortcodes()` is no help either — it also only knows registered
     * shortcodes, so it leaves the same text untouched.
     *
     * Reading the stored tree directly is what every other builder here already
     * does, and it matches the class's stated design: no render engine, no
     * dependency on load order, safe during a bulk run.
     *
     * Parsing unconditionally, rather than rendering when Oxygen happens to be
     * loaded and parsing otherwise, is deliberate. It makes the extracted text
     * the same in every context, so the score in the editor matches the score
     * from a bulk run or from MCP. The old code produced whichever of the two
     * the request happened to allow, which is why the same post could report
     * two different word counts depending on how it was asked.
     *
     * The trade-off is that rendered output (resolved images, links, anything
     * Oxygen pulls in from a reusable part) is no longer reflected here. For
     * what this text feeds — word count, content scoring, meta-description
     * fallbacks and schema text — that markup was never the point, and counting
     * it only when the builder happened to be booted was the bug.
     *
     * @since 2.10.0
     *
     * @param string $stored Raw shortcode source.
     * @return string Extracted text.
     */
    private static function text_from_shortcodes(string $stored): string {
        // Oxygen stores each element's settings as a JSON blob in `ct_options`.
        // It is configuration, never copy, and it contains braces and brackets
        // that would otherwise confuse the tag scan below, so it goes first.
        //
        // The blob is matched as a balanced JSON object, not as "up to the
        // next quote". Oxygen wraps it in single quotes but does not escape
        // an apostrophe inside it (`"nicename":"Bob's Plumbing"`), so the
        // quote-to-quote match stopped mid-value and the rest of the blob,
        // `s Plumbing"}'` and all, was left in the tag and leaked into the
        // text. Strings inside the object are skipped whole, so neither a quote
        // nor a brace inside a value can end the match early.
        $source = (string) preg_replace(
            '/\sct_options\s*=\s*\'(?<obj>\{(?:[^{}"]++|"(?:[^"\\\\]|\\\\.)*+"|(?&obj))*+\})\'/s',
            '',
            $stored
        );

        // Anything not shaped like Oxygen's JSON blob keeps the old,
        // quote-delimited strip.
        $source = (string) preg_replace(
            '/\sct_options\s*=\s*(["\']).*?\1/s',
            '',
            $source
        );

        $attributes = implode('|', array_map(
            static fn(string $name): string => preg_quote($name, '/'),
            self::SHORTCODE_TEXT_ATTRIBUTES
        ));

        // Replace each shortcode tag with whatever readable copy its attributes
        // carry. Text between tags is left exactly where it is, so the result
        // keeps the page's reading order rather than hoisting all the headings
        // to the front.
        // The attribute blob is matched quote-aware rather than as "anything up
        // to the first `]`". Oxygen copy contains brackets often enough to
        // matter — "Best tools [2026]", "[Updated] our policy" — and a naive
        // scan ends the tag inside the `text` attribute, dropping the copy
        // before the bracket and leaking the stray `"]` after it into the
        // prose. Which is this bug's own failure mode: the wrong text scored.
        //
        // A tag name must start with a letter or underscore. `[2026]` is not a
        // shortcode anyone can register, and scanning it as one dropped the
        // year out of "Best tools [2026]".
        $text = (string) preg_replace_callback(
            '/\[\/?[a-zA-Z_][a-zA-Z0-9_-]*((?:[^\]"\']|"[^"]*"|\'[^\']*\')*)\]/',
            static function (array $matches) use ($attributes): string {
                if ('' === trim($matches[1])) {
                    return ' ';
                }

                if (!preg_match_all(
                    '/\b(' . $attributes . ')\s*=\s*(["\'])(.*?)\2/s',
                    $matches[1],
                    $found,
                    PREG_SET_ORDER
                )) {
                    return ' ';
                }

                $parts = [];
                foreach ($found as $attribute) {
                    $value = trim($attribute[3]);

                    // An attribute holding markup or a JSON fragment is
                    // configuration that happens to share a name with a copy
                    // field, not something a visitor reads.
                    if ('' === $value || preg_match('/^[\[{<]/', $value)) {
                        continue;
                    }

                    $parts[] = $value;
                }

                return empty($parts) ? ' ' : ' ' . implode(' ', $parts) . ' ';
            },
            $source
        );

        // Oxygen escapes square brackets in an element's copy before writing
        // it between the tags, so that "Best tools [2026]" cannot be mistaken
        // for a shortcode (`oxygen_vsb_filter_shortcode_content_encode()`).
        // Decoded only now, after the tag scan, for the same reason; left
        // encoded, the placeholders were scored as words of their own.
        $text = str_replace(
            ['_OXY_OPENING_BRACKET_', '_OXY_CLOSING_BRACKET_'],
            ['[', ']'],
            $text
        );

        // Entities are stored encoded in attributes (&amp;, &#8217;), and would
        // otherwise be counted as words.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
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
     * One depth-first walk, so the output follows the tree's own order, which
     * is the order the builders read here render in. This used to be two
     * passes over the whole tree, one for the reconstructed headings, links and
     * images and one for the remaining text, and the output followed pass
     * order: every heading and button on the page first, every paragraph after
     * them. That order became the meta description, og:description, the schema
     * description and Pro's Markdown for AI document (#907).
     *
     * @param array $tree Decoded builder tree.
     * @return string Collected HTML.
     */
    private static function text_from_tree(array $tree): string {
        // Each entry is [value, is_markup], in tree order.
        $entries = [];

        // Strings already represented inside reconstructed markup, so the plain
        // text doesn't emit a link label or heading a second time and double it
        // in the word count. Applied after the walk, against the whole tree:
        // a string folded into markup anywhere is dropped everywhere, exactly
        // as it was when the markup pass ran over the whole tree first. Checking
        // it during the walk instead would let a bare copy that appears before
        // its heading through.
        $consumed = [];

        // Markup is content wherever it appears; bare strings only count when
        // their key says they are content, so slugs and class names stay out of
        // the word count.
        $leaf = static function ($value, $key) use (&$entries): void {
            if (!is_string($value) || '' === trim($value)) {
                return;
            }

            $is_content_key = is_string($key)
                && in_array(strtolower($key), self::CONTENT_KEYS, true);

            if ($is_content_key || strpos($value, '<') !== false) {
                $entries[] = [$value, false];
            }
        };

        // Text only, no reconstruction. Used for a `link` / `image` / video
        // sub-object: a destination descriptor the parent has already folded
        // into its markup. Rebuilding inside it would emit the same URL a second
        // time as a bare link and turn an image's own `url` field into a
        // spurious <a>, but any copy it carries still counts.
        $sweep = static function ($node, $key) use (&$sweep, $leaf): void {
            $children = self::as_children($node);
            if (null === $children) {
                $leaf($node, $key);
                return;
            }

            foreach ($children as $child_key => $child) {
                $sweep($child, is_string($child_key) ? $child_key : $key);
            }
        };

        // Rebuild <a>, <img> and <hN> from node *shape*, then carry on through
        // the node's own fields in order. This has to happen per node rather
        // than per leaf: a link's label and its destination are separate
        // sibling fields, so once the tree is flattened to leaves the pairing
        // is gone.
        $walk = static function ($node, $key = null) use (&$walk, $sweep, $leaf, &$entries, &$consumed): void {
            $children = self::as_children($node);
            if (null === $children) {
                $leaf($node, $key);
                return;
            }

            $markup = self::markup_for_node($children, $consumed);
            if ('' !== $markup) {
                $entries[] = [$markup, true];
            }

            foreach ($children as $child_key => $child) {
                $next_key = is_string($child_key) ? $child_key : $key;

                if (is_string($child_key)
                    && (in_array(strtolower($child_key), self::URL_KEYS, true)
                        || in_array(strtolower($child_key), self::IMAGE_KEYS, true)
                        || in_array(strtolower($child_key), self::VIDEO_KEYS, true))
                ) {
                    $sweep($child, $next_key);
                    continue;
                }

                $walk($child, $next_key);
            }
        };

        $walk($tree);

        $collected = [];
        foreach ($entries as [$value, $is_markup]) {
            // Already inside a reconstructed tag.
            if (!$is_markup && in_array($value, $consumed, true)) {
                continue;
            }

            $collected[] = $value;
        }

        if (empty($collected)) {
            return '';
        }

        // De-duplicate: builder trees often repeat a value across responsive
        // breakpoints, which would otherwise multiply the word count. Keeps the
        // first occurrence, so a repeat never moves a value later in the page.
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
