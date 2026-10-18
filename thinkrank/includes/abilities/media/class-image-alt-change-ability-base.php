<?php
/**
 * Shared exact apply/restore path for reviewed Media Library alt text.
 *
 * @package ThinkRank\Abilities\Media
 * @since 2.17.0
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Media;

use ThinkRank\SEO\Image_Alt_Store;
use ThinkRank\SEO\SEO_Analyzer;

if (!defined('ABSPATH')) {
    exit;
}

abstract class Image_Alt_Change_Ability_Base extends Image_Alt_Draft_Ability_Base {
    public function get_annotations() {
        return ['readonly' => false, 'destructive' => false, 'idempotent' => false, 'priority' => 1.0, 'openWorldHint' => false];
    }

    public function get_input_schema() {
        $schema = parent::get_input_schema();
        $schema['required'][] = 'expected';
        $schema['properties']['expected'] = $this->expected_property();
        return $schema;
    }

    public function get_output_schema() {
        $properties = [
            'saved' => ['type' => 'boolean', 'enum' => [true]],
            'changed' => ['type' => 'boolean'],
            'image' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => array_keys($this->image_properties()), 'properties' => $this->image_properties(),
            ],
            'metadata_exists' => ['type' => 'boolean'],
            'expected' => $this->expected_property(),
            'restore_token' => [
                'type' => 'string', 'maxLength' => 12000,
                'description' => __('Authenticated restoration handle, valid for 30 days for this WordPress user and site. Empty for an unchanged value. Contains prior alt metadata: retain it only encrypted, never in logs. Pass it with a fresh expected fingerprint to restore-image-alt-text.', 'thinkrank'),
            ],
            'cache' => $this->cache_property(),
        ];
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties];
    }

    /**
     * Validate, compare, save, and verify while the attachment/metadata are locked.
     *
     * @param array      $input   Ability arguments.
     * @param array|null $restore Authenticated restoration payload, or null for a new save.
     * @return array|\WP_Error
     */
    protected function change(array $input, ?array $restore = null) {
        $id = $input['attachment_id'] ?? null;
        $expected = $input['expected'] ?? null;
        if (!is_int($id) || $id < 1 || !is_string($expected) || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $expected)) {
            return new \WP_Error('thinkrank_alt_review_required', __('Choose one image and its current expected fingerprint from get-image-alt-preview.', 'thinkrank'), ['status' => 400]);
        }
        $store = new Image_Alt_Store();
        $result = $store->run($id, function () use ($store, $id, $expected, $input, $restore) {
            $before = $store->state($id);
            if (is_wp_error($before)) {
                return $before;
            }
            $snapshot = $this->draft_snapshot($id);
            if (is_wp_error($snapshot)) {
                return $snapshot;
            }
            if (!hash_equals($snapshot['expected'], $expected)) {
                return new \WP_Error('thinkrank_alt_preview_stale', __('This image changed since review. Read a new preview before saving. No alt text was changed.', 'thinkrank'), ['status' => 409]);
            }
            if (null !== $restore && !hash_equals($restore['after'], Image_Alt_Store::state_digest($before))) {
                return new \WP_Error('thinkrank_alt_restore_stale', __('The stored alt text no longer matches the change being undone. No alt text was changed.', 'thinkrank'), ['status' => 409]);
            }
            $wanted = null !== $restore ? $restore['before'] : ['exists' => true, 'value' => $input['alt_text']];
            $changed = $before !== $wanted;
            $token = $changed ? $store->restoration($id, $before, $wanted) : '';
            if (is_wp_error($token)) {
                return $token;
            }
            if ($changed) {
                // The row/range lock covers missing and empty values too; do
                // not use WordPress's optional previous-value argument here.
                if ($wanted['exists']) {
                    update_post_meta($id, self::ALT_META_KEY, wp_slash($wanted['value']));
                } else {
                    delete_post_meta($id, self::ALT_META_KEY);
                }
                Image_Alt_Store::clear_cache($id);
                $stored = $store->state($id);
                if (is_wp_error($stored) || $stored !== $wanted) {
                    return new \WP_Error('thinkrank_alt_save_unverified', __('The database or a site filter did not store the exact reviewed alt value. The transaction was rolled back; read the image again before retrying.', 'thinkrank'), ['status' => 409]);
                }
                $snapshot = $this->draft_snapshot($id);
                if (is_wp_error($snapshot) || $snapshot['image']['alt_text'] !== $wanted['value']) {
                    return new \WP_Error('thinkrank_alt_save_unverified', __('The saved image could not be read back exactly. The transaction was rolled back.', 'thinkrank'), ['status' => 409]);
                }
            }
            return [
                'saved' => true, 'changed' => $changed, 'image' => $snapshot['image'],
                'metadata_exists' => $wanted['exists'], 'expected' => $snapshot['expected'], 'restore_token' => $token,
                'cache' => ['posts_purged' => 0, 'warnings' => []],
            ];
        });
        if (is_wp_error($result) || !$result['changed']) {
            return $result;
        }
        // Purging is outside the transaction: rendering/network integrations
        // must not extend the database lock or make a committed save disappear.
        try {
            $purge = $this->manager()->purge_alt_caches([$id]);
            $result['cache'] = ['posts_purged' => count($purge['posts']), 'warnings' => $purge['warnings']];
            (new SEO_Analyzer())->flush_cache();
        } catch (\Throwable $error) {
            $result['cache']['warnings'][] = __('Alt text was saved, but not all rendered or analyzer caches could be cleared. Refresh them on the site.', 'thinkrank');
        }
        return $result;
    }
}
