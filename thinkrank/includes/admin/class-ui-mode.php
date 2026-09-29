<?php
/**
 * Simple / Advanced mode: how much of ThinkRank's navigation a user sees.
 *
 * @package ThinkRank\Admin
 * @since 2.11.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * UI Mode
 *
 * One switch in the ThinkRank header (#730). Simple shows the sections a site
 * owner needs day to day and hides the rest of the menu; Advanced is the full
 * navigation. It only changes what the menu and search list: every screen
 * still opens by its URL, and no setting changes.
 *
 * Per user, in user meta, so a site owner and their agency each see their own
 * — and it follows the user across a multisite network.
 *
 * A user who never chose gets Advanced: that is the navigation everyone had
 * before the switch existed, and hiding sections from them unasked would read
 * as features disappearing. The setup wizard asks new sites.
 *
 * @since 2.11.0
 */
final class UI_Mode {

    public const META_KEY = 'thinkrank_ui_mode';

    public const SIMPLE = 'simple';

    public const ADVANCED = 'advanced';

    /**
     * Accepted modes.
     *
     * @var string[]
     */
    public const MODES = [self::SIMPLE, self::ADVANCED];

    /**
     * A user's mode.
     *
     * @param int $user_id User id; the current user when 0.
     * @return string One of MODES.
     */
    public static function get(int $user_id = 0): string {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();
        if ($user_id <= 0) {
            return self::ADVANCED;
        }

        $stored = (string) get_user_meta($user_id, self::META_KEY, true);

        return in_array($stored, self::MODES, true) ? $stored : self::ADVANCED;
    }

    /**
     * Store a user's mode and return what was stored.
     *
     * @param string $mode    One of MODES.
     * @param int    $user_id User id; the current user when 0.
     * @return string|null The stored mode, or null for an unknown mode or no user.
     */
    public static function set(string $mode, int $user_id = 0): ?string {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();
        if ($user_id <= 0 || !in_array($mode, self::MODES, true)) {
            return null;
        }

        update_user_meta($user_id, self::META_KEY, $mode);

        return self::get($user_id);
    }
}
