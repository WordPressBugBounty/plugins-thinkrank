<?php
/**
 * JSON out of an AI answer.
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
 * Finds the JSON in a model's answer text.
 *
 * Every parser used to cut from the first `{` to the last `}`. An open
 * reasoning model (DeepSeek-R1, Qwen3) served by vLLM without a reasoning
 * parser, or by some Ollama and LM Studio builds, writes its reasoning into
 * the answer as `<think>...</think>` before the JSON. When that reasoning
 * mentions a brace, the cut starts inside it and valid JSON after the block
 * fails to parse (#1044). The reasoning is now removed first, and the whole
 * answer is tried as JSON before any slicing.
 *
 * Public and static so ThinkRank Pro's parsers can use the same rules.
 *
 * @since 2.17.1
 */
final class Ai_Json {

    /**
     * Remove inline reasoning from an answer.
     *
     * Keeps only what follows the last closing `</think>` or `</thinking>`.
     * That covers a full `<think>...</think>` block, and also a model whose
     * chat template already opened the block in the prompt, so its answer
     * holds only the closing tag (Qwen3 and DeepSeek-R1 templates do this).
     * Text without a closing tag is returned unchanged.
     *
     * @since 2.17.1
     *
     * @param string $text Answer text.
     * @return string
     */
    public static function strip_reasoning(string $text): string {
        if (false === stripos($text, '</think')) {
            return $text;
        }

        $parts = preg_split('#</think(?:ing)?\s*>#i', $text);
        if (!is_array($parts) || count($parts) < 2) {
            return $text;
        }

        return ltrim((string) end($parts));
    }

    /**
     * The JSON object text in an answer, or '' when there is none.
     *
     * Reasoning is stripped first. When the rest is one JSON object or array
     * it is returned whole; otherwise the text from the first `{` to the last
     * `}` is, the way every parser always cut it.
     *
     * @since 2.17.1
     *
     * @param string $text Answer text.
     * @return string
     */
    public static function object_text(string $text): string {
        return self::json_text($text, '{', '}');
    }

    /**
     * The JSON array text in an answer, or '' when there is none.
     *
     * Same rules as object_text(), for an answer that is a list.
     *
     * @since 2.17.1
     *
     * @param string $text Answer text.
     * @return string
     */
    public static function list_text(string $text): string {
        return self::json_text($text, '[', ']');
    }

    /**
     * Decode the JSON object in an answer.
     *
     * @since 2.17.1
     *
     * @param string $text Answer text.
     * @return array|null Decoded object, or null when none parses.
     */
    public static function decode_object(string $text): ?array {
        $json = self::object_text($text);
        if ('' === $json) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Shared body of object_text() and list_text().
     *
     * @param string $text  Answer text.
     * @param string $open  Opening bracket.
     * @param string $close Closing bracket.
     * @return string
     */
    private static function json_text(string $text, string $open, string $close): string {
        $text = trim(self::strip_reasoning($text));

        if ('' !== $text && $open === $text[0]) {
            json_decode($text, true);
            if (JSON_ERROR_NONE === json_last_error()) {
                return $text;
            }
        }

        $start = strpos($text, $open);
        $end = strrpos($text, $close);

        if (false === $start || false === $end || $end < $start) {
            return '';
        }

        return substr($text, $start, $end - $start + 1);
    }
}
