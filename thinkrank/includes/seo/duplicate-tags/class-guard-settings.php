<?php
/**
 * Duplicate Tag Guard settings.
 *
 * @package ThinkRank\SEO\Duplicate_Tags
 * @since 2.23.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Duplicate_Tags;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Guard Settings
 *
 * One switch per tag family (#733), all off by default: taking another
 * plugin's or the theme's tags out of a page is a choice the owner makes
 * after seeing what is duplicated, and a site may well want to keep its
 * theme's Open Graph while dropping a second canonical.
 *
 * Autoloaded: the guard reads it on every front-end request, and an
 * autoloaded option costs nothing there.
 *
 * @since 2.23.0
 */
class Guard_Settings {

    public const OPTION = 'thinkrank_duplicate_tag_guard';

    /**
     * Every family off.
     *
     * @return array<string, bool>
     */
    public static function defaults(): array {
        return array_fill_keys(Head_Tags::FAMILIES, false);
    }

    /**
     * The switches.
     *
     * @return array<string, bool>
     */
    public function get(): array {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        $out = [];
        foreach (self::defaults() as $family => $default) {
            $out[$family] = array_key_exists($family, $stored) ? (bool) $stored[$family] : $default;
        }

        return $out;
    }

    /**
     * Families switched on.
     *
     * @return string[]
     */
    public function enabled(): array {
        return array_keys(array_filter($this->get()));
    }

    /**
     * Change the families given, keep the rest, and return what was stored.
     * Unknown families are ignored.
     *
     * @param array<string, mixed> $patch Family => on.
     * @return array<string, bool>
     */
    public function save(array $patch): array {
        $next = $this->get();
        foreach (Head_Tags::FAMILIES as $family) {
            if (array_key_exists($family, $patch)) {
                $next[$family] = (bool) $patch[$family];
            }
        }

        update_option(self::OPTION, $next, true);

        /**
         * Fires after the Duplicate Tag Guard switches were saved.
         *
         * @since 2.23.0
         *
         * @param array<string, bool> $next The stored switches.
         */
        do_action('thinkrank_duplicate_tag_guard_saved', $next);

        return $this->get();
    }
}
