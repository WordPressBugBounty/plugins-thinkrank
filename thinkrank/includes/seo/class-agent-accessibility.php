<?php

/**
 * Agent-centric accessibility of rendered pages.
 *
 * Lighthouse 13.3.0 added an experimental Agentic Browsing category, and the
 * half of it that is not llms.txt or WebMCP is the oldest accessibility rule
 * there is: every interactive element needs a programmatic name. An agent
 * driving a page has exactly the same problem a screen reader does. It cannot
 * see the icon; it reads the accessibility tree, and a link whose entire
 * content is `<i class="fa-cart"></i>` is a link to nowhere describable.
 *
 * ThinkRank's analyzer had nothing on links or buttons (#769). The nearest
 * check counts Media Library attachments with alt text, which is a different
 * question about different objects, and it is blind to exactly the case that
 * matters here: markup that exists only after the page renders.
 *
 * Which is why this reads rendered HTML rather than `post_content`. On a
 * page-builder site the stored content is empty or a shortcode, so parsing it
 * would report a clean bill of health on precisely the sites most likely to be
 * full of icon links. `Page_Sampler` supplies the documents.
 *
 * The rules implemented are the accname ones a browser actually applies, in
 * their real order, and no more than that. Anything this file is unsure about
 * it treats as named, because a false positive here sends a user hunting
 * through a template for a problem that is not there, and the finding then
 * reads as noise rather than as a defect list.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 2.21.3
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Finds interactive elements a machine cannot name.
 *
 * @since 2.21.3
 */
class Agent_Accessibility {

    /**
     * Most findings of one kind reported per page.
     *
     * A broken template repeats its broken element in every row of a loop, and
     * a list of two hundred identical menu links is not a better bug report
     * than a list of five.
     *
     * @since 2.21.3
     * @var int
     */
    public const PER_PAGE_LIMIT = 5;

    /**
     * Longest markup snippet kept with a finding, in characters.
     *
     * @since 2.21.3
     * @var int
     */
    public const SNIPPET_LENGTH = 160;

    /**
     * `input` types that are buttons rather than fields.
     *
     * @var string[]
     */
    private const BUTTON_INPUT_TYPES = ['button', 'submit', 'reset', 'image'];

    /**
     * Analyse one rendered document.
     *
     * @since 2.21.3
     *
     * @param string $html Rendered page HTML.
     * @return array{unnamed: array<int, array{tag: string, snippet: string}>, hidden: array<int, array{tag: string, snippet: string}>, dangling: array<int, array{tag: string, snippet: string}>, interactive: int}
     */
    public static function analyze(string $html): array {
        // Every return carries the same keys. The short-circuit paths below
        // omitted the totals, so a caller reading them got an undefined key
        // on exactly the documents it could learn least from.
        $empty = [
            'unnamed'        => [],
            'hidden'         => [],
            'dangling'       => [],
            'interactive'    => 0,
            'unnamed_total'  => 0,
            'hidden_total'   => 0,
            'dangling_total' => 0,
        ];

        if ('' === trim($html) || !class_exists('DOMDocument')) {
            return $empty;
        }

        $document = self::load($html);

        if (null === $document) {
            return $empty;
        }

        $xpath = new \DOMXPath($document);
        $ids   = self::element_ids($xpath);

        $unnamed     = [];
        $hidden      = [];
        $dangling    = [];
        $interactive = 0;

        foreach (self::interactive_elements($xpath) as $element) {
            // A `<a>` with no href is not a link, it is an anchor or a
            // placeholder a script may wire up later; neither is a control an
            // agent can be expected to name.
            if ('a' === strtolower($element->nodeName) && !$element->hasAttribute('href')) {
                continue;
            }

            $interactive++;

            $labelledby = trim($element->getAttribute('aria-labelledby'));
            if ('' !== $labelledby && !self::labelledby_resolves($labelledby, $ids)) {
                $dangling[] = self::describe($element);
            }

            if (self::is_hidden_from_tree($element)) {
                $hidden[] = self::describe($element);

                // An element the tree cannot see has no accessible name by
                // definition. Reporting it twice would make one defect look
                // like two.
                continue;
            }

            if ('' === self::accessible_name($element, $ids)) {
                $unnamed[] = self::describe($element);
            }
        }

        return [
            'unnamed'     => array_slice($unnamed, 0, self::PER_PAGE_LIMIT),
            'hidden'      => array_slice($hidden, 0, self::PER_PAGE_LIMIT),
            'dangling'    => array_slice($dangling, 0, self::PER_PAGE_LIMIT),
            'interactive' => $interactive,
            'unnamed_total'  => count($unnamed),
            'hidden_total'   => count($hidden),
            'dangling_total' => count($dangling),
        ];
    }

    /**
     * Parse HTML without letting its warnings escape.
     *
     * Real pages are not well-formed XML and never will be; libxml complains
     * about every unclosed tag on the internet. The point is to read what a
     * browser would build, not to judge the markup.
     *
     * @param string $html Rendered page HTML.
     * @return \DOMDocument|null Null when nothing could be parsed.
     */
    private static function load(string $html): ?\DOMDocument {
        $previous = libxml_use_internal_errors(true);

        $document = new \DOMDocument();

        // The meta hint makes libxml read the bytes as UTF-8 instead of
        // guessing Latin-1, which otherwise mangles every non-ASCII link label
        // into mojibake and turns a perfectly good name into a different one.
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    /**
     * Every interactive element in the document, in document order.
     *
     * @param \DOMXPath $xpath Document xpath.
     * @return \DOMElement[]
     */
    private static function interactive_elements(\DOMXPath $xpath): array {
        $types = implode(
            ' or ',
            array_map(
                static fn(string $type): string => 'translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "' . $type . '"',
                self::BUTTON_INPUT_TYPES
            )
        );

        $query = '//a | //button | //input[' . $types . ']'
            . ' | //*[translate(@role, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "button"'
            . ' or translate(@role, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "link"]';

        $nodes = $xpath->query($query);

        if (false === $nodes) {
            return [];
        }

        $elements = [];
        $seen     = [];

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            // An `<a role="link">` matches both halves of the query.
            $key = spl_object_id($node);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $elements[] = $node;
        }

        return $elements;
    }

    /**
     * Every `id` in the document, as a lookup.
     *
     * @param \DOMXPath $xpath Document xpath.
     * @return array<string, bool>
     */
    private static function element_ids(\DOMXPath $xpath): array {
        $ids   = [];
        $nodes = $xpath->query('//*[@id]');

        if (false === $nodes) {
            return $ids;
        }

        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $ids[$node->getAttribute('id')] = true;
            }
        }

        return $ids;
    }

    /**
     * The element's accessible name, by the rules a browser applies.
     *
     * Order matters and is not arbitrary: `aria-labelledby` wins over
     * `aria-label`, which wins over content, which wins over `title`. Getting
     * the order wrong would report a named element as unnamed whenever an
     * author used the stronger mechanism and left the weaker one empty.
     *
     * @param \DOMElement          $element Element to name.
     * @param array<string, bool>  $ids     Ids present in the document.
     * @return string The name, or '' when the element has none.
     */
    private static function accessible_name(\DOMElement $element, array $ids): string {
        $labelledby = trim($element->getAttribute('aria-labelledby'));

        // A resolvable aria-labelledby names the element whatever its target
        // contains; this class does not walk into the target, because a
        // reference that resolves is the author having done the work.
        if ('' !== $labelledby && self::labelledby_resolves($labelledby, $ids)) {
            return $labelledby;
        }

        $label = trim($element->getAttribute('aria-label'));
        if ('' !== $label) {
            return $label;
        }

        // A name the Interactivity API binds when the page hydrates. The
        // server renders it empty in a row template (WooCommerce's mini-cart
        // remove button is one), and the browser has the name before anyone
        // can reach the control, so it is named, not missing.
        foreach (['data-wp-bind--aria-label', 'data-wp-text'] as $binding) {
            if ('' !== trim($element->getAttribute($binding))) {
                return $binding;
            }
        }

        $tag = strtolower($element->nodeName);

        // `<input type="submit">` is named by its value, and by the browser's
        // own default ("Submit") when there is none — which is a name, however
        // unhelpful, so it is not reported as missing.
        if ('input' === $tag) {
            $type  = strtolower($element->getAttribute('type'));
            $value = trim($element->getAttribute('value'));

            if ('image' === $type) {
                $alt = trim($element->getAttribute('alt'));

                return '' !== $alt ? $alt : trim($element->getAttribute('title'));
            }

            if ('' !== $value) {
                return $value;
            }

            return in_array($type, ['submit', 'reset'], true)
                ? $type
                : trim($element->getAttribute('title'));
        }

        $text = self::visible_text($element);
        if ('' !== $text) {
            return $text;
        }

        return trim($element->getAttribute('title'));
    }

    /**
     * The text a screen reader would read out of an element's contents.
     *
     * Descendant images contribute their `alt`, which is the whole of the
     * icon-link question: `<a><img alt="Home"></a>` is named, and the same
     * link with `alt=""` is not. Anything hidden from the tree contributes
     * nothing, because that is what hiding it means.
     *
     * @param \DOMNode $node Node to read.
     * @return string
     */
    private static function visible_text(\DOMNode $node): string {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= $child->textContent;
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            if (self::is_hidden_from_tree($child)) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if ('img' === $tag) {
                $text .= ' ' . $child->getAttribute('alt');
                continue;
            }

            if ('svg' === $tag) {
                // An inline SVG names itself with a <title> child or an
                // aria-label; a bare icon path names nothing.
                $label = trim($child->getAttribute('aria-label'));
                if ('' === $label) {
                    $titles = $child->getElementsByTagName('title');
                    $label  = $titles->length > 0 ? trim($titles->item(0)->textContent) : '';
                }

                $text .= ' ' . $label;
                continue;
            }

            $label = trim($child->getAttribute('aria-label'));
            if ('' !== $label) {
                $text .= ' ' . $label;
                continue;
            }

            $text .= ' ' . self::visible_text($child);
        }

        // A non-breaking space is whitespace to a reader and to an agent, but
        // not to trim(), so a link containing only `&nbsp;` looked named.
        $text = str_replace(["\xc2\xa0", "\u{200B}"], ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Whether an element is hidden from the accessibility tree.
     *
     * `aria-hidden="true"` on the element or any ancestor, or a bare `hidden`
     * attribute. Inline `display:none` is deliberately not treated as hidden
     * here: it is how nearly every menu, modal and dropdown on the web starts
     * out, and those are interactive elements that do need names.
     *
     * The same goes for a dialog or drawer that is merely closed. Hiding a
     * closed dialog with aria-hidden is correct markup, and it is shown when
     * opened, so its controls are not hidden from an agent in any way a site
     * could fix. Only a hidden ancestor that never toggles counts.
     *
     * @param \DOMElement $element Element to test.
     * @return bool
     */
    private static function is_hidden_from_tree(\DOMElement $element): bool {
        $node = $element;

        while ($node instanceof \DOMElement) {
            $hidden = 'true' === strtolower(trim($node->getAttribute('aria-hidden')))
                || $node->hasAttribute('hidden');

            if ($hidden && !self::is_toggled_container($node)) {
                return true;
            }

            $node = $node->parentNode;
        }

        return false;
    }

    /**
     * Whether a hidden element is hidden only until it is opened.
     *
     * A `<dialog>`, an element with a dialog role or `aria-modal`, or one whose
     * hidden state is bound to script state through the Interactivity API
     * (`data-wp-bind--aria-hidden`, `data-wp-bind--hidden`), which is how core
     * blocks and WooCommerce's mini-cart drawer render while closed.
     *
     * @since 2.21.3
     *
     * @param \DOMElement $node Hidden element.
     * @return bool
     */
    private static function is_toggled_container(\DOMElement $node): bool {
        if ('dialog' === strtolower($node->nodeName)) {
            return true;
        }

        if (in_array(strtolower(trim($node->getAttribute('role'))), ['dialog', 'alertdialog'], true)) {
            return true;
        }

        if ($node->hasAttribute('aria-modal')) {
            return true;
        }

        return $node->hasAttribute('data-wp-bind--aria-hidden') || $node->hasAttribute('data-wp-bind--hidden');
    }

    /**
     * Whether every id an `aria-labelledby` names exists in the document.
     *
     * One missing id is enough: the browser concatenates the targets it finds
     * and silently drops the rest, so a typo in a two-id reference produces
     * half a name and no error anywhere.
     *
     * @param string              $value `aria-labelledby` attribute value.
     * @param array<string, bool> $ids   Ids present in the document.
     * @return bool
     */
    private static function labelledby_resolves(string $value, array $ids): bool {
        $targets = preg_split('/\s+/', trim($value)) ?: [];
        $targets = array_filter($targets);

        if ([] === $targets) {
            return false;
        }

        foreach ($targets as $target) {
            if (!isset($ids[$target])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A finding: what the element is, and enough markup to find it.
     *
     * @param \DOMElement $element Element to describe.
     * @return array{tag: string, snippet: string}
     */
    private static function describe(\DOMElement $element): array {
        $document = $element->ownerDocument;
        $snippet  = $document instanceof \DOMDocument
            ? (string) $document->saveHTML($element)
            : '';

        $snippet = trim((string) preg_replace('/\s+/u', ' ', $snippet));

        if (mb_strlen($snippet) > self::SNIPPET_LENGTH) {
            $snippet = mb_substr($snippet, 0, self::SNIPPET_LENGTH) . '…';
        }

        return [
            'tag'     => strtolower($element->nodeName),
            'snippet' => $snippet,
        ];
    }
}
