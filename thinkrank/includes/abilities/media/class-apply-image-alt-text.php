<?php
/**
 * Save exactly one reviewed plain-text alt value, with a guarded restoration handle.
 *
 * @package ThinkRank\Abilities\Media
 * @since 2.17.0
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Media;

if (!defined('ABSPATH')) {
    exit;
}

class Apply_Image_Alt_Text extends Image_Alt_Change_Ability_Base {
    public function __construct() {
        $this->id = 'thinkrank/apply-image-alt-text';
        $this->label = __('Save Reviewed ThinkRank Image Alt Text', 'thinkrank');
        $this->description = __('Save one explicitly reviewed alt value in the Media Library, only if its expected metadata fingerprint still matches. Read get-image-alt-preview first and obtain agreement to these exact words. Makes no AI request. Uses a bounded database transaction, verifies the stored value, and returns a 30-day restoration handle. It refuses unsupported storage and ambiguous metadata. A cache purge cannot rewrite alt embedded in page content. Read the image back before reporting success; never retry an unknown outcome automatically.', 'thinkrank');
    }

    public function get_input_schema() {
        $schema = parent::get_input_schema();
        $schema['required'][] = 'alt_text';
        $schema['properties']['alt_text'] = [
            'type' => 'string', 'maxLength' => 1024,
            'description' => __('The exact reviewed plain text. Markup, line breaks and text that WordPress would normalize are refused. An empty string deliberately clears the alt value.', 'thinkrank'),
        ];
        return $schema;
    }

    public function execute($input) {
        $input = (array) $input;
        $text = $input['alt_text'] ?? null;
        // 1,024 valid UTF-8 characters fit the store's 4,096-byte ceiling.
        if (!is_string($text) || mb_strlen($text, 'UTF-8') > 1024 || sanitize_text_field($text) !== $text) {
            return new \WP_Error('thinkrank_alt_invalid_text', __('Review up to 1,024 characters of plain text without markup, line breaks or extra whitespace. No alt text was saved.', 'thinkrank'), ['status' => 422]);
        }
        return $this->change($input);
    }
}
