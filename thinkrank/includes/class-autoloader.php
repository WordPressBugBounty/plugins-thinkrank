<?php
/**
 * Autoloader Class
 * 
 * PSR-4 compliant autoloader for ThinkRank plugin
 * 
 * @package ThinkRank\Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Autoloader Class
 * 
 * Handles automatic loading of plugin classes following PSR-4 standard
 * Single Responsibility: Only handles class loading
 * 
 * @since 1.0.0
 */
class Autoloader {
    
    /**
     * Namespace prefix
     * 
     * @var string
     */
    private const NAMESPACE_PREFIX = 'ThinkRank\\';
    
    /**
     * Base directory for the namespace prefix
     * 
     * @var string
     */
    private string $base_dir;
    
    /**
     * Class map for faster loading
     * 
     * @var array
     */
    private static array $class_map = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->base_dir = THINKRANK_PLUGIN_DIR . 'includes/';
    }
    
    /**
     * Register autoloader
     * 
     * @return void
     */
    public static function register(): void {
        $autoloader = new self();
        spl_autoload_register([$autoloader, 'load_class']);
    }
    
    /**
     * Load class file
     * 
     * @param string $class_name Fully qualified class name
     * @return bool True if file was loaded, false otherwise
     */
    public function load_class(string $class_name): bool {
        // Check if class uses our namespace
        if (strpos($class_name, self::NAMESPACE_PREFIX) !== 0) {
            return false;
        }
        
        // Check class map first for performance
        if (isset(self::$class_map[$class_name])) {
            $file = self::$class_map[$class_name];
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
        
        // Get relative class name
        $relative_class = substr($class_name, strlen(self::NAMESPACE_PREFIX));
        
        // Convert namespace to file path
        $file_path = $this->get_file_path($relative_class);
        
        // Load file if it exists
        if (file_exists($file_path)) {
            require_once $file_path;

            // Cache successful loads
            self::$class_map[$class_name] = $file_path;

            return true;
        }

        return false;
    }
    
    /**
     * Convert class name to file path
     *
     * @param string $relative_class Relative class name
     * @return string File path
     */
    private function get_file_path(string $relative_class): string {
        // Replace namespace separators with directory separators
        $file_path = str_replace('\\', DIRECTORY_SEPARATOR, $relative_class);

        // Convert to lowercase and add class- prefix for WordPress convention
        $parts = explode(DIRECTORY_SEPARATOR, $file_path);
        $class_name = array_pop($parts);

        // Handle special cases where the generic CamelCase→kebab conversion
        // splits a brand/compound word (e.g. PageSpeed → page-speed) or an
        // acronym differently than the actual file name on disk.
        $special_cases = [
            'OpenAI_Client' => 'openai-client',
            'Claude_Client' => 'claude-client',
            'Gemini_Client' => 'gemini-client',
            'OpenRouter_Client' => 'openrouter-client',
            'Google_PageSpeed_Client' => 'google-pagespeed-client',
            'Analytics_Endpoint' => 'analytics-endpoint',
            'SEO_Manager' => 'seo-manager',
            'SEO_Plugin_Detector' => 'seo-plugin-detector',
            'SEO_Notice' => 'seo-notice',
            'LLMs_Txt_Endpoint' => 'llms-txt-endpoint',
            'LLMs_Txt_Manager' => 'llms-txt-manager',
            'Prompt_Builder' => 'prompt-builder',
            'SEO_Prompts' => 'seo-prompts',
            'Site_Identity_Prompts' => 'site-identity-prompts',
            'Homepage_Prompts' => 'homepage-prompts',
            'LLMs_Txt_Prompts' => 'llms-txt-prompts',
            'Content_Brief_Prompts' => 'content-brief-prompts',
            'SEOPress_Exporter' => 'seopress-exporter',
            'AIOSEO_Exporter' => 'aioseo-exporter',
            'Mcp_OAuth' => 'mcp-oauth',
            'Google_OAuth_Proxy' => 'google-oauth-proxy',
        ];

        if (isset($special_cases[$class_name])) {
            $class_file = 'class-' . $special_cases[$class_name] . '.php';
        } else {
            // Convert CamelCase to kebab-case for proper WordPress naming
            // Handle consecutive uppercase letters (like SEO) properly
            $class_name = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $class_name);
            $class_name = preg_replace('/([a-z])([A-Z])/', '$1-$2', $class_name);
            $class_file = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
        }

        // Build full path. Namespace segments with underscores map to
        // hyphenated directories (Email_Report_Sections → email-report-sections).
        $directory = !empty($parts) ? strtolower(str_replace('_', '-', implode(DIRECTORY_SEPARATOR, $parts))) . DIRECTORY_SEPARATOR : '';

        $file = $this->base_dir . $directory . $class_file;

        // Interfaces may use the WordPress interface- file prefix instead of class-
        // (e.g. Email_Report_Section_Interface → interface-email-report-section.php).
        if (!file_exists($file) && str_ends_with($class_file, '-interface.php')) {
            $interface_file = $this->base_dir . $directory . 'interface-' . substr($class_file, strlen('class-'), -strlen('-interface.php')) . '.php';
            if (file_exists($interface_file)) {
                return $interface_file;
            }
        }

        return $file;
    }
    
    /**
     * Preload critical classes for performance
     * 
     * @return void
     */
    public static function preload_critical_classes(): void {
        $critical_classes = [
            'ThinkRank\\Core\\Database',
            'ThinkRank\\Core\\Settings',
            'ThinkRank\\API\\Manager',
            'ThinkRank\\Admin\\Manager',
        ];
        
        foreach ($critical_classes as $class) {
            if (!class_exists($class)) {
                $autoloader = new self();
                $autoloader->load_class($class);
            }
        }
    }
}
