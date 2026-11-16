<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Content-loss guard for abilities that replace a post's whole post_content.
 *
 * Two ways an agent loses content without meaning to:
 *
 * 1. It writes a shorter body than it read: a truncated model reply, a summary sent where
 *    the full text belonged, or blocks the agent failed to round-trip. The guard measures the
 *    visible text and the media items before and after, and refuses a write that drops more
 *    than half of either unless the call sets `confirm_content_loss: true`.
 * 2. Someone edited the post after the agent read it. The agent's write is built from the
 *    copy it read, so writing it erases that edit. Each read ability notes the content the
 *    agent saw; a later content write from the same agent refuses when the stored content no
 *    longer matches, unless the call sets `confirm_content_loss: true`.
 *
 * The flag is its own name rather than `confirm` because gutenberg-write-content is a
 * destructive ability: the confirmation gate already requires `confirm: true` on every call
 * to it, so `confirm` could never mean "I accept losing this content" there.
 *
 * Limits: text and media are measured from saved markup, so content a dynamic block renders
 * from elsewhere (a query loop, a shortcode's output) is not counted; the read note lives in
 * a transient for a day and is per agent session (or per user when the call has no session),
 * so an agent that never read the post through GoBuildWP is not checked for a newer edit.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** A drop larger than this share of the text or the media is refused without confirmation. */
const WPPILOT_CONTENT_LOSS_RATIO = 0.5;

/**
 * Text shorter than this (in characters) is not checked: rewriting a one-line stub is routine
 * and asking to confirm it would only teach agents to set the flag on every call.
 */
const WPPILOT_CONTENT_LOSS_MIN_TEXT = 100;

/** How long a read note is kept. */
const WPPILOT_CONTENT_READ_TTL = 86400;

/**
 * Visible text length (characters) and media count in a post_content string.
 *
 * Media counts img, video, audio, iframe, embed and object tags, plus gallery, video, audio,
 * embed and playlist shortcodes, plus dynamic blocks that save no HTML but carry a media id or
 * URL attribute. Text drops script, style and comments (which includes block delimiters), then
 * counts what is left after tags are removed and whitespace collapsed. A dynamic block saves its
 * attributes, not its text, so a string attribute that reads as prose counts as text too.
 *
 * @return array{text: int, media: int}
 */
function wppilot_content_measure(string $content): array
{
    $media = 0;
    $text_parts = [];

    if (function_exists('parse_blocks') && str_contains($content, '<!-- wp:')) {
        foreach (wppilot_content_measure_blocks(parse_blocks($content)) as $part) {
            if ($part === 'media') {
                $media++;
            } else {
                $text_parts[] = $part;
            }
        }
    }

    $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $content);
    $html = (string) preg_replace('#<!--.*?-->#s', ' ', $html);

    $media += (int) preg_match_all('#<(img|video|audio|iframe|embed|object)\b#i', $html);
    $media += (int) preg_match_all('#\[(gallery|video|audio|embed|playlist)\b#i', $html);

    $text = (string) preg_replace('#\[/?[a-z][a-z0-9_-]*[^\]]*\]#i', ' ', $html);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(implode(' ', [$text, ...$text_parts]));
    $text = (string) preg_replace('/\s+/u', ' ', $text);

    return ['text' => mb_strlen(trim($text), 'UTF-8'), 'media' => $media];
}

/**
 * Text and media found in the attributes of blocks that saved no HTML of their own.
 *
 * @param array<int, mixed> $blocks parse_blocks() output.
 * @return list<string> 'media' for each media item, otherwise a text fragment.
 */
function wppilot_content_measure_blocks(array $blocks): array
{
    $found = [];
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $has_html = is_string($block['innerHTML'] ?? null) && trim($block['innerHTML']) !== '';
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        if (!$has_html) {
            foreach ($attrs as $key => $value) {
                if ($value === '' || $value === 0 || $value === null || $value === [] || $value === false) {
                    continue;
                }
                if (preg_match('/image|video|audio|media|gallery|poster|^src$|^url$/i', (string) $key) === 1) {
                    $found[] = 'media';
                    continue;
                }
                // A string with a space in it reads as prose; ids, slugs, colours and JSON do not.
                if (is_string($value) && preg_match('/\S\s+\S/u', $value) === 1 && !str_starts_with(ltrim($value), '{')) {
                    $found[] = strip_tags($value);
                }
            }
        }
        if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
            array_push($found, ...wppilot_content_measure_blocks($block['innerBlocks']));
        }
    }

    return $found;
}

/**
 * The refusal for a write that drops more than half the text or media, or null when it does not.
 *
 * @param array{text: int, media: int} $before
 * @param array{text: int, media: int} $after
 */
function wppilot_content_loss_error(int $post_id, array $before, array $after): ?WP_Error
{
    $losses = [];
    if ($before['text'] >= WPPILOT_CONTENT_LOSS_MIN_TEXT && $after['text'] < $before['text'] * (1 - WPPILOT_CONTENT_LOSS_RATIO)) {
        $losses[] = sprintf(
            'visible text from %d to %d characters (%d%% removed)',
            $before['text'],
            $after['text'],
            (int) round(100 * ($before['text'] - $after['text']) / $before['text']),
        );
    }
    if ($before['media'] > 0 && $after['media'] < $before['media'] * (1 - WPPILOT_CONTENT_LOSS_RATIO)) {
        $losses[] = sprintf('media items from %d to %d', $before['media'], $after['media']);
    }
    if ($losses === []) {
        return null;
    }

    return new WP_Error(
        'wppilot_content_loss',
        sprintf(
            'This write would cut post %d\'s %s. Nothing was changed. If removing that content is intended, re-call with confirm_content_loss: true; otherwise send the full content.',
            $post_id,
            implode(' and ', $losses),
        ),
        ['status' => 409, 'before' => $before, 'after' => $after, 'post_id' => $post_id],
    );
}

/** Whose read notes a call sees: the agent session, or the signed-in user outside one. */
function wppilot_content_read_actor(): string
{
    $session = function_exists('wppilot_current_session_id') ? wppilot_current_session_id() : '';

    return $session !== '' ? 's:' . $session : 'u:' . get_current_user_id();
}

function wppilot_content_read_key(int $post_id): string
{
    return 'wppilot_cread_' . md5(wppilot_content_read_actor() . '|' . $post_id);
}

/** Record that this agent has now seen the post's current content. */
function wppilot_content_note_read(int $post_id): void
{
    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return;
    }
    set_transient(wppilot_content_read_key($post_id), [
        'hash' => md5((string) $post->post_content),
        'modified_gmt' => (string) $post->post_modified_gmt,
    ], WPPILOT_CONTENT_READ_TTL);
}

/**
 * The refusal for overwriting content someone changed after this agent read it, or null.
 *
 * No note means the agent did not read the post through GoBuildWP in this session; there is
 * nothing to compare against, so the write is not refused for this reason.
 */
function wppilot_content_changed_since_read_error(WP_Post $post): ?WP_Error
{
    /** @var mixed $note */
    $note = get_transient(wppilot_content_read_key((int) $post->ID));
    if (!is_array($note) || !is_string($note['hash'] ?? null)) {
        return null;
    }
    if (hash_equals($note['hash'], md5((string) $post->post_content))) {
        return null;
    }

    $editor = '';
    $last = (int) get_post_meta((int) $post->ID, '_edit_last', true);
    if ($last > 0) {
        $user = get_userdata($last);
        $editor = $user instanceof WP_User ? sprintf(' by %s (user %d)', $user->display_name, $last) : sprintf(' by user %d', $last);
    }

    return new WP_Error(
        'wppilot_post_changed_since_read',
        sprintf(
            'Post %d\'s content was changed%s at %s UTC, after you read it at %s UTC. Writing now would overwrite that edit. Nothing was changed. Read the post again and rebuild your change from the current content, or re-call with confirm_content_loss: true to overwrite it.',
            (int) $post->ID,
            $editor,
            (string) $post->post_modified_gmt,
            (string) ($note['modified_gmt'] ?? 'unknown'),
        ),
        ['status' => 409, 'post_id' => (int) $post->ID],
    );
}

/**
 * Both checks, for a write replacing $post's content with $new_content.
 *
 * Returns null when the write may go ahead. A confirmed call skips both checks.
 */
function wppilot_content_write_guard(WP_Post $post, string $new_content, bool $confirmed): ?WP_Error
{
    if ($confirmed) {
        return null;
    }
    $changed = wppilot_content_changed_since_read_error($post);
    if ($changed !== null) {
        return $changed;
    }

    return wppilot_content_loss_error(
        (int) $post->ID,
        wppilot_content_measure((string) $post->post_content),
        wppilot_content_measure($new_content),
    );
}
