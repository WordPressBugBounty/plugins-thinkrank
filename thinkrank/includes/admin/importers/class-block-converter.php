<?php

/**
 * Rank Math FAQ / HowTo block converter.
 *
 * Rewrites `rank-math/faq-block` and `rank-math/howto-block` in `post_content`
 * into ThinkRank's own `thinkrank/faq` and `thinkrank/howto` blocks, so the
 * questions and steps keep rendering AND regain their FAQPage / HowTo schema
 * once Rank Math is gone (#777).
 *
 * Two properties matter more than anything else here:
 *
 * 1. **Only the matched blocks are touched.** The rewrite is a delimiter-level
 *    replacement, not a parse_blocks() / serialize_blocks() round trip. A round
 *    trip re-serializes every block in the post and quietly normalises markup
 *    all over it; here, every byte outside a Rank Math FAQ/HowTo block is left
 *    exactly as the author saved it. The blocks are located with core's own
 *    block tokenizer (WP_Block_Parser::next_token()), and a post whose Rank
 *    Math markup does not close cleanly is refused whole rather than guessed
 *    at.
 *
 * 2. **The generated markup is byte-identical to what the block's save.js would
 *    produce.** Gutenberg validates a block by re-running save() and comparing
 *    with the stored HTML, so markup that is merely equivalent still opens as
 *    "this block contains unexpected or invalid content". The renderers below
 *    therefore mirror `src/blocks/faq-block/save.js` and
 *    `src/blocks/howto-block/save.js` — including @wordpress/element's
 *    serializer rules: bare boolean attributes (` open`, not `open=""`),
 *    self-closing void tags with no space (`<img .../>`), inline styles as
 *    `prop:value` joined by `;` with no trailing separator, and the style
 *    attribute omitted entirely when every value is undefined.
 *
 * Any change to those save.js files must be mirrored here, and the byte-parity
 * tests in tests/Unit/ are what catch it when it is not.
 *
 * @package ThinkRank\Admin\Importers
 * @since 2.10.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

use ThinkRank\Integrations\Rank_Math_Blocks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Block Converter Class
 *
 * @since 2.10.0
 */
class Block_Converter {

    /**
     * Migration type slug this converter backs.
     */
    public const TYPE = 'content_blocks';

    /**
     * Post meta holding the pre-conversion content, written only when the site
     * has revisions disabled and there is therefore no other way back.
     * restore_post(), exposed as POST /thinkrank/v1/import/content-blocks/restore,
     * puts it back.
     */
    public const BACKUP_META = '_thinkrank_rank_math_blocks_backup';

    /**
     * Post meta holding an md5 of the content the converter wrote, next to the
     * backup. restore_post() compares it with the post as it stands, so a post
     * edited after the conversion is not silently rolled back over the edits.
     *
     * @since 2.10.0
     */
    public const BACKUP_HASH_META = '_thinkrank_rank_math_blocks_backup_hash';

    /**
     * Posts converted per migrate chunk.
     */
    private const CHUNK_SIZE = 50;

    /**
     * Post statuses that are never scanned: a revision is a copy of a post we
     * convert anyway, and trash / auto-draft are not published content.
     *
     * @var string[]
     */
    private const EXCLUDED_STATUSES = ['trash', 'auto-draft', 'inherit'];

    /**
     * ThinkRank FAQ block defaults that the renderer depends on. Mirrors
     * src/blocks/faq-block/index.js.
     */
    private const FAQ_DEFAULTS = [
        'firstOpen'        => true,
        'itemSpacing'      => 8,
        'itemBorderColor'  => '#e2e4e7',
        'itemBorderRadius' => 6,
        'titleFontSize'    => 17,
    ];

    /**
     * ThinkRank HowTo block defaults. Mirrors src/blocks/howto-block/index.js.
     */
    private const HOWTO_DEFAULTS = [
        'showNumbers'       => true,
        'stepSpacing'       => 12,
        'stepBorderRadius'  => 6,
        'stepTitleFontSize' => 17,
    ];

    /**
     * How many posts still carry a convertible Rank Math block.
     *
     * @return int
     */
    public static function count_posts(): int {
        global $wpdb;

        // where_clause() is built entirely from $wpdb->prepare() fragments and
        // esc_sql()'d literals, so there is no caller input left to place; the
        // sniff cannot see through the helper.
        $sql = "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE " . self::where_clause();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($sql);
    }

    /**
     * One page of post ids still carrying a convertible block.
     *
     * Ordered by ID so paging is stable while earlier pages are being written.
     *
     * `$per_page` is caller-supplied because the exporter decides whether
     * another page follows by comparing the returned row count against its own
     * `chunk_size`. A converter paging in smaller units than the exporter
     * expects would look like a short final page on the very first call, and
     * every post after it would be dropped without a word.
     *
     * @param int      $page     Page number (1-indexed).
     * @param int|null $per_page Rows per page; defaults to this class's chunk size.
     * @return int[]
     */
    public static function get_post_ids(int $page, ?int $per_page = null): array {
        global $wpdb;

        $page     = max(1, $page);
        $per_page = max(1, $per_page ?? self::CHUNK_SIZE);
        $offset   = ($page - 1) * $per_page;

        // As above: the only caller-supplied values here are the two integers,
        // and both are passed as placeholders.
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE " . self::where_clause()
            . ' ORDER BY ID ASC LIMIT %d OFFSET %d';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col($wpdb->prepare($sql, $per_page, $offset));

        return array_map('intval', $ids ?: []);
    }

    /**
     * Number of posts handled per chunk, so callers can page in step.
     *
     * @return int
     */
    public static function chunk_size(): int {
        return self::CHUNK_SIZE;
    }

    /**
     * The shared WHERE clause for "this post holds a Rank Math FAQ/HowTo block".
     *
     * Matches on the opening block delimiter rather than the rendered class, so
     * a post whose block was already converted stops matching immediately.
     *
     * @return string
     */
    private static function where_clause(): string {
        global $wpdb;

        $statuses = implode(
            ',',
            array_map(
                static fn(string $status): string => "'" . esc_sql($status) . "'",
                self::EXCLUDED_STATUSES
            )
        );

        $likes = [];
        foreach (array_keys(Rank_Math_Blocks::BLOCK_MAP) as $block_name) {
            $likes[] = $wpdb->prepare(
                'post_content LIKE %s',
                '%' . $wpdb->esc_like('<!-- wp:' . $block_name) . '%'
            );
        }

        return '(' . implode(' OR ', $likes) . ")"
            . " AND post_type != 'revision'"
            . " AND post_status NOT IN ({$statuses})";
    }

    /**
     * Convert one post in place.
     *
     * A post with nothing left to convert is reported as `unchanged` and is
     * never written, which is what makes re-running the migration free of extra
     * revisions. A post whose Rank Math markup cannot be converted safely
     * (an unclosed block, attributes that are not JSON, a PCRE failure) is
     * reported as `error` and also never written: it needs a human, and
     * counting it as a skip would hide it among the posts that were simply
     * already done.
     *
     * @param int $post_id Post id.
     * @return array{status:string,converted:int,message:string}
     */
    public static function convert_post(int $post_id): array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return ['status' => 'error', 'converted' => 0, 'message' => 'Post not found.'];
        }

        $result = self::convert_content((string) $post->post_content);

        if ('' !== $result['error']) {
            return [
                'status'    => 'error',
                'converted' => 0,
                'message'   => sprintf('Post %d left unchanged: %s', $post_id, $result['error']),
            ];
        }

        if (0 === $result['converted']) {
            return ['status' => 'unchanged', 'converted' => 0, 'message' => ''];
        }

        // Revisions are the natural undo. Where the site has turned them off,
        // stash the original once so the conversion is still reversible; never
        // overwrite an earlier backup, or a second run would bury the original.
        //
        // update_post_meta() unslashes its value, so the raw content has to be
        // slashed first. Without it every `"` / `<` escape in the
        // block attribute JSON lost its backslash and the backup could not be
        // restored into a valid post.
        $backup = !wp_revisions_enabled($post) && '' === (string) get_post_meta($post_id, self::BACKUP_META, true);
        if ($backup) {
            update_post_meta($post_id, self::BACKUP_META, wp_slash($post->post_content));
        }

        $updated = wp_update_post(
            [
                'ID'           => $post_id,
                'post_content' => wp_slash($result['content']),
            ],
            true
        );

        if (is_wp_error($updated)) {
            // Nothing was written, so a backup of it would only make a later
            // restore look necessary when it is not.
            if ($backup) {
                delete_post_meta($post_id, self::BACKUP_META);
            }

            return [
                'status'    => 'error',
                'converted' => 0,
                'message'   => $updated->get_error_message(),
            ];
        }

        if ($backup) {
            // Hash what was actually stored, not what we asked for: content
            // filters (kses for a user without unfiltered_html) may have
            // adjusted it on the way in.
            $saved = get_post($post_id);
            $stored = $saved instanceof \WP_Post ? (string) $saved->post_content : $result['content'];
            update_post_meta($post_id, self::BACKUP_HASH_META, md5($stored));
        }

        return ['status' => 'converted', 'converted' => $result['converted'], 'message' => ''];
    }

    /**
     * Put a converted post back the way it was before the conversion.
     *
     * Only posts converted while revisions were disabled carry a backup; on
     * every other site the post's revision history is the undo. A post edited
     * since the conversion is refused with `modified` unless `$force` is set,
     * because restoring it would throw those edits away.
     *
     * @since 2.10.0
     *
     * @param int  $post_id Post id.
     * @param bool $force   Restore even when the post changed after conversion.
     * @return array{status:string,message:string} Status is one of `restored`,
     *                                             `no_backup`, `modified` or `error`.
     */
    public static function restore_post(int $post_id, bool $force = false): array {
        $backup = get_post_meta($post_id, self::BACKUP_META, true);
        if (!is_string($backup) || '' === $backup) {
            return ['status' => 'no_backup', 'message' => ''];
        }

        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return ['status' => 'error', 'message' => 'Post not found.'];
        }

        $hash = (string) get_post_meta($post_id, self::BACKUP_HASH_META, true);
        if (!$force && md5((string) $post->post_content) !== $hash) {
            return [
                'status'  => 'modified',
                'message' => sprintf('Post %d was edited after the conversion; pass force to restore it anyway.', $post_id),
            ];
        }

        $updated = wp_update_post(
            [
                'ID'           => $post_id,
                'post_content' => wp_slash($backup),
            ],
            true
        );

        if (is_wp_error($updated)) {
            return ['status' => 'error', 'message' => $updated->get_error_message()];
        }

        delete_post_meta($post_id, self::BACKUP_META);
        delete_post_meta($post_id, self::BACKUP_HASH_META);

        return ['status' => 'restored', 'message' => ''];
    }

    /**
     * One page of post ids that still hold a conversion backup.
     *
     * Keyset-paged on the post id rather than by offset: a restored post drops
     * out of the set, so an offset would skip rows, while a `modified` post
     * stays in it and would otherwise be returned forever.
     *
     * @since 2.10.0
     *
     * @param int $after Only ids greater than this.
     * @param int $limit Maximum ids returned.
     * @return int[]
     */
    public static function get_backup_post_ids(int $after = 0, int $limit = 50): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d",
                self::BACKUP_META,
                max(0, $after),
                max(1, $limit)
            )
        );

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * Rewrite every convertible Rank Math block in a content string.
     *
     * The blocks are found with core's block tokenizer, so a delimiter is
     * recognised (and its attribute JSON decoded) exactly as the editor would.
     * Each Rank Math block's span runs from its opener to its closer, which
     * must be the very next delimiter: these blocks have no inner blocks, so
     * anything else in between means the markup is broken. The previous regex
     * let a block with no closer run on to the next Rank Math block's closer
     * and replaced everything in between, author paragraphs included.
     *
     * On any structural problem, or a PCRE failure inside the tokenizer, the
     * content is returned untouched with `error` set; nothing is converted
     * partially.
     *
     * @param string $content Post content.
     * @return array{content:string,converted:int,error:string}
     */
    public static function convert_content(string $content): array {
        $unchanged = ['content' => $content, 'converted' => 0, 'error' => ''];

        if ('' === $content || !class_exists('WP_Block_Parser')) {
            return $unchanged;
        }

        $spans = self::find_source_blocks($content);

        if (is_string($spans)) {
            return ['content' => $content, 'converted' => 0, 'error' => $spans];
        }

        if (empty($spans)) {
            return $unchanged;
        }

        $out       = '';
        $cursor    = 0;
        $converted = 0;

        foreach ($spans as $span) {
            $rendered = self::render_block($span['name'], $span['attrs']);
            if (null === $rendered) {
                continue;
            }

            $out   .= substr($content, $cursor, $span['start'] - $cursor) . $rendered;
            $cursor = $span['end'];
            $converted++;
        }

        if (0 === $converted) {
            return $unchanged;
        }

        return [
            'content'   => $out . substr($content, $cursor),
            'converted' => $converted,
            'error'     => '',
        ];
    }

    /**
     * Byte spans of every convertible block, in document order.
     *
     * @param string $content Post content.
     * @return array<int,array{start:int,end:int,name:string,attrs:array<string,mixed>}>|string
     *         The spans, or a message describing why the content is unsafe to convert.
     */
    private static function find_source_blocks(string $content) {
        $parser           = new \WP_Block_Parser();
        $parser->document = $content;
        $parser->offset   = 0;

        $spans = [];

        while (true) {
            list($type, $name, $attrs, $start, $length) = $parser->next_token();

            if ('no-more-tokens' === $type) {
                // next_token() reports a PCRE failure (backtrack or JIT stack
                // limit on a very large post) as the end of the document.
                // Taking it at its word would convert only the blocks before
                // the failure point and report the rest as done.
                if (PREG_NO_ERROR !== preg_last_error()) {
                    return 'the block tokenizer failed (' . self::pcre_error_name() . ').';
                }
                break;
            }

            $parser->offset = $start + $length;

            // A stray Rank Math closer with no opener has nothing to convert,
            // and removing it is not ours to decide.
            if ('block-closer' === $type || !Rank_Math_Blocks::is_source_block((string) $name)) {
                continue;
            }

            // Attribute text that is not valid JSON decodes to null. Mapping
            // that as "no attributes" would convert a block with questions in
            // it into an empty one and delete them.
            if (!is_array($attrs)) {
                return sprintf('%s at byte %d has attributes that are not valid JSON.', $name, $start);
            }

            if ('void-block' === $type) {
                $spans[] = ['start' => $start, 'end' => $start + $length, 'name' => $name, 'attrs' => $attrs];
                continue;
            }

            list($next_type, $next_name, , $next_start, $next_length) = $parser->next_token();

            if ('block-closer' !== $next_type || $next_name !== $name) {
                if ('no-more-tokens' === $next_type && PREG_NO_ERROR !== preg_last_error()) {
                    return 'the block tokenizer failed (' . self::pcre_error_name() . ').';
                }

                return sprintf('%s opened at byte %d is never closed.', $name, $start);
            }

            $parser->offset = $next_start + $next_length;
            $spans[]        = [
                'start' => $start,
                'end'   => $next_start + $next_length,
                'name'  => $name,
                'attrs' => $attrs,
            ];
        }

        return $spans;
    }

    /**
     * Readable name for the last PCRE error.
     *
     * @return string
     */
    private static function pcre_error_name(): string {
        return function_exists('preg_last_error_msg') ? preg_last_error_msg() : 'PCRE error ' . preg_last_error();
    }

    /**
     * Serialize one converted block, or null when it carries nothing to keep.
     *
     * A Rank Math block whose every item was hidden still gets replaced — with
     * nothing. Leaving it in place would keep showing the editor's "your site
     * doesn't include support for this block" warning for content that was
     * never on the page to begin with.
     *
     * @param string              $block_name Rank Math block name.
     * @param array<string,mixed> $attrs      Rank Math attributes.
     * @return string|null
     */
    private static function render_block(string $block_name, array $attrs): ?string {
        $mapped = Rank_Math_Blocks::map_block($block_name, $attrs);

        if (null === $mapped) {
            return '';
        }

        if ('thinkrank/faq' === $mapped['name']) {
            return self::serialize_block('thinkrank/faq', $mapped['attrs'], self::render_faq_html($mapped['attrs']));
        }

        // The HowTo block has no field for Rank Math's lead image, so it is
        // preserved as a core/image block above the steps rather than dropped.
        $prefix = '';
        $image  = Rank_Math_Blocks::howto_main_image($attrs);
        if (null !== $image) {
            $prefix = self::serialize_core_image($image) . "\n\n";
        }

        return $prefix . self::serialize_block(
            'thinkrank/howto',
            $mapped['attrs'],
            self::render_howto_html($mapped['attrs'])
        );
    }

    /**
     * Wrap rendered HTML in the block delimiters the editor would write.
     *
     * @param string              $name  Block name.
     * @param array<string,mixed> $attrs Block attributes.
     * @param string              $html  Saved markup ('' when save() returns null).
     * @return string
     */
    private static function serialize_block(string $name, array $attrs, string $html): string {
        $encoded = serialize_block_attributes($attrs);

        if ('' === $html) {
            return "<!-- wp:{$name} {$encoded} /-->";
        }

        return "<!-- wp:{$name} {$encoded} -->\n{$html}\n<!-- /wp:{$name} -->";
    }

    /**
     * A `core/image` block for Rank Math's HowTo lead image.
     *
     * @param array{id:int,url:string,alt:string,width:int,height:int} $image Image details.
     * @return string
     */
    private static function serialize_core_image(array $image): string {
        $attrs = [
            'id'              => $image['id'],
            'sizeSlug'        => 'full',
            'linkDestination' => 'none',
        ];

        $img = '<img src="' . self::escape_attribute($image['url']) . '"'
            . ' alt="' . self::escape_attribute($image['alt']) . '"'
            . ' class="wp-image-' . $image['id'] . '"/>';

        return '<!-- wp:image ' . serialize_block_attributes($attrs) . " -->\n"
            . '<figure class="wp-block-image size-full">' . $img . '</figure>'
            . "\n<!-- /wp:image -->";
    }

    /**
     * Render `thinkrank/faq` save markup.
     *
     * Mirrors src/blocks/faq-block/save.js exactly. See the class docblock for
     * why byte parity is the requirement rather than equivalence.
     *
     * @param array<string,mixed> $attrs ThinkRank FAQ attributes.
     * @return string
     */
    public static function render_faq_html(array $attrs): string {
        $items = [];
        foreach ($attrs['faqs'] ?? [] as $faq) {
            if ('' !== ($faq['question'] ?? '') || '' !== ($faq['answer'] ?? '') || '' !== ($faq['imageUrl'] ?? '')) {
                $items[] = $faq;
            }
        }

        if (empty($items)) {
            return '';
        }

        $item_style = self::style([
            'margin-bottom' => self::FAQ_DEFAULTS['itemSpacing'] . 'px',
            'background'    => null,
            'border'        => '1px solid ' . self::FAQ_DEFAULTS['itemBorderColor'],
            'border-radius' => self::FAQ_DEFAULTS['itemBorderRadius'] . 'px',
        ]);
        $question_style = self::style([
            'color'      => null,
            'background' => null,
            'font-size'  => self::FAQ_DEFAULTS['titleFontSize'] . 'px',
        ]);
        $answer_style = self::style(['color' => null]);

        $html = '<div class="wp-block-thinkrank-faq thinkrank-faq">';

        foreach ($items as $index => $faq) {
            // `open` is a boolean attribute, so the serializer emits the bare
            // name — `open`, never `open=""`.
            $open = (self::FAQ_DEFAULTS['firstOpen'] && 0 === $index) ? ' open' : '';

            $html .= '<details class="thinkrank-faq__item"' . $item_style . $open . '>';
            $html .= '<summary class="thinkrank-faq__question"' . $question_style . '>'
                . (string) ($faq['question'] ?? '') . '</summary>';
            $html .= '<div class="thinkrank-faq__answer"' . $answer_style . '>'
                . (string) ($faq['answer'] ?? '') . '</div>';

            if ('' !== ($faq['imageUrl'] ?? '')) {
                $html .= '<img class="thinkrank-faq__image"'
                    . ' src="' . self::escape_attribute((string) $faq['imageUrl']) . '"'
                    . ' alt="' . self::escape_attribute((string) ($faq['imageAlt'] ?? '')) . '"/>';
            }

            $html .= '</details>';
        }

        return $html . '</div>';
    }

    /**
     * Render `thinkrank/howto` save markup.
     *
     * Mirrors src/blocks/howto-block/save.js exactly.
     *
     * @param array<string,mixed> $attrs ThinkRank HowTo attributes.
     * @return string
     */
    public static function render_howto_html(array $attrs): string {
        $items = [];
        foreach ($attrs['steps'] ?? [] as $step) {
            if ('' !== ($step['title'] ?? '') || '' !== ($step['text'] ?? '') || '' !== ($step['imageUrl'] ?? '')) {
                $items[] = $step;
            }
        }

        if (empty($items)) {
            return '';
        }

        $step_style = self::style([
            'margin-bottom' => self::HOWTO_DEFAULTS['stepSpacing'] . 'px',
            'background'    => null,
            'border'        => null,
            'border-radius' => self::HOWTO_DEFAULTS['stepBorderRadius'] . 'px',
        ]);
        $title_style = self::style([
            'color'     => null,
            'font-size' => self::HOWTO_DEFAULTS['stepTitleFontSize'] . 'px',
        ]);
        $text_style = self::style(['color' => null]);

        $html = '<div class="wp-block-thinkrank-howto thinkrank-howto">';

        $description = (string) ($attrs['description'] ?? '');
        if ('' !== $description) {
            $html .= '<p class="thinkrank-howto__description">' . $description . '</p>';
        }

        $total_time = self::format_total_time($attrs);
        if ('' !== $total_time) {
            $html .= '<p class="thinkrank-howto__duration"><strong>Total time:</strong> '
                . self::escape_html($total_time) . '</p>';
        }

        $list_tag = self::HOWTO_DEFAULTS['showNumbers'] ? 'ol' : 'ul';
        $html .= '<' . $list_tag . ' class="thinkrank-howto__steps">';

        foreach ($items as $step) {
            $html .= '<li class="thinkrank-howto__step"' . $step_style . '>';
            $html .= '<div class="thinkrank-howto__step-title"' . $title_style . '>'
                . (string) ($step['title'] ?? '') . '</div>';

            if ('' !== ($step['imageUrl'] ?? '')) {
                $html .= '<img class="thinkrank-howto__step-image"'
                    . ' src="' . self::escape_attribute((string) $step['imageUrl']) . '"'
                    . ' alt="' . self::escape_attribute((string) ($step['imageAlt'] ?? '')) . '"/>';
            }

            $html .= '<div class="thinkrank-howto__step-text"' . $text_style . '>'
                . (string) ($step['text'] ?? '') . '</div>';
            $html .= '</li>';
        }

        return $html . '</' . $list_tag . '></div>';
    }

    /**
     * PHP port of the HowTo block's formatTotalTime() helper.
     *
     * @param array<string,mixed> $attrs ThinkRank HowTo attributes.
     * @return string
     */
    public static function format_total_time(array $attrs): string {
        $parts = [];

        $days = (int) ($attrs['totalDays'] ?? 0);
        if ($days > 0) {
            $parts[] = 1 === $days ? '1 day' : "{$days} days";
        }

        $hours = (int) ($attrs['totalHours'] ?? 0);
        if ($hours > 0) {
            $parts[] = 1 === $hours ? '1 hour' : "{$hours} hours";
        }

        $minutes = (int) ($attrs['totalMinutes'] ?? 0);
        if ($minutes > 0) {
            $parts[] = 1 === $minutes ? '1 minute' : "{$minutes} minutes";
        }

        return implode(', ', $parts);
    }

    /**
     * Serialize an inline style object the way @wordpress/element does.
     *
     * Null values are skipped (they are the `undefined` the style helpers
     * return for unset colours), and when nothing survives the whole attribute
     * is omitted rather than rendered empty.
     *
     * @param array<string,string|null> $declarations Property => value.
     * @return string Leading-space attribute, or '' when there is nothing to set.
     */
    private static function style(array $declarations): string {
        $parts = [];
        foreach ($declarations as $property => $value) {
            if (null === $value) {
                continue;
            }
            $parts[] = $property . ':' . $value;
        }

        if (empty($parts)) {
            return '';
        }

        return ' style="' . self::escape_attribute(implode(';', $parts)) . '"';
    }

    /**
     * Port of @wordpress/escape-html's escapeAttribute().
     *
     * Escapes the quotation mark, and only those ampersands that do not already
     * start a character reference — so `&amp;` stays `&amp;` rather than
     * becoming `&amp;amp;` and doubling on every pass.
     *
     * @param string $value Attribute value.
     * @return string
     */
    private static function escape_attribute(string $value): string {
        $value = (string) preg_replace(
            '/&(?!([a-zA-Z0-9]+|#[0-9]+|#x[a-fA-F0-9]+);)/',
            '&amp;',
            $value
        );

        return str_replace('"', '&quot;', $value);
    }

    /**
     * Port of @wordpress/escape-html's escapeHTML() for text nodes.
     *
     * @param string $value Text value.
     * @return string
     */
    private static function escape_html(string $value): string {
        $value = self::escape_attribute($value);

        return str_replace(['<', '>'], ['&lt;', '&gt;'], $value);
    }
}
