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
     * The fixable checks, keyed by the analyzer's check id.
     *
     * `warning` is shown in the UI before the user commits when a fix has a
     * consequence worth naming.
     *
     * @return array<string, array{label: string, warning: string}>
     */
    public static function fixable(): array {
        $fixable = [
            'search_visibility' => [
                'label'   => __('Allow search engines to index this site', 'thinkrank'),
                // Worth stating plainly: this is exactly the switch people use
                // to keep a staging site out of Google.
                'warning' => __('This makes your site visible to search engines. Do not apply it on a staging or private site.', 'thinkrank'),
            ],
            'xml_sitemap' => [
                'label'   => __('Enable the XML sitemap', 'thinkrank'),
                'warning' => '',
            ],
            'schema' => [
                'label'   => __('Turn on automatic structured data', 'thinkrank'),
                'warning' => '',
            ],
            'image_alt_text' => [
                'label'   => __('Fill in missing image alt text', 'thinkrank'),
                'warning' => __('Runs in batches over your media library. With AI alt text enabled this uses your AI provider key.', 'thinkrank'),
            ],
            'ai_crawler_access' => [
                'label'   => __('Allow AI answer engines to crawl this site', 'thinkrank'),
                // Only the answer engines are unblocked. Saying so matters:
                // people block the training crawlers on purpose, and a fix
                // that quietly reversed that decision would be a betrayal.
                'warning' => __('Sets the answer-engine crawlers (ChatGPT, Claude, Perplexity, Google-Extended) to Allow. Crawlers that only collect training data keep whatever setting you gave them.', 'thinkrank'),
            ],
            'llms_txt' => [
                'label'   => __('Publish llms.txt', 'thinkrank'),
                'warning' => __('Publishes /llms.txt from your saved LLMs.txt settings. It needs those fields filled in first.', 'thinkrank'),
            ],
        ];

        /**
         * Filter the checks the analyzer can fix automatically.
         *
         * Pro/add-ons registering their own checks through
         * `thinkrank_seo_analyzer_checks` can register their fixes here.
         *
         * @since 1.28.0
         *
         * @param array $fixable Check id => ['label' => string, 'warning' => string].
         */
        return apply_filters('thinkrank_seo_analyzer_fixable', $fixable);
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
     * @param string $check_id Analyzer check id.
     * @return array{fixed: bool, message: string, data: array} Outcome.
     * @throws \Exception When the check is unknown or unfixable.
     */
    public function fix(string $check_id): array {
        if (!self::can_fix($check_id)) {
            throw new \Exception(esc_html__('This issue cannot be fixed automatically.', 'thinkrank'));
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

        throw new \Exception(esc_html__('No handler is registered for this fix.', 'thinkrank'));
    }

    /**
     * Untick "Discourage search engines from indexing this site".
     *
     * @return array
     */
    private function fix_search_visibility(): array {
        update_option('blog_public', 1);

        return [
            'fixed'   => true,
            'message' => __('Search engines can now index this site.', 'thinkrank'),
            'data'    => [],
        ];
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

        $settings['enabled'] = true;

        if (!$generator->save_settings('site', null, $settings)) {
            throw new \Exception(esc_html__('The sitemap setting could not be saved. Check the PHP error log for the ThinkRank line naming the cause.', 'thinkrank'));
        }

        return [
            'fixed'   => true,
            'message' => __('Your XML sitemap is now enabled.', 'thinkrank'),
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
            throw new \Exception(esc_html__('The schema module is unavailable.', 'thinkrank'));
        }

        // 'site' is the storage context; 'schema_management_system' is the
        // MANAGER type, not a context — passing it made save_settings() reject
        // the write while this fixer reported success.
        $schema    = new Schema_Management_System();
        $settings  = $schema->get_settings('site');
        $settings  = is_array($settings) ? $settings : [];

        $settings['auto_generate_schema'] = true;

        // Seed the two types that apply to virtually every site, so enabling
        // the toggle actually produces output rather than an empty config.
        if (empty($settings['enabled_schema_types']) || !is_array($settings['enabled_schema_types'])) {
            $settings['enabled_schema_types'] = ['Article', 'WebPage'];
        }

        if (!$schema->save_settings('site', null, $settings)) {
            throw new \Exception(esc_html__('The schema setting could not be saved. Check the PHP error log for the ThinkRank line naming the cause.', 'thinkrank'));
        }

        return [
            'fixed'   => true,
            'message' => __('Automatic structured data is now enabled.', 'thinkrank'),
            'data'    => [],
        ];
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

        $updated   = (int) ($result['updated'] ?? 0);
        $remaining = (int) ($result['remaining'] ?? 0);
        $done      = !empty($result['done']);

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
                'message' => __('No images could be filled automatically. Check your alt text format under Essential SEO → Image SEO, or add alt text manually in the Media Library.', 'thinkrank'),
                'data'    => [
                    'updated'   => 0,
                    'remaining' => $remaining,
                ],
            ];
        }

        return [
            'fixed'   => true,
            'message' => $remaining > 0
                ? sprintf(
                    /* translators: 1: images updated in this batch, 2: images still to process. */
                    __('Added alt text to %1$d images. %2$d still to go — run the fix again to continue.', 'thinkrank'),
                    $updated,
                    $remaining
                )
                : sprintf(
                    /* translators: %d: number of images updated. */
                    __('Added alt text to %d images. Your media library is done.', 'thinkrank'),
                    $updated
                ),
            'data'    => [
                'updated'   => $updated,
                'remaining' => $remaining,
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
            throw new \Exception(esc_html__('The AI crawler rules could not be saved. Check the PHP error log for the ThinkRank line naming the cause.', 'thinkrank'));
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
            throw new \Exception(
                sprintf(
                    /* translators: %s: comma-separated crawler names. */
                    esc_html__('The crawler rules were saved, but your robots.txt still blocks %s. That block comes from your own robots.txt content, a robots.txt file in your site root, or the site-wide search-engine setting — edit it under Essential SEO → Crawling and AI Indexing.', 'thinkrank'),
                    esc_html(implode(', ', $still_blocked))
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
            throw new \Exception(esc_html__('The llms.txt module is unavailable.', 'thinkrank'));
        }

        $manager   = new LLMs_Txt_Manager();
        $generated = $manager->generate_llms_txt([], []);

        $validation = isset($generated['validation']) && is_array($generated['validation'])
            ? $generated['validation']
            : [];

        if (empty($validation['valid'])) {
            throw new \Exception(esc_html__('llms.txt could not be published because its settings are incomplete. Fill in Website Description, Key Features and Target Audience under Essential SEO → Crawling and AI Indexing → LLMs.txt, then try again.', 'thinkrank'));
        }

        $content = (string) ($generated['content'] ?? '');
        $write   = $manager->write_llms_txt_to_file($content);

        if (empty($write['success'])) {
            $reason = (string) ($write['message'] ?? '');

            throw new \Exception(
                '' !== $reason
                    ? esc_html($reason)
                    : esc_html__('llms.txt could not be written. Check that your site root is writable.', 'thinkrank')
            );
        }

        return [
            'fixed'   => true,
            'message' => __('Your llms.txt is now published at /llms.txt.', 'thinkrank'),
            'data'    => [],
        ];
    }
}
