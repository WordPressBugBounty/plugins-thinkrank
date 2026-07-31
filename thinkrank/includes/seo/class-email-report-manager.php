<?php
/**
 * Email Report Manager (Boot)
 *
 * The single component the plugin's DI container instantiates. Wires
 * together the config, registry, renderer, mailer, generator, and
 * scheduler, then registers the cron and the section catalog.
 *
 * Free sections are require_once'd here because the project autoloader
 * doesn't translate underscores in sub-namespaces to hyphenated
 * directories. Pro can register more sections via the
 * `thinkrank_email_report_register_sections` action.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\SEO\Email_Report_Sections\Email_Report_Section_Interface;
use ThinkRank\SEO\Email_Report_Sections\Key_Metrics_Section;
use ThinkRank\SEO\Email_Report_Sections\Position_Summary_Section;
use ThinkRank\SEO\Email_Report_Sections\Top_Winning_Posts_Section;
use ThinkRank\SEO\Email_Report_Sections\Top_Losing_Posts_Section;
use ThinkRank\SEO\Email_Report_Sections\Top_Winning_Keywords_Section;
use ThinkRank\SEO\Email_Report_Sections\Top_Losing_Keywords_Section;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email_Report_Manager
 *
 * @since 1.9.0
 */
final class Email_Report_Manager {

    private ?Email_Report_Config $config = null;
    private ?Email_Report_Section_Registry $registry = null;
    private ?Email_Report_Renderer $renderer = null;
    private ?Email_Report_Mailer $mailer = null;
    private ?Email_Report_Generator $generator = null;
    private ?Email_Report_Scheduler $scheduler = null;
    private ?Email_Report_Data_Provider $data_provider = null;

    /**
     * Called by the plugin DI container at plugins_loaded.
     *
     * Two-phase: load files now (so endpoint classes can resolve types
     * during rest_api_init), then register cron/sections on init.
     */
    public function init(): void {
        $this->require_files();
        $this->build_graph();
        $this->register_default_sections();
        $this->register_extension_sections();
        $this->scheduler->register();
    }

    /**
     * Section files live under `seo/email-report-sections/` (hyphenated)
     * but the namespace uses underscores — autoloader can't bridge that
     * mismatch. Explicit require keeps boot order deterministic.
     */
    private function require_files(): void {
        $base = THINKRANK_PLUGIN_DIR . 'includes/seo/email-report-sections/';
        require_once $base . 'interface-email-report-section.php';
        require_once $base . 'class-top-posts-renderer.php';
        require_once $base . 'class-key-metrics-section.php';
        require_once $base . 'class-position-summary-section.php';
        require_once $base . 'class-top-winning-posts-section.php';
        require_once $base . 'class-top-losing-posts-section.php';
        require_once $base . 'class-top-winning-keywords-section.php';
        require_once $base . 'class-top-losing-keywords-section.php';

        // Defaults config is procedural — load eagerly.
        require_once THINKRANK_PLUGIN_DIR . 'includes/config/email-report-settings-config.php';
    }

    private function build_graph(): void {
        $this->config = new Email_Report_Config();
        $this->registry = new Email_Report_Section_Registry();
        $this->renderer = new Email_Report_Renderer($this->registry);
        $this->mailer = new Email_Report_Mailer();
        $this->data_provider = new Email_Report_Data_Provider();
        $this->generator = new Email_Report_Generator(
            $this->config,
            $this->renderer,
            $this->mailer,
            $this->data_provider
        );
        $this->scheduler = new Email_Report_Scheduler($this->config, $this->generator);
    }

    private function register_default_sections(): void {
        $this->registry->register(new Key_Metrics_Section());
        $this->registry->register(new Position_Summary_Section());
        $this->registry->register(new Top_Winning_Posts_Section());
        $this->registry->register(new Top_Losing_Posts_Section());
        $this->registry->register(new Top_Winning_Keywords_Section());
        $this->registry->register(new Top_Losing_Keywords_Section());
    }

    private function register_extension_sections(): void {
        /**
         * Pro plugin (or any other plugin) hooks here and calls
         *   $registry->register(new MySection())
         * to add or override a section.
         *
         * @since 1.9.0
         *
         * @param Email_Report_Section_Registry $registry
         */
        do_action('thinkrank_email_report_register_sections', $this->registry);
    }

    // Accessors used by the REST endpoint.

    public function config(): Email_Report_Config {
        return $this->config;
    }

    public function registry(): Email_Report_Section_Registry {
        return $this->registry;
    }

    public function generator(): Email_Report_Generator {
        return $this->generator;
    }

    public function scheduler(): Email_Report_Scheduler {
        return $this->scheduler;
    }
}
