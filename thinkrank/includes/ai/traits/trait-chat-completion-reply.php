<?php
/**
 * Reading the answer out of a Chat Completions reply.
 *
 * @package ThinkRank\AI\Traits
 */

declare(strict_types=1);

namespace ThinkRank\AI\Traits;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One reader for every OpenAI and OpenRouter parser.
 *
 * The parsers checked only that choices[0].message.content was set, so a
 * refusal (content null, reason in message.refusal), a content-filter stop, a
 * reply cut off at the token limit and an OpenRouter upstream error
 * (finish_reason "error" with choices[0].error) all reached the user as
 * "Invalid response format", "No valid JSON found" or "Failed to parse JSON",
 * with nothing to diagnose (#1041). This reads why the model stopped first.
 *
 * @since 2.17.1
 */
trait Chat_Completion_Reply {

    /**
     * The answer text of the first choice, or an exception that says why there is none.
     *
     * @since 2.17.1
     *
     * @param array  $response Decoded Chat Completions response.
     * @param string $provider Provider or endpoint name for the messages.
     * @return string Answer text, never empty.
     * @throws \Exception On a refusal, a policy stop, a provider error, a truncated reply, or no text.
     */
    private function chat_reply_text(array $response, string $provider): string {
        $choice = $response['choices'][0] ?? null;

        if (!is_array($choice)) {
            // OpenRouter can answer 200 with only an error object when the
            // upstream provider fails before producing a choice.
            $error = $this->chat_error_message($response['error'] ?? null);
            if ('' !== $error) {
                throw new \Exception(esc_html(sprintf('%1$s reported an error: %2$s', $provider, $error)));
            }

            throw new \Exception(esc_html(sprintf('Invalid response format from %s: the reply has no choices.', $provider)));
        }

        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $finish  = (string) ($choice['finish_reason'] ?? '');

        if (!empty($message['refusal']) && is_string($message['refusal'])) {
            throw new \Exception(esc_html('The AI declined this request: ' . $message['refusal']));
        }

        if ('content_filter' === $finish) {
            throw new \Exception('The AI blocked this request under its content policy. Try different wording.');
        }

        $error = $this->chat_error_message($choice['error'] ?? null);
        if ('' !== $error || 'error' === $finish) {
            throw new \Exception(esc_html(sprintf(
                '%1$s reported an error while writing the answer: %2$s',
                $provider,
                '' !== $error ? $error : 'no details given'
            )));
        }

        // Every caller parses a JSON object, and a cut-off one either fails
        // to parse or parses a fragment.
        if ('length' === $finish) {
            throw new \Exception('The AI ran out of output tokens before it finished the answer. Try again, or pick a model that spends less of its budget on reasoning.');
        }

        // Message parts go through Chat_Content, which keeps only the parts
        // that are the answer, so a reasoning part never reaches the text (#1042).
        $content = \ThinkRank\AI\Chat_Content::text($message['content'] ?? null);

        if (!is_string($content) || '' === trim($content)) {
            throw new \Exception(esc_html(sprintf(
                'Invalid response format from %1$s: no text in the reply (finish reason: %2$s).',
                $provider,
                '' !== $finish ? $finish : 'none'
            )));
        }

        return $content;
    }

    /**
     * The message of a provider error object, or ''.
     *
     * @since 2.17.1
     *
     * @param mixed $error `error` field: an object with `message`, or a string.
     * @return string
     */
    private function chat_error_message($error): string {
        if (is_array($error)) {
            $error = $error['message'] ?? '';
        }

        return is_string($error) ? trim($error) : '';
    }
}
