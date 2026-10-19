<?php
/**
 * Chat Completions message content as plain text.
 *
 * @package ThinkRank\AI
 * @since 2.17.1
 */

declare(strict_types=1);

namespace ThinkRank\AI;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads `choices[0].message.content` the same way for every caller.
 *
 * The Chat Completions shape lets `content` be a string or an array of typed
 * parts. OpenAI answers with a string, but a compatible server can answer with
 * parts: a reasoning model on Mistral's API sends
 * `[{"type":"thinking",...},{"type":"text","text":"..."}]`, and OpenRouter
 * passes such answers through. A parser that hands that array to strpos() or
 * trim() throws a TypeError, which no `catch (\Exception)` stops, so the
 * request ended in a fatal error instead of a message (#1042).
 *
 * @since 2.17.1
 */
final class Chat_Content {

    /**
     * Part types that carry the answer. Anything else (thinking, reasoning,
     * image, refusal) is not part of the text the parsers read.
     */
    private const TEXT_PART_TYPES = ['text', 'output_text'];

    /**
     * The answer text of a message's `content`, whatever its shape.
     *
     * A string comes back as is. An array of parts gives the text of its text
     * parts, joined without a separator so JSON split across two parts still
     * decodes. Reasoning parts are left out. Null, or anything else without
     * text, gives an empty string.
     *
     * @since 2.17.1
     *
     * @param mixed $content `choices[0].message.content` as decoded.
     * @return string
     */
    public static function text($content): string {
        if (is_string($content)) {
            return $content;
        }

        if (is_int($content) || is_float($content)) {
            return (string) $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (is_string($part)) {
                $text .= $part;
                continue;
            }

            if (!is_array($part) || !is_string($part['text'] ?? null)) {
                continue;
            }

            // A part with no type is read as text; a typed one only when the
            // type says it is the answer.
            $type = $part['type'] ?? 'text';
            if (in_array($type, self::TEXT_PART_TYPES, true)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }
}
