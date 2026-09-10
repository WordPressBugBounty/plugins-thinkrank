<?php
/**
 * Beaver Builder module render entry point. See the FAQ module's frontend.php.
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

/** @var ThinkRank_Beaver_TOC_Module $module */
/** @var object $settings */
$module->render_content($settings);
