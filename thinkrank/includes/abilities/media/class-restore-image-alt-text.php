<?php
/**
 * Restore one reviewed alt change only while its saved value still matches.
 *
 * @package ThinkRank\Abilities\Media
 * @since 2.17.0
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Media;

use ThinkRank\SEO\Image_Alt_Store;

if (!defined('ABSPATH')) {
    exit;
}

class Restore_Image_Alt_Text extends Image_Alt_Change_Ability_Base {
    public function __construct() {
        $this->id = 'thinkrank/restore-image-alt-text';
        $this->label = __('Restore Reviewed ThinkRank Image Alt Text', 'thinkrank');
        $this->description = __('Undo one reviewed Media Library alt change using its authenticated restoration handle and a fresh expected fingerprint from get-image-alt-preview. Ask the user before restoring. The handle expires after 30 days and is bound to its WordPress user, site and image. Refuses a current alt value that differs from the saved change, invalid handles and stale previews. Restores the exact prior value, including whether an empty metadata row existed; does not undo AI charges or change embedded page markup.', 'thinkrank');
    }

    public function get_input_schema() {
        $schema = parent::get_input_schema();
        $schema['required'][] = 'restore_token';
        $schema['properties']['restore_token'] = ['type' => 'string', 'minLength' => 1, 'maxLength' => 12000];
        return $schema;
    }

    public function execute($input) {
        $input = (array) $input;
        $id = $input['attachment_id'] ?? null;
        $token = $input['restore_token'] ?? null;
        if (!is_int($id) || $id < 1 || !is_string($token)) {
            return new \WP_Error('thinkrank_alt_undo_invalid', __('Choose an image and its restoration handle.', 'thinkrank'), ['status' => 400]);
        }
        $payload = (new Image_Alt_Store())->open_restoration($id, $token);
        return is_wp_error($payload) ? $payload : $this->change($input, $payload);
    }
}
