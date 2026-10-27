<?php
/**
 * One-click fixes for Site SEO Analyzer findings.
 *
 * Deliberately conservative. A check is only fixable when the remedy is an
 * unambiguous SETTINGS change we can make correctly and the user can undo.
 * Three categories are excluded on principle:
 *
 *  - **Content.** Site title, tagline and meta descriptions are the user's
 *    words. Writing them for them (even with AI) is authorship, not a fix.
 *  - **Destructive.** Permalink structure is the classic example: switching it
 *    is the textbook SEO recommendation AND it 404s every existing URL. That
 *    needs redirects and a human decision, so it stays advisory.
 *  - **Out of reach.** HTTPS, PHP version, object cache and the wp-config
 *    constants (DISALLOW_FILE_EDIT, WP_DEBUG_DISPLAY) are server or file-system
 *    concerns. Pretending to fix them would be worse than explaining them.
 *
 * Everything here is user-initiated: a fix only runs when someone clicks it on
 * a finding the analyzer already explained.
 *
 * @package ThinkRank\SEO
 * @since 1.28.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Config\Schema_Settings_Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies safe, reversible fixes for analyzer checks.
 */
class SEO_Analyzer_Fixer {

    /**
     * Option holding the alt-text bulk-fill pager position.
     *
     * Not autoloaded: it is read only while a fix is running.
     *
     * @since 2.2.0
     * @var string
     */
    private const ALT_FIX_OFFSET_OPTION = 'thinkrank_alt_fix_offset';

    /**
     * The fixes ThinkRank itself ships, keyed by the analyzer's check id,
     * before the `thinkrank_seo_analyzer_fixable` filter.
     *
     * `warning` is shown in the UI before the user commits when a fix has a
     * consequence worth naming.
     *
     * `undo` says whether an existing write ability can put back what the fix
     * changes, so a person knows before agreeing to it. The
     * `thinkrank/fix-seo-check` ability answers with that write and its
     * arguments for exactly the checks marked true. The others cannot be put
     * back that way:
     *
     *  - search_visibility: no ability writes `blog_public`.
     *  - schema: the save runs the schema auto-deploy pass, which deploys the
     *    selected site types and retires deployed ones the selection leaves
     *    out. Writing the previous settings back runs that pass again rather
     *    than reversing it: what the fix deployed stays deployed, and is on
     *    the page whenever the master switch is on; what it retired stays
     *    retired; and with an empty selection the replay deploys every site
     *    type that validates.
     *  - image_alt_text: one batch fills up to 50 images, and alt text is
     *    written back one image per call.
     *  - llms_txt: no ability takes the document back down without also
     *    switching the llms.txt feature off.
     *
     * @since 2.15.0
     *
     * @return array<string, array{label: string, warning: string, undo: bool}>
     */
    public static function built_in(): array {
        return [
            'search_visibility' => [
                'label'   => __('Allow search engines to index this site', 'thinkrank'),
                // Worth stating plainly: this is exactly the switch people use
                // to keep a staging site out of Google.
                'warning' => __('This makes your site visible to search engines. Do not apply it on a staging or private site.', 'thinkrank'),
                'undo'    => false,
            ],
            'xml_sitemap' => [
                'label'   => __('Enable the XML sitemap', 'thinkrank'),
                'warning' => '',
                'undo'    => true,
            ],
            'schema' => [
                'label'   => __('Turn on automatic structured data', 'thinkrank'),
                'warning' => '',
                'undo'    => false,
            ],
            'image_alt_text' => [
                'label'   => __('Fill in missing image alt text', 'thinkrank'),
                'warning' => __('Runs in batches over your media library. With AI alt text enabled this uses your AI provider key.', 'thinkrank'),
                'undo'    => false,
            ],
            'ai_crawler_access' => [
                'label'   => __('Allow AI answer engines to crawl this site', 'thinkrank'),
                // Only the answer engines are unblocked. Saying so matters:
                // people block the training crawlers on purpose, and a fix
                // that quietly reversed that decision would be a betrayal.
                'warning' => __('Sets the answer-engine crawlers (ChatGPT, Claude, Perplexity, Google-Extended) to Allow. Crawlers that only collect training data keep whatever setting you gave them.', 'thinkrank'),
                'undo'    => true,
            ],
            'llms_txt' => [
                'label'   => __('Publish llms.txt', 'thinkrank'),
                'warning' => __('Publishes /llms.txt from your saved LLMs.txt settings. It needs those fields filled in first.', 'thinkrank'),
                'undo'    => false,
            ],
        ];
    }

    /**
     * The fixable checks, keyed by the analyzer's check id.
     *
     * The built-in fixes, after the filter. `undo` is not the filter's to
     * set: only a built-in fix has an undo the ability can build, so every
     * other entry carries false, and a built-in entry a filter rewrote keeps
     * its own answer.
     *
     * @return array<string, array{label: string, warning: string, undo: bool}>
     */
    public static function fixable(): array {
        $built_in = self::built_in();

        /**
         * Filter the checks the analyzer can fix automatically.
         *
         * Pro/add-ons registering their own checks through
         * `thinkrank_seo_analyzer_checks` can register their fixes here.
         *
         * @since 1.28.0
         * @since 2.15.0 Each entry carries `undo`, which the filter cannot change.
         *
         * @param array $fixable Check id => ['label' => string, 'warning' => string, 'undo' => bool].
         */
        $fixable = apply_filters('thinkrank_seo_analyzer_fixable', $built_in);

        if (is_array($fixable)) {
            foreach ($fixable as $check_id => $fix) {
                if (is_array($fix)) {
                    $fixable[$check_id]['undo'] = !empty($built_in[$check_id]['undo']);
                }
            }
        }

        return $fixable;
    }

    /**
     * Whether the search visibility fix may run on this copy of the site.
     *
     * "Discourage search engines" is the switch people use to keep a staging,
     * local or development copy out of Google, and the fix cannot be put
     * back by any ability. Outside production it is refused, so one MCP call
     * or a "fix every failing check" loop cannot make such a copy indexable
     * (#1351). WordPress's own environment type decides; the filter is for a
     * site that knows better, such as a production site wrongly marked.
     *
     * The audit offers Fix only where this holds
     * (SEO_Analyzer::search_block_is_fixable()), so it never offers a fix
     * this refuses.
     *
     * @since 2.18.1
     * @return bool
     */
    public static function search_visibility_fix_allowed(): bool {
        $environment = function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'production';

        /**
         * Filter whether the search visibility fix may run on this site.
         *
         * @since 2.18.1
         *
         * @param bool   $allowed     True only when the environment type is production.
         * @param string $environment wp_get_environment_type().
         */
        return (bool) apply_filters('thinkrank_allow_search_visibility_fix', 'production' === $environment, $environment);
    }

    /**
     * Whether a check can be fixed automatically.
     *
     * @param string $check_id Analyzer check id.
     * @return bool
     */
    public static function can_fix(string $check_id): bool {
        return isset(self::fixable()[$check_id]);
    }

    /**
     * Apply the fix for a check.
     *
     * Exception messages thrown from here and the fix methods are plain text,
     * not HTML. They only travel as JSON, from the REST endpoint to the audit
     * toast and from the fix-seo-check ability to MCP clients, and both render
     * them as text. Escaping them with esc_html() showed entities such as
     * &quot; to the user (#1203), so escaping is left to whatever renders them.
     *
     * @param string $check_id Analyzer check id.
     * @return array{fixed: bool, message: string, data: array} Outcome.
     * @throws \Exception When the check is unknown or unfixable.
     */
    public function fix(string $check_id): array {
        if (!self::can_fix($check_id)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
            throw new \Exception(__('This issue cannot be fixed automatically.', 'thinkrank'));
        }

        switch ($check_id) {
            case 'search_visibility':
                return $this->fix_search_visibility();
            case 'xml_sitemap':
                return $this->fix_sitemap();
            case 'schema':
                return $this->fix_schema();
            case 'image_alt_text':
                return $this->fix_image_alt_text();
            case 'ai_crawler_access':
                return $this->fix_ai_crawler_access();
            case 'llms_txt':
                return $this->fix_llms_txt();
        }

        /**
         * Let a third party handle a fix it registered.
         *
         * @since 1.28.0
         *
         * @param array|null $result   ['fixed' => bool, 'message' => string, 'data' => array].
         * @param string     $check_id Check id being fixed.
         */
        $result = apply_filters('thinkrank_seo_analyzer_apply_fix', null, $check_id);

        if (is_array($result)) {
            return array_merge(['fixed' => false, 'message' => '', 'data' => []], $result);
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
        throw new \Exception(__('No handler is registered for this fix.', 'thinkrank'));
    }

    /**
     * Let search engines crawl the site again.
     *
     * Unticks "Discourage search engines" and turns ThinkRank's Allow Search
     * Engines switch back on. Setting blog_public alone reported success while
     * the switch kept serving `Disallow: /` (#967).
     *
     * A physical robots.txt is rewritten only when ThinkRank wrote it
     * (Site_Identity_Manager::robots_file_written_by_thinkrank()) under robots
     * management, and only when this fix turned the switch on or the file
     * still blocks with both settings on. The second case is a stale file the
     * switch was turned on without rewriting, for example through a save that
     * did not sync (#1202). Turning on the switch says nothing about who
     * wrote the file, and a hand-written one is never overwritten: one that
     * still blocks is reported instead, as is a custom robots.txt body that
     * blocks. A blog_public change re-syncs a ThinkRank file through
     * SEO_Manager::on_blog_public_changed(), under the same rule, so a file
     * that hook already repaired no longer blocks and is not synced again.
     *
     * Refused before anything is written outside production
     * (search_visibility_fix_allowed()).
     *
     * @return array
     * @throws \Exception When the site is not production, when the setting
     *                    cannot be saved, or when the served robots.txt still
     *                    blocks search engines afterwards.
     */
    private function fix_search_visibility(): array {
        if (!self::search_visibility_fix_allowed()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
            throw new \Exception(self::search_visibility_refusal());
        }

        $has_manager = class_exists('ThinkRank\\SEO\\Site_Identity_Manager');

        if (!get_option('blog_public')) {
            update_option('blog_public', 1);
        }

        $analyzer = new SEO_Analyzer();

        if ($has_manager) {
            $manager  = $this->site_identity_manager();
            $settings = $manager->get_settings('site');
            $settings = is_array($settings) ? $settings : [];
            $turned_on = false;

            if (empty($settings['allow_search_engines'] ?? true)) {
                // Only the one key: save_settings() writes key by key, so the
                // rest of the robots configuration is left exactly as it was.
                if (!$manager->save_settings('site', null, ['allow_search_engines' => true])) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
                    throw new \Exception(__('The Allow Search Engines setting could not be saved. Check the PHP error log for the ThinkRank line naming the cause.', 'thinkrank'));
                }

                $turned_on = true;
            }

            // Re-read from a fresh manager: the one above holds the pre-save
            // settings. Only a file ThinkRank wrote is rewritten, and only
            // under robots management. Creating a file nobody generated, or
            // deleting one ThinkRank does not manage, is not part of this fix.
            $fresh = $this->site_identity_manager();
            $now   = $fresh->get_settings('site');

            if (!empty($now['robots_txt_enabled']) && $fresh->robots_file_written_by_thinkrank()) {
                $still_blocks = !$turned_on && 'file' === ($analyzer->search_engine_block()['source'] ?? '');

                if ($turned_on || $still_blocks) {
                    $fresh->sync_robots_txt_file();
                }
            }
        }

        $block = $analyzer->search_engine_block();

        if (!empty($block)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
            throw new \Exception($this->search_block_error($block));
        }

        return [
            'fixed'   => true,
            'message' => __('Search engines can now index this site.', 'thinkrank'),
            'data'    => [],
        ];
    }

    /**
     * Why the search visibility fix is refused outside production, in words
     * to show the person.
     *
     * @since 2.18.1
     * @return string
     */
    public static function search_visibility_refusal(): string {
        return __('This site is not marked as production, so ThinkRank will not make it visible to search engines. A staging, local or development copy should stay hidden. On the live site, untick "Discourage search engines from indexing this site" under Settings → Reading.', 'thinkrank');
    }

    /**
     * Name what is still blocking search engines after the fix ran.
     *
     * @since 2.17.0
     * @param array $block Output of SEO_Analyzer::search_engine_block().
     * @return string
     */
    private function search_block_error(array $block): string {
        switch ((string) ($block['source'] ?? '')) {
            case 'file':
                if (!empty($block['thinkrank_file'])) {
                    // The fix tried to rewrite ThinkRank's own file and the
                    // block survived, so the write itself failed (#1202).
                    return __('Search engines are allowed in your settings, but the robots.txt file ThinkRank wrote in your site root could not be rewritten and still blocks them. Check that the file is writable, then save the Robots.txt settings again.', 'thinkrank');
                }

                return __('Search engines are allowed in your settings, but the robots.txt file in your site root still blocks them. Your web server serves that file directly, so edit or remove it.', 'thinkrank');

            case 'blog_public':
            case 'allow_search_engines':
                return __('Search engine access was turned on, but the setting did not stick. Another plugin may be changing it back.', 'thinkrank');

            default:
                return __('Search engines are allowed in your settings, but your custom robots.txt content still blocks them. Remove the "Disallow: /" rule under Essential SEO → Crawling and AI Indexing → Robots.txt.', 'thinkrank');
        }
    }

    /**
     * A Site Identity manager to read and save the robots.txt settings.
     *
     * A seam so tests can substitute a manager that does not need the
     * settings table.
     *
     * @since 2.17.0
     * @return Site_Identity_Manager
     */
    protected function site_identity_manager(): Site_Identity_Manager {
        return new Site_Identity_Manager();
    }

    /**
     * Enable XML sitemap output.
     *
     * @return array
     * @throws \Exception When the sitemap setting cannot be saved.
     */
    private function fix_sitemap(): array {
        // 'site' is the context every real save path uses; 'global' is not a
        // supported context and Abstract_SEO_Manager rejects it — which this
        // fixer originally ignored, reporting success while saving nothing.
        $generator = new Sitemap_Generator();
        $settings  = $generator->get_settings('site');
        $settings  = is_array($settings) ? $settings : [];

        // Read before the save: once ThinkRank's sitemap is on it switches
        // core's off, and the person should hear that it was replaced (#1290).
        $core_url = empty($settings['enabled']) ? SEO_Analyzer::core_sitemap_url() : '';

        $settings['enabled'] = true;

        if (!$generator->save_settings('site', null, $settings)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
            throw new \Exception(__('The sitemap setting could not be saved. Check the PHP error log for the ThinkRank line naming the cause.', 'thinkrank'));
        }

        $message = '' !== $core_url
            ? sprintf(
                /* translators: %s: URL of WordPress core's sitemap index. */
                __('Your XML sitemap is now enabled. It replaces WordPress core\'s sitemap at %s, which ThinkRank switches off while its own is on.', 'thinkrank'),
                $core_url
            )
            : __('Your XML sitemap is now enabled.', 'thinkrank');

        return [
            'fixed'   => true,
            'message' => $message,
            'data'    => [],
        ];
    }

    /**
     * Turn on automatic schema generation.
     *
     * @return array
     * @throws \Exception When the schema module is unavailable or the setting cannot be saved.
     */
    private function fix_schema(): array {
        if (!class_exists('ThinkRank\\SEO\\Schema_Management_System')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
            throw new \Exception(__('The schema module is unavailable.', 'thinkrank'));
        }

        // 'site' is the storage context; 'schema_management_system' is the
        // MANAGER type, not a context — passing it made save_settings() reject
        // the write while this fixer reported success.
        $schema    = new Schema_Management_System();
        $settings  = $schema->get_settings('site');
        $settings  = is_array($settings) ? $settings : [];

        // The audit fails while the master switch is off, and nothing is
        // emitted then, so "now enabled" was only true once it is on too.
        $settings['enabled']              = true;
        $settings['auto_generate_schema'] = true;

        // Seed a site-level selection so enabling the toggle produces output
        // rather than an empty config. The site context holds the site
        // entities only. Seeding Article/WebPage made the auto-deploy pass
        // treat a selection the grid cannot even show as the user's choice,
        // and retire every deployed Organization, WebSite, LocalBusiness and
        // Person (#1004).
        $selected = is_array($settings['enabled_schema_types'] ?? null) ? $settings['enabled_schema_types'] : [];
        if (empty(Schema_Management_System::filter_types_for_context('site', $selected))) {
            $settings['enabled_schema_types'] = self::site_schema_types_to_seed(
                array_keys($schema->get_deployed_schemas('site', null))
            );
        }

        if (!$schema->save_settings('site', null, $settings)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
            throw new \Exception(__('The schema setting could not be saved. Check the PHP error log for the ThinkRank line naming the cause.', 'thinkrank'));
        }

        return [
            'fixed'   => true,
            'message' => __('Automatic structured data is now enabled.', 'thinkrank'),
            'data'    => [],
        ];
    }

    /**
     * The site Schema Types a schema fix seeds.
     *
     * The types already live on the site come first: they are what the page
     * publishes now, so seeding them turns the setting on without retiring
     * anything. The site defaults would not do that, since they leave out
     * WebSite and Person, and auto-deploy retires every deployed type the
     * selection leaves out. A site with nothing deployed gets the site
     * defaults from Schema_Settings_Config.
     *
     * @since 2.14.3
     *
     * @param string[] $deployed_types Schema types deployed in the site context.
     * @return string[] Site-level schema types to save as the selection.
     */
    private static function site_schema_types_to_seed(array $deployed_types): array {
        $deployed = Schema_Management_System::filter_types_for_context('site', $deployed_types);
        if (!empty($deployed)) {
            return $deployed;
        }

        $defaults = Schema_Settings_Config::get_default_settings('site')['enabled_schema_types'] ?? [];

        return Schema_Management_System::filter_types_for_context('site', is_array($defaults) ? $defaults : []);
    }

    /**
     * Why a fill left some images without alt text.
     *
     * Images the format would only have given filler are left empty on
     * purpose (#1124). Say so, or the count of images still missing alt text
     * looks like the fix did not run. The quality gate also refuses default
     * names from cameras, phones and design tools (#1211, #1250), so the
     * note names those too rather than only numbers and stock words.
     *
     * @param int $low_quality Images skipped as filler.
     * @return string The note, or '' when nothing was skipped.
     */
    private static function low_quality_note(int $low_quality): string {
        if ($low_quality <= 0) {
            return '';
        }

        return sprintf(
            /* translators: %d: images left without descriptive alt text. */
            _n(
                '%d image was left without descriptive alt text because your alt text format would only give it filler: a number, a word like "image", or a default name such as "IMG_1234" or "Screenshot". Add it by hand, or switch the alt text source to AI.',
                '%d images were left without descriptive alt text because your alt text format would only give them filler: a number, a word like "image", or a default name such as "IMG_1234" or "Screenshot". Add them by hand, or switch the alt text source to AI.',
                $low_quality,
                'thinkrank'
            ),
            $low_quality
        );
    }

    /**
     * Fill missing alt text across one batch of the media library.
     *
     * Returns progress rather than looping to completion: a large library
     * would exceed the request timeout, and in AI mode each image is a paid
     * call. The client re-invokes while `remaining` is above zero, so the user
     * sees progress and can stop.
     *
     * @return array
     */
    private function fix_image_alt_text(): array {
        // bulk_fill_missing_alt() pages by ATTACHMENT offset, so a caller that
        // always starts at 0 can only ever touch the first batch: once those
        // images have alt text they are skipped, `remaining` never moves, and
        // the "run the fix again to continue" message is an instruction that
        // cannot work. Carry the pager position across clicks.
        $manager = new Image_SEO_Manager();

        // Nothing missing means nothing to walk. Without this the pager still
        // marches through the library reporting `remaining` from the total
        // attachment count, so a fully-covered library kept claiming work was
        // left and re-armed the offset on every click.
        $stats = $manager->get_media_alt_stats();
        if (0 === (int) ($stats['missing'] ?? 0)) {
            delete_option(self::ALT_FIX_OFFSET_OPTION);

            return [
                'fixed'   => true,
                'message' => __('Every image in your media library already has alt text.', 'thinkrank'),
                'data'    => [
                    'updated'   => 0,
                    'remaining' => 0,
                ],
            ];
        }

        $offset  = (int) get_option(self::ALT_FIX_OFFSET_OPTION, 0);
        $result  = $manager->bulk_fill_missing_alt([
            'offset'    => $offset,
            'limit'     => 50,
            'overwrite' => false,
        ]);

        $updated     = (int) ($result['updated'] ?? 0);
        $remaining   = (int) ($result['remaining'] ?? 0);
        $done        = !empty($result['done']);
        $low_quality = (int) ($result['skipped_low_quality'] ?? 0);

        $low_quality_note = self::low_quality_note($low_quality);

        // Reset when the walk finishes so a later run (after new uploads)
        // starts from the top rather than off the end of the library.
        if ($done) {
            delete_option(self::ALT_FIX_OFFSET_OPTION);
        } else {
            update_option(self::ALT_FIX_OFFSET_OPTION, (int) ($result['next_offset'] ?? 0), false);
        }

        // A batch that changed nothing and has nothing left to walk is not a
        // success. Reporting `fixed => true` here showed a green toast while
        // the finding below it stayed red.
        if (0 === $updated && $done) {
            return [
                'fixed'   => false,
                'message' => trim(__('No images could be filled automatically. Check your alt text format under Essential SEO → Image SEO, or add alt text manually in the Media Library.', 'thinkrank') . ' ' . $low_quality_note),
                'data'    => [
                    'updated'             => 0,
                    'remaining'           => $remaining,
                    'skipped_low_quality' => $low_quality,
                ],
            ];
        }

        $message = $remaining > 0
            ? sprintf(
                /* translators: 1: images updated in this batch, 2: images still to process. */
                __('Added alt text to %1$d images. %2$d still to go. Run the fix again to continue.', 'thinkrank'),
                $updated,
                $remaining
            )
            : sprintf(
                /* translators: %d: number of images updated. */
                __('Added alt text to %d images. Your media library is done.', 'thinkrank'),
                $updated
            );

        // AI mode: some images fell back to the template, or kept what they
        // had, because the vision call failed. Say why (#1039).
        $ai_error = (string) ($result['ai_error'] ?? '');
        if ((int) ($result['ai_failed'] ?? 0) > 0 && '' !== $ai_error) {
            $message .= ' ' . sprintf(
                /* translators: 1: number of images, 2: the AI provider's error message. */
                __('The AI could not describe %1$d of them: %2$s', 'thinkrank'),
                (int) $result['ai_failed'],
                $ai_error
            );
        }

        // The write is done, but a cache ThinkRank cannot reach still decides
        // what the visitor sees. Saying so beats a green toast over a page
        // that has not changed (#763).
        if ('' !== $low_quality_note) {
            $message .= ' ' . $low_quality_note;
        }

        $warnings = (array) ($result['cache']['warnings'] ?? []);
        if ([] !== $warnings) {
            $message .= ' ' . implode(' ', $warnings);
        }

        return [
            'fixed'   => true,
            'message' => $message,
            'data'    => [
                'updated'             => $updated,
                'remaining'           => $remaining,
                'skipped_low_quality' => $low_quality,
            ],
        ];
    }

    /**
     * Set every AI answer-engine crawler to `allow`.
     *
     * Deliberately narrow: only the crawlers that decide whether the site can
     * be CITED are touched. GPTBot, ClaudeBot, CCBot and the other
     * training-only agents keep whatever the user chose for them — blocking
     * those is an editorial position, not a misconfiguration.
     *
     * @since 2.5.0
     * @return array
     * @throws \Exception When the rules cannot be saved, or when something
     *                    other than the rule map is still blocking them.
     */
    private function fix_ai_crawler_access(): array {
        $manager  = new Site_Identity_Manager();
        $settings = $manager->get_settings('site');
        $settings = is_array($settings) ? $settings : [];

        $rules = isset($settings['ai_crawler_rules']) && is_array($settings['ai_crawler_rules'])
            ? $settings['ai_crawler_rules']
            : [];

        foreach (SEO_Analyzer::GEO_ANSWER_AGENTS as $slug) {
            if (AI_Crawlers::exists($slug)) {
                $rules[$slug] = 'allow';
            }
        }

        $settings['ai_crawler_rules'] = $rules;

        if (!$manager->save_settings('site', null, $settings)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
            throw new \Exception(__('The AI crawler rules could not be saved. Check the PHP error log for the ThinkRank line naming the cause.', 'thinkrank'));
        }

        // The directives are composed into the served body at render time, but
        // a physical robots.txt in the web root is a copy the web server hands
        // out directly — saving the rules does not touch it. Re-sync from a
        // fresh manager (the one above holds the pre-save settings) or the fix
        // changes only the file nobody is reading.
        (new Site_Identity_Manager())->sync_robots_txt_file();

        // The rules are only one of the things that can disallow a crawler: a
        // hand-written robots.txt body, a physical robots.txt in the web root,
        // and the site-wide search block all outrank them. Ask the served file
        // again rather than reporting a success it contradicts.
        $still_blocked = (new SEO_Analyzer())->blocked_answer_agents();

        if (!empty($still_blocked)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
            throw new \Exception(
                sprintf(
                    /* translators: %s: comma-separated crawler names. */
                    __('The crawler rules were saved, but your robots.txt still blocks %s. That block comes from your own robots.txt content, a robots.txt file in your site root, or the site-wide search-engine setting. Edit it under Essential SEO → Crawling and AI Indexing.', 'thinkrank'),
                    implode(', ', $still_blocked)
                )
            );
        }

        return [
            'fixed'   => true,
            'message' => __('AI answer engines can now crawl and cite your site.', 'thinkrank'),
            'data'    => [],
        ];
    }

    /**
     * Generate and publish /llms.txt from the saved LLMs.txt settings.
     *
     * The document is built from settings the user already wrote, so this is a
     * publish, not authorship. Incomplete settings produce an invalid document
     * and are refused rather than written — a half-empty llms.txt is worse
     * than none, because an assistant will read it and believe it.
     *
     * @since 2.5.0
     * @return array
     * @throws \Exception When the module is unavailable, the settings are
     *                    incomplete, or the write fails.
     */
    private function fix_llms_txt(): array {
        if (!class_exists('ThinkRank\\SEO\\LLMs_Txt_Manager')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
            throw new \Exception(__('The llms.txt module is unavailable.', 'thinkrank'));
        }

        $manager = new LLMs_Txt_Manager();

        // The audit does not score llms.txt while it is switched off and
        // offers no Fix for it; fix-seo-check is refused the same way rather
        // than publishing a file the site chose not to have (#1288).
        if (!SEO_Analyzer::llms_txt_enabled($manager)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
            throw new \Exception(__('llms.txt is turned off in ThinkRank. Turn it on under Essential SEO → Crawling and AI Indexing → LLMs.txt first if you want it published.', 'thinkrank'));
        }

        $generated = $manager->generate_llms_txt([], []);

        $validation = isset($generated['validation']) && is_array($generated['validation'])
            ? $generated['validation']
            : [];

        if (empty($validation['valid'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
            throw new \Exception(__('llms.txt could not be published because its settings are incomplete. Fill in Website Description, Key Features and Target Audience under Essential SEO → Crawling and AI Indexing → LLMs.txt, then try again.', 'thinkrank'));
        }

        $content = (string) ($generated['content'] ?? '');
        $write   = $manager->write_llms_txt_to_file($content);

        if (empty($write['success'])) {
            $reason = (string) ($write['message'] ?? '');

            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for JSON, see fix().
            throw new \Exception(
                '' !== $reason
                    ? $reason
                    : __('llms.txt could not be written. Check that your site root is writable.', 'thinkrank')
            );
        }

        return [
            'fixed'   => true,
            'message' => __('Your llms.txt is now published at /llms.txt.', 'thinkrank'),
            'data'    => [],
        ];
    }
}
