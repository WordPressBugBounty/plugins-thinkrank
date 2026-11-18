<?php

/**
 * The organizations and brands around the site's own Organization.
 *
 * ThinkRank described one organization and nothing it belongs to. A site run by
 * a subsidiary, a group with divisions, or a company shipping several named
 * products had no way to say so, so a knowledge graph saw an isolated entity
 * where the real structure is a tree (#768).
 *
 * Three properties, and the distinction between them is the part that is easy
 * to get wrong:
 *
 *  - `parentOrganization` — the company this one belongs to. One, or none.
 *  - `subOrganization` — divisions or separate legal entities *under* this one.
 *    Not products. A company's apps are not its sub-organizations, however they
 *    are marketed, and modelling them that way tells a crawler there are six
 *    companies where there is one.
 *  - `brand` — the names this organization sells under. This is where a product
 *    line belongs. A product's own node points at its brand; the organization
 *    lists the brands it owns.
 *
 * Parent and subs are emitted as their own nodes with stable `@id`s, because
 * each is an organization in its own right that a crawler may want to resolve
 * and that other nodes may want to reference. Brands are emitted inline on the
 * organization, because a Brand carrying only a name and a logo gains nothing
 * from a graph slot of its own — but each still gets an `@id`, so a Product
 * node can point at one rather than repeating the name.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads, validates and emits the organization's relationships.
 *
 * @since 2.22.0
 */
class Organization_Relations {

    /**
     * Setting key holding the parent organization.
     *
     * @var string
     */
    public const SETTING_PARENT = 'organization_parent';

    /**
     * Setting key holding the sub-organizations.
     *
     * @var string
     */
    public const SETTING_SUBS = 'organization_subs';

    /**
     * Setting key holding the brands.
     *
     * @var string
     */
    public const SETTING_BRANDS = 'organization_brands';

    /**
     * Most sub-organizations one site may declare.
     *
     * A cap rather than a judgement about company size: every entry becomes a
     * node in every page's graph, and a list long enough to matter is a sign
     * the user has modelled products as divisions, which is the mistake this
     * class exists to steer away from.
     *
     * @var int
     */
    public const MAX_SUBS = 25;

    /**
     * Most brands one site may declare.
     *
     * @var int
     */
    public const MAX_BRANDS = 50;

    /**
     * Most `sameAs` URLs one entry may carry.
     *
     * @var int
     */
    public const MAX_SAME_AS = 10;

    /**
     * The three setting keys, for the places that enumerate settings.
     *
     * @since 2.22.0
     * @return string[]
     */
    public static function setting_keys(): array {
        return [self::SETTING_PARENT, self::SETTING_SUBS, self::SETTING_BRANDS];
    }

    /**
     * The stored default for each key.
     *
     * @since 2.22.0
     * @return array<string, array>
     */
    public static function defaults(): array {
        return [
            self::SETTING_PARENT => [],
            self::SETTING_SUBS   => [],
            self::SETTING_BRANDS => [],
        ];
    }

    /**
     * JSON Schema for the three keys, shared by both MCP abilities.
     *
     * Declared once so the reader and the writer cannot describe the same
     * setting differently, which is how an agent ends up posting a shape the
     * store then drops.
     *
     * @since 2.22.0
     * @return array<string, array<string, mixed>>
     */
    public static function json_schema_properties(): array {
        $same_as = [
            'type'        => 'array',
            'items'       => ['type' => 'string'],
            'description' => __('Absolute URLs identifying the same entity elsewhere, such as a Wikipedia page, Crunchbase profile or LinkedIn company page. Relative URLs are dropped.', 'thinkrank'),
        ];

        return [
            self::SETTING_PARENT => [
                'type'        => 'object',
                'description' => __('The company this organization belongs to. Pass an empty object to clear it. Emitted as parentOrganization, pointing at its own node in the graph.', 'thinkrank'),
                'properties'  => [
                    'name'    => [
                        'type'        => 'string',
                        'description' => __('Required. An entry with no name is dropped.', 'thinkrank'),
                    ],
                    'url'     => ['type' => 'string'],
                    'same_as' => $same_as,
                ],
            ],
            self::SETTING_SUBS   => [
                'type'        => 'array',
                'maxItems'    => self::MAX_SUBS,
                'description' => __('Divisions or separate legal entities under this organization, emitted as subOrganization. Not products: a company\'s apps and product lines are brands, and filing them here tells a crawler there are several companies where there is one. An entry whose URL is this site is dropped.', 'thinkrank'),
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'name'    => [
                            'type'        => 'string',
                            'description' => __('Required, and unique within the list. Two entries with the same name would claim one identity.', 'thinkrank'),
                        ],
                        'url'     => ['type' => 'string'],
                        'logo'    => ['type' => 'string'],
                        'same_as' => $same_as,
                    ],
                ],
            ],
            self::SETTING_BRANDS => [
                'type'        => 'array',
                'maxItems'    => self::MAX_BRANDS,
                'description' => __('The names this organization sells under, emitted as brand. This is where product lines and apps belong. Each gets a stable @id so a Product node can reference it.', 'thinkrank'),
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'name' => [
                            'type'        => 'string',
                            'description' => __('Required, and unique within the list.', 'thinkrank'),
                        ],
                        'url'  => ['type' => 'string'],
                        'logo' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Sanitise one of the three settings by key.
     *
     * @since 2.22.0
     * @param string $key   One of the SETTING_* constants.
     * @param mixed  $value Submitted value.
     * @return array Sanitised value, or [] when the key is not one of ours.
     */
    public static function sanitize_setting(string $key, $value): array {
        switch ($key) {
            case self::SETTING_PARENT:
                return self::sanitize_parent($value);
            case self::SETTING_SUBS:
                return self::sanitize_subs($value);
            case self::SETTING_BRANDS:
                return self::sanitize_brands($value);
        }

        return [];
    }

    /**
     * Sanitise the parent organization.
     *
     * @since 2.22.0
     * @param mixed $value Submitted value.
     * @return array{name?: string, url?: string, same_as?: string[]} Empty when unusable.
     */
    public static function sanitize_parent($value): array {
        if (!is_array($value)) {
            return [];
        }

        $entry = self::sanitize_entry($value, ['name', 'url', 'same_as']);

        return null === $entry ? [] : $entry;
    }

    /**
     * Sanitise the sub-organization list.
     *
     * @since 2.22.0
     * @param mixed $value Submitted value.
     * @return array<int, array<string, mixed>>
     */
    public static function sanitize_subs($value): array {
        return self::sanitize_list($value, ['name', 'url', 'logo', 'same_as'], self::MAX_SUBS);
    }

    /**
     * Sanitise the brand list.
     *
     * @since 2.22.0
     * @param mixed $value Submitted value.
     * @return array<int, array<string, mixed>>
     */
    public static function sanitize_brands($value): array {
        return self::sanitize_list($value, ['name', 'url', 'logo'], self::MAX_BRANDS);
    }

    /**
     * Attach the relationships to the site's Organization node.
     *
     * Reads the settings itself rather than taking them from the caller,
     * because two different producers build the Organization node — the Schema
     * Manager's deployed snapshot and the Site Identity fallback — and only one
     * of them ever passes through the settings. Doing it where the graph
     * assigns the canonical `@id` is the one point both reach.
     *
     * @since 2.22.0
     * @param array $organization The Organization node, with its `@id` assigned.
     * @param array $settings     Schema settings.
     * @return array{organization: array, nodes: array<int, array>} The node, and the extra graph nodes.
     */
    public static function attach(array $organization, array $settings): array {
        $organization_id = (string) ($organization['@id'] ?? '');
        $nodes           = [];

        $parent = self::sanitize_parent($settings[self::SETTING_PARENT] ?? []);
        if ([] !== $parent) {
            $parent_id = self::mint_id('parent-organization');

            // A parent that is this organization is a loop, not a hierarchy.
            if ($parent_id !== $organization_id && !self::is_self($parent, $organization)) {
                $organization['parentOrganization'] = ['@id' => $parent_id];

                $node = self::organization_node($parent_id, $parent);
                if ('' !== $organization_id) {
                    $node['subOrganization'] = [['@id' => $organization_id]];
                }

                $nodes[] = $node;
            }
        }

        $sub_refs = [];
        foreach (self::sanitize_subs($settings[self::SETTING_SUBS] ?? []) as $sub) {
            $sub_id = self::mint_id('suborganization-' . sanitize_title((string) $sub['name']));

            if ($sub_id === $organization_id || self::is_self($sub, $organization)) {
                continue;
            }

            $node = self::organization_node($sub_id, $sub);
            if ('' !== $organization_id) {
                $node['parentOrganization'] = ['@id' => $organization_id];
            }

            $nodes[]    = $node;
            $sub_refs[] = ['@id' => $sub_id];
        }

        if ([] !== $sub_refs) {
            $organization['subOrganization'] = $sub_refs;
        }

        $brands = [];
        foreach (self::sanitize_brands($settings[self::SETTING_BRANDS] ?? []) as $brand) {
            $node = [
                '@type' => 'Brand',
                '@id'   => self::mint_id('brand-' . sanitize_title((string) $brand['name'])),
                'name'  => $brand['name'],
            ];

            if ('' !== ($brand['url'] ?? '')) {
                $node['url'] = $brand['url'];
            }
            if ('' !== ($brand['logo'] ?? '')) {
                $node['logo'] = self::image_object($brand['logo'], (string) $brand['name']);
            }

            $brands[] = $node;
        }

        if ([] !== $brands) {
            // Merged rather than assigned: an Organization node built elsewhere
            // may already carry a brand, and replacing it would silently drop
            // whatever that producer knew.
            $existing = $organization['brand'] ?? [];
            $existing = self::as_list($existing);

            $organization['brand'] = array_merge($existing, $brands);
        }

        return [
            'organization' => $organization,
            'nodes'        => $nodes,
        ];
    }

    /**
     * Build an Organization node from a sanitised entry.
     *
     * @param string $id    Node `@id`.
     * @param array  $entry Sanitised entry.
     * @return array
     */
    private static function organization_node(string $id, array $entry): array {
        $node = [
            '@type' => 'Organization',
            '@id'   => $id,
            'name'  => $entry['name'],
        ];

        if ('' !== ($entry['url'] ?? '')) {
            $node['url'] = $entry['url'];
        }
        if ('' !== ($entry['logo'] ?? '')) {
            $node['logo'] = self::image_object($entry['logo'], (string) $entry['name']);
        }
        if (!empty($entry['same_as'])) {
            $node['sameAs'] = $entry['same_as'];
        }

        return $node;
    }

    /**
     * An ImageObject for a logo URL.
     *
     * @param string $url  Absolute image URL.
     * @param string $name Owning entity name, for the caption.
     * @return array
     */
    private static function image_object(string $url, string $name): array {
        return [
            '@type'      => 'ImageObject',
            'url'        => $url,
            'contentUrl' => $url,
            'caption'    => '' === $name ? '' : $name . ' Logo',
        ];
    }

    /**
     * Whether an entry is really the site's own organization.
     *
     * A self-reference is worse than useless: it tells a crawler the entity is
     * its own parent or its own division, which is a contradiction rather than
     * a missing fact.
     *
     * @param array $entry        Sanitised entry.
     * @param array $organization The site's Organization node.
     * @return bool
     */
    private static function is_self(array $entry, array $organization): bool {
        $entry_url = self::normalise_url((string) ($entry['url'] ?? ''));

        if ('' === $entry_url) {
            return false;
        }

        foreach ([(string) ($organization['url'] ?? ''), home_url('/')] as $own) {
            if ($entry_url === self::normalise_url($own)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare URLs without being fooled by a trailing slash or scheme case.
     *
     * @param string $url URL.
     * @return string Comparable form, or '' when there is nothing to compare.
     */
    private static function normalise_url(string $url): string {
        $url = trim($url);

        if ('' === $url) {
            return '';
        }

        return strtolower(rtrim($url, '/'));
    }

    /**
     * A stable `@id` under the site's home URL.
     *
     * Slashed, matching the graph's own `home_url('/#organization')`. The
     * un-slashed form mints a different IRI, which is how a reference to the
     * organization came to resolve to nothing before (#116).
     *
     * @param string $fragment Fragment, without the hash.
     * @return string
     */
    private static function mint_id(string $fragment): string {
        return home_url('/#' . $fragment);
    }

    /**
     * Sanitise a list of entries, dropping the unusable and the duplicated.
     *
     * @param mixed    $value  Submitted list.
     * @param string[] $fields Fields to keep.
     * @param int      $max    Cap.
     * @return array<int, array<string, mixed>>
     */
    private static function sanitize_list($value, array $fields, int $max): array {
        if (!is_array($value)) {
            return [];
        }

        $out  = [];
        $seen = [];

        foreach ($value as $row) {
            if (count($out) >= $max) {
                break;
            }

            $entry = self::sanitize_entry(is_array($row) ? $row : [], $fields);

            if (null === $entry) {
                continue;
            }

            // Two entries with the same name would mint the same `@id`, and the
            // graph would resolve that collision by inventing a second identity
            // for what the user typed as one thing.
            $key = sanitize_title((string) $entry['name']);

            if ('' === $key || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[]      = $entry;
        }

        return $out;
    }

    /**
     * Sanitise one entry.
     *
     * @param array    $row    Submitted entry.
     * @param string[] $fields Fields to keep.
     * @return array<string, mixed>|null Null when the entry carries no name.
     */
    private static function sanitize_entry(array $row, array $fields): ?array {
        $name = isset($row['name']) ? sanitize_text_field((string) $row['name']) : '';
        $name = trim($name);

        // The name is the entity. An entry without one has nothing to emit and
        // no stable identity to mint.
        if ('' === $name) {
            return null;
        }

        $entry = ['name' => $name];

        if (in_array('url', $fields, true)) {
            $entry['url'] = self::absolute_url($row['url'] ?? '');

            // An entry that IS this site is a loop, not a relationship, and
            // storing it would report a save of something that can never be
            // emitted. attach() applies the same test against the
            // organization's own URL, which can differ from the site's.
            if ('' !== $entry['url']
                && self::normalise_url($entry['url']) === self::normalise_url(home_url('/'))) {
                return null;
            }
        }

        if (in_array('logo', $fields, true)) {
            $entry['logo'] = self::absolute_url($row['logo'] ?? '');
        }

        if (in_array('same_as', $fields, true)) {
            $entry['same_as'] = self::absolute_url_list($row['same_as'] ?? []);
        }

        return $entry;
    }

    /**
     * An absolute http(s) URL, or ''.
     *
     * Relative and protocol-relative values are dropped rather than guessed at:
     * schema URLs are resolved by crawlers with no knowledge of this site's
     * base, so a relative one is not a shorter way of saying the same thing.
     *
     * @param mixed $value Submitted URL.
     * @return string
     */
    private static function absolute_url($value): string {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        // The scheme has to be tested BEFORE esc_url_raw(), which prepends
        // http:// to anything without one. Checking afterwards accepted
        // `not-a-url` and `relative/path`, storing them as `http://not-a-url`
        // and `http://relative/path` — an invalid IRI in the graph, reported to
        // the caller as a successful save.
        if (1 !== preg_match('#^https?://#i', $value)) {
            return '';
        }

        $url = esc_url_raw($value);

        if ('' === $url) {
            return '';
        }

        return '' === (string) wp_parse_url($url, PHP_URL_HOST) ? '' : $url;
    }

    /**
     * A list of absolute URLs, deduplicated and capped.
     *
     * @param mixed $value Submitted list.
     * @return string[]
     */
    private static function absolute_url_list($value): array {
        if (is_string($value)) {
            $value = preg_split('/[\r\n]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $urls = [];

        foreach ($value as $candidate) {
            if (count($urls) >= self::MAX_SAME_AS) {
                break;
            }

            $url = self::absolute_url($candidate);

            if ('' !== $url && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Wrap a single node in a list, leaving a list alone.
     *
     * @param mixed $value Node or list of nodes.
     * @return array<int, mixed>
     */
    private static function as_list($value): array {
        if (!is_array($value) || [] === $value) {
            return [];
        }

        return isset($value['@type']) || isset($value['@id']) ? [$value] : array_values($value);
    }
}
