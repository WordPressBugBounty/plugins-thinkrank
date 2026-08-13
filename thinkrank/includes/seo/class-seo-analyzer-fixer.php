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
        $manager = new Image_SEO_Manager();
        $result  = $manager->bulk_fill_missing_alt(['limit' => 50, 'overwrite' => false]);

        $updated   = (int) ($result['updated'] ?? 0);
        $remaining = (int) ($result['remaining'] ?? 0);

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
}
