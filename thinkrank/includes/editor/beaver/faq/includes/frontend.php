<?php
/**
 * Beaver Builder renders a module by including this file with `$module` and
 * `$settings` in scope. The markup lives on the class so it sits next to the
 * settings it reads and stays reachable from tests.
 *
 * @package ThinkRank
 * @subpackage Editor\Beaver
 * @since 2.5.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/** @var ThinkRank_Beaver_FAQ_Module $module */
/** @var object $settings */
$module->render_content($settings);
