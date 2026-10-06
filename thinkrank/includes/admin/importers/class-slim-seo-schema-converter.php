<?php

/**
 * Slim SEO Pro schema converter
 *
 * Turns Slim SEO Pro's schema builder entries into ThinkRank Pro Custom
 * Schema entries: static JSON-LD plus display conditions (#886).
 *
 * The two models differ in one way that decides everything here. A Slim SEO
 * schema is a TEMPLATE: field values carry Slim Twig variables
 * (`{{ post.title }}`, `{{ current.url }}`) that are resolved on every
 * request, and its location is a set of rule groups or arbitrary PHP. A
 * ThinkRank Pro entry is fixed JSON-LD shown where its conditions match. So:
 *
 *  - A per-post schema (`slim_seo_schema` post meta) only ever renders on
 *    its own post, so its variables are resolved against that post now and
 *    the entry is scoped to it (`singular` = post ID).
 *  - A global schema (`slim_seo_schemas` option) converts when its values
 *    are the same on every page it matches — no variables beyond `site.*`
 *    and references to site-level nodes — and its location maps onto
 *    ThinkRank's targets. One scoped to specific posts is rendered per post.
 *  - Anything else (a per-page template applied to a whole post type, a PHP
 *    location, rule groups ThinkRank cannot express) is returned as
 *    preserved: kept in the snapshot, reported, and blocking cleanup.
 *  - A post with schemas of its own shows no global schema in Slim SEO Pro
 *    unless it opts in, so those posts are excluded from every global entry.
 *  - The types ThinkRank's own graph already emits (WebSite, WebPage,
 *    Organization, Article…) are not imported: emitting both would publish
 *    two competing nodes.
 *
 * Entries are shaped to pass ThinkRank Pro's Repository on the first try:
 * every nested object carries an `@type` (references get the referenced
 * node's type) and URL properties hold absolute http(s) URLs only.
 *
 * @package ThinkRank\Admin\Importers
 * @since 2.13.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Slim SEO Pro schema → ThinkRank Pro Custom Schema entries.
 *
 * @since 2.13.0
 */
class Slim_SEO_Schema_Converter {

    /**
     * Schema types ThinkRank's own graph emits on every page it applies to.
     * Slim SEO Pro's default set is exactly these, so a site that never
     * touched the builder imports nothing.
     */
    public const NATIVE_TYPES = [
        'WebSite', 'SearchAction', 'WebPage', 'Organization', 'Person',
        'BreadcrumbList', 'Article', 'BlogPosting', 'NewsArticle',
    ];

    /**
     * Slim SEO Pro's default global schemas by reference id, used to resolve
     * `{{ schemas.<id> }}` when the option was never saved
     * (SlimSEOPro\Schema\Defaults::get()).
     */
    private const DEFAULT_IDS = [
        'website'        => 'WebSite',
        'searchaction'   => 'SearchAction',
        'webpage'        => 'WebPage',
        'organization'   => 'Organization',
        'breadcrumblist' => 'BreadcrumbList',
        'article'        => 'Article',
        'person'         => 'Person',
    ];

    /**
     * Properties ThinkRank Pro's Repository requires to hold http(s) URLs.
     */
    private const URL_PROPS = ['url', 'sameAs', 'logo', 'image', 'contentUrl', 'thumbnailUrl'];

    /**
     * Fields Slim SEO Pro lets the user repeat ("cloneable"), by id: every one
     * declared `'cloneable' => true` in its schema specs (181 declarations,
     * 57 ids, Slim SEO Pro 1.11.1). They are stored as id-keyed maps of
     * clones — `['q1' => [...], 'q2' => [...]]` — and listed only at render
     * time (SchemaRenderer::render_array_prop()). A field that is one typed
     * object in another schema type carries its own `@type` and is left alone.
     */
    private const CLONEABLE_FIELDS = [
        'actor', 'additionalType', 'address', 'alumni', 'applicableCountry', 'availableAtOrFrom', 'brand',
        'businessDays', 'children', 'colleague', 'contactPoint', 'department', 'distribution',
        'educationalAlignment', 'eligibleRegion', 'employee', 'estimatedSalary', 'founder', 'funder',
        'hasPOS', 'hasPart', 'hoursAvailable', 'image', 'includedInDataCatalog', 'includesObject',
        'ineligibleRegion', 'isRelatedTo', 'itemCondition', 'itemListElement', 'knows', 'knowsAbout',
        'knowsLanguage', 'location', 'mainContentOfPage', 'mainEntity', 'memberOf', 'offers',
        'openingHoursSpecification', 'owns', 'performer', 'potentialAction', 'previousStartDate',
        'recipeIngredient', 'recipeInstructions', 'returnPolicyCountry', 'returnPolicySeasonalOverride',
        'review', 'sameAs', 'sibling', 'sponsor', 'spouse', 'step', 'suggestedAnswer', 'supply', 'tool',
        'workExample', 'worksFor',
    ];

    /**
     * Fields Slim SEO Pro keeps as strings even when numeric
     * (Factory\Base::convert_numeric_fields_to_string(), abridged to the
     * ones its builder offers).
     */
    private const STRING_FIELDS = [
        'telephone', 'faxNumber', 'postalCode', 'sku', 'mpn', 'gtin', 'gtin8', 'gtin12',
        'gtin13', 'gtin14', 'isbn', 'issn', 'identifier', 'productID', 'priceRange',
        'vatID', 'taxID', 'duns', 'leiCode', 'globalLocationNumber', 'size', 'servingSize',
    ];

    /**
     * Guard against a pathological nesting in foreign data.
     */
    private const MAX_DEPTH = 32;

    /**
     * Global schemas, active only, keyed by their Slim SEO key.
     *
     * @var array<string,array>
     */
    private array $globals;

    /**
     * Reference id (`{{ schemas.<id> }}`) => schema type, for globals.
     *
     * @var array<string,string>
     */
    private array $global_ids = [];

    /**
     * @param mixed $option Raw `slim_seo_schemas` option, or null when never saved
     */
    public function __construct($option) {
        $this->globals = is_array($option) ? $this->active($option) : [];

        if (!is_array($option)) {
            $this->global_ids = self::DEFAULT_IDS;
        }
        foreach ($this->globals as $schema) {
            $this->global_ids[$this->reference_id($schema)] = (string) ($schema['type'] ?? '');
        }
    }

    // -------------------------------------------------------------------------
    // Per-post schemas
    // -------------------------------------------------------------------------

    /**
     * Entries for one post's own schemas.
     *
     * @param int   $post_id  Post ID
     * @param mixed $meta     Raw `slim_seo_schema` post meta
     * @param array $slim_seo The post's rendered Slim SEO fields (`slim_seo.*` variables)
     * @return array<int,array> Canonical custom schema entries
     */
    public function convert_post(int $post_id, $meta, array $slim_seo = []): array {
        $schemas = $this->active($this->post_schema_list($meta));
        if (empty($schemas)) {
            return [];
        }

        $context = $this->post_context($post_id, $schemas, $slim_seo);
        $title = (string) get_the_title($post_id);

        $entries = [];
        foreach ($schemas as $key => $schema) {
            $type = (string) ($schema['type'] ?? '');
            if ($type === '' || in_array($type, self::NATIVE_TYPES, true)) {
                continue;
            }

            $json = $this->render_json($schema, $context);
            if ($json === null) {
                continue;
            }

            $entries[] = [
                'key'        => 'post:' . $post_id . ':' . $key,
                'title'      => sprintf('Slim SEO: %s — %s', $this->label($schema), $title !== '' ? $title : '#' . $post_id),
                'enabled'    => true,
                'json'       => $json,
                'conditions' => ['include' => [['target' => 'singular', 'value' => (string) $post_id]], 'exclude' => []],
            ];
        }

        return $entries;
    }

    /**
     * The stored per-post value as a key => schema list. The oldest format
     * was one schema stored directly (Post::get_schemas()).
     *
     * @param mixed $meta Raw post meta
     * @return array<string,array>
     */
    private function post_schema_list($meta): array {
        if (!is_array($meta) || empty($meta)) {
            return [];
        }
        if (isset($meta['type'])) {
            return ['schema' => $meta];
        }

        return $meta;
    }

    // -------------------------------------------------------------------------
    // Global schemas
    // -------------------------------------------------------------------------

    /**
     * Whether a post's stored schemas include at least one active one.
     *
     * @param mixed $meta Raw `slim_seo_schema` post meta (unserialized)
     */
    public static function has_active_schemas($meta): bool {
        if (!is_array($meta) || empty($meta)) {
            return false;
        }
        $converter = new self([]);

        return !empty($converter->active($converter->post_schema_list($meta)));
    }

    /**
     * Entries for the global schemas, and the ones that cannot be converted.
     *
     * @param int[] $suppressed_post_ids Posts that show no global schema: they
     *                                   have schemas of their own and did not
     *                                   opt in to the globals as well.
     * @return array{entries: array<int,array>, preserved: array<int,array>}
     */
    public function convert_globals(array $suppressed_post_ids = []): array {
        $entries = [];
        $preserved = [];
        $suppressed_post_ids = array_values(array_unique(array_filter(array_map('intval', $suppressed_post_ids))));
        $suppressed_rules = array_map(
            static fn(int $post_id): array => ['target' => 'singular', 'value' => (string) $post_id],
            $suppressed_post_ids
        );

        foreach ($this->globals as $key => $schema) {
            $type = (string) ($schema['type'] ?? '');
            if ($type === '' || in_array($type, self::NATIVE_TYPES, true)) {
                continue;
            }
            // Slim SEO Pro renders a schema with no location nowhere
            // (Factory\Base::validate()); there is nothing to carry.
            if (empty($schema['location']) || !is_array($schema['location'])) {
                continue;
            }

            $include = $this->map_location($schema['location']);
            $exclude = $this->map_location(is_array($schema['exclude'] ?? null) ? $schema['exclude'] : [], true);
            if ($exclude !== null) {
                $exclude = array_merge($exclude, $suppressed_rules);
            }

            if ($include === null || $exclude === null) {
                $preserved[] = $this->preserve($key, $schema, 'Its location rules have no ThinkRank equivalent (PHP code, an author/date/search archive, a term-based post rule, or several rule groups).');
                continue;
            }

            if ($this->is_static($schema)) {
                $json = $this->render_json($schema, $this->site_context());
                if ($json !== null) {
                    $entries[] = $this->global_entry($key, $schema, $json, $include, $exclude);
                }
                continue;
            }

            // A per-page template bound to specific posts can be rendered for
            // each of them; bound to a whole post type, it cannot.
            $post_ids = $this->only_singular_posts($include);
            if ($post_ids === null) {
                $preserved[] = $this->preserve($key, $schema, 'It takes its values from each page it is shown on, and Custom Schema entries are fixed JSON-LD.');
                continue;
            }

            foreach ($post_ids as $post_id) {
                // The post replaces the globals with its own schemas.
                if (in_array($post_id, $suppressed_post_ids, true)) {
                    continue;
                }
                $json = $this->render_json($schema, $this->post_context($post_id, [], []));
                if ($json === null) {
                    continue;
                }
                $entry = $this->global_entry($key . ':' . $post_id, $schema, $json, [['target' => 'singular', 'value' => (string) $post_id]], $exclude);
                $entry['title'] .= ': ' . (string) get_the_title($post_id);
                $entries[] = $entry;
            }
        }

        return ['entries' => $entries, 'preserved' => $preserved];
    }

    /**
     * @param string $key      Slim SEO schema key
     * @param array  $schema   Schema
     * @param string $json     Rendered JSON-LD
     * @param array  $includes ThinkRank include rules
     * @param array  $exclude  ThinkRank exclude rules
     */
    private function global_entry(string $key, array $schema, string $json, array $includes, array $exclude): array {
        return [
            'key'        => 'global:' . $key,
            'title'      => 'Slim SEO: ' . $this->label($schema),
            'enabled'    => true,
            'json'       => $json,
            'conditions' => ['include' => $includes, 'exclude' => $exclude],
        ];
    }

    /**
     * A preserved (unconverted) schema, as the snapshot keeps it.
     *
     * @param string $key    Slim SEO schema key
     * @param array  $schema Schema
     * @param string $reason Why it was not converted
     */
    private function preserve(string $key, array $schema, string $reason): array {
        return [
            'key'    => $key,
            'type'   => (string) ($schema['type'] ?? ''),
            'label'  => $this->label($schema),
            'reason' => $reason,
            'schema' => $schema,
        ];
    }

    /**
     * Whether a schema renders the same on every page: no variable beyond
     * `site.*` and references to site-level nodes. Its `@id` is excluded —
     * the default `{{ current.url }}#id` is replaced with a site-level id.
     *
     * @param array $schema Schema
     */
    private function is_static(array $schema): bool {
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        unset($fields['@id'], $fields['_label']);
        if (($schema['type'] ?? '') === 'CustomJsonLd') {
            $fields = ['code' => (string) ($fields['code'] ?? '')];
        }

        $static = true;
        array_walk_recursive($fields, function ($value) use (&$static): void {
            if (!$static || !is_string($value) || strpos($value, '{{') === false) {
                return;
            }
            preg_match_all('/\{\{\s*([^}\s]+?)\s*\}\}/', $value, $matches);
            foreach ($matches[1] as $path) {
                if (strpos($path, 'site.') === 0) {
                    continue;
                }
                if (strpos($path, 'schemas.') === 0 && in_array($this->global_ids[substr($path, 8)] ?? '', ['WebSite', 'Organization'], true)) {
                    continue;
                }
                $static = false;
            }
        });

        return $static;
    }

    /**
     * Post IDs when every include rule names one post, else null.
     *
     * @param array $includes ThinkRank include rules
     * @return int[]|null
     */
    private function only_singular_posts(array $includes): ?array {
        $ids = [];
        foreach ($includes as $rule) {
            if (($rule['target'] ?? '') !== 'singular' || (int) ($rule['value'] ?? 0) <= 0) {
                return null;
            }
            $ids[] = (int) $rule['value'];
        }

        return empty($ids) ? null : $ids;
    }

    // -------------------------------------------------------------------------
    // Locations
    // -------------------------------------------------------------------------

    /**
     * Slim SEO location → ThinkRank rules, or null when not expressible.
     *
     * Slim SEO requires EVERY rule group to match and ANY rule in a group;
     * ThinkRank OR-s its rules. One group maps exactly, several do not.
     *
     * @param array $location   Slim SEO location (or exclude) settings
     * @param bool  $is_exclude Whether this is the exclude side (empty = none)
     * @return array<int,array{target:string,value:string}>|null
     */
    private function map_location(array $location, bool $is_exclude = false): ?array {
        $type = (string) ($location['type'] ?? '');

        if ($type === '') {
            return $is_exclude ? [] : null;
        }
        if ($type === 'site') {
            return [['target' => 'entire_site', 'value' => '']];
        }
        if ($type !== 'singular' && $type !== 'archive') {
            return null;
        }

        $groups = is_array($location["{$type}_locations"] ?? null) ? array_values($location["{$type}_locations"]) : [];
        if (count($groups) !== 1 || !is_array($groups[0]) || empty($groups[0])) {
            return null;
        }

        $rules = [];
        foreach ($groups[0] as $rule) {
            $mapped = is_array($rule) ? $this->map_rule($type, $rule) : null;
            if ($mapped === null) {
                return null;
            }
            $rules[] = $mapped;
        }

        return $rules;
    }

    /**
     * One Slim SEO rule (`name` = "{type}:{subtype}", `value` = "all" or an ID).
     *
     * @param string $location_type singular | archive
     * @param array  $rule          Rule
     * @return array{target:string,value:string}|null
     */
    private function map_rule(string $location_type, array $rule): ?array {
        $parts = explode(':', (string) ($rule['name'] ?? ''), 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$type, $subtype] = $parts;
        $value = (string) ($rule['value'] ?? 'all');
        $all = $value === 'all' || $value === '';

        if ($location_type === 'singular') {
            if ($type === 'general') {
                return ['target' => 'singular', 'value' => '0'];
            }
            // "{post_type}:post" picks posts; "{post_type}:{taxonomy}" filters
            // by term, which ThinkRank has no singular target for.
            if ($subtype !== 'post') {
                return null;
            }

            return $all
                ? ['target' => 'post_type', 'value' => $type]
                : ['target' => 'singular', 'value' => (string) (int) $value];
        }

        if ($type === 'general') {
            return $subtype === 'all' || $subtype === '' ? ['target' => 'archive', 'value' => 'any'] : null;
        }
        if ($subtype === 'archive') {
            return ['target' => 'archive', 'value' => $type];
        }

        return ['target' => 'taxonomy', 'value' => $subtype . ':' . ($all ? '0' : (string) (int) $value)];
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render one schema to a JSON-LD string, or null when nothing is left.
     *
     * Mirrors PropParser::parse() (the `@type`, the `@id`, the custom key/value
     * fields) and SchemaRenderer::render() (variables, Cleaner rules).
     *
     * @param array $schema  Schema
     * @param array $context Variable data tree
     */
    private function render_json(array $schema, array $context): ?string {
        $type = (string) ($schema['type'] ?? '');

        if ($type === 'CustomJsonLd') {
            $nodes = $this->custom_json_ld($schema, $context);
        } else {
            $node = $this->clean($this->render_value($this->normalize_clones($this->parse_props($schema, $context)), $context), true);
            $nodes = $node === [] ? [] : [$node];
        }

        $nodes = array_values(array_filter($nodes, static fn($n): bool => is_array($n) && !empty($n['@type'])));
        if (empty($nodes)) {
            return null;
        }

        $document = count($nodes) === 1
            ? ['@context' => 'https://schema.org'] + $nodes[0]
            : ['@context' => 'https://schema.org', '@graph' => $nodes];

        $json = wp_json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : null;
    }

    /**
     * The builder's props as one node: `@type`, the `@id` and the custom
     * key/value fields folded in (dotted keys nest).
     *
     * @param array $schema  Schema
     * @param array $context Variable data tree
     */
    private function parse_props(array $schema, array $context): array {
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $custom = is_array($fields['custom'] ?? null) ? $fields['custom'] : [];
        unset($fields['_label'], $fields['custom']);

        $id = '';
        $extra = [];
        foreach ($custom as $row) {
            $key = is_array($row) ? trim((string) ($row['key'] ?? '')) : '';
            $value = is_array($row) ? ($row['value'] ?? '') : '';
            if ($key === '' || $value === '' || $value === null) {
                continue;
            }
            if ($key === '@id') {
                $id = (string) $value;
                continue;
            }
            $extra[$key] = $value;
        }

        if ($id === '') {
            $id = (string) ($fields['@id'] ?? '{{ current.url }}#{{ id }}');
        }
        $fields['@id'] = str_replace('{{ id }}', $this->reference_id($schema), $id);

        $node = array_merge(['@type' => (string) ($schema['type'] ?? '')], $fields);
        foreach ($extra as $path => $value) {
            $this->set_path($node, (string) $path, $value);
        }

        return $node;
    }

    /**
     * Turn Slim SEO Pro's stored clone maps into lists, as its renderer does.
     *
     * A clone map is an array, not a list, and has no `@type` of its own, so
     * left as it is clean() would drop it as an untyped nested object — which
     * removed every repeatable property and every FAQPage outright (#896).
     * Only builder fields are touched; Custom JSON-LD carries real JSON.
     *
     * @param array $node  Node or nested value
     * @param int   $depth Recursion depth
     * @return array
     */
    private function normalize_clones(array $node, int $depth = 0): array {
        if ($depth > self::MAX_DEPTH) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (is_string($key) && in_array($key, self::CLONEABLE_FIELDS, true)
                && !self::is_list($value) && !isset($value['@type']) && !isset($value['@id'])) {
                $value = array_values($value);
            }
            $node[$key] = $this->normalize_clones($value, $depth + 1);
        }

        return $node;
    }

    /**
     * Nodes from a CustomJsonLd schema: the JSON between the first `{` and the
     * last `}` (users paste whole <script> tags), its `@graph` unwrapped.
     *
     * @param array $schema  Schema
     * @param array $context Variable data tree
     * @return array<int,array>
     */
    private function custom_json_ld(array $schema, array $context): array {
        $code = (string) ($schema['fields']['code'] ?? '');
        $start = strpos($code, '{');
        $end = strrpos($code, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($code, $start, $end - $start + 1), true);
        if (!is_array($decoded)) {
            return [];
        }
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            $decoded = $decoded['@graph'];
        }
        $nodes = array_key_exists('@type', $decoded) || !self::is_list($decoded) ? [$decoded] : $decoded;

        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            unset($node['@context']);
            $node = $this->clean($this->render_value($node, $context), true);
            if ($node !== []) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * Resolve variables through a value, recursively.
     *
     * @param mixed  $value   Prop value
     * @param array  $context Variable data tree
     * @param string $key     Prop key (decides numeric conversion)
     * @param int    $depth   Recursion depth
     * @return mixed
     */
    private function render_value($value, array $context, string $key = '', int $depth = 0) {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->render_value($v, $context, is_string($k) ? $k : $key, $depth + 1);
            }

            return $out;
        }
        if (!is_string($value)) {
            return $value;
        }

        if (strpos($value, '{{') === false) {
            return $this->normalize($value, $key);
        }

        preg_match_all('/\{\{\s*([^}\s]+?)\s*\}\}/', $value, $matches);

        // One variable standing alone may resolve to a list (categories,
        // images) or to a schema reference; keep its shape.
        if (count($matches[0]) === 1 && trim($value) === $matches[0][0]) {
            $resolved = $this->lookup($context, $matches[1][0]);
            if (is_array($resolved)) {
                return $resolved;
            }

            return $this->normalize((string) ($resolved ?? ''), $key);
        }

        $replacements = [];
        foreach ($matches[0] as $i => $token) {
            $resolved = $this->lookup($context, $matches[1][$i]);
            $replacements[$token] = is_array($resolved)
                ? implode(', ', array_filter($resolved, 'is_scalar'))
                : (string) ($resolved ?? '');
        }

        return $this->normalize(strtr($value, $replacements), $key);
    }

    /**
     * Slim SEO Pro's Normalizer: tags out, whitespace collapsed, numeric text
     * to a number except where the field is a string by nature.
     *
     * @param string $text Value
     * @param string $key  Prop key
     * @return string|int|float
     */
    private function normalize(string $text, string $key) {
        $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));

        if ($text !== '' && is_numeric($text) && !in_array($key, self::STRING_FIELDS, true)) {
            return $text + 0;
        }

        return $text;
    }

    /**
     * Drop what Slim SEO's Cleaner drops, and what ThinkRank Pro's Repository
     * would reject: empty values, untyped nested objects, URL properties that
     * are not absolute http(s) URLs, a FAQ without answered questions, a
     * rating without a value and a count, and a node with nothing but its
     * `@type` and `@id`.
     *
     * @param mixed $node    Rendered value
     * @param bool  $is_root Whether this is a top-level node
     * @return mixed
     */
    private function clean($node, bool $is_root = false) {
        if (!is_array($node)) {
            return $node;
        }

        $is_list = self::is_list($node);
        $out = [];
        foreach ($node as $key => $value) {
            if (in_array($key, self::URL_PROPS, true) && !is_array($value)) {
                $value = $this->is_url($value) ? $value : null;
            } elseif (in_array($key, self::URL_PROPS, true) && self::is_list($value)) {
                $value = array_values(array_filter($value, fn($v): bool => is_array($v) || $this->is_url($v)));
            }

            $value = $this->clean($value);

            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value) && !self::is_list($value) && empty($value['@type'])) {
                // ThinkRank Pro requires a type on every nested object.
                continue;
            }
            if (is_array($value) && !self::is_list($value) && $key !== '' && $this->only_identity($value) && empty($value['@id'])) {
                continue;
            }
            if ($key === 'aggregateRating' && is_array($value)
                && (empty($value['ratingValue']) || (empty($value['reviewCount']) && empty($value['ratingCount'])))) {
                continue;
            }
            $out[$key] = $value;
        }

        if ($is_list) {
            // A repeated value whose variable resolved to several values
            // (`{{ post.categories }}`) is merged into the one list, as Slim
            // SEO Pro's Arr::flatten() does.
            $flat = [];
            foreach ($out as $value) {
                if (is_array($value) && self::is_list($value) && $value === array_filter($value, 'is_scalar')) {
                    array_push($flat, ...$value);
                } else {
                    $flat[] = $value;
                }
            }
            $out = $flat;
        }

        if (!$is_list && ($out['@type'] ?? '') === 'FAQPage') {
            $questions = array_values(array_filter(
                is_array($out['mainEntity'] ?? null) ? $out['mainEntity'] : [],
                static fn($q): bool => is_array($q) && !empty($q['name']) && !empty($q['acceptedAnswer']['text'])
            ));
            if (empty($questions)) {
                return [];
            }
            $out['mainEntity'] = $questions;
        }

        if ($is_root && !$is_list && $this->only_identity($out)) {
            return [];
        }

        return $out;
    }

    /**
     * array_is_list() without requiring PHP 8.1.
     *
     * @param array $value Array
     */
    private static function is_list(array $value): bool {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Whether a node holds nothing but `@type` / `@id`.
     *
     * @param array $node Node
     */
    private function only_identity(array $node): bool {
        return empty(array_diff(array_keys($node), ['@type', '@id']));
    }

    /**
     * Whether a value is an absolute http(s) URL.
     *
     * @param mixed $value Candidate
     */
    private function is_url($value): bool {
        return is_string($value)
            && (bool) filter_var($value, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) wp_parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    // -------------------------------------------------------------------------
    // Data trees
    // -------------------------------------------------------------------------

    /**
     * Data for a schema that renders the same everywhere.
     */
    private function site_context(): array {
        return [
            'site'    => $this->site_data(),
            'current' => ['url' => home_url('/')],
            'schemas' => $this->references([], home_url('/'), true),
        ];
    }

    /**
     * Data for one post, mirroring SlimSEOPro\Schema\Renderer\Data::collect()
     * as that post's own page would see it. `user.*` is the visitor and
     * resolves to nothing, as it does for one who is not logged in.
     *
     * @param int   $post_id      Post ID
     * @param array $post_schemas The post's own schemas, for references
     * @param array $slim_seo     The post's rendered Slim SEO fields
     */
    private function post_context(int $post_id, array $post_schemas, array $slim_seo): array {
        $post = get_post($post_id);
        if (!$post) {
            return $this->site_context();
        }

        $url = (string) get_permalink($post);
        $content = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags(strip_shortcodes((string) $post->post_content))));

        $tax = [];
        if (function_exists('get_object_taxonomies')) {
            foreach ((array) get_object_taxonomies((string) $post->post_type, 'names') as $taxonomy) {
                $tax[str_replace('-', '_', (string) $taxonomy)] = $this->term_names($post_id, (string) $taxonomy);
            }
        }

        $custom_field = [];
        foreach ((array) get_post_meta($post_id) as $meta_key => $values) {
            // Any plugin's meta can sit on the post: never instantiate objects
            // out of it. Arrays are kept so `custom_field.a.b` paths resolve.
            $raw = is_array($values) ? reset($values) : '';
            $custom_field[(string) $meta_key] = is_string($raw) && is_serialized($raw)
                ? Safe_Unserializer::unserialize($raw, '')
                : $raw;
        }

        $current_url = trailingslashit(strtok(strtok($url, '#'), '?') ?: $url);

        return [
            'post'     => [
                'ID'            => $post_id,
                'title'         => (string) $post->post_title,
                'excerpt'       => (string) $post->post_excerpt,
                'content'       => $content,
                'url'           => $url,
                'slug'          => (string) ($post->post_name ?? ''),
                'date'          => $this->iso_date((string) ($post->post_date_gmt ?? '')),
                'modified_date' => $this->iso_date((string) ($post->post_modified_gmt ?? '')),
                'thumbnail'     => function_exists('get_the_post_thumbnail_url') ? (string) get_the_post_thumbnail_url($post_id, 'full') : '',
                'comment_count' => (int) ($post->comment_count ?? 0),
                'tags'          => $this->term_names($post_id, 'post_tag'),
                'categories'    => $this->term_names($post_id, 'category'),
                'custom_field'  => $custom_field,
                'tax'           => $tax,
                'word_count'    => str_word_count($content),
            ],
            'author'   => $this->author_data((int) ($post->post_author ?? 0)),
            'user'     => [],
            'term'     => [],
            'site'     => $this->site_data(),
            'current'  => ['url' => $current_url, 'title' => (string) ($slim_seo['title'] ?? $post->post_title)],
            'slim_seo' => $slim_seo,
            'schemas'  => $this->references($post_schemas, $current_url, false, $url),
        ];
    }

    /**
     * `{{ schemas.<id> }}` targets: a reference carrying the node's `@type`
     * (ThinkRank Pro requires one on every nested object) and the `@id`
     * ThinkRank's own graph uses for the nodes it emits, or Slim SEO's
     * `{current url}#{id}` for the rest.
     *
     * @param array  $post_schemas The post's own schemas
     * @param string $current_url  The page the reference renders on
     * @param bool   $site_level   Whether the entry renders site-wide
     * @param string $permalink    The post's permalink exactly as WordPress
     *                             builds it: Schema_Graph keys its page-level
     *                             ids off that, trailing slash or not
     * @return array<string,array{@id:string,@type:string}>
     */
    private function references(array $post_schemas, string $current_url, bool $site_level, string $permalink = ''): array {
        $ids = $this->global_ids;
        foreach ($this->active($post_schemas) as $schema) {
            $ids[$this->reference_id($schema)] = (string) ($schema['type'] ?? '');
        }

        $page = $permalink !== '' ? $permalink : $current_url;
        $native = [
            'WebSite'        => home_url('/#website'),
            'Organization'   => home_url('/#organization'),
            'Person'         => home_url('/#person'),
            'WebPage'        => $page . '#webpage',
            'BreadcrumbList' => $page . '#breadcrumb',
        ];

        $references = [];
        foreach ($ids as $id => $type) {
            if ($type === '') {
                continue;
            }
            $references[$id] = [
                '@type' => $type,
                '@id'   => $native[$type] ?? (($site_level ? home_url('/') : $current_url) . '#' . $id),
            ];
        }

        return $references;
    }

    /**
     * `site.*` (Renderer\Data::get_site_data()).
     */
    private function site_data(): array {
        return [
            'title'       => (string) get_bloginfo('name'),
            'description' => (string) get_bloginfo('description'),
            'url'         => home_url('/'),
            'language'    => str_replace('_', '-', (string) get_locale()),
            'icon'        => function_exists('get_site_icon_url') ? (string) get_site_icon_url() : '',
        ];
    }

    /**
     * `author.*` (Renderer\Data::get_user()).
     *
     * @param int $user_id User ID
     */
    private function author_data(int $user_id): array {
        $user = $user_id > 0 ? get_userdata($user_id) : false;
        if (!$user) {
            return [];
        }

        return [
            'ID'           => (int) $user->ID,
            'first_name'   => (string) ($user->first_name ?? ''),
            'last_name'    => (string) ($user->last_name ?? ''),
            'display_name' => (string) ($user->display_name ?? ''),
            'nickname'     => (string) ($user->nickname ?? ''),
            'url'          => (string) ($user->user_url ?? ''),
            'nicename'     => (string) ($user->user_nicename ?? ''),
            'description'  => (string) ($user->description ?? ''),
            'posts_url'    => function_exists('get_author_posts_url') ? (string) get_author_posts_url((int) $user->ID) : '',
            'avatar'       => function_exists('get_avatar_url') ? (string) get_avatar_url((int) $user->ID) : '',
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Active schemas only (a missing `active` flag means active), keyed.
     *
     * @param array $schemas Schemas
     * @return array<string,array>
     */
    private function active(array $schemas): array {
        $active = [];
        foreach ($schemas as $key => $schema) {
            if (!is_array($schema)) {
                continue;
            }
            $flag = $schema['active'] ?? true;
            if ($flag === true || $flag === 'true' || $flag === 1 || $flag === '1') {
                $active[(string) $key] = $schema;
            }
        }

        return $active;
    }

    /**
     * The id other schemas reference this one by: its label, or its type,
     * sanitised the way PropParser::sanitize_id() does it.
     *
     * @param array $schema Schema
     */
    private function reference_id(array $schema): string {
        $label = (string) ($schema['fields']['_label'] ?? ($schema['type'] ?? ''));
        $id = sanitize_title($label);
        $id = (string) preg_replace('/[^a-z0-9_]/', '_', $id);
        $id = (string) preg_replace('/[ _]{2,}/', '_', $id);
        $id = trim($id, '_');
        $id = (string) preg_replace('/^\d+/', '', $id);

        return trim($id, '_');
    }

    /**
     * A schema's display name: its label, else its type.
     *
     * @param array $schema Schema
     */
    private function label(array $schema): string {
        $label = trim((string) ($schema['fields']['_label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $type = (string) ($schema['type'] ?? '');

        return $type === 'CustomJsonLd' ? 'Custom JSON-LD' : ($type !== '' ? $type : 'Schema');
    }

    /**
     * Walk a dotted path through nested arrays.
     *
     * @param array  $context Data tree
     * @param string $path    e.g. "post.custom_field.price"
     * @return mixed|null
     */
    private function lookup(array $context, string $path) {
        $node = $context;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Set a dotted path in a nested array (Arr::undot()).
     *
     * @param array  $node  Target
     * @param string $path  e.g. "offers.price"
     * @param mixed  $value Value
     */
    private function set_path(array &$node, string $path, $value): void {
        $ref = &$node;
        foreach (explode('.', $path) as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    /**
     * Term names for a post in one taxonomy.
     *
     * @param int    $post_id  Post ID
     * @param string $taxonomy Taxonomy
     * @return string[]
     */
    private function term_names(int $post_id, string $taxonomy): array {
        if (!function_exists('get_the_terms')) {
            return [];
        }
        $terms = get_the_terms($post_id, $taxonomy);

        return is_array($terms) ? array_values(array_map(static fn($t): string => (string) $t->name, $terms)) : [];
    }

    /**
     * A stored GMT datetime as ISO 8601, as Slim SEO Pro prints dates.
     *
     * @param string $gmt MySQL datetime (GMT)
     */
    private function iso_date(string $gmt): string {
        if ($gmt === '' || strpos($gmt, '0000-00-00') === 0) {
            return '';
        }
        $ts = strtotime($gmt . ' UTC');

        return $ts ? gmdate('c', $ts) : '';
    }
}
