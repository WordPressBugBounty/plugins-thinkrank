<?php
/**
 * SEO Goals: provider contract.
 *
 * @package ThinkRank\SEO\Goals
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Goals;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * A source of goals (#728).
 *
 * Register one on `thinkrank_seo_goal_providers`. `goals()` runs whenever the
 * list is rebuilt — at most once an hour, sooner when settings or content
 * change — so it must read what is already computed (options, transients, the
 * cached analyzer run, the snippet index) and never make a remote request.
 *
 * Report every goal the provider knows about, open or not: a closed one is
 * what ticks a section checklist, and one that stops being reported is how a
 * "done" mark learns the condition went away.
 *
 * @since 2.22.0
 */
interface Goal_Provider_Interface {

    /**
     * Provider id, also the prefix of its goal ids.
     *
     * @return string
     */
    public function id(): string;

    /**
     * Current goals.
     *
     * @return Goal[]
     */
    public function goals(): array;
}
