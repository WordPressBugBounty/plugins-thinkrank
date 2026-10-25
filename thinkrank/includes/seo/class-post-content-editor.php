<?php
/**
 * Guarded edits to a post's body, for the connector.
 *
 * @package ThinkRank\SEO
 * @since 2.18.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Admin\Importers\Block_Converter;
use ThinkRank\Admin\Metabox_Manager;
use ThinkRank\AI\SEOScoreCalculator;
use ThinkRank\Core\Database;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Plan, confirm and apply one edit to a block editor post's `post_content`.
 *
 * The SEO checks tell an author to deepen thin content, put the focus keyword
 * in a subheading and the first paragraph, and break up long paragraphs. An
 * agent could read the post and draft the fix, but every write it had stopped
 * at SEO meta or a FAQ block, so the fix ended as text for the author to paste.
 * This is the write, with the guards a live page needs:
 *
 * - **Only posts the block editor renders.** A page builder keeps its layout in
 *   postmeta, so `post_content` is stale or ignored there and a write would
 *   change nothing a reader sees, or resurrect superseded markup (#335). The
 *   detection is FAQ_Content's, the same that update-faq refuses with.
 * - **A version, not a hope.** Every edit names the version it was planned
 *   against. Anything saved since, in wp-admin or by another agent, refuses
 *   the edit instead of being overwritten.
 * - **Plan, then confirm.** The first call computes the result and its effect
 *   (words, headings, links, focus keyword placement, projected score) and
 *   writes nothing. It returns a confirmation bound to the site, the user, the
 *   post, the version and the exact result; only that confirmation writes.
 * - **Byte ranges.** Block operations replace, insert or remove one top-level
 *   block by its byte range, so every other block keeps the bytes its author
 *   saved. A parse/serialize round trip of the whole post would normalise
 *   markup nobody asked to change.
 * - **FAQ blocks are update-faq's.** Every `thinkrank/faq` block must survive
 *   byte for byte, in order; a FAQ block is never added, removed or edited here.
 * - **A revision that undoes it.** The content as it was is kept as a revision
 *   before the write, and restore-post-content puts it back while the post
 *   still holds this edit's result.
 *
 * The write goes through wp_update_post() with slashed content, so kses and
 * every other `content_save_pre` filter apply exactly as in the block editor.
 * The plan runs the same filters, so what it reports is what will be stored.
 *
 * - **Agent markup is kses'd, whoever the user is.** The connector runs as the
 *   administrator who granted the credential, who holds `unfiltered_html`, so
 *   core's save filters would let a content:write credential, or an agent
 *   talked into it by text in a post, put a script on a live page without the
 *   plan showing it. The markup an edit adds goes through wp_kses_post() first
 *   ({@see filter_markup()}); blocks the edit leaves alone keep their bytes.
 */
final class Post_Content_Editor {

    /**
     * Seconds a confirmation stays valid.
     */
    public const CONFIRMATION_TTL = 1800;

    /**
     * Largest content one edit may store, in bytes.
     */
    public const MAX_CONTENT_BYTES = 1048576;

    /**
     * Most block operations one edit may carry.
     */
    public const MAX_OPERATIONS = 50;

    /**
     * Most blocks get-post-content lists.
     */
    public const MAX_BLOCKS_LISTED = 500;

    /**
     * Block operations, in the order an agent is most likely to want them.
     */
    public const OPERATIONS = ['replace_block', 'insert_after', 'insert_before', 'remove_block'];

    /**
     * Revision meta marking the revision an edit was made from.
     */
    public const REVISION_META = '_thinkrank_content_edit';

    /**
     * Shape of a confirmation: expiry, before hash, after hash, signature.
     */
    public const TOKEN_PATTERN = '^[0-9]{10}\.[a-f0-9]{64}\.[a-f0-9]{64}\.[a-f0-9]{64}$';

    /**
     * Characters of plain text in a block preview.
     */
    private const PREVIEW_CHARS = 120;

    /**
     * Entries kept in each added/removed list of a plan.
     */
    private const PLAN_LIST_CAP = 50;

    /**
     * Resolve a post ID.
     *
     * @param int $post_id Post ID.
     * @return \WP_Post|\WP_Error
     */
    public static function resolve_post(int $post_id) {
        if ($post_id <= 0) {
            return new \WP_Error(
                'thinkrank_invalid_post_id',
                __('A valid post ID is required. Use list-content-items to find one.', 'thinkrank'),
                ['status' => 400]
            );
        }

        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error(
                'thinkrank_post_not_found',
                __('No post was found for the provided ID.', 'thinkrank'),
                ['status' => 404]
            );
        }

        return $post;
    }

    /**
     * Whether the current user may write this post's body through the connector.
     *
     * One answer for the read (get-post-content's `editable`) and both writes,
     * so the two cannot disagree about a post.
     *
     * @param \WP_Post $post Post.
     * @return true|\WP_Error
     */
    public static function editable(\WP_Post $post) {
        $id = (int) $post->ID;

        if (!current_user_can('edit_post', $id)) {
            return new \WP_Error(
                'thinkrank_cannot_edit_post',
                __('You are not allowed to edit this post.', 'thinkrank'),
                ['status' => 403]
            );
        }

        $status = (string) $post->post_status;

        if ('revision' === $post->post_type || wp_is_post_revision($id) || wp_is_post_autosave($id)) {
            return self::not_editable(__('This is a revision or an autosave, not a post. Edit the post it belongs to.', 'thinkrank'));
        }

        if ('trash' === $status || 'auto-draft' === $status || 'inherit' === $status) {
            return self::not_editable(__('This post is in the trash or was never saved, so its content is not edited here.', 'thinkrank'));
        }

        if ('attachment' === $post->post_type || !Global_SEO_Post_Types::is_allowed((string) $post->post_type) || !post_type_supports((string) $post->post_type, 'editor')) {
            return new \WP_Error(
                'thinkrank_post_type_not_supported',
                __('ThinkRank does not manage this content type, or it has no body to edit. Use list-content-types to see the types it does.', 'thinkrank'),
                ['status' => 422]
            );
        }

        // get-post-content withholds a protected body, so an edit could only be
        // planned blind.
        if ('' !== (string) $post->post_password) {
            return new \WP_Error(
                'thinkrank_post_password_protected',
                __('This post is password protected, so its body is neither read nor edited through the connector.', 'thinkrank'),
                ['status' => 403]
            );
        }

        $builder = FAQ_Content::builder($id);

        if ('' !== $builder) {
            return new \WP_Error(
                'thinkrank_unsupported_builder',
                sprintf(
                    /* translators: %s: page builder key, e.g. elementor. */
                    __('A page builder (%s) renders this post from its own data, so a change to post_content would not reach the page and could bring back markup the builder replaced. Edit the content in the builder.', 'thinkrank'),
                    $builder
                ),
                [
                    'status'  => 422,
                    'builder' => $builder,
                ]
            );
        }

        if (!self::uses_block_editor($post)) {
            return new \WP_Error(
                'thinkrank_unsupported_builder',
                __('This post is edited in the classic editor, which does not read block markup. Block edits would show up there as raw HTML comments. Edit it in wp-admin.', 'thinkrank'),
                [
                    'status'  => 422,
                    'builder' => 'classic-editor',
                ]
            );
        }

        return true;
    }

    /**
     * The version of a post's body: its modified time and content, hashed.
     *
     * The modified time is in it so a save that leaves the body alone (a title
     * change in wp-admin) still tells the agent its read is out of date.
     *
     * @param \WP_Post $post Post.
     * @return string 64 hex characters.
     */
    public static function version(\WP_Post $post): string {
        return hash('sha256', (string) $post->post_modified_gmt . "\n" . (string) $post->post_content);
    }

    /**
     * Hash of a body, for "does the post still hold this exact content".
     *
     * @param string $content Content.
     * @return string 64 hex characters.
     */
    public static function content_hash(string $content): string {
        return hash('sha256', $content);
    }

    /**
     * Top-level segments of a body: each block, and each run of text between
     * blocks that is more than whitespace.
     *
     * Found with core's tokenizer, so the byte offsets are the ones core reads.
     * The whitespace between segments is not a segment; it stays where it is.
     *
     * @param string $content Content.
     * @return array<int, array{start:int, end:int, name:?string, attrs:array<string,mixed>}>|string
     *         Segments in order, or why the content cannot be edited by block.
     */
    public static function segments(string $content) {
        if (!class_exists('WP_Block_Parser')) {
            return __('the block parser is not available.', 'thinkrank');
        }

        $parser           = new \WP_Block_Parser();
        $parser->document = $content;
        $parser->offset   = 0;

        $segments = [];
        $stack    = [];
        $cursor   = 0;
        $open     = 0;
        $attrs    = [];

        while (true) {
            list($type, $name, $token_attrs, $start, $length) = $parser->next_token();

            if ('no-more-tokens' === $type) {
                // next_token() reports a PCRE failure as the end of the
                // document; editing only the part before it would lose the rest.
                if (PREG_NO_ERROR !== preg_last_error()) {
                    return __('the block tokenizer failed on this content.', 'thinkrank');
                }
                break;
            }

            $parser->offset = $start + $length;

            if ('block-closer' === $type) {
                if ([] === $stack || end($stack) !== $name) {
                    return sprintf(
                        /* translators: 1: block name, 2: byte offset. */
                        __('the closing comment for %1$s at byte %2$d has no matching opener.', 'thinkrank'),
                        (string) $name,
                        (int) $start
                    );
                }

                array_pop($stack);

                if ([] === $stack) {
                    $segments[] = [
                        'start' => $open,
                        'end'   => $start + $length,
                        'name'  => (string) $name,
                        'attrs' => $attrs,
                    ];
                    $cursor = $start + $length;
                }
                continue;
            }

            if (!is_array($token_attrs)) {
                return sprintf(
                    /* translators: 1: block name, 2: byte offset. */
                    __('%1$s at byte %2$d has attributes that are not valid JSON.', 'thinkrank'),
                    (string) $name,
                    (int) $start
                );
            }

            if ([] !== $stack) {
                if ('block-opener' === $type) {
                    $stack[] = $name;
                }
                continue;
            }

            self::add_freeform($segments, $content, $cursor, $start);

            if ('void-block' === $type) {
                $segments[] = [
                    'start' => $start,
                    'end'   => $start + $length,
                    'name'  => (string) $name,
                    'attrs' => $token_attrs,
                ];
                $cursor = $start + $length;
                continue;
            }

            $stack[] = $name;
            $open    = $start;
            $attrs   = $token_attrs;
        }

        if ([] !== $stack) {
            return sprintf(
                /* translators: %s: block name. */
                __('%s is opened but never closed.', 'thinkrank'),
                (string) end($stack)
            );
        }

        self::add_freeform($segments, $content, $cursor, strlen($content));

        return $segments;
    }

    /**
     * The block list get-post-content reports.
     *
     * @param string $content Content.
     * @return array{blocks: array<int, array<string, mixed>>, total: int}|string
     */
    public static function describe_blocks(string $content) {
        $segments = self::segments($content);

        if (is_string($segments)) {
            return $segments;
        }

        $faq    = self::faq_spans($content);
        $blocks = [];

        foreach (array_slice($segments, 0, self::MAX_BLOCKS_LISTED) as $index => $segment) {
            $markup = substr($content, $segment['start'], $segment['end'] - $segment['start']);
            $text   = self::plain($markup);
            $entry  = [
                'index'     => $index,
                'name'      => null === $segment['name'] ? 'core/freeform' : $segment['name'],
                'preview'   => self::truncate($text),
                'protected' => self::overlaps($segment, $faq),
            ];

            if ('core/heading' === $segment['name']) {
                $entry['level'] = (int) ($segment['attrs']['level'] ?? 2);
                $entry['text']  = $text;
            }

            $blocks[] = $entry;
        }

        return [
            'blocks' => $blocks,
            'total'  => count($segments),
        ];
    }

    /**
     * The body an edit asks for: a whole new body, or block operations on the
     * current one.
     *
     * @param string               $content Current content.
     * @param array<string, mixed> $input   Ability input.
     * @return string|\WP_Error
     */
    public static function proposed_content(string $content, array $input) {
        $has_content    = array_key_exists('content', $input);
        $has_operations = array_key_exists('operations', $input);

        if ($has_content === $has_operations) {
            return new \WP_Error(
                'thinkrank_invalid_edit',
                __('Send either content (the whole new body) or operations (block edits), not both and not neither.', 'thinkrank'),
                ['status' => 400]
            );
        }

        if ($has_content) {
            return self::checked_markup((string) $input['content'], true);
        }

        return self::apply_operations($content, $input['operations']);
    }

    /**
     * Apply block operations to a body.
     *
     * Every index refers to the block list the agent read, whatever comes
     * before it in the list: operations are not renumbered by each other. A
     * block may be replaced or removed once; any number of blocks may be
     * inserted around it, in the order given.
     *
     * @param string $content    Current content.
     * @param mixed  $operations Operations from the input.
     * @return string|\WP_Error
     */
    public static function apply_operations(string $content, $operations) {
        if (!is_array($operations) || [] === $operations || count($operations) > self::MAX_OPERATIONS) {
            return new \WP_Error(
                'thinkrank_invalid_edit',
                sprintf(
                    /* translators: %d: maximum number of operations. */
                    __('Send between 1 and %d block operations.', 'thinkrank'),
                    self::MAX_OPERATIONS
                ),
                ['status' => 400]
            );
        }

        $segments = self::segments($content);

        if (is_string($segments)) {
            return self::unparsable($segments);
        }

        $count   = count($segments);
        $before  = [];
        $after   = [];
        $replace = [];

        foreach (array_values($operations) as $position => $operation) {
            $operation = (array) $operation;
            $op        = (string) ($operation['op'] ?? '');
            $index     = $operation['index'] ?? null;

            if (!in_array($op, self::OPERATIONS, true)) {
                return new \WP_Error(
                    'thinkrank_invalid_edit',
                    sprintf(
                        /* translators: 1: operation position, 2: accepted operation names. */
                        __('Operation %1$d is not one of: %2$s.', 'thinkrank'),
                        $position,
                        implode(', ', self::OPERATIONS)
                    ),
                    ['status' => 400]
                );
            }

            if (!is_int($index) || $index < 0 || $index >= $count) {
                return new \WP_Error(
                    'thinkrank_block_index_out_of_range',
                    sprintf(
                        /* translators: 1: operation position, 2: number of blocks. */
                        __('Operation %1$d names a block index outside the post, which has %2$d blocks (indexes start at 0). Read get-post-content again for the current list.', 'thinkrank'),
                        $position,
                        $count
                    ),
                    [
                        'status' => 400,
                        'blocks' => $count,
                    ]
                );
            }

            if ('remove_block' === $op || 'replace_block' === $op) {
                if (array_key_exists($index, $replace)) {
                    return new \WP_Error(
                        'thinkrank_conflicting_operations',
                        sprintf(
                            /* translators: %d: block index. */
                            __('Block %d is replaced or removed more than once in one edit.', 'thinkrank'),
                            $index
                        ),
                        ['status' => 400]
                    );
                }

                if ('remove_block' === $op) {
                    $replace[$index] = null;
                    continue;
                }
            }

            $markup = self::checked_markup((string) ($operation['markup'] ?? ''), false);

            if (is_wp_error($markup)) {
                return new \WP_Error(
                    $markup->get_error_code(),
                    $markup->get_error_message(),
                    array_merge((array) $markup->get_error_data(), ['operation' => $position])
                );
            }

            if ('replace_block' === $op) {
                $replace[$index] = $markup;
            } elseif ('insert_before' === $op) {
                $before[$index][] = $markup;
            } else {
                $after[$index][] = $markup;
            }
        }

        $out    = '';
        $cursor = 0;

        foreach ($segments as $index => $segment) {
            $out .= substr($content, $cursor, $segment['start'] - $cursor);

            $pieces = $before[$index] ?? [];

            if (!array_key_exists($index, $replace)) {
                $pieces[] = substr($content, $segment['start'], $segment['end'] - $segment['start']);
            } elseif (null !== $replace[$index]) {
                $pieces[] = $replace[$index];
            }

            $out   .= implode("\n\n", array_merge($pieces, $after[$index] ?? []));
            $cursor = $segment['end'];
        }

        return $out . substr($content, $cursor);
    }

    /**
     * Check block markup an agent wrote.
     *
     * The markup must be blocks (text outside a block would land in the post
     * as a classic block) and must come back byte for byte from core's parser
     * and serializer, which is what the block editor saves. Markup that does
     * not is refused with the form that would, so the agent can resend it.
     *
     * @param string $markup Markup.
     * @param bool   $whole  Whether it is a whole body rather than one fragment.
     * @return string|\WP_Error The trimmed markup.
     */
    public static function checked_markup(string $markup, bool $whole) {
        $markup = trim($markup);

        if ('' === $markup) {
            return self::invalid_markup(
                $whole
                    ? __('The new content is empty. A post body is not emptied through the connector.', 'thinkrank')
                    : __('An operation that adds or replaces a block needs its block markup.', 'thinkrank')
            );
        }

        if (strlen($markup) > self::MAX_CONTENT_BYTES) {
            return new \WP_Error(
                'thinkrank_content_too_large',
                __('The markup is larger than one edit may store (1 MB).', 'thinkrank'),
                ['status' => 413]
            );
        }

        $segments = self::segments($markup);

        if (is_string($segments)) {
            return self::invalid_markup(sprintf(
                /* translators: %s: reason. */
                __('The block markup cannot be parsed: %s', 'thinkrank'),
                $segments
            ));
        }

        foreach ($segments as $segment) {
            if (null === $segment['name']) {
                return self::invalid_markup(__('There is text outside a block. Wrap every piece of content in block comments, such as <!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->.', 'thinkrank'));
            }
        }

        $canonical = serialize_blocks(parse_blocks($markup));

        if ($canonical !== $markup) {
            return new \WP_Error(
                'thinkrank_invalid_block_markup',
                __('The block markup is not in the form the block editor saves, so it would change the next time anyone opens the post. Write block attributes as compact JSON with <, >, & and -- escaped (<, >, &, --), or resend canonical_markup, which is your markup in that form.', 'thinkrank'),
                [
                    'status'          => 400,
                    'canonical_markup' => strlen($canonical) <= 20000 ? $canonical : '',
                ]
            );
        }

        return $markup;
    }

    /**
     * Whether the connector may store markup kses would remove.
     *
     * Off by default, whatever the acting user may save in wp-admin: nothing
     * the connector is for (a first paragraph, a subheading, a link, a shorter
     * paragraph) needs a script, an iframe or an event handler, and the plan
     * a person agrees to does not show one. A site that wants the connector
     * to store exactly what its administrator may can say so here.
     *
     * @since 2.18.0
     *
     * @return bool
     */
    public static function unfiltered_html_allowed(): bool {
        /**
         * Filters whether markup an agent sends through update-post-content
         * may keep what wp_kses_post() would strip.
         *
         * @since 2.18.0
         *
         * @param bool $allowed Default false.
         */
        return (bool) apply_filters('thinkrank_content_edit_unfiltered_html', false);
    }

    /**
     * Run kses over the markup an edit adds, leaving the rest byte for byte.
     *
     * A user without `unfiltered_html` gets this from core on every save, over
     * the whole body. The connector's user has it, so this is the same pass,
     * made only over the top-level blocks the edit introduces: a block that
     * also exists, byte for byte, in the current body was saved by its author
     * and is kept as it is, whether the edit left it alone or copied it into a
     * rewrite. {@see stored_form()} then runs the site's own save filters over
     * the result, as before, so the plan still describes what is stored.
     *
     * @since 2.18.0
     *
     * @param string $before Current content.
     * @param string $after  Proposed content.
     * @return string Proposed content with its new blocks filtered.
     */
    public static function filter_markup(string $before, string $after): string {
        if (!function_exists('wp_kses_post') || self::unfiltered_html_allowed()) {
            return $after;
        }

        $segments = self::segments($after);

        if (is_string($segments)) {
            // Not block markup the tokenizer can split; checked_markup() has
            // refused it already. Filter the whole thing rather than nothing.
            return (string) wp_kses_post($after);
        }

        $out    = '';
        $cursor = 0;

        foreach ($segments as $segment) {
            $bytes  = substr($after, $segment['start'], $segment['end'] - $segment['start']);
            $out   .= substr($after, $cursor, $segment['start'] - $cursor);
            $out   .= false === strpos($before, $bytes) ? (string) wp_kses_post($bytes) : $bytes;
            $cursor = $segment['end'];
        }

        return $out . substr($after, $cursor);
    }

    /**
     * Elements styled so a reader cannot see them.
     *
     * display:none, visibility:hidden, a zero font size or opacity, text pushed
     * off screen, and the `hidden` attribute. kses allows every one of them,
     * and text a visitor cannot see is what search engines penalise as hidden
     * text, so the plan counts them before and after for the person to see.
     *
     * @since 2.18.0
     *
     * @param string $markup Markup.
     * @return int
     */
    public static function hidden_text_count(string $markup): int {
        $count = 0;

        if (preg_match_all('/<[a-z][^>]*?\sstyle\s*=\s*(["\'])(.*?)\1/is', $markup, $matches)) {
            foreach ($matches[2] as $style) {
                $style = html_entity_decode((string) $style, ENT_QUOTES);

                if (preg_match('/display\s*:\s*none|visibility\s*:\s*hidden|font-size\s*:\s*0(?:[a-z%]+)?\s*(?:;|!|$)|opacity\s*:\s*0(?:\.0+)?\s*(?:;|!|$)|text-indent\s*:\s*-|(?:left|top|right|bottom)\s*:\s*-\d{3}/i', $style)) {
                    ++$count;
                }
            }
        }

        $count += (int) preg_match_all('/<[a-z][^>]*?\shidden(?:\s*=\s*(["\'])[^"\']*\1)?(?=[\s\/>])/is', $markup);

        return $count;
    }

    /**
     * Refuse a change to any ThinkRank FAQ block.
     *
     * @param string $before Current content.
     * @param string $after  Proposed content.
     * @return true|\WP_Error
     */
    public static function faq_unchanged(string $before, string $after) {
        $old = self::faq_markup($before);
        $new = self::faq_markup($after);

        if (is_string($old)) {
            return self::unparsable($old);
        }

        if (is_string($new)) {
            return self::invalid_markup($new);
        }

        if ($old !== $new) {
            return new \WP_Error(
                'thinkrank_faq_block_protected',
                __('This edit adds, removes or changes a ThinkRank FAQ block. FAQ blocks are written only by update-faq, so their questions and their FAQPage schema stay in step. Keep every FAQ block exactly as it is, and use update-faq for the questions.', 'thinkrank'),
                [
                    'status'      => 422,
                    'faq_blocks'  => count($old),
                ]
            );
        }

        return true;
    }

    /**
     * The content WordPress will store for this markup.
     *
     * wp_insert_post() runs `pre_post_content` and `content_save_pre` over the
     * slashed value, which is where kses strips what a user without
     * `unfiltered_html` may not save. Running them here makes the plan describe
     * the stored result rather than the request.
     *
     * @param int    $post_id Post ID.
     * @param string $content Proposed content.
     * @return string
     */
    public static function stored_form(int $post_id, string $content): string {
        return (string) wp_unslash(sanitize_post_field('post_content', wp_slash($content), $post_id, 'db'));
    }

    /**
     * A confirmation for one planned change.
     *
     * Bound to the site, the user, the post, the kind of change and the before
     * and after state, and signed with the site's auth salt. It carries the two
     * hashes in the clear so a repeated confirmation can be recognised as one
     * that already landed.
     *
     * @param string $kind    'content' or 'slug'.
     * @param int    $post_id Post ID.
     * @param string $before  Hash of the state planned against.
     * @param string $after   Hash of the state the change produces.
     * @return array{token: string, expires: int}
     */
    public static function confirmation(string $kind, int $post_id, string $before, string $after): array {
        $expires = time() + self::CONFIRMATION_TTL;

        return [
            'token'   => sprintf('%010d.%s.%s.%s', $expires, $before, $after, self::sign($kind, $post_id, $expires, $before, $after)),
            'expires' => $expires,
        ];
    }

    /**
     * Read and authenticate a confirmation.
     *
     * @param string $token   Token.
     * @param string $kind    'content' or 'slug'.
     * @param int    $post_id Post ID.
     * @return array{expires: int, before: string, after: string}|\WP_Error
     */
    public static function read_confirmation(string $token, string $kind, int $post_id) {
        if (1 !== preg_match('/' . self::TOKEN_PATTERN . '/', $token)) {
            return self::bad_confirmation();
        }

        list($expires, $before, $after, $signature) = explode('.', $token);
        $expires = (int) $expires;

        if (!hash_equals(self::sign($kind, $post_id, $expires, $before, $after), $signature)) {
            return self::bad_confirmation();
        }

        if ($expires <= time()) {
            return new \WP_Error(
                'thinkrank_confirmation_expired',
                __('This confirmation expired. Plan the change again and ask for agreement again.', 'thinkrank'),
                ['status' => 409]
            );
        }

        return [
            'expires' => $expires,
            'before'  => $before,
            'after'   => $after,
        ];
    }

    /**
     * Keep the body as it is now in a revision, and mark it as the revision
     * this edit can be undone to.
     *
     * Reuses the newest revision when it already holds this body, which it
     * does after any ordinary save. Refuses when the site would not keep it:
     * a post type without revisions has nothing to undo to, and a site keeping
     * a single revision deletes this one in the very save it is for.
     *
     * @param \WP_Post $post       Post as it is now.
     * @param string   $after_hash Hash of the content the edit stores.
     * @return int|\WP_Error Revision ID.
     */
    public static function keep_revision(\WP_Post $post, string $after_hash) {
        $keep = function_exists('wp_revisions_to_keep') ? (int) wp_revisions_to_keep($post) : -1;

        if (!post_type_supports((string) $post->post_type, 'revisions') || 0 === $keep || 1 === $keep) {
            return new \WP_Error(
                'thinkrank_undo_unavailable',
                __('This site keeps no revisions for this post (or only one), so the edit could not be undone. It was not made. Turn revisions on, or edit the post in wp-admin.', 'thinkrank'),
                ['status' => 422]
            );
        }

        $revision_id = 0;
        $latest      = wp_get_post_revisions((int) $post->ID, ['numberposts' => 5]);

        // The newest revision that is not an autosave. Core lists autosaves
        // with the revisions, and right after a save from the editor the
        // newest one often holds the body just saved; but the next autosave
        // overwrites it in place, and undo_revision() refuses it, so marking
        // it would promise an undo the site cannot keep.
        foreach (is_array($latest) ? $latest : [] as $candidate) {
            if (!$candidate instanceof \WP_Post || wp_is_post_autosave((int) $candidate->ID)) {
                continue;
            }

            if ((string) $candidate->post_content === (string) $post->post_content) {
                $revision_id = (int) $candidate->ID;
            }

            break;
        }

        if (0 === $revision_id) {
            $created     = _wp_put_post_revision($post);
            $revision_id = is_wp_error($created) ? 0 : (int) $created;
        }

        if ($revision_id <= 0) {
            return new \WP_Error(
                'thinkrank_undo_unavailable',
                __('WordPress did not store a revision of the current content, so the edit was not made.', 'thinkrank'),
                ['status' => 500]
            );
        }

        self::mark_revision($revision_id, $after_hash);

        return $revision_id;
    }

    /**
     * Record which stored content a revision is the undo for.
     *
     * Called again after a save when a filter stored something other than
     * the plan, so the undo matches what the post really holds.
     *
     * @param int    $revision_id Revision ID.
     * @param string $after_hash  Hash of the content the edit stored.
     * @return void
     */
    public static function mark_revision(int $revision_id, string $after_hash): void {
        // update_metadata() rather than update_post_meta(), which would write
        // to the parent post: core redirects post meta from a revision.
        update_metadata('post', $revision_id, self::REVISION_META, [
            'after' => $after_hash,
            'user'  => get_current_user_id(),
            'at'    => time(),
        ]);
    }

    /**
     * The revision a given edit was made from, if the site still has it.
     *
     * @param int    $post_id    Post ID.
     * @param string $after_hash Hash of the content the edit stored.
     * @return int Revision ID, or 0.
     */
    public static function revision_for(int $post_id, string $after_hash): int {
        $revisions = wp_get_post_revisions($post_id, ['numberposts' => 20]);

        foreach (is_array($revisions) ? $revisions : [] as $revision) {
            if (!$revision instanceof \WP_Post) {
                continue;
            }

            $mark = get_metadata('post', (int) $revision->ID, self::REVISION_META, true);

            if (is_array($mark) && hash_equals((string) ($mark['after'] ?? ''), $after_hash)) {
                return (int) $revision->ID;
            }
        }

        return 0;
    }

    /**
     * An edit's undo revision, checked.
     *
     * @param int $post_id     Post ID.
     * @param int $revision_id Revision ID.
     * @return array{revision: \WP_Post, after: string}|\WP_Error
     */
    public static function undo_revision(int $post_id, int $revision_id) {
        $revision = $revision_id > 0 ? get_post($revision_id) : null;
        $mark     = $revision instanceof \WP_Post ? get_metadata('post', $revision_id, self::REVISION_META, true) : null;

        if (
            !$revision instanceof \WP_Post
            || 'revision' !== $revision->post_type
            || (int) $revision->post_parent !== $post_id
            || wp_is_post_autosave($revision_id)
            || !is_array($mark)
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/', (string) ($mark['after'] ?? ''))
        ) {
            return new \WP_Error(
                'thinkrank_revision_not_restorable',
                __('That is not a revision update-post-content or restore-post-content made for this post, or the site no longer keeps it. Older states are restored from the Revisions screen in wp-admin.', 'thinkrank'),
                ['status' => 404]
            );
        }

        return [
            'revision' => $revision,
            'after'    => (string) $mark['after'],
        ];
    }

    /**
     * Save a body, and read back what was stored.
     *
     * @param \WP_Post $post    Post.
     * @param string   $content Content.
     * @return \WP_Post|\WP_Error The post as stored.
     */
    public static function save(\WP_Post $post, string $content) {
        $saved = wp_update_post(
            [
                'ID'           => (int) $post->ID,
                // Slashed, because wp_update_post() unslashes: an unslashed
                // write turns the < in block attributes into u003c.
                'post_content' => wp_slash($content),
            ],
            true
        );

        if (is_wp_error($saved) || (int) $saved !== (int) $post->ID) {
            return new \WP_Error(
                'thinkrank_save_failed',
                is_wp_error($saved) ? $saved->get_error_message() : __('WordPress did not save the post.', 'thinkrank'),
                ['status' => 500]
            );
        }

        clean_post_cache((int) $post->ID);
        $fresh = get_post((int) $post->ID);

        return $fresh instanceof \WP_Post ? $fresh : new \WP_Error(
            'thinkrank_save_failed',
            __('The post could not be read back after saving.', 'thinkrank'),
            ['status' => 500]
        );
    }

    /**
     * Drop cached renderings of a post and the analyzer's cached audit.
     *
     * The same purge purge-caches makes for a post. Failures are reported,
     * not raised: the save has already happened.
     *
     * @param int $post_id Post ID.
     * @return string[] Warnings.
     */
    public static function purge(int $post_id): array {
        try {
            if (class_exists(Cache_Purger::class)) {
                Cache_Purger::purge_posts([$post_id]);
            } else {
                clean_post_cache($post_id);
            }

            if (class_exists(SEO_Analyzer::class)) {
                (new SEO_Analyzer())->flush_cache();
            }
        } catch (\Throwable $error) {
            return [__('The change was saved, but not every cache could be cleared. Refresh caches on the site if the page still shows the old content.', 'thinkrank')];
        }

        return [];
    }

    /**
     * What an edit does to the page, for the person agreeing to it.
     *
     * @param \WP_Post $post  Post as it is now.
     * @param string   $after Content as it will be stored.
     * @return array<string, mixed>
     */
    public static function plan(\WP_Post $post, string $after): array {
        $before_blocks = self::segments((string) $post->post_content);
        $after_blocks  = self::segments($after);
        $faq           = self::faq_markup($after);

        $plan = [
            'blocks'   => [
                'before' => is_array($before_blocks) ? count($before_blocks) : 0,
                'after'  => is_array($after_blocks) ? count($after_blocks) : 0,
            ],
            'faq_blocks_kept' => is_array($faq) ? count($faq) : 0,
            'hidden_text'     => [
                'before' => self::hidden_text_count((string) $post->post_content),
                'after'  => self::hidden_text_count($after),
            ],
        ];

        $analysis = self::analyze($post, $after);

        if (null === $analysis) {
            $plan['analysis_unavailable'] = true;

            return $plan;
        }

        list($old, $new, $old_score, $new_score, $keywords) = $analysis;

        $plan['word_count'] = [
            'before' => (int) ($old['word_count'] ?? 0),
            'after'  => (int) ($new['word_count'] ?? 0),
        ];
        $plan['headings']       = self::heading_changes((array) ($old['headings'] ?? []), (array) ($new['headings'] ?? []));
        $plan['links']          = self::link_changes((string) ($old['content'] ?? ''), (string) ($new['content'] ?? ''));
        $plan['focus_keywords'] = self::keyword_changes($old_score, $new_score, $keywords);
        $plan['seo_score']      = [
            'before'       => self::number($old_score['overall_score'] ?? 0),
            'after'        => self::number($new_score['overall_score'] ?? 0),
            'grade_before' => (string) ($old_score['grade'] ?? ''),
            'grade_after'  => (string) ($new_score['grade'] ?? ''),
        ];

        return $plan;
    }

    /**
     * Words a reader reads in the post, counted as get-post-content counts them.
     *
     * @param \WP_Post $post Post.
     * @return int
     */
    public static function word_count(\WP_Post $post): int {
        return Word_Count_Index::count_words(Word_Count_Index::reading_text(Builder_Content::resolve($post)));
    }

    /**
     * The post's SEO score as stored now.
     *
     * @param \WP_Post $post Post.
     * @return array{overall_score: float, grade: string}|null Null when it could not be computed.
     */
    public static function score(\WP_Post $post): ?array {
        try {
            $calculator = new SEOScoreCalculator(new Database());
            $result     = $calculator->calculate_score($calculator->analyze_post_content((int) $post->ID), self::metadata((int) $post->ID));
        } catch (\Throwable $error) {
            return null;
        }

        return [
            'overall_score' => self::number($result['overall_score'] ?? 0),
            'grade'         => (string) ($result['grade'] ?? ''),
        ];
    }

    /**
     * The analysis of the post before and after, with both scores.
     *
     * @param \WP_Post $post  Post.
     * @param string   $after Content after the edit.
     * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: array<string,mixed>, 3: array<string,mixed>, 4: string[]}|null
     */
    private static function analyze(\WP_Post $post, string $after): ?array {
        try {
            $calculator = new SEOScoreCalculator(new Database());
            $metadata   = self::metadata((int) $post->ID);
            $old        = $calculator->analyze_post_content((int) $post->ID);
            $new        = $calculator->analyze_live_content($after, (int) $post->ID);

            return [
                $old,
                $new,
                $calculator->calculate_score($old, $metadata),
                $calculator->calculate_score($new, $metadata),
                Focus_Keywords::get((int) $post->ID),
            ];
        } catch (\Throwable $error) {
            return null;
        }
    }

    /**
     * The SEO fields a score is computed with, as get-post-seo-checks reads them.
     *
     * @param int $post_id Post ID.
     * @return array<string, mixed>
     */
    private static function metadata(int $post_id): array {
        $metadata                = (new Metabox_Manager())->get_post_metadata($post_id);
        $metadata['title']       = Pattern_Resolver::effective_title($post_id);
        $metadata['description'] = Pattern_Resolver::effective_description($post_id);

        return $metadata;
    }

    /**
     * Headings added, removed, and moved to another level.
     *
     * @param array<int, array<string, mixed>> $old_headings Headings before.
     * @param array<int, array<string, mixed>> $new_headings Headings after.
     * @return array{added: array<int, array<string, mixed>>, removed: array<int, array<string, mixed>>, changed: array<int, array<string, mixed>>}
     */
    private static function heading_changes(array $old_headings, array $new_headings): array {
        $normalize = static function (array $headings): array {
            $out = [];
            foreach ($headings as $heading) {
                $text = trim(preg_replace('/\s+/u', ' ', (string) ($heading['text'] ?? '')) ?? '');
                if ('' !== $text) {
                    $out[] = ['level' => (int) ($heading['level'] ?? 0), 'text' => $text];
                }
            }
            return $out;
        };

        $old = $normalize($old_headings);
        $new = $normalize($new_headings);

        // Exact matches first, so a heading that stayed is never reported.
        foreach ($old as $i => $heading) {
            foreach ($new as $j => $candidate) {
                if ($candidate === $heading) {
                    unset($old[$i], $new[$j]);
                    break;
                }
            }
        }

        $changed = [];
        foreach ($old as $i => $heading) {
            foreach ($new as $j => $candidate) {
                if ($candidate['text'] === $heading['text']) {
                    $changed[] = ['text' => $heading['text'], 'from_level' => $heading['level'], 'to_level' => $candidate['level']];
                    unset($old[$i], $new[$j]);
                    break;
                }
            }
        }

        return [
            'added'   => array_slice(array_values($new), 0, self::PLAN_LIST_CAP),
            'removed' => array_slice(array_values($old), 0, self::PLAN_LIST_CAP),
            'changed' => array_slice($changed, 0, self::PLAN_LIST_CAP),
        ];
    }

    /**
     * Links added and removed, each marked internal or external.
     *
     * @param string $old_html Rendered HTML before.
     * @param string $new_html Rendered HTML after.
     * @return array<string, mixed>
     */
    private static function link_changes(string $old_html, string $new_html): array {
        $old_links = self::links($old_html);
        $new_links = self::links($new_html);

        $removed = $old_links;
        $added   = [];

        foreach ($new_links as $url) {
            $at = array_search($url, $removed, true);

            if (false === $at) {
                $added[] = $url;
            } else {
                unset($removed[$at]);
            }
        }

        $describe = function (array $urls): array {
            return array_slice(array_map(function (string $url): array {
                return ['url' => $url, 'internal' => self::is_internal($url)];
            }, array_values($urls)), 0, self::PLAN_LIST_CAP);
        };

        $count = function (array $urls, bool $internal): int {
            return count(array_filter($urls, function (string $url) use ($internal): bool {
                return self::is_internal($url) === $internal;
            }));
        };

        return [
            'added'    => $describe($added),
            'removed'  => $describe($removed),
            'internal' => ['before' => $count($old_links, true), 'after' => $count($new_links, true)],
            'external' => ['before' => $count($old_links, false), 'after' => $count($new_links, false)],
        ];
    }

    /**
     * Whether each focus keyword is in the first paragraph and a subheading,
     * before and after.
     *
     * @param array<string, mixed> $old_score Score before.
     * @param array<string, mixed> $new_score Score after.
     * @param string[]             $keywords  Focus keywords.
     * @return array<int, array<string, mixed>>
     */
    private static function keyword_changes(array $old_score, array $new_score, array $keywords): array {
        $index = static function (array $score): array {
            $out = [];
            foreach ((array) ($score['keywords'] ?? []) as $row) {
                $out[(string) ($row['keyword'] ?? '')] = (array) ($row['placements'] ?? []);
            }
            return $out;
        };

        $before = $index($old_score);
        $after  = $index($new_score);
        $rows   = [];

        foreach ($keywords as $keyword) {
            $keyword = (string) $keyword;
            $row     = ['keyword' => $keyword];

            foreach (['first_paragraph', 'subheading'] as $placement) {
                $row[$placement] = [
                    'before' => !empty($before[$keyword][$placement]['passed']),
                    'after'  => !empty($after[$keyword][$placement]['passed']),
                ];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Every link target in some HTML, in order.
     *
     * @param string $html HTML.
     * @return string[]
     */
    private static function links(string $html): array {
        if (!preg_match_all('/<a\s[^>]*?\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $matches)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($url): string {
            return trim(html_entity_decode((string) $url, ENT_QUOTES));
        }, $matches[2]), static function (string $url): bool {
            return '' !== $url;
        }));
    }

    /**
     * Whether a link points at this site.
     *
     * @param string $url URL.
     * @return bool
     */
    private static function is_internal(string $url): bool {
        if ('#' === $url[0] || '?' === $url[0] || ('/' === $url[0] && 0 !== strpos($url, '//'))) {
            return true;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        $home = wp_parse_url(home_url('/'), PHP_URL_HOST);

        if (!is_string($host) || !is_string($home)) {
            return !preg_match('#^[a-z][a-z0-9+.-]*:#i', $url);
        }

        $strip = static function (string $value): string {
            $value = strtolower($value);
            return 0 === strpos($value, 'www.') ? substr($value, 4) : $value;
        };

        return $strip($host) === $strip($home);
    }

    /**
     * Whether core would open this post in the block editor.
     *
     * The function lives in wp-admin and is not loaded for REST requests, so
     * it is loaded here when the site has it.
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    private static function uses_block_editor(\WP_Post $post): bool {
        if (!function_exists('use_block_editor_for_post') && defined('ABSPATH') && is_readable(ABSPATH . 'wp-admin/includes/post.php')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        return function_exists('use_block_editor_for_post') ? (bool) use_block_editor_for_post($post) : true;
    }

    /**
     * Byte spans of every ThinkRank FAQ block.
     *
     * @param string $content Content.
     * @return array<int, array{start:int, end:int}>
     */
    private static function faq_spans(string $content): array {
        $spans = Block_Converter::find_faq_blocks($content);

        return is_array($spans) ? $spans : [];
    }

    /**
     * The exact markup of every ThinkRank FAQ block, in order.
     *
     * @param string $content Content.
     * @return string[]|string The markup, or why it could not be read.
     */
    private static function faq_markup(string $content) {
        $spans = Block_Converter::find_faq_blocks($content);

        if (is_string($spans)) {
            return $spans;
        }

        return array_map(static function (array $span) use ($content): string {
            return substr($content, $span['start'], $span['end'] - $span['start']);
        }, $spans);
    }

    /**
     * Whether a segment contains any of the given spans.
     *
     * @param array{start:int, end:int}               $segment Segment.
     * @param array<int, array{start:int, end:int}>   $spans   Spans.
     * @return bool
     */
    private static function overlaps(array $segment, array $spans): bool {
        foreach ($spans as $span) {
            if ($span['start'] >= $segment['start'] && $span['end'] <= $segment['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record the text between two blocks, if it is more than whitespace.
     *
     * @param array<int, array<string, mixed>> $segments Segments, appended to.
     * @param string                           $content  Content.
     * @param int                              $from     Start offset.
     * @param int                              $to       End offset.
     * @return void
     */
    private static function add_freeform(array &$segments, string $content, int $from, int $to): void {
        $run = substr($content, $from, $to - $from);

        if ('' === trim($run)) {
            return;
        }

        $lead = strlen($run) - strlen(ltrim($run));
        $tail = strlen($run) - strlen(rtrim($run));

        $segments[] = [
            'start' => $from + $lead,
            'end'   => $to - $tail,
            'name'  => null,
            'attrs' => [],
        ];
    }

    /**
     * Plain, single-spaced text of some markup.
     *
     * @param string $markup Markup.
     * @return string
     */
    private static function plain(string $markup): string {
        // Tags become spaces first: stripping them outright runs a question
        // into its answer ("How long?Ten minutes.").
        $text = wp_strip_all_tags(preg_replace(['/<!--.*?-->/s', '/<[^>]+>/'], ' ', $markup) ?? '');

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES)) ?? '');
    }

    /**
     * Cut text to a preview length.
     *
     * @param string $text Text.
     * @return string
     */
    private static function truncate(string $text): string {
        return mb_strlen($text) > self::PREVIEW_CHARS ? rtrim(mb_substr($text, 0, self::PREVIEW_CHARS - 1)) . '…' : $text;
    }

    /**
     * A score as a number: whole when it is whole, else to one decimal.
     *
     * @param mixed $value Value.
     * @return float
     */
    private static function number($value): float {
        return round((float) $value, 1);
    }

    /**
     * The signature over a confirmation's fields.
     *
     * @param string $kind    Kind of change.
     * @param int    $post_id Post ID.
     * @param int    $expires Expiry timestamp.
     * @param string $before  Before hash.
     * @param string $after   After hash.
     * @return string
     */
    private static function sign(string $kind, int $post_id, int $expires, string $before, string $after): string {
        $payload = implode('|', [
            'thinkrank-content-change-v1',
            $kind,
            home_url('/'),
            (string) get_current_user_id(),
            (string) $post_id,
            (string) $expires,
            $before,
            $after,
        ]);

        return hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    /**
     * A refusal for a post that is not a writable post.
     *
     * @param string $message Message.
     * @return \WP_Error
     */
    private static function not_editable(string $message): \WP_Error {
        return new \WP_Error('thinkrank_post_not_editable', $message, ['status' => 422]);
    }

    /**
     * A refusal for content that cannot be edited by block.
     *
     * @param string $reason Reason.
     * @return \WP_Error
     */
    private static function unparsable(string $reason): \WP_Error {
        return new \WP_Error(
            'thinkrank_unparsable_content',
            sprintf(
                /* translators: %s: reason. */
                __('This post\'s content could not be edited safely: %s Fix the block markup in the editor, or send the whole body as content.', 'thinkrank'),
                $reason
            ),
            ['status' => 422]
        );
    }

    /**
     * A refusal for markup an agent wrote.
     *
     * @param string $message Message.
     * @return \WP_Error
     */
    private static function invalid_markup(string $message): \WP_Error {
        return new \WP_Error('thinkrank_invalid_block_markup', $message, ['status' => 400]);
    }

    /**
     * A refusal for a confirmation that is not this site's, user's or change's.
     *
     * @return \WP_Error
     */
    private static function bad_confirmation(): \WP_Error {
        return new \WP_Error(
            'thinkrank_confirmation_invalid',
            __('This confirmation was not issued for this change, post or user. Plan the change again; nothing was written.', 'thinkrank'),
            ['status' => 400]
        );
    }
}
