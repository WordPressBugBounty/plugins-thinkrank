<?php
/**
 * SEO Goals: failing Site SEO Analyzer checks.
 *
 * @package ThinkRank\SEO\Goals\Providers
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Goals\Providers;

use ThinkRank\SEO\Goals\Goal;
use ThinkRank\SEO\SEO_Analyzer;
use ThinkRank\SEO\Goals\Goal_Provider_Interface;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analyzer goals (#728): one goal per failing or warning check of the last
 * site audit, carrying the analyzer's own one-click fix when it has one.
 *
 * Reads the cached audit only. Computing one samples a hundred posts and
 * fires the completion action Pro records history on — not something a list
 * rebuild should set off. With no cached audit the goal is to run one.
 *
 * @since 2.22.0
 */
class Analyzer_Provider implements Goal_Provider_Interface {

    /**
     * The analyzer's cache. Private to SEO_Analyzer; read, never written here.
     */
    private const ANALYSIS_TRANSIENT = 'thinkrank_site_seo_analysis';

    /**
     * The last completed audit's checks, kept for the goals. The analyzer
     * drops its own cache on every settings save, which used to empty the
     * goal list of every audit goal until someone re-ran the audit.
     */
    public const LAST_AUDIT_OPTION = 'thinkrank_seo_goals_last_audit';

    /**
     * How long a kept audit stands in for a missing one. Past this the goal
     * is to run the audit again.
     */
    private const LAST_AUDIT_MAX_AGE = 7 * DAY_IN_SECONDS;

    /**
     * Checks another provider answers better: llms.txt is a setup goal, and
     * missing descriptions are counted sitewide from the snippet index rather
     * than from the audit's hundred-post sample.
     *
     * @var string[]
     */
    private const COVERED_ELSEWHERE = ['llms_txt', 'meta_descriptions'];

    /**
     * Checks whose failure takes the site out of search, or keeps browsers
     * from trusting it.
     *
     * @var string[]
     */
    private const CRITICAL = ['search_visibility', 'https'];

    /**
     * Checks whose fix means working through content, not flipping a switch.
     *
     * @var string[]
     */
    private const CONTENT_WORK = ['image_alt_text', 'direct_answer', 'question_headings', 'structured_content', 'content_depth', 'content_freshness'];

    /**
     * Checklist each check belongs to.
     *
     * @var array<string, string>
     */
    private const SECTIONS = [
        'site_title'          => 'site-identity',
        'tagline'             => 'site-identity',
        'search_visibility'   => 'crawling-ai-indexing',
        'xml_sitemap'         => 'crawling-ai-indexing',
        'ai_crawler_access'   => 'crawling-ai-indexing',
        'schema'              => 'schema',
        'answer_ready_schema' => 'schema',
        'image_alt_text'      => 'image-seo',
    ];

    /**
     * Screen where each checklist check is set. A check missing here is
     * fixed through its content, so it links to the Site Audit that lists
     * the pages.
     *
     * @var array<string, array<string, string>>
     */
    private const LINKS = [
        // Both checks read WordPress' own title and tagline, which Settings →
        // General sets; Site Identity's fields are ThinkRank's and separate.
        'site_title'          => ['screen' => 'options-general.php'],
        'tagline'             => ['screen' => 'options-general.php'],
        'search_visibility'   => ['section' => 'crawling-ai-indexing', 'item' => 'robots-management'],
        'xml_sitemap'         => ['section' => 'crawling-ai-indexing', 'item' => 'xml-sitemap'],
        'ai_crawler_access'   => ['section' => 'crawling-ai-indexing', 'item' => 'robots-management'],
        'schema'              => ['section' => 'schema', 'item' => 'schema-settings'],
        'answer_ready_schema' => ['section' => 'schema', 'item' => 'schema-settings'],
        'image_alt_text'      => ['section' => 'image-seo', 'item' => 'image-seo-settings'],
    ];

    /**
     * Where a search visibility block is lifted, by what causes it (the
     * check's `value`). Each cause is set on a different screen, and one
     * fixed link sent a site hidden by WordPress' own Reading setting to
     * Robots.txt, where Allow Search Engines was already on. A cause missing
     * here is lifted on the Robots.txt screen, the LINKS entry.
     *
     * @var array<string, array<string, string>>
     */
    private const VISIBILITY_LINKS = [
        'blog_public' => ['screen' => 'options-reading.php'],
        'robots_meta' => ['section' => 'crawling-ai-indexing', 'item' => 'global-robot-meta'],
    ];

    /**
     * Checks that read one setting and do no network or content work, so
     * they are re-run on every rebuild. Without this a checklist kept the
     * last audit's answer: turning the sitemap on left "Turn on the XML
     * sitemap" open until someone re-ran the Site Audit.
     *
     * @var string[]
     */
    private const LIVE = ['site_title', 'tagline', 'search_visibility', 'xml_sitemap', 'schema', 'ai_crawler_access'];

    /**
     * Analyzer that re-runs the live checks; null builds one when needed.
     *
     * @var SEO_Analyzer|null
     */
    private $analyzer;

    /**
     * @param SEO_Analyzer|null $analyzer Analyzer for the live checks.
     */
    public function __construct(?SEO_Analyzer $analyzer = null) {
        $this->analyzer = $analyzer;
    }

    /**
     * {@inheritDoc}
     */
    public function id(): string {
        return 'analyzer';
    }

    /**
     * {@inheritDoc}
     */
    public function goals(): array {
        $checks = $this->audit_checks();

        // Reported open or closed so a mark on it is dropped once an audit
        // exists, like any other goal whose condition went away.
        $run_audit = Goal::from_array([
            'id'       => 'analyzer:run-audit',
            'title'    => __('Run the site SEO audit', 'thinkrank'),
            'why'      => __('The audit checks your whole site in a few seconds: search visibility, sitemap, structured data, HTTPS and AI readiness. Anything it finds is added to this list.', 'thinkrank'),
            'how'      => [__('Open SEO Analyzer → Site Audit and click Re-analyze.', 'thinkrank')],
            'link'     => ['section' => 'seo-analyzer', 'item' => 'site-audit'],
            'severity' => 'high',
            'effort'   => 'S',
            'open'     => null === $checks,
        ]);

        // With no audit at all, the setting checks still answer, live, so a
        // section checklist keeps its items; the rest wait for the audit.
        $goals = $this->goals_from(null === $checks ? $this->live_checks() : $this->with_live_checks($checks));

        return array_merge(array_filter([$run_audit]), $goals);
    }

    /**
     * Keep a completed audit's checks for the goals, without the per-post
     * lists the goals never read.
     *
     * @param mixed $result Analysis, as `thinkrank_seo_analysis_completed` passes it.
     * @return void
     */
    public static function remember($result): void {
        if (!is_array($result) || !is_array($result['checks'] ?? null)) {
            return;
        }

        $checks = [];
        foreach ($result['checks'] as $check) {
            if (is_array($check)) {
                unset($check['affected_posts']);
                $checks[] = $check;
            }
        }

        update_option(self::LAST_AUDIT_OPTION, ['at' => time(), 'checks' => $checks], false);
    }

    /**
     * The audit's checks: the analyzer's cached run, else the last kept one
     * while it is recent. Null when there is neither.
     *
     * @return array<int, mixed>|null
     */
    private function audit_checks(): ?array {
        $analysis = get_transient(self::ANALYSIS_TRANSIENT);
        if (is_array($analysis) && is_array($analysis['checks'] ?? null)) {
            return $analysis['checks'];
        }

        $kept = get_option(self::LAST_AUDIT_OPTION, null);
        if (is_array($kept) && is_array($kept['checks'] ?? null)
            && time() - (int) ($kept['at'] ?? 0) <= self::LAST_AUDIT_MAX_AGE) {
            return $kept['checks'];
        }

        return null;
    }

    /**
     * Check results as goals, skipping the ones another provider owns.
     *
     * @param array<int, mixed> $checks Check results.
     * @return Goal[]
     */
    private function goals_from(array $checks): array {
        $goals = [];
        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $id = sanitize_key((string) ($check['id'] ?? ''));
            if ('' === $id || in_array($id, self::COVERED_ELSEWHERE, true)) {
                continue;
            }

            $goal = $this->goal($id, $check);
            if (null !== $goal) {
                $goals[] = $goal;
            }
        }

        return $goals;
    }

    /**
     * The setting checks, run now. Empty when the analyzer is unavailable or
     * fails, so callers fall back to what they had.
     *
     * @return array<int, array<string, mixed>>
     */
    private function live_checks(): array {
        if (!class_exists(SEO_Analyzer::class)) {
            return [];
        }

        try {
            return ($this->analyzer ?? new SEO_Analyzer())->recheck(self::LIVE);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The cached audit's checks, with the cheap setting checks replaced by
     * their current answer. A check that fails to re-run keeps its cached
     * result.
     *
     * @param array<int, mixed> $checks Cached check results.
     * @return array<int, mixed>
     */
    private function with_live_checks(array $checks): array {
        $by_id = [];
        foreach ($this->live_checks() as $check) {
            $by_id[(string) ($check['id'] ?? '')] = $check;
        }

        foreach ($checks as $index => $check) {
            $id = is_array($check) ? (string) ($check['id'] ?? '') : '';
            if (isset($by_id[$id])) {
                $checks[$index] = $by_id[$id];
            }
        }

        return $checks;
    }

    /**
     * One check as a goal.
     *
     * @param string               $id    Check id.
     * @param array<string, mixed> $check Check result.
     * @return Goal|null
     */
    private function goal(string $id, array $check): ?Goal {
        $status = (string) ($check['status'] ?? 'passed');
        $open   = 'passed' !== $status;
        $label  = (string) ($check['label'] ?? '');
        $weight = (float) ($check['weight'] ?? 1);
        $fix    = !empty($check['can_auto_fix']);

        if (in_array($id, self::CRITICAL, true) && 'failed' === $status) {
            $severity = 'critical';
        } elseif ('failed' === $status) {
            $severity = $weight >= 2 ? 'high' : 'medium';
        } else {
            $severity = $weight >= 2 ? 'medium' : 'low';
        }

        if ($fix) {
            $effort = 'S';
        } elseif (in_array($id, self::CONTENT_WORK, true)) {
            $effort = 'L';
        } else {
            $effort = 'M';
        }

        $how = array_filter([(string) ($check['how_to_fix'] ?? '')]);
        if ($fix) {
            array_unshift($how, sprintf(
                /* translators: %s: the fix button's label */
                __('Open SEO Analyzer → Site Audit and click “%s” on this check.', 'thinkrank'),
                (string) ($check['fix_label'] ?? __('Fix it for me', 'thinkrank'))
            ));
        }

        return Goal::from_array([
            'id'       => 'analyzer:' . $id,
            'title'    => $open ? $this->title($id, $label) : $label,
            'why'      => (string) ($check['message'] ?? ''),
            'how'      => $how,
            'link'     => self::link($id, $check),
            'severity' => $severity,
            'effort'   => $effort,
            'count'    => (int) ($check['affected_total'] ?? 0),
            'section'  => self::SECTIONS[$id] ?? '',
            'open'     => $open,
            'action'   => $fix ? [
                'type'     => 'analyzer_fix',
                'check_id' => $id,
                'label'    => (string) ($check['fix_label'] ?? ''),
                'warning'  => (string) ($check['fix_warning'] ?? ''),
            ] : null,
        ]);
    }

    /**
     * The screen where a check is set.
     *
     * @param string               $id    Check id.
     * @param array<string, mixed> $check Check result.
     * @return array<string, string>
     */
    private static function link(string $id, array $check): array {
        if ('search_visibility' === $id) {
            $cause = (string) ($check['value'] ?? '');

            if (isset(self::VISIBILITY_LINKS[$cause])) {
                return self::VISIBILITY_LINKS[$cause];
            }
        }

        return self::LINKS[$id] ?? ['section' => 'seo-analyzer', 'item' => 'site-audit'];
    }

    /**
     * What to do about a failing check. The analyzer labels a check by the
     * state it wants ("Site uses HTTPS"), which reads as done on a to-do list.
     *
     * @param string $id    Check id.
     * @param string $label Analyzer label, the fallback.
     * @return string
     */
    private function title(string $id, string $label): string {
        $titles = [
            'site_title'          => __('Set a site title', 'thinkrank'),
            'tagline'             => __('Write a tagline for your site', 'thinkrank'),
            'search_visibility'   => __('Let search engines index your site', 'thinkrank'),
            'permalinks'          => __('Switch to readable permalinks', 'thinkrank'),
            'xml_sitemap'         => __('Turn on the XML sitemap', 'thinkrank'),
            'schema'              => __('Turn on structured data', 'thinkrank'),
            'image_alt_text'      => __('Add alt text to your images', 'thinkrank'),
            'php_version'         => __('Upgrade to a supported PHP version', 'thinkrank'),
            'object_cache'        => __('Add a persistent object cache', 'thinkrank'),
            'https'               => __('Serve your site over HTTPS', 'thinkrank'),
            'file_editing'        => __('Turn off the built-in file editor', 'thinkrank'),
            'debug_display'       => __('Hide PHP errors from visitors', 'thinkrank'),
            'ai_crawler_access'   => __('Let AI assistants read your site', 'thinkrank'),
            'answer_ready_schema' => __('Add FAQ or HowTo structured data where it fits', 'thinkrank'),
            'direct_answer'       => __('Open posts with a direct answer', 'thinkrank'),
            'question_headings'   => __('Phrase some headings as questions', 'thinkrank'),
            'structured_content'  => __('Break long posts into lists and tables', 'thinkrank'),
            'content_depth'       => __('Expand your shortest posts', 'thinkrank'),
            'content_freshness'   => __('Update your oldest posts', 'thinkrank'),
        ];

        return $titles[$id] ?? sprintf(
            /* translators: %s: analyzer check label */
            __('Fix: %s', 'thinkrank'),
            $label
        );
    }
}
