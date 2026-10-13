<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\A11yAudit;

use DOMElement;
use DOMNode;
use DOMXPath;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Page;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The WCAG 2.2 success criteria the audit reports against: name, level, Understanding slug.
 */
const WCAG = [
    '1.1.1' => ['Non-text Content', 'A', 'non-text-content'],
    '1.3.1' => ['Info and Relationships', 'A', 'info-and-relationships'],
    '1.4.3' => ['Contrast (Minimum)', 'AA', 'contrast-minimum'],
    '1.4.4' => ['Resize Text', 'AA', 'resize-text'],
    '2.1.1' => ['Keyboard', 'A', 'keyboard'],
    '2.4.1' => ['Bypass Blocks', 'A', 'bypass-blocks'],
    '2.4.2' => ['Page Titled', 'A', 'page-titled'],
    '2.4.3' => ['Focus Order', 'A', 'focus-order'],
    '2.4.4' => ['Link Purpose (In Context)', 'A', 'link-purpose-in-context'],
    '2.4.6' => ['Headings and Labels', 'AA', 'headings-and-labels'],
    '2.4.7' => ['Focus Visible', 'AA', 'focus-visible'],
    '2.5.8' => ['Target Size (Minimum)', 'AA', 'target-size-minimum'],
    '3.1.1' => ['Language of Page', 'A', 'language-of-page'],
    '4.1.2' => ['Name, Role, Value', 'A', 'name-role-value'],
];

/**
 * Every rule the audit can report: its criterion, severity, what is wrong, how to fix it, and
 * the ability that makes the fix when there is one, by its name without the host prefix.
 *
 * Duplicate IDs are reported under 4.1.2 rather than 4.1.1: WCAG 2.2 removed 4.1.1 Parsing,
 * and a duplicate ID only harms users where it breaks a name or relationship, which is 4.1.2.
 */
const RULES = [
    'html-lang-missing' => ['3.1.1', 'serious', 'The <html> element has no lang attribute, so screen readers guess the language and may read the page with the wrong voice.', 'The theme must print language_attributes() on <html>; check Settings > General > Site Language.', ''],
    'html-lang-invalid' => ['3.1.1', 'serious', 'The <html> lang attribute is not a valid language tag.', 'Use a BCP 47 tag such as en or en-GB; the theme prints it through language_attributes().', ''],
    'document-title-missing' => ['2.4.2', 'serious', 'The page has no <title>, or it is empty.', 'The theme needs add_theme_support(\'title-tag\'), or the SEO plugin must output a title.', ''],
    'heading-empty' => ['2.4.6', 'moderate', 'A heading has no text, so it appears as a blank entry in the screen-reader heading list.', 'Give the heading text, or remove it and style the element without a heading tag.', 'update-post'],
    'heading-skipped-level' => ['1.3.1', 'moderate', 'A heading skips a level (for example h2 followed by h4), which breaks the outline screen-reader users navigate by.', 'Use the next level down, and change the look with CSS rather than the heading level.', 'update-post'],
    'heading-no-h1' => ['1.3.1', 'minor', 'The page has no h1, so there is no top-level heading describing it.', 'Make the page title an h1 (most themes do this in the page template).', 'update-post'],
    'image-alt-missing' => ['1.1.1', 'critical', 'An image has no alt attribute. Screen readers announce the file name instead.', 'Look at each image with wppilot/get-media-image, write alt text that says what it shows (alt="" if purely decorative), then set it with wppilot/update-image-alt.', 'update-image-alt'],
    'image-alt-filename' => ['1.1.1', 'serious', 'An image\'s alt text is its file name, which tells a screen-reader user nothing.', 'Look at the image with wppilot/get-media-image and replace the alt text with a description, using wppilot/update-image-alt.', 'update-image-alt'],
    'input-image-alt-missing' => ['1.1.1', 'critical', 'An image button (<input type="image">) has no alt text, so its purpose is not announced.', 'Add alt text naming the action, for example alt="Search".', ''],
    'area-alt-missing' => ['1.1.1', 'critical', 'An image-map <area> link has no alt text.', 'Add alt text naming where each area links to.', ''],
    'svg-img-name-missing' => ['1.1.1', 'serious', 'An SVG with role="img" has no accessible name.', 'Add aria-label, or a <title> as the SVG\'s first child.', ''],
    'form-label-missing' => ['4.1.2', 'critical', 'A form field has no label, so a screen reader announces only "edit text" or similar.', 'Add a <label for> matching the field\'s id, or aria-label. Placeholder text is not a label in every screen reader, but it is counted here.', ''],
    'link-name-missing' => ['2.4.4', 'serious', 'A link has no text, so it is announced only as "link" or by its URL.', 'Add link text, alt text on the image inside it, or aria-label.', ''],
    'button-name-missing' => ['4.1.2', 'critical', 'A button has no accessible name, so a screen reader announces only "button".', 'Add text inside the button, or aria-label for icon buttons.', ''],
    'duplicate-id-referenced' => ['4.1.2', 'serious', 'An id used by a label or ARIA reference appears more than once, so the reference may point at the wrong element.', 'Make each id unique; this is usually a widget or form placed twice on the page.', ''],
    'duplicate-id' => ['4.1.2', 'minor', 'An id appears more than once. Nothing refers to it yet, but any label or ARIA reference to it would be ambiguous.', 'Make each id unique.', ''],
    'aria-labelledby-missing-target' => ['4.1.2', 'serious', 'aria-labelledby names an id that is not on the page, so the element loses its name.', 'Point aria-labelledby at an existing id, or use aria-label.', ''],
    'aria-reference-missing-target' => ['1.3.1', 'moderate', 'An ARIA relationship (aria-describedby, aria-controls, aria-owns and the like) names an id that is not on the page.', 'Point the attribute at an existing id, or remove it.', ''],
    'label-for-missing-target' => ['1.3.1', 'moderate', 'A <label for> names an id that is not on the page, so the label is attached to nothing.', 'Set for to the id of the field it labels.', ''],
    'bypass-missing' => ['2.4.1', 'moderate', 'The page has no <main> landmark and no skip link, so keyboard users must tab through the whole header on every page.', 'Wrap the content in <main>, or add a "Skip to content" link as the first link on the page (most themes can).', ''],
    'landmark-main-multiple' => ['1.3.1', 'minor', 'The page has more than one visible main landmark.', 'Keep one <main>; use <section> or <article> for the others.', ''],
    'tabindex-positive' => ['2.4.3', 'serious', 'An element has tabindex greater than 0, which moves it out of the reading order for keyboard users.', 'Use tabindex="0" (or none) and order the markup instead.', ''],
    'viewport-zoom-disabled' => ['1.4.4', 'critical', 'The viewport meta tag stops people zooming (user-scalable=no, or maximum-scale below 2).', 'Remove user-scalable=no and maximum-scale from the viewport meta tag; this is usually in the theme header.', ''],
    'iframe-title-missing' => ['4.1.2', 'serious', 'An iframe has no title, so screen-reader users cannot tell what it contains before entering it.', 'Add a title describing the embed, for example title="Map of our office".', ''],
    'table-headers-missing' => ['1.3.1', 'serious', 'A data table has no header cells, so cells are read without their column or row names.', 'Mark the header row with <th> (the table block\'s "Header section" setting), or role="presentation" if it is only for layout.', 'update-post'],
];

/** Rule penalty weights, before instance scaling. */
const SEVERITY_WEIGHT = ['critical' => 10, 'serious' => 6, 'moderate' => 3, 'minor' => 1];

/** Examples kept per rule; the count is always the full number. */
const MAX_EXAMPLES = 10;

/** ARIA attributes that hold id references. */
const IDREF_ATTRIBUTES = ['aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns', 'aria-activedescendant', 'aria-errormessage', 'aria-details', 'aria-flowto'];

/**
 * What the audit cannot see from HTML alone, said plainly so a clean score is not read as a
 * clean page.
 */
const NOT_CHECKED = [
    ['check' => 'color-contrast', 'criterion' => '1.4.3', 'reason' => 'Needs the rendered colours of text and background, which only a browser computes.'],
    ['check' => 'focus-visible', 'criterion' => '2.4.7', 'reason' => 'Needs a browser to move focus and see whether an outline appears.'],
    ['check' => 'target-size', 'criterion' => '2.5.8', 'reason' => 'Needs the rendered layout to measure controls.'],
    ['check' => 'keyboard-operation', 'criterion' => '2.1.1', 'reason' => 'Needs interaction: menus, sliders and dialogs must be tried with a keyboard.'],
    ['check' => 'javascript-rendered-content', 'criterion' => null, 'reason' => 'Only the HTML the server sent is checked. Anything a script adds or changes afterwards (menus, popups, sliders, cookie banners, lazy widgets) is not.'],
];

/**
 * @return array{criterion: string, name: string, level: string, version: string, url: string}
 */
function wcag(string $criterion): array
{
    [$name, $level, $slug] = WCAG[$criterion];
    return [
        'criterion' => $criterion,
        'name' => $name,
        'level' => $level,
        'version' => '2.2',
        'url' => 'https://www.w3.org/WAI/WCAG22/Understanding/' . $slug . '.html',
    ];
}

/**
 * Audit one page's HTML.
 *
 * @return array{score: int, summary: array<string, int>, findings: list<array<string, mixed>>, checked: list<string>, not_checked: list<array<string, mixed>>}
 */
function audit_html(string $html): array
{
    $collector = new Findings();
    // An empty response is still a page a visitor received: it has no language, title or
    // headings, and saying so beats an empty report that reads as a pass.
    $document = Page::parse($html) ?? Page::parse('<html><head></head><body></body></html>');
    if ($document !== null) {
        $xpath = new DOMXPath($document);
        check_language($xpath, $collector);
        check_title($xpath, $collector);
        check_headings($xpath, $collector);
        check_images($xpath, $collector);
        check_form_labels($xpath, $collector);
        check_links_and_buttons($xpath, $collector);
        check_ids($xpath, $collector);
        check_landmarks($xpath, $collector);
        check_tabindex($xpath, $collector);
        check_viewport($xpath, $collector);
        check_iframes($xpath, $collector);
        check_tables($xpath, $collector);
    }
    $findings = $collector->all();

    return [
        'score' => score($findings),
        'summary' => summary($findings),
        'findings' => $findings,
        'checked' => array_keys(RULES),
        'not_checked' => array_map(
            static fn(array $item): array => $item + ['wcag' => is_string($item['criterion']) ? wcag($item['criterion']) : null],
            NOT_CHECKED,
        ),
    ];
}

/**
 * 100 less a penalty per failing rule: the severity weight, plus a quarter of it for each further
 * instance up to four. A rule failing once and a rule failing fifty times differ, but one
 * template bug repeated on every card cannot sink the score on its own.
 *
 * @param list<array<string, mixed>> $findings
 */
function score(array $findings): int
{
    $penalty = 0.0;
    foreach ($findings as $finding) {
        $weight = SEVERITY_WEIGHT[(string) $finding['severity']] ?? 1;
        $penalty += $weight * (1 + min(max((int) $finding['count'] - 1, 0), 4) * 0.25);
    }
    return max(0, (int) round(100 - $penalty));
}

/**
 * @param list<array<string, mixed>> $findings
 * @return array<string, int>
 */
function summary(array $findings): array
{
    $summary = ['critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0, 'rules_failed' => count($findings), 'instances' => 0];
    foreach ($findings as $finding) {
        $summary[(string) $finding['severity']] += 1;
        $summary['instances'] += (int) $finding['count'];
    }
    return $summary;
}

/**
 * Findings grouped by rule, in the order rules first fail.
 */
final class Findings
{
    /** @var array<string, array<string, mixed>> */
    private array $by_rule = [];

    /** @param array<string, mixed> $extra */
    public function add(string $rule, ?DOMElement $element = null, array $extra = []): void
    {
        [$criterion, $severity, $message, $fix, $ability] = RULES[$rule];
        if (!isset($this->by_rule[$rule])) {
            $this->by_rule[$rule] = [
                'rule' => $rule,
                'severity' => $severity,
                'wcag' => wcag($criterion),
                'message' => $message,
                'count' => 0,
                'examples' => [],
                'fix' => $fix,
                'fix_ability' => fix_ability($ability),
            ];
        }
        $this->by_rule[$rule]['count']++;
        if ($element !== null && count($this->by_rule[$rule]['examples']) < MAX_EXAMPLES) {
            $this->by_rule[$rule]['examples'][] = array_merge(['selector' => selector($element), 'html' => snippet($element)], $extra);
        }
        $attachment = (int) ($extra['attachment_id'] ?? 0);
        if ($attachment > 0) {
            $ids = $this->by_rule[$rule]['attachment_ids'] ?? [];
            if (!in_array($attachment, $ids, strict: true)) {
                $ids[] = $attachment;
            }
            $this->by_rule[$rule]['attachment_ids'] = $ids;
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values($this->by_rule);
    }
}

/**
 * The full name of the ability that fixes a rule, if this site has it.
 *
 * Built from the host's prefix rather than written out: `update-post` is GoBuildWP's own ability,
 * not this kit's, so a copy of the kit in another plugin must not advertise it, and there it is
 * simply not registered. Only a registered ability is named, so an agent is never sent to one
 * that does not exist.
 */
function fix_ability(string $slug): ?string
{
    if ($slug === '' || !Runtime\has_host()) {
        return null;
    }
    $name = Runtime\host()->id() . '/' . $slug;
    return function_exists('wp_has_ability') && wp_has_ability($name) ? $name : null;
}

function check_language(DOMXPath $xpath, Findings $findings): void
{
    $html = $xpath->query('/html')->item(0);
    if (!$html instanceof DOMElement) {
        $findings->add('html-lang-missing');
        return;
    }
    $lang = trim($html->getAttribute('lang'));
    if ($lang === '') {
        $lang = trim($html->getAttribute('xml:lang'));
    }
    if ($lang === '') {
        $findings->add('html-lang-missing', $html);
    } elseif (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{1,8})*$/i', $lang) !== 1) {
        $findings->add('html-lang-invalid', $html, ['lang' => $lang]);
    }
}

function check_title(DOMXPath $xpath, Findings $findings): void
{
    // head/title only: an inline <svg> carries <title> elements of its own.
    foreach ($xpath->query('//head/title') as $title) {
        if ($title instanceof DOMElement && !in_ignored_subtree($title) && trim($title->textContent) !== '') {
            return;
        }
    }
    $findings->add('document-title-missing');
}

function check_headings(DOMXPath $xpath, Findings $findings): void
{
    $previous = 0;
    $has_h1 = false;
    $any = false;
    foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6|//*[@role="heading"]') as $heading) {
        if (!$heading instanceof DOMElement || is_hidden($heading)) {
            continue;
        }
        $level = strtolower($heading->getAttribute('role')) === 'heading'
            ? max(1, min(6, (int) ($heading->getAttribute('aria-level') ?: 2)))
            : (int) substr(strtolower($heading->nodeName), 1);
        $any = true;
        if ($level === 1) {
            $has_h1 = true;
        }
        if (accessible_name($heading, $xpath) === '') {
            $findings->add('heading-empty', $heading);
        }
        if ($previous > 0 && $level > $previous + 1) {
            $findings->add('heading-skipped-level', $heading, ['from' => 'h' . $previous, 'to' => 'h' . $level]);
        }
        $previous = $level;
    }
    if (!$has_h1) {
        $findings->add('heading-no-h1', null, ['headings_found' => $any]);
    }
}

function check_images(DOMXPath $xpath, Findings $findings): void
{
    foreach ($xpath->query('//img') as $img) {
        if (!$img instanceof DOMElement || is_hidden($img)) {
            continue;
        }
        $extra = image_context($img);
        if (!$img->hasAttribute('alt')) {
            $role = strtolower(trim($img->getAttribute('role')));
            // role="presentation" or "none" is an explicit decorative marking, and a name from
            // aria-label or aria-labelledby is a name.
            if (in_array($role, ['presentation', 'none'], strict: true) || accessible_name($img, $xpath) !== '') {
                continue;
            }
            $findings->add('image-alt-missing', $img, $extra);
            continue;
        }
        if (alt_is_filename($img->getAttribute('alt'), $img->getAttribute('src'))) {
            $findings->add('image-alt-filename', $img, $extra + ['alt' => $img->getAttribute('alt')]);
        }
    }
    foreach ($xpath->query('//input[translate(@type,"IMAGE","image")="image"]') as $input) {
        if ($input instanceof DOMElement && !is_hidden($input) && accessible_name($input, $xpath) === '') {
            $findings->add('input-image-alt-missing', $input);
        }
    }
    foreach ($xpath->query('//area[@href]') as $area) {
        if ($area instanceof DOMElement && !is_hidden($area) && accessible_name($area, $xpath) === '') {
            $findings->add('area-alt-missing', $area);
        }
    }
    foreach ($xpath->query('//svg[@role="img"]') as $svg) {
        if ($svg instanceof DOMElement && !is_hidden($svg) && accessible_name($svg, $xpath) === '') {
            $findings->add('svg-img-name-missing', $svg);
        }
    }
}

/**
 * The media-library attachment an <img> came from, when WordPress marked it: the block editor
 * and the classic editor both add class wp-image-<id>.
 *
 * @return array<string, mixed>
 */
function image_context(DOMElement $img): array
{
    return preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $img->getAttribute('class'), $match) === 1
        ? ['attachment_id' => (int) $match[1]]
        : [];
}

/**
 * Whether alt text is a file name rather than a description: it ends in an image extension,
 * looks like a camera or screenshot name, or equals the image's own file name.
 */
function alt_is_filename(string $alt, string $src = ''): bool
{
    $alt = trim($alt);
    if ($alt === '') {
        return false;
    }
    if (preg_match('/\.(?:jpe?g|png|gif|webp|avif|svg|heic|bmp|tiff?)$/i', $alt) === 1) {
        return true;
    }
    if (preg_match('/^(?:img|dsc|dscn|dcim|pxl|mvimg|screenshot|screen shot|image|photo|wp)[-_ ]?\d[\w-]*$/i', $alt) === 1) {
        return true;
    }
    $path = (string) parse_url($src, PHP_URL_PATH);
    $name = $path !== '' ? pathinfo($path, PATHINFO_FILENAME) : '';
    if ($name === '') {
        return false;
    }
    // WordPress serves sized copies: photo-300x200.jpg, photo-scaled.jpg, photo-e1712345678.jpg.
    $name = (string) preg_replace('/(?:-\d+x\d+|-scaled|-rotated|-e\d{10,})+$/', '', $name);
    return normalise_name($alt) === normalise_name($name);
}

function normalise_name(string $value): string
{
    return trim((string) preg_replace('/[\s_\-.]+/', ' ', strtolower($value)));
}

function check_form_labels(DOMXPath $xpath, Findings $findings): void
{
    foreach ($xpath->query('//input|//select|//textarea') as $field) {
        if (!$field instanceof DOMElement || is_hidden($field)) {
            continue;
        }
        if (strtolower($field->nodeName) === 'input') {
            $type = strtolower(trim($field->getAttribute('type')));
            if (in_array($type, ['hidden', 'submit', 'reset', 'button', 'image'], strict: true)) {
                continue;
            }
        }
        if (field_name($field, $xpath) === '') {
            $findings->add('form-label-missing', $field);
        }
    }
}

function field_name(DOMElement $field, DOMXPath $xpath): string
{
    $name = accessible_name($field, $xpath, content: false);
    if ($name !== '') {
        return $name;
    }
    $id = $field->getAttribute('id');
    if ($id !== '') {
        foreach ($xpath->query('//label[@for=' . xpath_literal($id) . ']') as $label) {
            if ($label instanceof DOMElement && text_of($label, $xpath) !== '') {
                return text_of($label, $xpath);
            }
        }
    }
    for ($parent = $field->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
        if (strtolower($parent->nodeName) === 'label') {
            return text_of($parent, $xpath);
        }
    }
    return trim($field->getAttribute('placeholder'));
}

function check_links_and_buttons(DOMXPath $xpath, Findings $findings): void
{
    foreach ($xpath->query('//a[@href]') as $link) {
        if ($link instanceof DOMElement && !is_hidden($link) && accessible_name($link, $xpath) === '') {
            $findings->add('link-name-missing', $link);
        }
    }
    foreach ($xpath->query('//button|//*[@role="button"]|//input[@type]') as $button) {
        if (!$button instanceof DOMElement || is_hidden($button)) {
            continue;
        }
        if (strtolower($button->nodeName) === 'input') {
            $type = strtolower(trim($button->getAttribute('type')));
            // Submit and reset buttons have a built-in name ("Submit", "Reset").
            if ($type !== 'button' || trim($button->getAttribute('value')) !== '' || accessible_name($button, $xpath, content: false) !== '') {
                continue;
            }
            $findings->add('button-name-missing', $button);
            continue;
        }
        if (accessible_name($button, $xpath) === '') {
            $findings->add('button-name-missing', $button);
        }
    }
}

function check_ids(DOMXPath $xpath, Findings $findings): void
{
    $elements = [];
    foreach ($xpath->query('//*[@id]') as $element) {
        if ($element instanceof DOMElement && !in_ignored_subtree($element) && trim($element->getAttribute('id')) !== '') {
            $elements[$element->getAttribute('id')][] = $element;
        }
    }

    $referenced = [];
    foreach ($xpath->query('//label[@for]') as $label) {
        if (!$label instanceof DOMElement || in_ignored_subtree($label)) {
            continue;
        }
        $target = $label->getAttribute('for');
        $referenced[$target] = true;
        if ($target !== '' && !isset($elements[$target])) {
            $findings->add('label-for-missing-target', $label, ['missing_id' => $target]);
        }
    }
    $query = implode('|', array_map(static fn(string $attribute): string => '//*[@' . $attribute . ']', IDREF_ATTRIBUTES));
    foreach ($xpath->query($query) as $element) {
        if (!$element instanceof DOMElement || in_ignored_subtree($element)) {
            continue;
        }
        foreach (IDREF_ATTRIBUTES as $attribute) {
            if (!$element->hasAttribute($attribute)) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($element->getAttribute($attribute))) ?: [] as $id) {
                if ($id === '') {
                    continue;
                }
                $referenced[$id] = true;
                if (!isset($elements[$id])) {
                    $findings->add(
                        $attribute === 'aria-labelledby' ? 'aria-labelledby-missing-target' : 'aria-reference-missing-target',
                        $element,
                        ['attribute' => $attribute, 'missing_id' => $id],
                    );
                }
            }
        }
    }

    foreach ($elements as $id => $list) {
        if (count($list) > 1) {
            $findings->add(isset($referenced[$id]) ? 'duplicate-id-referenced' : 'duplicate-id', $list[1], ['id' => (string) $id, 'occurrences' => count($list)]);
        }
    }
}

function check_landmarks(DOMXPath $xpath, Findings $findings): void
{
    $mains = 0;
    foreach ($xpath->query('//main|//*[@role="main"]') as $main) {
        if ($main instanceof DOMElement && !is_hidden($main)) {
            $mains++;
        }
    }
    if ($mains > 1) {
        $findings->add('landmark-main-multiple', null, ['count' => $mains]);
    }
    if ($mains > 0) {
        return;
    }
    // A skip link: one of the first links on the page, pointing at an id that exists.
    $checked = 0;
    foreach ($xpath->query('//body//a[@href]') as $link) {
        if (!$link instanceof DOMElement || ++$checked > 5) {
            break;
        }
        $href = $link->getAttribute('href');
        if (str_starts_with($href, '#') && strlen($href) > 1 && $xpath->query('//*[@id=' . xpath_literal(substr($href, 1)) . ']')->length > 0) {
            return;
        }
    }
    $findings->add('bypass-missing');
}

function check_tabindex(DOMXPath $xpath, Findings $findings): void
{
    foreach ($xpath->query('//*[@tabindex]') as $element) {
        if ($element instanceof DOMElement && !in_ignored_subtree($element) && (int) trim($element->getAttribute('tabindex')) > 0) {
            $findings->add('tabindex-positive', $element, ['tabindex' => (int) trim($element->getAttribute('tabindex'))]);
        }
    }
}

function check_viewport(DOMXPath $xpath, Findings $findings): void
{
    foreach ($xpath->query('//meta[translate(@name,"VIEWPORT","viewport")="viewport"]') as $meta) {
        if (!$meta instanceof DOMElement) {
            continue;
        }
        $settings = [];
        foreach (preg_split('/[,;]/', strtolower($meta->getAttribute('content'))) ?: [] as $pair) {
            $parts = array_map('trim', explode('=', $pair, 2));
            if (count($parts) === 2) {
                $settings[$parts[0]] = $parts[1];
            }
        }
        $scalable = $settings['user-scalable'] ?? '';
        $maximum = $settings['maximum-scale'] ?? '';
        if (in_array($scalable, ['no', '0'], strict: true) || ($maximum !== '' && is_numeric($maximum) && (float) $maximum < 2)) {
            $findings->add('viewport-zoom-disabled', $meta, ['content' => $meta->getAttribute('content')]);
        }
    }
}

function check_iframes(DOMXPath $xpath, Findings $findings): void
{
    foreach ($xpath->query('//iframe|//frame') as $frame) {
        if (!$frame instanceof DOMElement || is_hidden($frame)) {
            continue;
        }
        if (in_array(strtolower(trim($frame->getAttribute('role'))), ['presentation', 'none'], strict: true)) {
            continue;
        }
        if (accessible_name($frame, $xpath, content: false) === '') {
            $findings->add('iframe-title-missing', $frame, ['src' => substr($frame->getAttribute('src'), 0, 200)]);
        }
    }
}

function check_tables(DOMXPath $xpath, Findings $findings): void
{
    foreach ($xpath->query('//table') as $table) {
        if (!$table instanceof DOMElement || is_hidden($table)) {
            continue;
        }
        if (in_array(strtolower(trim($table->getAttribute('role'))), ['presentation', 'none'], strict: true)) {
            continue;
        }
        // Only this table's own rows, not a nested table's.
        $rows = $xpath->query('./tr|./thead/tr|./tbody/tr|./tfoot/tr', $table);
        $widest = 0;
        foreach ($rows as $row) {
            $widest = max($widest, $xpath->query('./td|./th', $row)->length);
        }
        // One row or one column is a list or a layout box, not a grid that needs headers.
        if ($rows->length < 2 || $widest < 2) {
            continue;
        }
        $headers = $xpath->query('./tr/th|./thead/tr/th|./tbody/tr/th|./tfoot/tr/th|.//*[@role="columnheader" or @role="rowheader"]', $table);
        if ($headers->length === 0) {
            $findings->add('table-headers-missing', $table, ['rows' => $rows->length, 'columns' => $widest]);
        }
    }
}

/**
 * A simplified accessible-name computation: aria-labelledby, aria-label, the element's own
 * text alternative (alt, title, value), then its content, skipping hidden descendants.
 *
 * Simplified on purpose. The full algorithm needs CSS (pseudo-element content, display) that a
 * served-HTML audit does not have; what is computed here errs toward finding a name, so a
 * reported "no name" is one a person can confirm by reading the markup.
 */
function accessible_name(DOMElement $element, DOMXPath $xpath, bool $content = true): string
{
    $labelledby = trim($element->getAttribute('aria-labelledby'));
    if ($labelledby !== '') {
        $parts = [];
        foreach (preg_split('/\s+/', $labelledby) ?: [] as $id) {
            $target = $id !== '' ? $xpath->query('//*[@id=' . xpath_literal($id) . ']')->item(0) : null;
            if ($target instanceof DOMElement) {
                $parts[] = text_of($target, $xpath, include_hidden: true);
            }
        }
        $name = trim(implode(' ', $parts));
        if ($name !== '') {
            return $name;
        }
    }
    $label = trim($element->getAttribute('aria-label'));
    if ($label !== '') {
        return $label;
    }
    $tag = strtolower($element->nodeName);
    if (in_array($tag, ['img', 'area'], strict: true) || ($tag === 'input' && strtolower($element->getAttribute('type')) === 'image')) {
        $alt = trim($element->getAttribute('alt'));
        if ($alt !== '') {
            return $alt;
        }
    }
    if ($tag === 'svg') {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'title' && trim($child->textContent) !== '') {
                return trim($child->textContent);
            }
        }
    }
    if ($content && !in_array($tag, ['img', 'area', 'input', 'select', 'textarea', 'iframe', 'frame'], strict: true)) {
        $text = text_of($element, $xpath);
        if ($text !== '') {
            return $text;
        }
    }
    return trim($element->getAttribute('title'));
}

/**
 * The text a screen reader would take from an element's content: text nodes, and the names of
 * images and labelled elements inside it, skipping hidden subtrees.
 */
function text_of(DOMNode $node, DOMXPath $xpath, bool $include_hidden = false): string
{
    $parts = [];
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $parts[] = $child->textContent;
            continue;
        }
        if (!$child instanceof DOMElement) {
            continue;
        }
        if (!$include_hidden && is_hidden_self($child)) {
            continue;
        }
        $tag = strtolower($child->nodeName);
        if (in_array($tag, ['script', 'style', 'template', 'noscript'], strict: true)) {
            continue;
        }
        if ($child->hasAttribute('aria-label') || $child->hasAttribute('aria-labelledby') || in_array($tag, ['img', 'svg', 'area'], strict: true) || ($tag === 'input' && strtolower($child->getAttribute('type')) === 'image')) {
            $parts[] = accessible_name($child, $xpath);
            continue;
        }
        $parts[] = text_of($child, $xpath, $include_hidden);
    }
    return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
}

/** Hidden from assistive technology: the element or any ancestor, or inside inert markup. */
function is_hidden(DOMElement $element): bool
{
    for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
        if (is_hidden_self($node) || in_array(strtolower($node->nodeName), ['template', 'noscript', 'script'], strict: true)) {
            return true;
        }
    }
    return false;
}

function is_hidden_self(DOMElement $element): bool
{
    if ($element->hasAttribute('hidden') || strtolower(trim($element->getAttribute('aria-hidden'))) === 'true') {
        return true;
    }
    if (strtolower($element->nodeName) === 'input' && strtolower(trim($element->getAttribute('type'))) === 'hidden') {
        return true;
    }
    return preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)/i', $element->getAttribute('style')) === 1;
}

/** Inside <template> or <noscript>: markup no visitor with scripting gets as part of the page. */
function in_ignored_subtree(DOMElement $element): bool
{
    for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
        if (in_array(strtolower($node->nodeName), ['template', 'noscript'], strict: true)) {
            return true;
        }
    }
    return false;
}

/** tag#id.class.class, enough for a person to find the element in the page source. */
function selector(DOMElement $element): string
{
    $selector = strtolower($element->nodeName);
    $id = trim($element->getAttribute('id'));
    if ($id !== '') {
        $selector .= '#' . $id;
    }
    $classes = array_slice(array_values(array_filter(preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [])), 0, 3);
    foreach ($classes as $class) {
        $selector .= '.' . $class;
    }
    return $selector;
}

/** The element's opening tag, shortened: site markup to show, never to follow. */
function snippet(DOMElement $element): string
{
    $html = (string) $element->ownerDocument?->saveHTML($element);
    $open = preg_match('/^<[^>]*>/s', $html, $match) === 1 ? $match[0] : $html;
    $open = (string) preg_replace('/\s+/', ' ', $open);
    return strlen($open) > 200 ? substr($open, 0, 197) . '...' : $open;
}

/** A string as an XPath literal, whatever quotes it contains. */
function xpath_literal(string $value): string
{
    if (!str_contains($value, "'")) {
        return "'" . $value . "'";
    }
    if (!str_contains($value, '"')) {
        return '"' . $value . '"';
    }
    return "concat('" . str_replace("'", "',\"'\",'", $value) . "')";
}
