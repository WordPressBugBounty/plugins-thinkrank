<?php
/**
 * LLMs.txt Manager Class
 *
 * Comprehensive LLMs.txt file management with AI-powered content generation,
 * file writing, validation, and status monitoring. Implements structured
 * information format for AI assistants and LLMs to better understand websites.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Ensure dependencies are loaded
if (!class_exists('ThinkRank\\SEO\\Abstract_SEO_Manager')) {
    require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-abstract-seo-manager.php';
}

if (!interface_exists('ThinkRank\\SEO\\Interfaces\\SEO_Manager_Interface')) {
    require_once THINKRANK_PLUGIN_DIR . 'includes/seo/interfaces/class-seo-manager-interface.php';
}

/**
 * LLMs.txt Manager Class
 *
 * Manages LLMs.txt file generation, validation, and serving with AI-powered
 * content creation based on website information and user input.
 *
 * @since 1.0.0
 */
class LLMs_Txt_Manager extends Abstract_SEO_Manager {

    /**
     * WordPress filesystem instance
     *
     * @since 1.0.0
     * @var \WP_Filesystem_Base|null
     */
    private $filesystem = null;

    /**
     * Whether the most recent save_settings() persisted a disable but failed to
     * remove the published llms.txt file (so it may still be served). Callers
     * check this via {@see unpublish_failed()} to surface a partial failure.
     *
     * @var bool
     */
    private bool $last_unpublish_failed = false;

    /**
     * Message from the last save that switched delivery mode but could not move
     * the published document, or an empty string when the switch was clean.
     *
     * @since 2.1.0
     * @var string
     */
    private string $last_delivery_warning = '';

    /**
     * Whether the last save's delivery-mode switch failed outright, as opposed
     * to succeeding with a warning. Both set {@see delivery_switch_warning()},
     * and only one of them means /llms.txt is still on the old path.
     *
     * @since 2.1.0
     * @var bool
     */
    private bool $last_delivery_switch_failed = false;

    /**
     * LLMs.txt content sections configuration
     *
     * @since 1.0.0
     * @var array
     */
    private array $content_sections = [
        'project_overview' => [
            'title' => 'Project Overview',
            'required' => true,
            'description' => 'High-level description of the website/project purpose',
            'max_length' => 500
        ],
        'key_features' => [
            'title' => 'Key Features',
            'required' => true,
            'description' => 'Main features and functionality of the website',
            'max_length' => 300
        ],
        'architecture' => [
            'title' => 'Architecture & Components',
            'required' => false,
            'description' => 'Technical architecture and key components',
            'max_length' => 400
        ],
        'development_guidelines' => [
            'title' => 'Development Guidelines',
            'required' => false,
            'description' => 'Coding standards and development practices',
            'max_length' => 300
        ],
        'setup_instructions' => [
            'title' => 'Setup Instructions',
            'required' => false,
            'description' => 'How to get the project running',
            'max_length' => 400
        ],
        'ai_context' => [
            'title' => 'Context for AI Assistants',
            'required' => true,
            'description' => 'Specific information to help AI understand the project',
            'max_length' => 300
        ]
    ];

    /**
     * Maximum file size for LLMs.txt files (1MB)
     *
     * @since 1.0.0
     * @var int
     */
    private const MAX_FILE_SIZE = 1048576; // 1MB in bytes

    /**
     * Marker used for ThinkRank's block in the site's .htaccess.
     *
     * The published llms.txt is a physical file, so the web server — not PHP —
     * serves it and decides the response headers. Apache/LiteSpeed answer .txt
     * with a bare `Content-Type: text/plain` (no charset), which makes browsers
     * fall back to their legacy single-byte default and render UTF-8 content as
     * mojibake ("Aktivitäten" → "AktivitÃ¤ten"); `X-Content-Type-Options:
     * nosniff` removes even the sniffing fallback. This block pins the charset
     * for that one file. See {@see serve_llms_txt()} for the PHP-served path.
     *
     * @var string
     */
    private const HTACCESS_MARKER = 'ThinkRank llms.txt';

    /**
     * Option holding the published llms.txt document.
     *
     * The published content lives here regardless of delivery mode, so the
     * dynamic route has an authoritative source that does not depend on a
     * physical file, and switching modes never loses the published document.
     *
     * @since 2.1.0
     * @var string
     */
    private const CONTENT_OPTION = 'thinkrank_llms_txt_content';

    /**
     * Option holding the Unix timestamp of the last publish.
     *
     * @since 2.1.0
     * @var string
     */
    private const PUBLISHED_AT_OPTION = 'thinkrank_llms_txt_published_at';

    /**
     * Option recording what the site's public URL really answers /llms.txt with.
     *
     * Shaped as ['home' => string, 'result' => 'charset'|'no_charset'|'unknown',
     * 'checked_at' => int] and keyed on the home URL, so a clone or a migration
     * re-checks instead of inheriting the verdict of the host it came from.
     *
     * @since 2.1.0
     * @var string
     */
    private const DELIVERY_PROBE_OPTION = 'thinkrank_llms_delivery_probe';

    /**
     * How long an inconclusive delivery check is left alone before retrying.
     *
     * A conclusive verdict stands until the document is published again; only
     * the "could not tell" answer — a blocked loopback, an HTTP-auth'd staging
     * site — is worth asking about a second time, and not often.
     *
     * @since 2.1.0
     * @var int
     */
    private const DELIVERY_PROBE_RETRY = DAY_IN_SECONDS;

    /**
     * Shown when the server answers the published file without a charset.
     *
     * @since 2.1.0
     * @var string
     */
    private const STATIC_CHARSET_WARNING = 'This server answers the published llms.txt without a character set, so accented characters and curly quotes arrive mis-decoded. Set Delivery Method to "Served by WordPress" to publish it as UTF-8.';

    /**
     * Delivery modes accepted by the `delivery_mode` setting.
     *
     * @since 2.1.0
     * @var string[]
     */
    private const DELIVERY_MODES = ['auto', 'static', 'dynamic'];

    /**
     * Business type templates for content generation
     *
     * @since 1.0.0
     * @var array
     */
    private array $business_types = [
        'website' => 'website',
        'blog' => 'Personal or professional blog',
        'business' => 'Business/corporate website',
        'ecommerce' => 'E-commerce/online store',
        'portfolio' => 'Portfolio/showcase website',
        'nonprofit' => 'Non-profit organization',
        'educational' => 'Educational institution',
        'news' => 'News/media website',
        'community' => 'Community/forum website',
        'saas' => 'Software as a Service',
        'agency' => 'Agency/service provider',
        'other' => 'Other type of website'
    ];

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct('llms_txt');
    }

    /**
     * Initialize WordPress filesystem
     *
     * @since 1.0.0
     * @return bool True if filesystem is initialized, false otherwise
     */
    private function init_filesystem(): bool {
        if ($this->filesystem !== null) {
            return true;
        }

        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $credentials = request_filesystem_credentials('', '', false, false, null);
        if (!WP_Filesystem($credentials)) {
            return false;
        }

        $this->filesystem = $wp_filesystem;
        return true;
    }

    /**
     * Check if directory is writable using WP_Filesystem
     *
     * @since 1.0.0
     * @param string $path Directory path to check
     * @return bool True if writable, false otherwise
     */
    private function is_directory_writable(string $path): bool {
        if (!$this->init_filesystem()) {
            return false;
        }

        return $this->filesystem->is_writable($path);
    }

    /**
     * Check if file is writable using WP_Filesystem
     *
     * @since 1.0.0
     * @param string $file File path to check
     * @return bool True if writable, false otherwise
     */
    private function is_file_writable(string $file): bool {
        if (!$this->init_filesystem()) {
            return false;
        }

        return $this->filesystem->is_writable($file);
    }
    public function generate_llms_txt(array $user_input, array $options = []): array {
        $llms_data = [
            'content' => '',
            'sections' => [],
            'metadata' => [],
            'validation' => [],
            'file_info' => []
        ];

        // Get current settings
        $settings = $this->get_settings('site');

        // Merge saved settings underneath the provided input so that empty or
        // partial $user_input falls back to the persisted configuration.
        // Explicitly provided (non-empty) values win; blank ones are filled from
        // saved settings. This lets callers generate from saved settings by
        // passing an empty payload (e.g. the generate-llms-txt MCP ability),
        // matching the documented behavior.
        $provided = array_filter(
            $user_input,
            static function ($value) {
                if (is_string($value)) {
                    return '' !== trim($value);
                }
                return null !== $value && [] !== $value;
            }
        );
        $user_input = array_merge($settings, $provided);

        // Check file status
        $llms_file = ABSPATH . 'llms.txt';
        $llms_data['file_info'] = [
            'file_exists' => file_exists($llms_file),
            'writable' => $this->is_directory_writable(dirname($llms_file)),
            'file_path' => $llms_file,
            'last_modified' => file_exists($llms_file) ? filemtime($llms_file) : null
        ];

        // Validate user input
        $validation = $this->validate_user_input($user_input);
        $llms_data['validation'] = $validation;

        if (!$validation['valid']) {
            return $llms_data;
        }

        // Generate content sections
        $llms_data['sections'] = $this->build_content_sections($user_input, $settings);
        
        // Build final LLMs.txt content
        $site_name = $user_input['site_name'] ?? $settings['site_name'] ?? get_bloginfo('name');
        $llms_data['content'] = $this->build_llms_txt_content($llms_data['sections'], $site_name);
        
        // Add metadata
        $llms_data['metadata'] = [
            'generated_at' => gmdate('c'),
            'website_url' => home_url(),
            'generator' => 'ThinkRank SEO Plugin',
            'content_length' => strlen($llms_data['content']),
            'sections_count' => count($llms_data['sections'])
        ];

        return $llms_data;
    }

    /**
     * Persist settings, unpublishing the physical file when the feature is
     * disabled so a disable actually stops serving /llms.txt.
     *
     * @param string   $context_type Context type.
     * @param int|null $context_id   Context ID.
     * @param array    $settings     Settings to save.
     * @return bool
     */
    public function save_settings(string $context_type, ?int $context_id, array $settings): bool {
        $this->last_unpublish_failed = false;
        $this->last_delivery_warning = '';
        $this->last_delivery_switch_failed = false;
        $previous_mode = $this->resolve_delivery_mode();

        $result = parent::save_settings($context_type, $context_id, $settings);

        // The cached status carries the resolved delivery mode, so it goes stale
        // the moment settings change — even when nothing needs republishing.
        delete_transient('thinkrank_llms_file_status');

        // When a save explicitly disables the feature, delete the published file.
        if ($result && array_key_exists('enabled', $settings) && empty($settings['enabled'])) {
            if (!$this->delete_llms_txt_file()) {
                // The settings were persisted, but the physical file could not be
                // removed, so /llms.txt may still be served. Record it so callers
                // report a partial failure instead of an unqualified success.
                $this->last_unpublish_failed = true;
            }

            return $result;
        }

        // The published document has to sit where the active mode serves it from,
        // or the site keeps answering on the old path: a leftover physical file
        // shadows the dynamic route on every stack, and a database-only document
        // is invisible to a stack now expecting a file. Reconciled on any save,
        // not just an explicit mode change, so a site whose auto-detection now
        // resolves differently — an nginx install upgrading into this fix with a
        // static file already on disk — heals the next time settings are saved.
        if ($result && $this->delivery_needs_reconcile($previous_mode)) {
            $this->republish_for_delivery_mode();
        }

        return $result;
    }

    /**
     * Whether the published document is out of step with the active mode.
     *
     * @since 2.1.0
     *
     * @param string $previous_mode Mode in force before the save.
     * @return bool
     */
    private function delivery_needs_reconcile(string $previous_mode): bool {
        $mode = $this->resolve_delivery_mode();
        $file_exists = file_exists(ABSPATH . 'llms.txt');

        if ('dynamic' === $mode) {
            // A physical file would be served instead of the PHP route.
            return $file_exists;
        }

        // Static: a stored document with no file behind it is unreachable on a
        // stack that expects one. A mode flip also forces the charset block to
        // be (re)written for a file that predates it.
        return (!$file_exists && '' !== trim($this->get_published_content()))
            || $mode !== $previous_mode;
    }

    /**
     * Re-publish the current document under the active delivery mode.
     *
     * A no-op when nothing is published yet — this only moves an existing
     * document, it never publishes on the user's behalf.
     *
     * @since 2.1.0
     *
     * @return void
     */
    private function republish_for_delivery_mode(): void {
        $content = $this->get_published_content();

        if ('' === trim($content)) {
            // Published before the stored copy existed: recover it from the file.
            $llms_file = ABSPATH . 'llms.txt';
            if (file_exists($llms_file)) {
                $read_result = $this->safe_file_read($llms_file);
                if ($read_result['success']) {
                    $content = $read_result['content'];
                }
            }
        }

        if ('' === trim($content)) {
            return;
        }

        $write = $this->write_llms_txt_to_file($content);

        // The switch itself failed (an unwritable root on the way to static, a
        // stuck file on the way to dynamic). The settings are saved, so report
        // it rather than letting the mode read as applied when it is not.
        if (empty($write['success'])) {
            $this->last_delivery_warning = isset($write['message']) && '' !== (string) $write['message']
                ? (string) $write['message']
                : 'The delivery method was saved, but the published llms.txt could not be moved to it.';
            $this->last_delivery_switch_failed = true;
            return;
        }

        // The switch worked, but static delivery on this server cannot carry the
        // charset the document needs. Only an explicitly chosen `static` gets
        // this far — `auto` moves itself to WordPress delivery instead.
        if (!empty($write['delivery_warning'])) {
            $this->last_delivery_warning = (string) $write['delivery_warning'];
        }
    }

    /**
     * Message from the last save whose delivery-mode switch could not be
     * applied to the already-published document, or '' when there was none.
     *
     * @since 2.1.0
     *
     * @return string
     */
    public function delivery_switch_warning(): string {
        return $this->last_delivery_warning;
    }

    /**
     * Whether the last save's warning was a failed switch rather than a
     * successful one the server cannot serve correctly.
     *
     * @since 2.1.0
     *
     * @return bool
     */
    public function delivery_switch_failed(): bool {
        return $this->last_delivery_switch_failed;
    }

    /**
     * Whether the last save_settings() disabled the feature but could not remove
     * the published llms.txt file (which may therefore still be served).
     *
     * @return bool
     */
    public function unpublish_failed(): bool {
        return $this->last_unpublish_failed;
    }

    /**
     * Resolve the effective delivery mode for /llms.txt.
     *
     * `static` publishes a physical ABSPATH/llms.txt and lets the web server
     * answer it; `dynamic` keeps the document in the database and lets the PHP
     * route in {@see serve_llms_txt()} answer it. `auto` picks static only on
     * Apache/LiteSpeed, the stacks that read the .htaccess charset block — on
     * nginx a physical file is served with a bare `Content-Type: text/plain`
     * that neither fix path can reach, which renders UTF-8 as mojibake (#419).
     *
     * $is_apache is not trusted on its own: WordPress reads it from
     * $_SERVER['SERVER_SOFTWARE'], which describes the server that runs PHP
     * rather than the one answering the public request. A reverse proxy hides
     * the difference — an nginx edge in front of an Apache backend reports
     * Apache, so `auto` chose the file that nginx then served with no charset,
     * which is the very defect the setting was added to avoid (#493). A
     * publish-time self-request settles what the detection cannot see, and its
     * verdict is what this consults; an explicit setting still wins outright.
     *
     * @since 2.1.0
     *
     * @param string|null $mode Optional. Raw setting value; read from the saved
     *                          settings when null.
     * @return string Either 'static' or 'dynamic'.
     */
    public function resolve_delivery_mode(?string $mode = null): string {
        if (null === $mode) {
            $settings = $this->get_settings('site');
            $mode = (string) ($settings['delivery_mode'] ?? 'auto');
        }

        if ('static' === $mode || 'dynamic' === $mode) {
            return $mode;
        }

        // $is_apache also covers LiteSpeed, which reads .htaccess the same way.
        if (empty($GLOBALS['is_apache'])) {
            return 'dynamic';
        }

        // Detection says this stack reads the .htaccess charset block. Believe
        // it unless a self-request has caught the public URL answering without
        // a charset, which is what a reverse-proxied stack does (#493).
        return $this->static_delivery_drops_charset() ? 'dynamic' : 'static';
    }

    /**
     * Whether the recorded check caught the public URL dropping the charset.
     *
     * @since 2.1.0
     *
     * @return bool
     */
    private function static_delivery_drops_charset(): bool {
        return 'no_charset' === ($this->delivery_probe()['result'] ?? '');
    }

    /**
     * The delivery check recorded for this site, or [] when there is none.
     *
     * @since 2.1.0
     *
     * @return array
     */
    private function delivery_probe(): array {
        $probe = get_option(self::DELIVERY_PROBE_OPTION, []);

        if (!is_array($probe) || !isset($probe['result'])) {
            return [];
        }

        return ($probe['home'] ?? '') === home_url() ? $probe : [];
    }

    /**
     * Ask the site's own public URL what it answers /llms.txt with.
     *
     * @since 2.1.0
     *
     * @return string 'charset', 'no_charset', or 'unknown' when the response
     *                could not be read and nothing should be concluded from it.
     */
    private function probe_static_delivery(): string {
        if (!function_exists('wp_remote_get')) {
            return 'unknown';
        }

        // The cache-buster stops a page cache from answering with a copy stored
        // before the file was written; a server ignores the query string when it
        // serves a physical file, so the response still shows the real headers.
        $url = add_query_arg(
            'thinkrank-delivery-check',
            (string) time(),
            home_url('/llms.txt')
        );

        $response = wp_remote_get($url, [
            'timeout' => 5,
            'redirection' => 2,
            // A request to our own home URL, from which a single response header
            // is read. Staging and local installs routinely run on certificates
            // this host does not trust, and failing there would leave the very
            // sites most likely to be misconfigured unchecked.
            'sslverify' => false,
            'headers' => ['Cache-Control' => 'no-cache'],
        ]);

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return 'unknown';
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');

        // A header sent more than once comes back as an array.
        if (is_array($content_type)) {
            $content_type = implode(' ', $content_type);
        }

        $content_type = trim((string) $content_type);

        // No Content-Type at all is the same problem: the browser is left to
        // guess the encoding.
        if ('' === $content_type) {
            return 'no_charset';
        }

        return false !== stripos($content_type, 'charset=') ? 'charset' : 'no_charset';
    }

    /**
     * Persist the outcome of a delivery check.
     *
     * @since 2.1.0
     *
     * @param string $verdict One of 'charset', 'no_charset', 'unknown'.
     * @return void
     */
    private function record_delivery_probe(string $verdict): void {
        update_option(self::DELIVERY_PROBE_OPTION, [
            'home' => home_url(),
            'result' => $verdict,
            'checked_at' => time(),
        ], false);
    }

    /**
     * Confirm the published file is really served with a charset, and act on it.
     *
     * Static delivery leans on an .htaccess directive, so it is only ever as
     * good as the guess that the server reads .htaccess. This checks the guess
     * against the response the public URL actually returns: a site left on
     * `auto` is moved to WordPress delivery when the charset is missing — the
     * file has to go with it, or it would shadow the PHP route that carries the
     * charset — while a site that asked for `static` keeps its file and gets a
     * warning, because an explicit choice is not overruled.
     *
     * @since 2.1.0
     *
     * @param array $result Publish result to annotate.
     * @return array The annotated result.
     */
    private function verify_static_delivery(array $result): array {
        $verdict = $this->probe_static_delivery();

        $this->record_delivery_probe($verdict);

        if ('no_charset' !== $verdict) {
            return $result;
        }

        $settings = $this->get_settings('site');
        $explicit = 'static' === (string) ($settings['delivery_mode'] ?? 'auto');

        // Auto: resolve_delivery_mode() answers 'dynamic' from here on, so the
        // file it would otherwise leave behind has to be removed. The document
        // is already stored, so nothing is lost by deleting it.
        if (!$explicit && $this->delete_static_file()) {
            delete_transient('thinkrank_llms_file_status');
            $this->purge_llms_txt_caches();

            $result['delivery_mode'] = 'dynamic';
            $result['charset_pinned'] = true;
            $result['message'] = 'LLMs.txt published. This server answers a static file without a character set, so WordPress serves it as UTF-8 instead.';
            $result['permissions']['file_exists'] = false;
            $result['permissions']['file_writable'] = null;

            return $result;
        }

        $result['charset_pinned'] = false;
        $result['delivery_warning'] = self::STATIC_CHARSET_WARNING;
        $result['message'] = trim((string) $result['message'] . ' ' . self::STATIC_CHARSET_WARNING);

        return $result;
    }

    /**
     * Whether the delivery check may run on this request.
     *
     * It makes an HTTP request of its own, so it never runs on a front-end
     * page view — only where an administrator, the REST API, WP-CLI or cron is
     * already waiting on a status read.
     *
     * @since 2.1.0
     *
     * @return bool
     */
    private function delivery_probe_is_due(): bool {
        $interactive = is_admin()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI)
            || (function_exists('wp_doing_cron') && wp_doing_cron());

        if (!$interactive) {
            return false;
        }

        $probe = $this->delivery_probe();

        if ([] === $probe) {
            return true;
        }

        if ('unknown' !== $probe['result']) {
            return false;
        }

        return (time() - (int) ($probe['checked_at'] ?? 0)) > self::DELIVERY_PROBE_RETRY;
    }

    /**
     * The published llms.txt document, or an empty string when unpublished.
     *
     * @since 2.1.0
     *
     * @return string
     */
    public function get_published_content(): string {
        $content = get_option(self::CONTENT_OPTION, '');

        return is_string($content) ? $content : '';
    }

    /**
     * Ask the common page/CDN cache layers to drop their copy of /llms.txt.
     *
     * A cached response outlives a republish, so without this a mode switch or
     * a content change keeps serving the old document (and, on the static path,
     * the old headers). Every call is guarded — a site running none of these
     * simply gets the action hook, which integrations can use.
     *
     * @since 2.1.0
     *
     * @return void
     */
    private function purge_llms_txt_caches(): void {
        $url = home_url('/llms.txt');

        /**
         * Fires after the published llms.txt changes, so cache layers ThinkRank
         * does not know about can drop their copy.
         *
         * @since 2.1.0
         *
         * @param string $url Public URL of the llms.txt document.
         */
        do_action('thinkrank_llms_txt_updated', $url);

        // LiteSpeed Cache and Nginx Helper both listen on their own actions.
        // These are third-party hook names we fire, not ours to prefix.
        do_action('litespeed_purge_url', $url); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        do_action('rt_nginx_helper_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        if (function_exists('rocket_clean_files')) {
            rocket_clean_files([$url]);
        }
        if (function_exists('w3tc_flush_url')) {
            w3tc_flush_url($url);
        }
        if (function_exists('wpsc_delete_url_cache')) {
            wpsc_delete_url_cache($url);
        }
    }

    /**
     * Unpublish llms.txt: drop the stored document and any physical file.
     *
     * Both delivery modes are cleared, not just the active one, so a site that
     * published under one mode and switched to the other is left with nothing
     * still being served.
     *
     * @return bool True once nothing is left to serve.
     */
    public function delete_llms_txt_file(): bool {
        delete_option(self::CONTENT_OPTION);
        delete_option(self::PUBLISHED_AT_OPTION);

        return $this->unpublish_static_file();
    }

    /**
     * Stop serving llms.txt, but keep the document.
     *
     * Deactivation needs this half: the physical file must go — it shadows the
     * next plugin's routes and advertises a plugin that is switched off — but
     * the user's prose has to survive so reactivation can republish it.
     * {@see \ThinkRank\Core\Activator::restore_webroot_artifacts()} does that.
     *
     * Deactivation previously called {@see delete_llms_txt_file()}, which drops
     * the stored document too, so a deactivate/reactivate round-trip silently
     * lost whatever the user had written.
     *
     * @since 2.1.0
     *
     * @return bool True once nothing is left on disk.
     */
    public function unpublish_static_file(): bool {
        delete_transient('thinkrank_llms_file_status');

        $removed = $this->delete_static_file();
        $this->purge_llms_txt_caches();

        return $removed;
    }

    /**
     * Remove the physical ABSPATH/llms.txt and its .htaccess charset block.
     *
     * @since 2.1.0
     *
     * @return bool True if the file is absent or was removed.
     */
    private function delete_static_file(): bool {
        $llms_file = ABSPATH . 'llms.txt';
        if (!file_exists($llms_file)) {
            $this->remove_htaccess_charset();
            return true;
        }
        if (!$this->init_filesystem()) {
            return false;
        }

        $deleted = (bool) $this->filesystem->delete($llms_file);
        if ($deleted) {
            // Leave no orphaned rule behind once the file is gone.
            $this->remove_htaccess_charset();
        }

        return $deleted;
    }

    /**
     * Pin the served charset of the physical llms.txt to UTF-8 via .htaccess.
     *
     * Scoped to the single file with <Files>, and wrapped in <IfModule> so a
     * server without mod_mime ignores it instead of returning a 500. Nginx does
     * not read .htaccess — there the PHP route in {@see serve_llms_txt()} is
     * what carries the charset, provided no physical file shadows it.
     *
     * @since 1.32.0
     *
     * @return bool True when the block is in place.
     */
    private function sync_htaccess_charset(): bool {
        // $is_apache also covers LiteSpeed, which reads .htaccess the same way.
        if (empty($GLOBALS['is_apache'])) {
            return false;
        }

        $htaccess = ABSPATH . '.htaccess';

        if (file_exists($htaccess)) {
            if (!$this->is_file_writable($htaccess)) {
                return false;
            }
        } elseif (!$this->is_directory_writable(ABSPATH)) {
            return false;
        }

        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        return (bool) insert_with_markers($htaccess, self::HTACCESS_MARKER, [
            '<IfModule mod_mime.c>',
            '<Files "llms.txt">',
            "ForceType 'text/plain; charset=UTF-8'",
            '</Files>',
            '</IfModule>',
        ]);
    }

    /**
     * Remove ThinkRank's charset block from .htaccess.
     *
     * Strips the block outright rather than calling insert_with_markers() with
     * an empty insertion — that leaves the BEGIN/END markers behind as litter.
     *
     * @since 1.32.0
     *
     * @return void
     */
    private function remove_htaccess_charset(): void {
        $htaccess = ABSPATH . '.htaccess';

        if (!file_exists($htaccess) || !$this->is_file_writable($htaccess)) {
            return;
        }

        if (!$this->init_filesystem()) {
            return;
        }

        $contents = $this->filesystem->get_contents($htaccess);
        if (!is_string($contents) || false === strpos($contents, '# BEGIN ' . self::HTACCESS_MARKER)) {
            return;
        }

        $marker = preg_quote(self::HTACCESS_MARKER, '/');
        $cleaned = preg_replace(
            '/\R*# BEGIN ' . $marker . '.*?# END ' . $marker . '[ \t]*\R?/s',
            '',
            $contents
        );

        if (!is_string($cleaned)) {
            return;
        }

        // A file left holding nothing but our (now removed) block was ours to
        // begin with — a pre-existing .htaccess would still have content.
        if ('' === trim($cleaned)) {
            $this->filesystem->delete($htaccess);
            return;
        }

        // Keep the file newline-terminated after the block is cut out.
        $this->filesystem->put_contents($htaccess, rtrim($cleaned, "\r\n") . "\n", FS_CHMOD_FILE);
    }

    /**
     * Serve /llms.txt from PHP with an explicit UTF-8 charset.
     *
     * Only reached when the request actually gets to WordPress — i.e. when no
     * physical llms.txt shadows the route, or on a stack that routes every
     * request through index.php. Prefers the published file's exact bytes and
     * falls back to regenerating from the saved settings, so the response is
     * the same document either way, just with headers PHP controls.
     *
     * Called by \ThinkRank\Frontend\SEO_Manager on template_redirect.
     *
     * @since 1.32.0
     *
     * @return void
     */
    public function serve_llms_txt(): void {
        $settings = $this->get_settings('site');

        // Never resurrect the file for a site that turned the feature off.
        if (empty($settings['enabled'])) {
            return;
        }

        $content = '';
        $llms_file = ABSPATH . 'llms.txt';

        // In static mode a physical file is what the server would normally hand
        // back, so prefer its exact bytes; in dynamic mode there is no file and
        // the stored document is the authoritative copy.
        if ('static' === $this->resolve_delivery_mode() && file_exists($llms_file)) {
            $read_result = $this->safe_file_read($llms_file);
            if ($read_result['success']) {
                $content = $read_result['content'];
            }
        }

        if ('' === trim($content)) {
            $content = $this->get_published_content();
        }

        if ('' === trim($content)) {
            $generated = $this->generate_llms_txt([]);
            $content = (string) ($generated['content'] ?? '');
        }

        // Nothing configured yet: leave the 404 alone rather than serving a stub.
        if ('' === trim($content)) {
            return;
        }

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');

        // Plain-text file body — already sanitized on save by
        // sanitize_llms_content(); escaping it here would corrupt the markdown.
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Write LLMs.txt content to filesystem
     *
     * @since 1.0.0
     *
     * @param string $content LLMs.txt content to write
     * @return array Write operation result
     */
    public function write_llms_txt_to_file(string $content): array {
        $result = [
            'success' => false,
            'message' => '',
            'file_path' => '',
            'permissions' => []
        ];

        // Refuse to publish when the feature is disabled. The React UI hides the
        // publish button, but the REST endpoint and the MCP publish ability call
        // this directly, so enforce the toggle here at the single write choke point.
        $settings = $this->get_settings('site');
        if (empty($settings['enabled'])) {
            $result['message'] = 'LLMs.txt is disabled. Enable it before publishing.';
            return $result;
        }

        $mode = $this->resolve_delivery_mode();
        $result['delivery_mode'] = $mode;

        $llms_file = ABSPATH . 'llms.txt';
        $result['file_path'] = $llms_file;

        // Dynamic delivery: the document lives in the database and /llms.txt is
        // answered by serve_llms_txt(), which sets `charset=utf-8` itself. A
        // physical file would shadow that route on every stack, so any leftover
        // from a previous static publish has to go.
        if ('dynamic' === $mode) {
            if (!$this->delete_static_file()) {
                $result['message'] = 'A physical llms.txt is still present and could not be removed. It would be served instead of the dynamic route.';
                return $result;
            }

            $this->store_published_content($content);

            $result['success'] = true;
            $result['message'] = 'LLMs.txt published. It is served by WordPress as UTF-8 text.';
            $result['bytes_written'] = strlen($content);
            $result['charset_pinned'] = true;
            $result['permissions'] = [
                'directory_writable' => $this->is_directory_writable(ABSPATH),
                'file_exists' => false,
                'file_writable' => null,
            ];

            return $result;
        }

        // Security: Validate file path to prevent path traversal attacks
        $real_llms_file = realpath(dirname($llms_file)) . DIRECTORY_SEPARATOR . basename($llms_file);
        $allowed_dir = realpath(ABSPATH);

        if (!$allowed_dir || strpos(dirname($real_llms_file), $allowed_dir) !== 0) {
            $result['message'] = 'Invalid file path detected for security reasons.';
            return $result;
        }

        // Check directory permissions
        $result['permissions'] = [
            'directory_writable' => $this->is_directory_writable(ABSPATH),
            'file_exists' => file_exists($llms_file),
            'file_writable' => file_exists($llms_file) ? $this->is_file_writable($llms_file) : null
        ];

        // Check if we can write to the directory
        if (!$result['permissions']['directory_writable']) {
            $result['message'] = 'WordPress root directory is not writable. Please check file permissions.';
            return $result;
        }

        // Check if existing file is writable (if it exists)
        if ($result['permissions']['file_exists'] && !$result['permissions']['file_writable']) {
            $result['message'] = 'Existing llms.txt file is not writable. Please check file permissions.';
            return $result;
        }

        // Write new content using WP_Filesystem
        if (!$this->init_filesystem()) {
            $result['message'] = 'Could not initialize WordPress filesystem.';
            return $result;
        }

        if (!$this->filesystem->put_contents($llms_file, $content, FS_CHMOD_FILE)) {
            $result['message'] = 'Failed to write llms.txt file.';
            return $result;
        }

        $result['success'] = true;
        $result['message'] = 'LLMs.txt file written successfully.';
        $result['bytes_written'] = strlen($content);

        // Pin the served charset to UTF-8. Best effort: a site without a
        // writable .htaccess (or not on Apache/LiteSpeed) still gets a
        // correctly written file, so this must never fail the publish.
        $result['charset_pinned'] = $this->sync_htaccess_charset();

        // Keep the stored copy in step with the file so a later switch to
        // dynamic delivery serves the same document.
        $this->store_published_content($content);

        // Detection said this server reads the .htaccess block. Check what the
        // public URL really answers with before leaving the file in place — on a
        // reverse-proxied stack the detection describes the wrong server (#493).
        return $this->verify_static_delivery($result);
    }

    /**
     * Persist the published document and bust the caches that mirror it.
     *
     * @since 2.1.0
     *
     * @param string $content Published llms.txt content.
     * @return void
     */
    private function store_published_content(string $content): void {
        update_option(self::CONTENT_OPTION, $content, false);
        update_option(self::PUBLISHED_AT_OPTION, time(), false);

        // Invalidate file status cache since the published document has changed
        delete_transient('thinkrank_llms_file_status');

        $this->purge_llms_txt_caches();
    }

    /**
     * Get LLMs.txt file status and information
     *
     * @since 1.0.0
     *
     * @return array File status information
     */
    public function get_llms_txt_status(bool $force_refresh = false): array {
        // Check cache first (5 minute cache for performance)
        $cache_key = 'thinkrank_llms_file_status';

        if (!$force_refresh) {
            $cached_status = get_transient($cache_key);
            if ($cached_status !== false) {
                return $cached_status;
            }
        }

        $llms_file = ABSPATH . 'llms.txt';
        $mode = $this->resolve_delivery_mode();

        // A site that published before this check existed — or whose server has
        // changed under it — has never had its delivery confirmed. Do it here so
        // an already-broken install heals without waiting for a republish; the
        // recorded verdict and the status cache keep it to a couple of requests
        // a day at most.
        if ('static' === $mode && file_exists($llms_file) && $this->delivery_probe_is_due()) {
            $this->verify_static_delivery(['message' => '']);
            $mode = $this->resolve_delivery_mode();
        }

        $stored = $this->get_published_content();

        $status = [
            'file_exists' => file_exists($llms_file),
            // Whether /llms.txt is actually being served, either mode. Prefer
            // this over file_exists, which is only meaningful in static mode.
            'published' => file_exists($llms_file) || '' !== trim($stored),
            'delivery_mode' => $mode,
            'file_path' => 'dynamic' === $mode ? '' : $llms_file,
            'file_url' => home_url('/llms.txt'),
            'writable' => $this->is_directory_writable(dirname($llms_file)),
            // Non-empty only when the site is on static delivery that the server
            // is known to answer without a charset — i.e. an explicit `static`
            // the plugin will not overrule, which is the user's to fix.
            'delivery_warning' => 'static' === $mode && $this->static_delivery_drops_charset()
                ? self::STATIC_CHARSET_WARNING
                : '',
            'last_modified' => null,
            'file_size' => null,
            'content_preview' => ''
        ];

        $content = null;

        if ($status['file_exists']) {
            $status['last_modified'] = filemtime($llms_file);
            $status['file_size'] = filesize($llms_file);

            $read_result = $this->safe_file_read($llms_file);
            if ($read_result['success']) {
                $content = $read_result['content'];
            } else {
                $status['content_preview'] = 'Error: ' . $read_result['error'];
                $status['read_error'] = $read_result['error'];
            }
        } elseif ('' !== trim($stored)) {
            $published_at = (int) get_option(self::PUBLISHED_AT_OPTION, 0);
            $status['last_modified'] = $published_at > 0 ? $published_at : null;
            $status['file_size'] = strlen($stored);
            $content = $stored;
        }

        if (null !== $content) {
            // Get content preview (first 200 characters) with size safety
            $status['content_preview'] = substr($content, 0, 200);
            if (strlen($content) > 200) {
                $status['content_preview'] .= '...';
            }
        }

        // Cache the result for 5 minutes to improve performance
        set_transient($cache_key, $status, 5 * MINUTE_IN_SECONDS);

        return $status;
    }

    /**
     * Validate LLMs.txt content
     *
     * @since 1.0.0
     *
     * @param string $content LLMs.txt content to validate
     * @return array Validation results
     */
    public function validate_llms_txt_content(string $content): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        // Check if content is empty
        if (empty(trim($content))) {
            $validation['errors'][] = 'LLMs.txt content cannot be empty';
            $validation['valid'] = false;
            $validation['score'] = 0;
            return $validation;
        }

        // Check content length
        $content_length = strlen($content);
        if ($content_length < 100) {
            $validation['warnings'][] = 'LLMs.txt content is very short, consider adding more details';
            $validation['score'] -= 20;
        } elseif ($content_length > 10000) {
            $validation['warnings'][] = 'LLMs.txt content is very long, consider condensing key information';
            $validation['score'] -= 10;
        }

        // Check for required sections
        $required_sections = ['Project Overview', 'Key Features', 'Context for AI Assistants'];
        foreach ($required_sections as $section) {
            if (stripos($content, $section) === false) {
                $validation['warnings'][] = "Missing recommended section: {$section}";
                $validation['score'] -= 15;
            }
        }

        // Check for proper structure
        if (!preg_match('/^#\s+/', $content)) {
            $validation['suggestions'][] = 'Consider starting with a main heading (# Project Name)';
            $validation['score'] -= 5;
        }

        // Ensure score doesn't go below 0
        $validation['score'] = max(0, $validation['score']);

        return $validation;
    }

    /**
     * Validate user input for LLMs.txt generation
     *
     * @since 1.0.0
     *
     * @param array $user_input User-provided data
     * @return array Validation results
     */
    private function validate_user_input(array $user_input): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        // Check required fields
        $required_fields = [
            'website_description' => 'Website Description',
            'key_features' => 'Key Features',
            'target_audience' => 'Target Audience'
        ];

        foreach ($required_fields as $field => $label) {
            if (empty($user_input[$field])) {
                $validation['errors'][] = "{$label} is required for quality LLMs.txt generation";
                $validation['valid'] = false;
                $validation['score'] -= 25;
            } else {
                $validation['suggestions'][] = "✓ {$label} is properly configured";
            }
        }

        // Validate link formats in structured sections
        $this->validate_link_sections($user_input, $validation);

        // Check content quality
        $this->validate_content_quality($user_input, $validation);

        // Check optional enhancements
        $this->validate_optional_enhancements($user_input, $validation);

        // Validate website description
        if (!empty($user_input['website_description'])) {
            $desc_length = strlen($user_input['website_description']);
            if ($desc_length < 50) {
                $validation['warnings'][] = 'Website description is quite short, consider adding more details';
                $validation['score'] -= 10;
            } elseif ($desc_length > 1000) {
                $validation['warnings'][] = 'Website description is very long, consider condensing key points';
                $validation['score'] -= 5;
            }
        }

        // Validate business type
        if (!empty($user_input['business_type']) && !isset($this->business_types[$user_input['business_type']])) {
            $validation['warnings'][] = 'Unknown business type specified';
            $validation['score'] -= 5;
        }

        // Ensure score doesn't go below 0
        $validation['score'] = max(0, $validation['score']);

        return $validation;
    }

    /**
     * Validate LLMs.txt input (public method for API)
     *
     * @since 1.0.0
     *
     * @param array $user_input User-provided data
     * @return array Validation results
     */
    public function validate_llms_txt_input(array $user_input): array {
        return $this->validate_user_input($user_input);
    }

    /**
     * Override parent sanitize_settings to preserve line breaks in link fields
     *
     * @since 1.0.0
     *
     * @param array  $settings     Settings to sanitize
     * @param string $context_type Context the save is for.
     * @return array Sanitized settings
     */
    protected function sanitize_settings(array $settings, string $context_type = 'site'): array {
        $sanitized = [];
        $known     = $this->get_known_setting_keys($context_type);

        // Fields that should preserve line breaks
        $preserve_linebreaks = [
            'documentation_links',
            'technical_links',
            'optional_links',
            'custom_sections',
            'key_features',
            'website_description',
            'technical_stack',
            'development_approach',
            'setup_instructions',
            'ai_context_custom'
        ];

        foreach ($settings as $key => $value) {
            $sanitized_key = sanitize_key($key);

            // Never store the REST envelope back as settings (see
            // Abstract_Seo_Manager::RESERVED_ENVELOPE_KEYS).
            if (in_array($sanitized_key, self::RESERVED_ENVELOPE_KEYS, true)) {
                continue;
            }

            // And nothing this manager does not declare (#452).
            if (!$this->is_known_setting_key($sanitized_key, $known)) {
                continue;
            }

            // Constrain the delivery mode to the known enum so an unexpected
            // value falls back to auto-detection rather than being stored.
            if ('delivery_mode' === $sanitized_key) {
                $mode = is_string($value) ? sanitize_key($value) : '';
                $sanitized[$sanitized_key] = in_array($mode, self::DELIVERY_MODES, true) ? $mode : 'auto';
                continue;
            }

            if (is_string($value)) {
                if (in_array($key, $preserve_linebreaks, true)) {
                    // Use our custom sanitization that preserves line breaks
                    if (in_array($key, ['documentation_links', 'technical_links', 'optional_links', 'custom_sections'], true)) {
                        $sanitized[$sanitized_key] = $this->sanitize_llms_content($value);
                    } else {
                        // For textarea fields, use sanitize_textarea_field which preserves line breaks
                        $sanitized[$sanitized_key] = sanitize_textarea_field($value);
                    }
                } else {
                    // For regular text fields, use sanitize_text_field
                    $sanitized[$sanitized_key] = sanitize_text_field($value);
                }
            } elseif (is_array($value)) {
                $sanitized[$sanitized_key] = $this->sanitize_array_recursive($value);
            } elseif (is_numeric($value)) {
                $sanitized[$sanitized_key] = (float) $value;
            } elseif (is_bool($value)) {
                $sanitized[$sanitized_key] = (bool) $value;
            } else {
                $sanitized[$sanitized_key] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }

    /**
     * Recursively sanitize array values (preserving line breaks where needed)
     *
     * @since 1.0.0
     *
     * @param array $input Array to sanitize
     * @return array Sanitized array
     */
    private function sanitize_array_recursive(array $input): array {
        $sanitized = [];

        foreach ($input as $key => $value) {
            $sanitized_key = sanitize_key($key);

            if (is_string($value)) {
                $sanitized[$sanitized_key] = sanitize_textarea_field($value);
            } elseif (is_array($value)) {
                $sanitized[$sanitized_key] = $this->sanitize_array_recursive($value);
            } elseif (is_numeric($value)) {
                $sanitized[$sanitized_key] = (float) $value;
            } elseif (is_bool($value)) {
                $sanitized[$sanitized_key] = (bool) $value;
            } else {
                $sanitized[$sanitized_key] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }

    /**
     * Validate link formats in structured sections
     *
     * @since 1.0.0
     *
     * @param array $user_input User input data
     * @param array &$validation Validation results (passed by reference)
     */
    private function validate_link_sections(array $user_input, array &$validation): void {
        $link_sections = [
            'documentation_links' => 'Documentation Links',
            'technical_links' => 'Technical Links',
            'optional_links' => 'Optional Links'
        ];

        foreach ($link_sections as $field => $label) {
            if (!empty($user_input[$field])) {
                $links = explode("\n", $user_input[$field]);
                $valid_links = 0;
                $total_links = 0;

                foreach ($links as $line) {
                    $line = trim($line);
                    if (empty($line) || !str_starts_with($line, '-')) {
                        continue;
                    }

                    $total_links++;

                    // Check for proper markdown link format: - [Title](URL): Description
                    if (preg_match('/^-\s*\[([^\]]+)\]\(([^)]+)\):\s*(.+)$/', $line, $matches)) {
                        $title = trim($matches[1]);
                        $url = trim($matches[2]);
                        $description = trim($matches[3]);

                        if (!empty($title) && !empty($url) && !empty($description)) {
                            if (filter_var($url, FILTER_VALIDATE_URL)) {
                                $valid_links++;
                            } else {
                                $validation['warnings'][] = "Invalid URL in {$label}: {$url}";
                                $validation['score'] -= 5;
                            }
                        } else {
                            $validation['warnings'][] = "Incomplete link format in {$label}: missing title, URL, or description";
                            $validation['score'] -= 5;
                        }
                    } else {
                        $validation['warnings'][] = "Invalid link format in {$label}. Use: - [Title](URL): Description";
                        $validation['score'] -= 5;
                    }
                }

                if ($total_links > 0) {
                    if ($valid_links === $total_links) {
                        $validation['suggestions'][] = "✓ All {$label} are properly formatted";
                    } else {
                        $validation['warnings'][] = "{$label}: {$valid_links}/{$total_links} links are properly formatted";
                    }
                }
            }
        }
    }

    /**
     * Validate content quality
     *
     * @since 1.0.0
     *
     * @param array $user_input User input data
     * @param array &$validation Validation results (passed by reference)
     */
    private function validate_content_quality(array $user_input, array &$validation): void {
        // Check website description quality
        if (!empty($user_input['website_description'])) {
            $desc_length = strlen($user_input['website_description']);
            if ($desc_length < 50) {
                $validation['warnings'][] = 'Website description is quite short. Consider adding more detail for better AI understanding';
                $validation['score'] -= 10;
            } elseif ($desc_length > 500) {
                $validation['warnings'][] = 'Website description is very long. Consider making it more concise';
                $validation['score'] -= 5;
            } else {
                $validation['suggestions'][] = '✓ Website description length is optimal';
            }
        }

        // Check key features quality
        if (!empty($user_input['key_features'])) {
            $features = explode("\n", $user_input['key_features']);
            $feature_count = count(array_filter($features, 'trim'));

            if ($feature_count < 3) {
                $validation['warnings'][] = 'Consider adding more key features (3-8 recommended) for comprehensive AI understanding';
                $validation['score'] -= 10;
            } elseif ($feature_count > 10) {
                $validation['warnings'][] = 'Many key features listed. Consider focusing on the most important ones';
                $validation['score'] -= 5;
            } else {
                $validation['suggestions'][] = "✓ Good number of key features ({$feature_count})";
            }
        }
    }

    /**
     * Validate optional enhancements
     *
     * @since 1.0.0
     *
     * @param array $user_input User input data
     * @param array &$validation Validation results (passed by reference)
     */
    private function validate_optional_enhancements(array $user_input, array &$validation): void {
        $enhancement_score = 0;

        // Check for technical stack
        if (!empty($user_input['technical_stack'])) {
            $validation['suggestions'][] = '✓ Technical stack information provided';
            $enhancement_score += 5;
        } else {
            $validation['suggestions'][] = 'Consider adding technical stack information for developer context';
        }

        // Check for development approach
        if (!empty($user_input['development_approach'])) {
            $validation['suggestions'][] = '✓ Development approach documented';
            $enhancement_score += 5;
        } else {
            $validation['suggestions'][] = 'Consider documenting development approach for better AI assistance';
        }

        // Check for setup instructions
        if (!empty($user_input['setup_instructions'])) {
            $validation['suggestions'][] = '✓ Setup instructions provided';
            $enhancement_score += 5;
        } else {
            $validation['suggestions'][] = 'Consider adding setup instructions for new developers';
        }

        // Check for custom sections
        if (!empty($user_input['custom_sections'])) {
            $validation['suggestions'][] = '✓ Custom sections enhance documentation';
            $enhancement_score += 5;
        }

        // Bonus points for comprehensive documentation
        if ($enhancement_score >= 15) {
            $validation['suggestions'][] = '✓ Comprehensive LLMs.txt documentation - excellent for AI assistance!';
        }
    }

    /**
     * Build content sections from user input
     *
     * @since 1.0.0
     *
     * @param array $user_input User-provided data
     * @param array $settings Current settings
     * @return array Built content sections
     */
    private function build_content_sections(array $user_input, array $settings): array {
        $sections = [];

        // Blockquote summary (required by spec)
        $sections['summary'] = [
            'title' => '', // No title for blockquote
            'content' => $this->build_summary_blockquote($user_input, $settings)
        ];

        // Additional details (optional descriptive content)
        if (!empty($user_input['website_description'])) {
            $sections['details'] = [
                'title' => '', // No title for details
                'content' => $this->build_additional_details($user_input, $settings)
            ];
        }

        // Development Approach section (if provided)
        if (!empty($user_input['development_approach'])) {
            $sections['development_approach'] = [
                'title' => 'Development Approach',
                'content' => sanitize_textarea_field($user_input['development_approach'])
            ];
        }

        // Setup Instructions section (if provided)
        if (!empty($user_input['setup_instructions'])) {
            $sections['setup_instructions'] = [
                'title' => 'Setup Instructions',
                'content' => sanitize_textarea_field($user_input['setup_instructions'])
            ];
        }

        // User-controlled structured sections (always include with defaults if empty)
        $documentation_content = !empty($user_input['documentation_links'])
            ? $this->sanitize_llms_content($user_input['documentation_links'])
            : $this->get_default_documentation_links();

        $sections['documentation'] = [
            'title' => 'Documentation',
            'content' => $documentation_content
        ];

        // Technical section (only if user provided content or technical details exist)
        if (!empty($user_input['technical_links']) || !empty($user_input['technical_stack']) || !empty($user_input['development_approach'])) {
            $technical_content = !empty($user_input['technical_links'])
                ? $this->sanitize_llms_content($user_input['technical_links'])
                : $this->get_default_technical_links($user_input);

            $sections['technical'] = [
                'title' => 'Technical Details',
                'content' => $technical_content
            ];
        }

        // Optional section (only if user provided content)
        if (!empty($user_input['optional_links'])) {
            $sections['optional'] = [
                'title' => 'Optional',
                'content' => $this->sanitize_llms_content($user_input['optional_links'])
            ];
        }

        // Custom sections (user-defined markdown)
        if (!empty($user_input['custom_sections'])) {
            $sections['custom'] = [
                'title' => '', // No title since user provides their own H2 headers
                'content' => $this->sanitize_llms_content($user_input['custom_sections'])
            ];
        }

        /**
         * Filter the llms.txt content sections before assembly.
         *
         * Each entry is ['title' => string, 'content' => string]; an empty
         * title emits the content without an H2. Pro appends a "Markdown for
         * AI" section here when that feature is enabled. Section content is
         * the callback's responsibility to sanitize.
         *
         * @since 1.32.0
         *
         * @param array $sections   Sections keyed by slug.
         * @param array $user_input Validated user input for the generator.
         */
        return apply_filters('thinkrank_llms_txt_sections', $sections, $user_input);
    }

    /**
     * Sanitize LLMs.txt content while preserving line breaks
     *
     * @since 1.0.0
     *
     * @param string $content Raw content to sanitize
     * @return string Sanitized content with preserved line breaks
     */
    public function sanitize_llms_content(string $content): string {
        // Remove any potential script tags and dangerous content
        $content = wp_kses($content, [
            'a' => ['href' => [], 'title' => []],
            'strong' => [],
            'em' => [],
            'code' => [],
            'pre' => []
        ]);

        // wp_kses only guards HTML href attributes, not markdown link syntax
        // [text](url). Neutralize dangerous schemes (javascript:/data:/vbscript:)
        // in markdown link targets so they don't survive into the published file
        // for downstream consumers that render it as markdown/HTML.
        $content = preg_replace_callback('/\]\(([^)]*)\)/', static function ($m) {
            if (preg_match('#^\s*(?:javascript|data|vbscript):#i', $m[1])) {
                return '](#)';
            }
            return $m[0];
        }, $content);

        // Normalize line endings and preserve line breaks
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // Remove excessive whitespace but preserve intentional line breaks
        $content = preg_replace('/[ \t]+/', ' ', $content); // Multiple spaces/tabs to single space
        $content = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $content); // Multiple empty lines to double

        return trim($content);
    }

    /**
     * Safely read file content with size limits
     *
     * @since 1.0.0
     *
     * @param string $file_path Path to file to read
     * @param int|null $max_size Maximum file size to read (null for class default)
     * @return array Result with success status, content, and any errors
     */
    private function safe_file_read(string $file_path, ?int $max_size = null): array {
        $result = [
            'success' => false,
            'content' => '',
            'error' => '',
            'file_size' => 0
        ];

        if (!file_exists($file_path)) {
            $result['error'] = 'File does not exist';
            return $result;
        }

        $file_size = filesize($file_path);
        $result['file_size'] = $file_size;

        $max_allowed = $max_size ?? self::MAX_FILE_SIZE;

        if ($file_size > $max_allowed) {
            $result['error'] = sprintf(
                'File size (%s) exceeds maximum allowed size (%s)',
                size_format($file_size),
                size_format($max_allowed)
            );
            return $result;
        }

        if (!$this->init_filesystem()) {
            $result['error'] = 'Could not initialize WordPress filesystem';
            return $result;
        }

        $content = $this->filesystem->get_contents($file_path);
        if (false === $content) {
            $result['error'] = 'Failed to read file content';
            return $result;
        }

        $result['success'] = true;
        $result['content'] = $content;
        return $result;
    }
    private function build_llms_txt_content(array $sections, string $site_name = ''): string {
        // Sanitize inside the manager rather than trusting callers — the MCP
        // abilities pass site_name through unsanitized.
        $site_name = sanitize_text_field($site_name ?: get_bloginfo('name'));
        $content = "# {$site_name}\n\n";

        foreach ($sections as $section_key => $section_data) {
            // Only add H2 header if title is not empty
            if (!empty($section_data['title'])) {
                $content .= "## {$section_data['title']}\n\n";
            }
            $content .= $section_data['content'] . "\n\n";
        }

        // Add generation timestamp
        $content .= "---\n";
        $content .= "Generated by ThinkRank SEO Plugin on " . gmdate('Y-m-d H:i:s') . " UTC\n";

        return $content;
    }

    /**
     * Validate SEO settings (implements interface)
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Validation results
     */
    public function validate_settings(array $settings): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        // Validate enabled setting
        if (!isset($settings['enabled'])) {
            $validation['errors'][] = 'Enabled setting is required';
            $validation['valid'] = false;
            $validation['score'] -= 25;
        }

        // Validate website description
        if (isset($settings['website_description'])) {
            if (empty($settings['website_description'])) {
                $validation['warnings'][] = 'Website description is empty, consider adding a description';
                $validation['score'] -= 15;
            } elseif (strlen($settings['website_description']) < 50) {
                $validation['suggestions'][] = 'Website description is quite short, consider adding more details';
                $validation['score'] -= 5;
            }
        }

        // Validate key features
        if (isset($settings['key_features'])) {
            if (empty($settings['key_features'])) {
                $validation['warnings'][] = 'Key features are empty, consider listing main website features';
                $validation['score'] -= 15;
            }
        }

        // Validate target audience
        if (isset($settings['target_audience'])) {
            if (empty($settings['target_audience'])) {
                $validation['suggestions'][] = 'Target audience is not specified, consider defining your audience';
                $validation['score'] -= 5;
            }
        }

        // Check file permissions if enabled. Only the static delivery mode needs
        // a writable root — dynamic delivery keeps the document in the database.
        $mode = $this->resolve_delivery_mode(
            isset($settings['delivery_mode']) ? (string) $settings['delivery_mode'] : null
        );
        if (!empty($settings['enabled']) && 'static' === $mode) {
            if (!$this->is_directory_writable(ABSPATH)) {
                $validation['warnings'][] = 'WordPress root directory is not writable, llms.txt cannot be automatically managed. Switch delivery to "Served by WordPress" to publish without writing a file.';
                $validation['score'] -= 10;
            }
        }

        // Ensure score doesn't go below 0
        $validation['score'] = max(0, $validation['score']);

        return $validation;
    }

    /**
     * Get output data for frontend rendering (implements interface)
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return array Output data ready for frontend rendering
     */
    public function get_output_data(string $context_type, ?int $context_id): array {
        $settings = $this->get_settings($context_type, $context_id);

        $output = [
            'llms_txt_content' => '',
            'file_status' => [],
            'metadata' => [],
            'enabled' => $settings['enabled'] ?? true
        ];

        if (!$output['enabled']) {
            return $output;
        }

        // Get file status
        $output['file_status'] = $this->get_llms_txt_status();

        // If a file is published, get its current content safely; otherwise fall
        // back to the stored document that dynamic delivery serves.
        if ($output['file_status']['file_exists']) {
            $llms_file = ABSPATH . 'llms.txt';
            $read_result = $this->safe_file_read($llms_file);
            if ($read_result['success']) {
                $output['llms_txt_content'] = $read_result['content'];
            } else {
                $output['llms_txt_content'] = '';
                $output['file_read_error'] = $read_result['error'];
            }
        } else {
            $output['llms_txt_content'] = $this->get_published_content();
        }

        // Add metadata
        $output['metadata'] = [
            'last_generated' => $settings['last_generated'] ?? null,
            'generator_version' => THINKRANK_VERSION ?? '1.0.0',
            'website_url' => home_url()
        ];

        return $output;
    }

    /**
     * Get default settings for a context type (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get defaults for
     * @return array Default settings array
     */
    public function get_default_settings(string $context_type): array {
        $defaults = [
            'enabled' => true,
            'site_name' => get_bloginfo('name'),
            'website_description' => get_bloginfo('description'),
            'key_features' => '',
            'target_audience' => 'general',
            'business_type' => 'website',
            'technical_stack' => 'WordPress',
            'development_approach' => '',
            'setup_instructions' => '',
            'ai_context_custom' => '',
            'auto_generate' => false,
            'delivery_mode' => 'auto',
            'last_generated' => null,
            // Structured sections for llms.txt spec compliance
            'documentation_links' => '',
            'technical_links' => '',
            'optional_links' => '',
            'custom_sections' => ''
        ];

        // Context-specific defaults
        switch ($context_type) {
            case 'site':
                // Site-wide defaults are already set above
                break;
            default:
                // Use site defaults for other contexts
                break;
        }

        return $defaults;
    }

    /**
     * Get settings schema definition (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get schema for
     * @return array Settings schema definition
     */
    public function get_settings_schema(string $context_type): array {
        return [
            'enabled' => [
                'type' => 'boolean',
                'title' => 'Enable LLMs.txt',
                'description' => 'Enable LLMs.txt file generation and management',
                'default' => true
            ],
            'site_name' => [
                'type' => 'string',
                'title' => 'Website Title',
                'description' => 'The name of your website as it will appear in the LLMs.txt file',
                'default' => get_bloginfo('name'),
                'maxLength' => 60
            ],
            'website_description' => [
                'type' => 'string',
                'title' => 'Website Description',
                'description' => 'Comprehensive description of your website and its purpose',
                'default' => get_bloginfo('description'),
                'maxLength' => 1000
            ],
            'key_features' => [
                'type' => 'string',
                'title' => 'Key Features',
                'description' => 'Main features and functionality of your website',
                'default' => '',
                'maxLength' => 500
            ],
            'target_audience' => [
                'type' => 'string',
                'title' => 'Target Audience',
                'description' => 'Primary audience for your website',
                'default' => 'general',
                'maxLength' => 200
            ],
            'business_type' => [
                'type' => 'string',
                'title' => 'Business Type',
                'description' => 'Type of website or business',
                'enum' => array_keys($this->business_types),
                'default' => 'website'
            ],
            'technical_stack' => [
                'type' => 'string',
                'title' => 'Technical Stack',
                'description' => 'Technologies and frameworks used',
                'default' => 'WordPress',
                'maxLength' => 300
            ],
            'development_approach' => [
                'type' => 'string',
                'title' => 'Development Approach',
                'description' => 'Development methodology and practices',
                'default' => '',
                'maxLength' => 400
            ],
            'setup_instructions' => [
                'type' => 'string',
                'title' => 'Setup Instructions',
                'description' => 'Instructions for setting up or working with the project',
                'default' => '',
                'maxLength' => 500
            ],
            'ai_context_custom' => [
                'type' => 'string',
                'title' => 'Additional AI Context',
                'description' => 'Custom context information for AI assistants',
                'default' => '',
                'maxLength' => 400
            ],
            'auto_generate' => [
                'type' => 'boolean',
                'title' => 'Auto-generate',
                'description' => 'Automatically regenerate llms.txt when settings change',
                'default' => false
            ],
            'delivery_mode' => [
                'type' => 'string',
                'title' => 'Delivery Method',
                'description' => 'How /llms.txt is served: "static" writes a physical file the web server answers, "dynamic" keeps the document in WordPress and serves it from PHP as UTF-8, "auto" picks static on Apache/LiteSpeed and dynamic elsewhere.',
                'enum' => self::DELIVERY_MODES,
                'default' => 'auto'
            ],
            'last_generated' => [
                'type' => 'string',
                'title' => 'Last Generated',
                'description' => 'Timestamp of last generation',
                'format' => 'date-time',
                'readonly' => true
            ],
            'documentation_links' => [
                'type' => 'string',
                'title' => 'Documentation Links',
                'description' => 'Links to documentation, guides, and important pages',
                'default' => '',
                'maxLength' => 2000
            ],
            'technical_links' => [
                'type' => 'string',
                'title' => 'Technical Links',
                'description' => 'Links to technical resources, code repositories, and development info',
                'default' => '',
                'maxLength' => 2000
            ],
            'optional_links' => [
                'type' => 'string',
                'title' => 'Optional Links',
                'description' => 'Secondary resources that can be skipped for shorter context',
                'default' => '',
                'maxLength' => 2000
            ],
            'custom_sections' => [
                'type' => 'string',
                'title' => 'Custom Sections',
                'description' => 'Additional custom sections in markdown format',
                'default' => '',
                'maxLength' => 3000
            ]
        ];
    }
    private function build_summary_blockquote(array $user_input, array $settings): string {
        $description = sanitize_textarea_field($user_input['website_description'] ?? '');

        if (empty($description)) {
            $site_name = sanitize_text_field($user_input['site_name'] ?? $settings['site_name'] ?? get_bloginfo('name'));
            $business_type = $user_input['business_type'] ?? 'website';
            $description = "{$site_name} is a {$this->business_types[$business_type]} providing valuable resources and information.";
        }

        // Format as blockquote (required by spec)
        return "> " . $description . "\n\n";
    }

    /**
     * Build additional details section
     *
     * @since 1.0.0
     *
     * @param array $user_input User input data
     * @param array $settings   Current settings
     * @return string Additional details content
     */
    private function build_additional_details(array $user_input, array $settings): string {
        $content = '';
        $target_audience = sanitize_text_field($user_input['target_audience'] ?? '');
        $key_features = sanitize_textarea_field($user_input['key_features'] ?? '');

        if (!empty($target_audience)) {
            $content .= "**Target Audience:** {$target_audience}\n\n";
        }

        if (!empty($key_features)) {
            $content .= "**Key Features:**\n";
            // The UI field is a multi-line textarea and validation counts by
            // newline, so split on newlines (and still tolerate commas) rather
            // than commas only — otherwise newline-separated input collapses
            // into one broken bullet.
            $features = preg_split('/[\r\n,]+/', $key_features);
            foreach ($features as $feature) {
                $feature = trim($feature);
                if (!empty($feature)) {
                    $content .= "- " . $feature . "\n";
                }
            }
            $content .= "\n";
        }

        return $content;
    }
    private function get_default_documentation_links(): string {
        $website_url = home_url();
        $content = '';

        // Add basic WordPress links
        $content .= "- [Website Home]({$website_url}): Main website homepage\n";
        $content .= "- [Sitemap]({$website_url}/sitemap.xml): Complete site structure\n";

        return $content;
    }

    /**
     * Get default technical links based on user input
     *
     * @since 1.0.0
     *
     * @param array $user_input User input data
     * @return string Default technical links
     */
    private function get_default_technical_links(array $user_input): string {
        $website_url = home_url();
        $content = '';

        if (!empty($user_input['technical_stack'])) {
            $stack = sanitize_text_field($user_input['technical_stack']);
            $content .= "- [Technical Stack]({$website_url}): Built with {$stack}\n";
        }

        if (!empty($user_input['development_approach'])) {
            $approach_summary = wp_trim_words($user_input['development_approach'], 10);
            $content .= "- [Development Guidelines]({$website_url}): {$approach_summary}\n";
        }

        // Add robots.txt reference
        $content .= "- [Robots.txt]({$website_url}/robots.txt): Site crawling guidelines\n";

        return $content;
    }
}
