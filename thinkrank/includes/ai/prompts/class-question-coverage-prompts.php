<?php
/**
 * Question Coverage prompts (#723).
 *
 * Three prompts, each answering in strict JSON:
 *
 * - fan_out(): the sub-questions searchers break a topic into, across fixed
 *   facets, in the post's language.
 * - coverage(): for each question, whether the post's sections answer it,
 *   which section does, and where a missing answer would fit.
 * - draft_section(): a short section answering one question, for the author
 *   to review before it goes anywhere near the post.
 *
 * Copy rule: these talk about questions and coverage. Nothing here promises
 * citations, AI rankings or visibility.
 *
 * @package ThinkRank\AI\Prompts
 * @since 2.21.2
 */

declare(strict_types=1);

namespace ThinkRank\AI\Prompts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prompt text for Question Coverage.
 */
final class Question_Coverage_Prompts {

    /**
     * Facets every question set is spread across, so the list covers the
     * topic instead of rephrasing one question ten times.
     */
    public const FACETS = [
        'definition',
        'how_to',
        'comparison',
        'cost',
        'best_for',
        'problems',
        'local',
        'freshness',
    ];

    /**
     * Language line shared by every prompt.
     *
     * @param string $language Language name, or '' to let the model infer it.
     * @return string
     */
    private static function language(string $language): string {
        if ('' === $language) {
            return '';
        }
        return "\n\nLANGUAGE (critical): Write every question, heading, reason and sentence in {$language}. Do NOT translate to English or switch languages.";
    }

    /**
     * Sub-questions for a topic.
     *
     * @param string $keyword  Focus keyword.
     * @param string $title    Post title, for context only.
     * @param string $language Target language name.
     * @return string
     */
    public static function fan_out(string $keyword, string $title, string $language): string {
        $facets = implode(', ', self::FACETS);

        return <<<PROMPT
You help a writer see which questions people ask around a topic, so they can check whether their page answers them.

Topic (the page's focus keyword): "{$keyword}"
Page title, for context only: "{$title}"

List 8 to 15 distinct questions a searcher researching this topic would ask. Spread them across these facets, skipping any facet that does not fit the topic: {$facets}.

Rules:
- Each question is one natural search question, under 15 words.
- No two questions ask the same thing in different words.
- Do not guess brands, product names, prices, dates or statistics that are not in the topic.
- Use "local" only when the topic depends on a place; use "freshness" only when the answer changes over time.

Return ONLY this JSON, no prose, no code fences:
{"questions":[{"question":"...","facet":"one of the facets above"}]}
PROMPT
            . self::language($language);
    }

    /**
     * Judge which questions a page answers.
     *
     * @param array<int,array{id:string,question:string}>                  $questions Questions to judge.
     * @param array<int,array{index:int,heading:string,text:string}>      $sections  The page, split by heading.
     * @param string                                                        $language  Target language name.
     * @return string
     */
    public static function coverage(array $questions, array $sections, string $language): string {
        $question_lines = [];
        foreach ($questions as $question) {
            $question_lines[] = $question['id'] . ': ' . $question['question'];
        }

        $section_lines = [];
        foreach ($sections as $section) {
            $heading         = '' === $section['heading'] ? '(introduction, before the first heading)' : $section['heading'];
            $section_lines[] = '[' . $section['index'] . '] ' . $heading . "\n" . $section['text'];
        }

        $questions_text = implode("\n", $question_lines);
        $sections_text  = implode("\n\n", $section_lines);

        // Page first, questions last: the questions are what the model must
        // act on, and small models attend most to the end of the prompt.
        // Asking for the sentence that answers each one keeps "answered"
        // grounded in the page instead of in what the model already knows.
        return <<<PROMPT
You check whether a web page answers a list of questions. Judge only from the page text; do not use outside knowledge to fill gaps.

PAGE SECTIONS (number in brackets, then heading, then text):
{$sections_text}

QUESTIONS:
{$questions_text}

For every question, look for the sentence in the page that answers it, then decide:
- "answered": a sentence gives the reader a direct, usable answer. Put that sentence in "quote".
- "partial": the page touches the question but leaves the reader without a clear answer. Put the closest sentence in "quote".
- "missing": no sentence addresses it. Leave "quote" empty.

"section" is the number of the section the quote comes from. For "missing" and "partial", "after" is the number of the section after which a new section answering it would fit best (-1 for before everything).
"reason" is one line, under 20 words, saying what the page does or does not say.

Return ONLY this JSON, one entry per question id, no prose, no code fences:
{"results":[{"id":"...","status":"answered|partial|missing","quote":"...","section":0,"after":0,"reason":"..."}]}
PROMPT
            . self::language($language);
    }

    /**
     * A draft section answering one question.
     *
     * @param string $question Question to answer.
     * @param string $keyword  Focus keyword.
     * @param string $title    Post title.
     * @param string $context  Plain-text excerpt of the page, for tone and facts.
     * @param string $language Target language name.
     * @return string
     */
    public static function draft_section(string $question, string $keyword, string $title, string $context, string $language): string {
        return <<<PROMPT
Write one short section for a web page titled "{$title}" (topic: "{$keyword}") that answers this question directly:

"{$question}"

Match the tone of the page excerpt below. Use only facts that are in the excerpt or are general, uncontroversial knowledge; where a specific figure would be needed and is not in the excerpt, say what the reader should check instead of inventing one.

Page excerpt:
{$context}

Write a heading (the question, or a tighter version of it) and 2 to 4 short paragraphs, 80 to 200 words in total. The first sentence must answer the question.

Return ONLY this JSON, no prose, no code fences:
{"heading":"...","paragraphs":["...","..."]}
PROMPT
            . self::language($language);
    }
}
