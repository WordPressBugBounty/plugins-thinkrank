<?php
/**
 * Persisted word count per post.
 *
 * @package ThinkRank\SEO
 * @since 2.10.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Core\Seo_Text;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Word Count Index
 *
 * "Which of my pages are thin?" is a question about every published page, and
 * answering it honestly means counting the words a visitor actually reads —
 * which on an Elementor, Divi, Oxygen, Beaver or Bricks page are not in
 * `post_content` at all (#565).
 *
 * That makes the count expensive in a way the snippet index's is not.
 * {@see Builder_Content::resolve()} walks a builder's stored tree, so it cannot
 * run over a whole site inside one request. The answer is the same shape as
 * {@see Snippet_Index}: count once, store the number in post meta, and answer
 * every report by SQL over the stored numbers. Counting happens a bounded batch
 * at a time and the caller is told how many are left.
 *
 * Deliberately a **separate** index rather than a fourth field on the snippet
 * index, because the two are invalidated by different things. A word count
 * changes when the body changes; a snippet verdict changes when a title
 * template, the site name or the separator changes. Sharing one entry would
 * mean every edit to an SEO template re-resolved every builder page on the
 * site, which is the one cost this class exists to avoid.
 *
 * The stored value is `{version}:{unit}:{count}`. There is no generation:
 * almost nothing global changes a post's word count, so a post's own edits are
 * what make its entry stale. The version exists so that changing how counting
 * works retires every entry with one constant.
 *
 * The two global things that do change a count are handled explicitly. The
 * **unit** is written into the entry, because a locale switch between words
 * and characters changes every number at once, and an entry is only current
 * while its unit is the one the site counts in now. **Content a post pulls in
 * by reference** (a synced pattern, a navigation menu) is expanded by
 * `do_blocks()` before counting, so editing it changes the count of every post
 * that references it; {@see on_referenced_change()} retires those entries.
 *
 * @since 2.10.0
 */
class Word_Count_Index {

    /**
     * Post meta holding the entry.
     */
    public const META_KEY = '_thinkrank_word_count';

    /**
     * Counting rules version. Bump to retire every stored entry.
     *
     * Version 3 stopped counting shortcode syntax as words and stopped merging
     * two words that only a tag separated (#893), so every version 2 entry is
     * recounted once.
     *
     * Version 4 stopped counting tokens with no letter or digit in them. The
     * tag-to-space change in version 3 left the punctuation after an inline
     * tag ("<a>link</a>.") standing alone, where it was counted as a word, so
     * every version 3 entry is recounted once.
     */
    public const VERSION = 4;

    /**
     * Short codes for the counting unit, as stored in an entry.
     *
     * Version 1 entries carried no unit, so a site that switched between a
     * words locale and a characters one kept its old numbers as "current" and
     * the report printed word counts as character counts. Version 2 is the
     * first to carry it; every version 1 entry is recounted once.
     *
     * @var array<string,string>
     */
    private const UNIT_CODES = [
        'words'                       => 'w',
        'characters_excluding_spaces' => 'c',
        'characters_including_spaces' => 'cs',
    ];

    /**
     * Post types whose content other posts pull in by `"ref":ID` and that
     * `do_blocks()` expands in place: synced patterns (`core/block`) and
     * navigation menus (`core/navigation`).
     *
     * Builder templates are not here. Elementor global widgets and templates,
     * and Bricks templates, are expanded by the builder at render time and are
     * referenced in shapes that differ per builder; a page using one is
     * recounted on its own next edit, or by Rescan.
     *
     * @var string[]
     */
    private const REFERENCED_POST_TYPES = ['wp_block', 'wp_navigation'];

    /**
     * How deep {@see posts_referencing()} follows a pattern nested inside a
     * pattern. Core refuses to render a pattern inside itself; this bound is
     * for the same reason, so a cycle in stored content cannot loop here.
     */
    private const MAX_REFERENCE_DEPTH = 5;

    /**
     * Posts per `IN (...)` list when retiring entries in bulk.
     */
    private const BULK_CHUNK = 500;

    /**
     * Most entries one refresh call will build, and the time it may spend.
     *
     * Much smaller than the snippet index's 500, and for a real reason:
     * resolving a builder tree is orders of magnitude dearer than reading two
     * meta values, so a batch sized for the cheap case would time out on a
     * site built entirely in Elementor.
     */
    private const REFRESH_MAX_POSTS   = 50;
    private const REFRESH_MAX_SECONDS = 3.0;

    /**
     * Memo for {@see watched_meta()}.
     *
     * @var string[]|null
     */
    private static $watched_meta = null;

    /**
     * Memo for {@see unit()} when it has to switch locale to answer: site
     * locale => unit.
     *
     * @var array<string,string>
     */
    private static $site_units = [];

    /**
     * Register invalidation hooks. Runs on every request, because posts are
     * edited everywhere and not only on the report's screen.
     *
     * @return void
     */
    public function init(): void {
        add_action('save_post', [self::class, 'mark_post_stale'], 99, 1);

        add_action('added_post_meta', [self::class, 'on_meta_change'], 10, 3);
        add_action('updated_post_meta', [self::class, 'on_meta_change'], 10, 3);
        add_action('deleted_post_meta', [self::class, 'on_meta_change'], 10, 3);

        // A post *leaving* the report is the case no other hook here covers.
        // Trashing, unpublishing or deleting one removes it from every query
        // this class answers, but it changes no entry, so nothing would tell a
        // report derived from the index that its answer had moved.
        add_action('transition_post_status', [self::class, 'on_status_change'], 10, 3);
        add_action('before_delete_post', [self::class, 'on_post_deleted'], 10, 1);

        // Content other posts pull in by reference. Saving covers trashing and
        // restoring too (both go through wp_update_post()), and a trashed
        // pattern renders nothing, so the posts that use it lose those words.
        foreach (self::REFERENCED_POST_TYPES as $post_type) {
            add_action('save_post_' . $post_type, [self::class, 'on_referenced_change'], 99, 1);
        }
    }

    /**
     * Post meta whose change changes a post's word count.
     *
     * Read from {@see Builder_Content::builder_meta_keys()} rather than listed
     * here, so a builder added to the resolver is invalidated by the same
     * commit that teaches the resolver to read it. Bricks stores its tree
     * outside that list, so it is named explicitly.
     *
     * Memoised because {@see on_meta_change()} is the callback on three hooks
     * that fire for every post meta write anywhere in WordPress — an import
     * writing a dozen fields across a thousand posts rebuilt this list tens of
     * thousands of times to answer the same question. The source is a class
     * constant, so there is nothing for the memo to go stale against.
     *
     * @return string[]
     */
    public static function watched_meta(): array {
        if (null === self::$watched_meta) {
            self::$watched_meta = array_values(array_unique(array_merge(
                Builder_Content::builder_meta_keys(),
                ['_bricks_page_content_2', '_bricks_editor_mode']
            )));
        }

        return self::$watched_meta;
    }

    /**
     * Post meta changed.
     *
     * @param int|array $meta_id   Meta ID(s).
     * @param int       $object_id Post ID.
     * @param string    $meta_key  Meta key.
     * @return void
     */
    public static function on_meta_change($meta_id, $object_id, $meta_key): void {
        if (in_array($meta_key, self::watched_meta(), true)) {
            self::mark_post_stale($object_id);
        }
    }

    /**
     * A post moved between statuses.
     *
     * Only one direction needs announcing: a post *leaving* `publish`. It takes
     * its row out of every query here while its entry sits untouched, so nothing
     * else can tell a report derived from the index that its answer moved.
     *
     * A post *arriving* at `publish` deliberately does not bump. It has no entry
     * yet, so it is pending, and a report is never served from cache while
     * anything is pending — the batch that counts it bumps the revision itself.
     * Bumping here as well would write one option row per post through a bulk
     * import of a thousand published posts, to say something already known.
     *
     * @param string        $new_status Status now.
     * @param string        $old_status Status before.
     * @param \WP_Post|null $post       Post.
     * @return void
     */
    public static function on_status_change($new_status, $old_status, $post = null): void {
        if ($new_status === $old_status || !$post instanceof \WP_Post) {
            return;
        }

        if ('publish' !== $old_status) {
            return;
        }

        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return;
        }

        self::bump_revision();
    }

    /**
     * A post is about to be deleted for good.
     *
     * Hooked before the delete rather than after it, so the entry is still
     * readable: only a post that had been counted can change an answer, and
     * revisions never have an entry, which keeps revision cleanup out of this.
     *
     * @param int|mixed $post_id Post ID.
     * @return void
     */
    public static function on_post_deleted($post_id): void {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        // A synced pattern or menu being deleted for good takes its words out
        // of every post that referenced it. It never has an entry of its own.
        if (in_array(get_post_type($post_id), self::REFERENCED_POST_TYPES, true)) {
            self::on_referenced_change($post_id);
            return;
        }

        if (null !== self::decode((string) get_post_meta($post_id, self::META_KEY, true))) {
            self::bump_revision();
        }
    }

    /**
     * A synced pattern or navigation menu changed.
     *
     * Counting renders a post through `do_blocks()`, which expands
     * `<!-- wp:block {"ref":12} /-->` into pattern 12's content. Nothing about
     * the referencing post changes when pattern 12 is edited, so without this
     * every page using a 400-word pattern kept its 400-word count after the
     * pattern was cut to one line, and was never reported as thin.
     *
     * Found by searching `post_content`, which is a scan of the posts table.
     * That is acceptable here and nowhere hotter: it runs when someone saves a
     * pattern or a menu, which is rare, and it replaces recounting the site.
     *
     * @param int|mixed $post_id Pattern or menu ID.
     * @return void
     */
    public static function on_referenced_change($post_id): void {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        self::forget(self::posts_referencing($post_id));
    }

    /**
     * Counted posts whose content references a post by `"ref":ID`, directly or
     * through patterns nested inside patterns.
     *
     * @param int $post_id Referenced post.
     * @return int[] IDs of posts that hold an entry.
     */
    private static function posts_referencing(int $post_id): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the interpolated fragment is built by reference_sql() through prepare(); every value is a placeholder. Runs on a pattern save, never on a front-end request.
        global $wpdb;

        $seen     = [$post_id => true];
        $frontier = [$post_id];
        $counted  = [];

        for ($depth = 0; !empty($frontier) && $depth < self::MAX_REFERENCE_DEPTH; $depth++) {
            $match = self::reference_sql($frontier);

            // Posts with an entry that use anything in the frontier.
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
                 WHERE " . $match,
                self::META_KEY
            ));
            foreach ((array) $ids as $id) {
                $counted[(int) $id] = true;
            }

            // Patterns that nest anything in the frontier: every post using
            // *them* renders the edited content too.
            $nested = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 WHERE p.post_type = %s AND " . $match,
                'wp_block'
            ));

            $frontier = [];
            foreach ((array) $nested as $id) {
                $id = (int) $id;
                if ($id > 0 && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $frontier[] = $id;
                }
            }
        }

        return array_keys($counted);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * WHERE fragment matching `post_content` that references any of these IDs.
     *
     * Core serialises block attributes as compact JSON, so a reference to 12
     * reads `"ref":12` followed by `}` or `,`. Matching the terminator is what
     * keeps an edit to pattern 12 from recounting every page that uses 120.
     *
     * @param int[] $ids Referenced post IDs.
     * @return string Trusted SQL.
     */
    private static function reference_sql(array $ids): string {
        global $wpdb;

        $likes = [];
        foreach ($ids as $id) {
            foreach (['}', ','] as $terminator) {
                $likes[] = $wpdb->prepare(
                    'p.post_content LIKE %s',
                    '%' . $wpdb->esc_like('"ref":' . (int) $id . $terminator) . '%'
                );
            }
        }

        return '(' . implode(' OR ', $likes) . ')';
    }

    /**
     * Retire the entries of many posts in one statement per chunk.
     *
     * `delete_post_meta()` per post would be one query and one set of hooks
     * each, for a pattern that can sit on every page of the site. The meta
     * cache is cleared per post so a persistent object cache does not keep
     * serving the entry that was just removed.
     *
     * @param int[] $post_ids Post IDs.
     * @return void
     */
    private static function forget(array $post_ids): void {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the IN list is a run of %d built from the chunk size, so the sniff cannot see the placeholders it looks for; every value is still passed to prepare(). The per-post meta cache is cleared below.
        global $wpdb;

        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (empty($post_ids)) {
            return;
        }

        foreach (array_chunk($post_ids, self::BULK_CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$in})",
                array_merge([self::META_KEY], $chunk)
            ));

            foreach ($chunk as $id) {
                wp_cache_delete($id, 'post_meta');
            }
        }

        self::bump_revision();
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Make one post's entry stale.
     *
     * The revision is bumped only when an entry was really removed. A post that
     * had none cannot have contributed to any answer, which keeps a bulk import
     * of fresh posts from writing one option row per post — and it means a
     * change that takes a counted post out of scope without changing its status
     * (adding a password, most of all) still retires the cached report.
     *
     * @param int|mixed $post_id Post ID.
     * @return void
     */
    public static function mark_post_stale($post_id): void {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (delete_post_meta($post_id, self::META_KEY)) {
            self::bump_revision();
        }
    }

    /**
     * How many times a batch of entries has been rebuilt.
     *
     * A report derived from the whole index needs to know that *some* entry
     * changed, which no single post's meta can tell it. Bumped once per batch
     * rather than once per entry.
     *
     * @return int
     */
    public static function revision(): int {
        return (int) get_option('thinkrank_word_count_index_revision', 0);
    }

    /**
     * Record that entries changed. Not autoloaded: only the report reads it,
     * and never on a front-end request.
     *
     * @return void
     */
    private static function bump_revision(): void {
        update_option('thinkrank_word_count_index_revision', self::revision() + 1, false);
    }

    /**
     * Encode an entry.
     *
     * @param int         $count Words (or characters, per the unit).
     * @param string|null $unit  Unit the count is in. Defaults to the site's.
     * @return string
     */
    public static function encode(int $count, ?string $unit = null): string {
        return self::prefix($unit ?? self::unit()) . max(0, $count);
    }

    /**
     * Decode an entry.
     *
     * An entry counted in another unit is as stale as one from another
     * version: 40 words and 40 characters are not the same page.
     *
     * @param string $value Stored value.
     * @return int|null Count, or null when malformed, from an older version or
     *                  in a unit the site no longer counts in.
     */
    public static function decode(string $value): ?int {
        $prefix = self::prefix(self::unit());
        if (0 !== strpos($value, $prefix)) {
            return null;
        }

        $count = substr($value, strlen($prefix));

        return '' !== $count && ctype_digit($count) ? (int) $count : null;
    }

    /**
     * The part of an entry before the count: version and unit.
     *
     * @param string $unit Counting unit.
     * @return string
     */
    private static function prefix(string $unit): string {
        $code = self::UNIT_CODES[$unit] ?? self::UNIT_CODES['characters_excluding_spaces'];

        return self::VERSION . ':' . $code . ':';
    }

    /**
     * LIKE pattern matching an entry written by the current rules, in the
     * unit the site counts in now.
     *
     * @return string
     */
    private static function current_like(): string {
        global $wpdb;

        return $wpdb->esc_like(self::prefix(self::unit())) . '%';
    }

    /**
     * Count the content of a post the way the locale counts it.
     *
     * Two things here are easy to get wrong and invisible in English.
     *
     * **What to count.** `post_content` is empty on a builder page, so counting
     * it reports a 900-word Elementor page as 0 and calls it thin. Every count
     * goes through {@see Builder_Content::resolve()}, which is the same
     * resolution the editor score and the meta description already use.
     *
     * **What a "word" is.** WordPress reads the unit from a per-locale gettext
     * string, and `th`, `ja` and `zh_*` set it to characters rather than words
     * (#687). Splitting those on whitespace returns 1 for an entire article, so
     * a Japanese site would report every page as thin. This mirrors core's own
     * counter: words where the locale counts words, characters otherwise.
     *
     * @param \WP_Post $post Post to count.
     * @return int Count in {@see self::unit()}.
     */
    public static function count_post(\WP_Post $post): int {
        return self::count_text(Builder_Content::resolve($post));
    }

    /**
     * Count a string the way the locale counts it.
     *
     * @param string $content HTML or text.
     * @return int
     */
    public static function count_text(string $content): int {
        $text = self::reading_text($content);

        if ('' === $text) {
            return 0;
        }

        switch (self::unit()) {
            case 'characters_including_spaces':
                return mb_strlen($text);

            case 'characters_excluding_spaces':
                return mb_strlen(str_replace(' ', '', $text));

            default:
                return self::count_words($text);
        }
    }

    /**
     * Count the words in a line of reading text.
     *
     * A word is a whitespace-separated token holding at least one letter or
     * digit, in any script. A token of punctuation or symbols alone is not
     * one: reading_text() turns every tag into a space, so the full stop in
     * "<a>link</a>." and the "৳" in WooCommerce's
     * "<span>৳</span>100" stand on their own, and a dash set between spaces
     * ("one — two") does the same in plain prose. Core's JS word counter drops
     * punctuation too. Letters and digits are matched by Unicode property, so
     * Bengali, Arabic or Cyrillic words still count; a combining mark is part
     * of the letter before it, so a Bengali word keeps its vowel signs.
     *
     * Only the words unit uses this. The character units count every
     * character, as core does, which is how CJK locales are counted.
     *
     * @since 2.14.2
     *
     * @param string $text Text as returned by {@see self::reading_text()}.
     * @return int
     */
    public static function count_words(string $text): int {
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($tokens)) {
            return 0;
        }

        return count(preg_grep('/[\p{L}\p{N}]/u', $tokens) ?: []);
    }

    /**
     * The text a reader reads in a piece of stored content, as one line.
     *
     * Everything that is markup rather than reading matter is removed:
     *
     * - **Scripts and styles.** A page builder's output can hold a great deal
     *   of both. Counting them would make an empty page look substantial,
     *   which is the failure that matters here.
     * - **Shortcode syntax** (#893). `[vc_column width="1/2"]` is not three
     *   words, and a WPBakery or Divi classic page is mostly made of it, so
     *   counting it reported a 216-word page as 325 and called it not thin.
     *   The shortcodes are stripped, not rendered: `do_shortcode()` would
     *   count what they output more accurately, but it means executing every
     *   shortcode on the site inside a batch count, with whatever side effects
     *   each one has (#860, #864). A shortcode that renders real prose is
     *   undercounted, which is the safe direction for a thin content report.
     *   Anything shortcode-shaped is stripped, registered or not, because a
     *   builder's shortcodes are often not registered when the count runs.
     *   Bracketed prose that looks like one ("see [note 4]") goes with it;
     *   "[1]" and "[...]" do not, since a shortcode name starts with a letter.
     * - **Tags**, replaced with a space rather than deleted, the way core's
     *   own word counter does, so `<p>five</p><p>six</p>` stays two words.
     *
     * @since 2.14.2
     *
     * @param string $content HTML or text.
     * @return string Plain text with whitespace collapsed to single spaces.
     */
    public static function reading_text(string $content): string {
        // A `/u` pattern answers null on bytes that are not valid UTF-8, and
        // the string casts below turned that into "": one Latin-1 byte from an
        // old import made the whole page read as empty, a word count of 0 in
        // both this report and the SEO score. Replace the bad bytes instead.
        if ('' !== $content && 1 !== preg_match('//u', $content) && function_exists('mb_scrub')) {
            $content = mb_scrub($content, 'UTF-8');
        }

        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $content);

        // Before tags: an attribute value may hold a ">", which would end a
        // tag match early. WordPress does not allow "[" or "]" inside a
        // shortcode's attributes, so neither is crossed; that also keeps a
        // stray "[" in prose from swallowing the text after it. The optional
        // outer brackets take the escaped form "[[name]]" whole, rather than
        // leaving two stray brackets to be counted as words.
        $text = (string) preg_replace('/\[?\[\/?[A-Za-z][\w-]*[^\[\]]*\]\]?/u', ' ', $text);

        $text = (string) preg_replace('/<!--.*?-->/s', ' ', $text);
        $text = (string) preg_replace('#</?[A-Za-z][^>]*>#', ' ', $text);
        // Backstop for anything malformed the patterns above did not take.
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Non-breaking spaces are spaces to a reader.
        $text = str_replace(["\xc2\xa0", "\xe2\x80\x8b"], ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The unit the site counts in.
     *
     * The **site** locale, not the viewer's. The report is one answer shared by
     * every user, but a REST request from wp-admin loads the translations of
     * the requesting user's profile language (`_locale=user`), which is where
     * core reads the unit from. Asking the loaded locale meant an administrator
     * whose profile is Japanese counted a batch in characters, a colleague in
     * English counted the next batch in words, and the index held both.
     *
     * Switching locale loads core's translations, so the answer is memoised
     * per site locale for the rest of the request. When the loaded locale is
     * already the site's, nothing is switched and nothing is memoised.
     *
     * @return string 'words', 'characters_excluding_spaces' or 'characters_including_spaces'.
     */
    public static function unit(): string {
        $site = (string) get_locale();

        // The switcher is created during setup_theme; a count asked for before
        // then (a save during plugins_loaded) cannot switch, and uses what is
        // loaded rather than fataling.
        if (!function_exists('determine_locale')
            || !function_exists('switch_to_locale')
            || empty($GLOBALS['wp_locale_switcher'])
            || determine_locale() === $site
        ) {
            return self::loaded_locale_unit();
        }

        if (!isset(self::$site_units[$site])) {
            $switched = switch_to_locale($site);
            try {
                self::$site_units[$site] = self::loaded_locale_unit();
            } finally {
                if ($switched) {
                    restore_previous_locale();
                }
            }
        }

        return self::$site_units[$site];
    }

    /**
     * The unit of whichever locale is loaded right now.
     *
     * @return string
     */
    private static function loaded_locale_unit(): string {
        if (Seo_Text::locale_counts_words()) {
            return 'words';
        }

        if (function_exists('wp_get_word_count_type')) {
            $type = (string) wp_get_word_count_type();

            return 'characters_including_spaces' === $type
                ? 'characters_including_spaces'
                : 'characters_excluding_spaces';
        }

        return 'characters_excluding_spaces';
    }

    /**
     * Build a bounded batch of missing entries.
     *
     * @param string[] $post_types Post types in scope.
     * @param string[] $statuses   Post statuses.
     * @return int How many entries remain to build after this batch.
     */
    public static function refresh(array $post_types, array $statuses): int {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the interpolated fragment is built by scope_sql() through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        if (empty($post_types)) {
            return 0;
        }

        $scope   = self::scope_sql($post_types, $statuses);
        $started = microtime(true);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . $scope . "
               AND (m.meta_value IS NULL OR m.meta_value NOT LIKE %s)
             ORDER BY p.ID DESC
             LIMIT %d",
            self::META_KEY,
            self::current_like(),
            self::REFRESH_MAX_POSTS
        ));

        $ids = array_map('intval', (array) $ids);

        if (!empty($ids)) {
            _prime_post_caches($ids, false, true);

            foreach ($ids as $post_id) {
                self::build($post_id);

                // Checked per post, not per chunk: one Bricks page can take
                // longer than the whole budget, and a batch that only checks
                // between chunks would sail past it.
                if (microtime(true) - $started > self::REFRESH_MAX_SECONDS) {
                    break;
                }
            }

            self::bump_revision();
        }

        return max(0, self::pending($post_types, $statuses));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Compute and store one entry.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    private static function build(int $post_id): void {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return;
        }

        update_post_meta($post_id, self::META_KEY, self::encode(self::count_post($post)));
    }

    /**
     * How many posts in scope still have no current entry.
     *
     * @param string[] $post_types Post types.
     * @param string[] $statuses   Post statuses.
     * @return int
     */
    public static function pending(array $post_types, array $statuses): int {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the interpolated fragment is built by scope_sql() through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        if (empty($post_types)) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . self::scope_sql($post_types, $statuses) . "
               AND (m.meta_value IS NULL OR m.meta_value NOT LIKE %s)",
            self::META_KEY,
            self::current_like()
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Counted posts per post type, and how many of them fall under that post
     * type's threshold.
     *
     * One query for every post type rather than one each, and the comparison
     * happens in SQL so a site with 20,000 products never loads them.
     *
     * @param array<string,int> $thresholds Post type => threshold.
     * @param string[]          $statuses   Post statuses.
     * @return array<string,array{counted:int, thin:int}>
     */
    public static function totals(array $thresholds, array $statuses): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the interpolated fragment is built by scope_sql() through prepare(); the CASE compares a column against integers cast from the threshold map. The index is itself the cache.
        global $wpdb;

        if (empty($thresholds)) {
            return [];
        }

        $count_sql = self::count_sql('m');

        // One CASE arm per post type, each prepared on its own and
        // concatenated rather than left as placeholders in the outer query.
        //
        // The order matters and is easy to get wrong: these placeholders sit
        // in the SELECT clause, *before* the ones in the JOIN and WHERE, so a
        // single prepare() over the whole statement binds them in that order.
        // Getting it wrong does not error — it silently hands `meta_key` a
        // post type name, the INNER JOIN matches nothing and the report reads
        // "0 posts counted" on a site full of content. Preparing each fragment
        // where it is built removes the ordering question entirely.
        $arms = [];
        foreach ($thresholds as $post_type => $threshold) {
            $arms[] = $wpdb->prepare(
                'WHEN p.post_type = %s THEN %d',
                (string) $post_type,
                max(0, (int) $threshold)
            );
        }
        $threshold_sql = 'CASE ' . implode(' ', $arms) . ' ELSE 0 END';

        $scope = self::scope_sql(array_keys($thresholds), $statuses);
        $meta_key_sql = $wpdb->prepare('m.meta_key = %s', self::META_KEY);
        $current_sql = $wpdb->prepare('m.meta_value LIKE %s', self::current_like());

        $rows = $wpdb->get_results(
            "SELECT p.post_type,
                    COUNT(*) AS counted,
                    SUM({$count_sql} < {$threshold_sql}) AS thin
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND {$meta_key_sql}
             WHERE {$scope} AND {$current_sql}
             GROUP BY p.post_type",
            ARRAY_A
        );

        $totals = [];
        foreach ((array) $rows as $row) {
            $totals[(string) $row['post_type']] = [
                'counted' => (int) $row['counted'],
                'thin'    => (int) $row['thin'],
            ];
        }

        return $totals;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * The thinnest posts of one post type, under its threshold.
     *
     * Thinnest first: the emptiest page is the one worth opening, and on a site
     * with hundreds of thin pages the tail is noise.
     *
     * @param string   $post_type Post type.
     * @param int      $threshold Count below which a post is thin.
     * @param string[] $statuses  Post statuses.
     * @param int      $limit     Most posts to return.
     * @return array<int,array{post_id:int, count:int}>
     */
    public static function thinnest(string $post_type, int $threshold, array $statuses, int $limit): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the interpolated fragment is built by scope_sql() through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        $count_sql = self::count_sql('m');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, {$count_sql} AS word_count
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . self::scope_sql([$post_type], $statuses) . "
               AND m.meta_value LIKE %s
               AND {$count_sql} < %d
             ORDER BY word_count ASC, p.ID DESC
             LIMIT %d",
            self::META_KEY,
            self::current_like(),
            max(0, $threshold),
            max(1, $limit)
        ), ARRAY_A);

        $posts = [];
        foreach ((array) $rows as $row) {
            $posts[] = [
                'post_id' => (int) $row['ID'],
                'count'   => (int) $row['word_count'],
            ];
        }

        return $posts;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * SQL reading the count out of a stored entry.
     *
     * @param string $alias Postmeta table alias.
     * @return string Trusted SQL.
     */
    private static function count_sql(string $alias): string {
        $alias = self::alias($alias);

        return "CAST(SUBSTRING_INDEX({$alias}.meta_value, ':', -1) AS UNSIGNED)";
    }

    /**
     * WHERE fragment for post types and statuses.
     *
     * @param string[] $post_types Post types.
     * @param string[] $statuses   Post statuses.
     * @return string Trusted SQL.
     */
    private static function scope_sql(array $post_types, array $statuses): string {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- both IN lists are runs of %s built from the argument counts, so the sniff cannot see the placeholders it looks for; every value is still passed to prepare().
        global $wpdb;

        $post_types = array_values(array_unique(array_filter($post_types, 'is_string')));
        if (empty($post_types)) {
            // No post type matches nothing. Falling back to every post type
            // would silently widen a scope the caller meant to narrow.
            return '1 = 0';
        }

        $statuses = array_values(array_intersect($statuses, ['publish', 'future', 'draft', 'pending', 'private']));
        if (empty($statuses)) {
            $statuses = ['publish'];
        }

        $types_in    = implode(',', array_fill(0, count($post_types), '%s'));
        $statuses_in = implode(',', array_fill(0, count($statuses), '%s'));

        return $wpdb->prepare(
            "p.post_type IN ({$types_in}) AND p.post_status IN ({$statuses_in}) AND p.post_password = ''",
            array_merge($post_types, $statuses)
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * A table alias this class uses, and nothing else.
     *
     * Aliases are interpolated into SQL (identifiers cannot be placeholders),
     * so only the fixed set this class writes is accepted.
     *
     * @param string $alias Requested alias.
     * @return string
     */
    private static function alias(string $alias): string {
        return in_array($alias, ['p', 'm'], true) ? $alias : 'm';
    }
}
