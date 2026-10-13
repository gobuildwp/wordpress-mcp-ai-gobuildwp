<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

if (!defined('ABSPATH')) {
    exit();
}

/*
 * The in-browser half of the header check. The server cannot lay a page out, so the geometry
 * checks run where a layout exists: a page opened with ?wppilot-kit-header-probe=1 measures its
 * own header at the width it is opened at - menu rows, labels that wrap, overlapping elements,
 * horizontal scroll, a menu with no way to open it, a menu button that does not open, header
 * height, text contrast from computed colours, broken images - and publishes the findings as
 * window.siteHeaderProbe, the data-wppilot-kit-header-probe attribute on <html>, and a console
 * line. It reads the page and changes nothing; it opens and closes the menu once to see it work.
 *
 * Only for someone who may change the header. The browser that opens the URL is often not signed
 * in, so the URL carries a token: the user it was issued to, an expiry 15 minutes out and an HMAC
 * of both under a key of its own. It turns the probe on for that page view and does nothing else:
 * it is not a WordPress nonce and no other check accepts it. Anyone else (an anonymous visitor
 * with ?wppilot-kit-header-probe=1, an expired or altered token) gets the page exactly as
 * everyone does, so a page cache never stores a variant; a page that does print the probe is
 * sent with no-cache headers and DONOTCACHEPAGE, and is never cached.
 */

const PROBE_ARG = 'wppilot-kit-header-probe';

/** How long a probe URL works. */
const PROBE_TTL = 900;

/** The probe URL for a page, signed for the current user. */
function probe_url(string $url): string
{
    $user = get_current_user_id();

    return add_query_arg(PROBE_ARG, $user > 0 ? probe_token($user, time() + PROBE_TTL, probe_key()) : '1', $url);
}

/** The probe's own signing key, derived from the site's salt so it matches no other token's. */
function probe_key(): string
{
    return hash_hmac('sha256', 'wppilot-kit-header-probe', wp_salt('nonce'));
}

/** "{user}.{expires}.{hmac}". */
function probe_token(int $user_id, int $expires, string $key): string
{
    return $user_id . '.' . $expires . '.' . hash_hmac('sha256', 'wppilot-kit-header-probe|' . $user_id . '|' . $expires, $key);
}

/** The user a probe token was issued to, or 0 when it is malformed, altered, expired or too far ahead. */
function probe_token_user(string $token, int $now, string $key): int
{
    if (preg_match('/^([1-9]\d{0,19})\.(\d{1,12})\.([0-9a-f]{64})$/D', $token, $m) !== 1) {
        return 0;
    }
    $user = (int) $m[1];
    $expires = (int) $m[2];
    if ($expires < $now || $expires > $now + PROBE_TTL + 60) {
        return 0;
    }

    return hash_equals(probe_token($user, $expires, $key), $token) ? $user : 0;
}

/** Whether this request may run the probe: a signed-in header editor, or a valid token of one. */
function probe_allowed(): bool
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the value is verified here (an HMAC token, or the signed-in user's capability).
    $raw = $_GET[PROBE_ARG] ?? null;
    if (!is_string($raw) || $raw === '' || strlen($raw) > 120) {
        return false;
    }
    if (current_user_can('edit_theme_options')) {
        return true;
    }
    $user = probe_token_user(sanitize_text_field(wp_unslash($raw)), time(), probe_key());

    return $user > 0 && user_can($user, 'edit_theme_options');
}

/** Set once the request is allowed the probe, before any output. */
function probing(?bool $set = null): bool
{
    static $on = false;
    if ($set !== null) {
        $on = $set;
    }

    return $on;
}

/**
 * On template_redirect, before output: decide whether this page view prints the probe, and if it
 * does, keep it out of every cache and keep the token out of Referer headers.
 */
function start_probe(): void
{
    if (is_admin() || !probe_allowed()) {
        return;
    }
    probing(true);
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    nocache_headers();
    if (!headers_sent()) {
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
    }
}

/** Print the probe on a page view start_probe() allowed. */
function print_probe(): void
{
    if (!probing()) {
        return;
    }
    wp_print_inline_script_tag(probe_script(), ['id' => 'wppilot-kit-header-probe']);
}

function probe_script(): string
{
    return <<<'JS'
(function () {
  var MAX_HEIGHT = function (w) { return w >= 1025 ? 180 : (w >= 768 ? 150 : 120); };
  function f(severity, check, id, detail, fix) { return { severity: severity, check: check, element_id: id, detail: detail, fix: fix }; }
  function visible(el) {
    if (!el || !el.getBoundingClientRect) { return false; }
    var r = el.getBoundingClientRect(), cs = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && parseFloat(cs.opacity) > 0;
  }
  // Shown, even when squeezed to zero width: a collapsed widget still paints its text.
  function shown(el) {
    var r = el.getBoundingClientRect(), cs = getComputedStyle(el);
    return (r.width > 0 || r.height > 0) && cs.visibility !== 'hidden' && cs.display !== 'none' && parseFloat(cs.opacity) > 0;
  }
  function idOf(el) {
    var e = el.closest('[data-id]');
    if (e) { return e.getAttribute('data-id'); }
    for (var n = el; n && n.classList; n = n.parentElement) {
      for (var i = 0; i < n.classList.length; i++) { if (n.classList[i].indexOf('wp-block-') === 0) { return n.classList[i]; } }
    }
    return el.tagName.toLowerCase();
  }
  function rgba(s) {
    var m = s.match(/rgba?\(([\d.]+)[ ,]+([\d.]+)[ ,]+([\d.]+)(?:[ ,\/]+([\d.]+))?/);
    return m ? [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]] : null;
  }
  function lum(c) {
    var a = [c[0], c[1], c[2]].map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
  }
  function ratio(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
  function blend(top, under) { var a = top[3]; return [top[0] * a + under[0] * (1 - a), top[1] * a + under[1] * (1 - a), top[2] * a + under[2] * (1 - a), 1]; }
  function backdrop(el) {
    // What is behind the text: solid layers blended down to the first opaque one. A gradient
    // counts as each of its colours (the text must pass on all of them); a picture cannot be
    // judged from styles, so text over one is skipped.
    var layers = [];
    for (var n = el; n && n.nodeType === 1; n = n.parentElement) {
      var cs = getComputedStyle(n), img = cs.backgroundImage || 'none';
      if (img !== 'none' && img.indexOf('gradient') === -1) { return null; }
      if (img !== 'none') {
        var stops = (img.match(/rgba?\([^)]*\)/g) || []).map(rgba).filter(Boolean);
        if (stops.length) { layers.push({ stops: stops }); if (stops.every(function (c) { return c[3] >= 1; })) { break; } }
      }
      var c = rgba(cs.backgroundColor);
      if (c && c[3] > 0) { layers.push({ stops: [c] }); if (c[3] >= 1) { break; } }
    }
    var unders = [[255, 255, 255, 1]];
    for (var i = layers.length - 1; i >= 0; i--) {
      var next = [];
      layers[i].stops.forEach(function (c) { unders.forEach(function (u) { next.push(blend(c, u)); }); });
      unders = next;
    }
    return unders;
  }
  function run() {
    var W = window.innerWidth, out = { width: W, header_height: 0, findings: [], checked: [] };
    var root = document.querySelector('[data-elementor-type="header"]') || document.querySelector('header.wp-block-template-part') || document.querySelector('.wp-block-template-part header');
    if (!root) { out.findings.push(f('error', 'header_served', '', 'No header on this page.', 'Check the header\'s display conditions.')); return finish(out); }
    var box = root.getBoundingClientRect();
    out.header_height = Math.round(box.height);
    out.checked.push('header_height');
    if (box.height > MAX_HEIGHT(W)) {
      out.findings.push(f('error', 'header_too_tall', idOf(root.querySelector('[data-id]') || root), 'The header is ' + Math.round(box.height) + 'px tall at ' + W + 'px; more than ' + MAX_HEIGHT(W) + 'px pushes the page down.', 'Reduce padding, the logo size, or rows (hide the top bar or the second row at this width).'));
    }
    out.checked.push('horizontal_scroll');
    if (document.documentElement.scrollWidth > W + 1) {
      var wide = Array.prototype.filter.call(root.querySelectorAll('*'), function (e) { return visible(e) && e.getBoundingClientRect().right > W + 1; });
      out.findings.push(f('error', 'horizontal_scroll', wide.length ? idOf(wide[wide.length - 1]) : '', 'The page scrolls sideways at ' + W + 'px (' + document.documentElement.scrollWidth + 'px wide)' + (wide.length ? '; the header runs past the edge.' : '.'), 'Let that element shrink or hide it at this width; check fixed widths and nowrap rows.'));
    }
    out.checked.push('nav_rows');
    var navs = root.querySelectorAll('nav.elementor-nav-menu--main, .wp-block-navigation__container');
    var anyNav = false;
    Array.prototype.forEach.call(navs, function (nav) {
      if (!visible(nav) || nav.closest('.wp-block-navigation__responsive-container.is-menu-open')) { return; }
      var items = Array.prototype.filter.call(nav.querySelectorAll(':scope > ul > li, :scope > li'), visible);
      if (!items.length) { return; }
      anyNav = true;
      var tops = [];
      items.forEach(function (li) { var t = Math.round(li.getBoundingClientRect().top); if (!tops.some(function (x) { return Math.abs(x - t) < 6; })) { tops.push(t); } });
      if (tops.length > 1) {
        out.findings.push(f('error', 'nav_wraps', idOf(nav), 'The menu wraps onto ' + tops.length + ' rows at ' + W + 'px.', 'Give the menu the free space (flex-grow, nowrap), tighten item padding or font size, shorten labels, or switch to the menu button at this width.'));
      }
      items.forEach(function (li) {
        var a = li.querySelector('a');
        if (!a || !visible(a)) { return; }
        var lh = parseFloat(getComputedStyle(a).lineHeight) || parseFloat(getComputedStyle(a).fontSize) * 1.3;
        var text = a.getBoundingClientRect().height - parseFloat(getComputedStyle(a).paddingTop) - parseFloat(getComputedStyle(a).paddingBottom);
        if (text > lh * 1.6) {
          out.findings.push(f('error', 'nav_label_wraps', idOf(a), 'The label "' + a.textContent.trim() + '" breaks onto two lines at ' + W + 'px.', 'Keep labels on one line (white-space: nowrap), or switch to the menu button at this width.'));
        }
        if (a.getBoundingClientRect().right > W + 1) {
          out.findings.push(f('error', 'nav_does_not_fit', idOf(nav), 'The menu runs off the screen at ' + W + 'px.', 'Switch to the menu button at this width.'));
        }
      });
    });
    out.checked.push('overlap');
    var leaves = Array.prototype.filter.call(root.querySelectorAll('.elementor-widget, .wp-block-site-logo, .wp-block-site-title, .wp-block-image, .wp-block-navigation, .wp-block-buttons, .wc-block-mini-cart, .wp-block-social-links, p'), function (e) {
      return shown(e) && !e.closest('.elementor-nav-menu--dropdown, .wp-block-navigation__responsive-container.is-menu-open, .sub-menu, .wp-block-navigation__submenu-container');
    });
    leaves = leaves.filter(function (e) { return !leaves.some(function (o) { return o !== e && e.contains(o); }); });
    // What each element paints: its box, and its text and pictures, which can spill out of a
    // box that flexbox squeezed narrower than its content.
    var painted = leaves.map(function (e) {
      var r = e.getBoundingClientRect(), box = { left: r.left, right: r.right, top: r.top, bottom: r.bottom }, spill = 0;
      var walker = document.createTreeWalker(e, NodeFilter.SHOW_TEXT), node;
      while ((node = walker.nextNode())) {
        if (!node.textContent.trim() || !shown(node.parentElement) || node.parentElement.closest('.elementor-nav-menu--dropdown, .sub-menu, .wp-block-navigation__submenu-container, .elementor-screen-only, .screen-reader-text')) { continue; }
        var range = document.createRange(); range.selectNodeContents(node);
        Array.prototype.forEach.call(range.getClientRects(), function (t) {
          if (t.width < 1) { return; }
          spill = Math.max(spill, r.left - t.left, t.right - r.right);
          box.left = Math.min(box.left, t.left); box.right = Math.max(box.right, t.right); box.top = Math.min(box.top, t.top); box.bottom = Math.max(box.bottom, t.bottom);
        });
      }
      if (spill > 2) {
        out.findings.push(f('error', 'text_overflows', idOf(e), 'Text spills ' + Math.round(spill) + 'px out of its element at ' + W + 'px (the element is narrower than its text).', 'Give it _element_width: auto and flex-shrink 0, a smaller font at this width, or hide it here.'));
      }
      return box;
    });
    for (var i = 0; i < leaves.length; i++) {
      for (var j = i + 1; j < leaves.length; j++) {
        var a = painted[i], b = painted[j];
        var x = Math.min(a.right, b.right) - Math.max(a.left, b.left), y = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
        if (x > 2 && y > 2) {
          out.findings.push(f('error', 'overlap', idOf(leaves[i]) + ' x ' + idOf(leaves[j]), 'Two header elements overlap at ' + W + 'px by ' + Math.round(x) + 'x' + Math.round(y) + 'px.', 'Give each element a content width (_element_width: auto) and let only one grow; hide or shrink what does not fit at this width.'));
        }
      }
    }
    out.checked.push('menu_reachable');
    var toggle = Array.prototype.filter.call(root.querySelectorAll('.elementor-menu-toggle, .wp-block-navigation__responsive-container-open'), visible)[0];
    if (!anyNav && !toggle && navs.length) {
      out.findings.push(f('error', 'menu_unreachable', '', 'At ' + W + 'px the menu is hidden and no menu button shows.', 'Show the menu button at this width (dropdown breakpoint / overlayMenu).'));
    }
    out.checked.push('raw_language_list');
    Array.prototype.forEach.call(root.querySelectorAll('li.lang-item'), function (li) {
      if (!li.closest('.elementor-nav-menu, .wp-block-navigation') && visible(li)) {
        out.findings.push(f('error', 'raw_language_list', idOf(li), 'The languages show as a bare list.', 'Use the language switcher menu ({{language_switcher}}) instead.'));
      }
    });
    out.checked.push('empty_cart');
    Array.prototype.forEach.call(root.querySelectorAll('.elementor-menu-cart__toggle_button, .wc-block-mini-cart__button'), function (btn) {
      if (!visible(btn)) { return; }
      var icon = Array.prototype.some.call(btn.querySelectorAll('svg, i'), visible);
      if (!icon) { out.findings.push(f('error', 'empty_cart', idOf(btn), 'The cart button shows no icon.', 'Set the cart widget\'s icon.')); }
    });
    out.checked.push('broken_images');
    Array.prototype.forEach.call(root.querySelectorAll('img'), function (img) {
      if (img.complete && img.naturalWidth === 0 && visible(img)) { out.findings.push(f('error', 'broken_image', idOf(img), 'The image ' + img.getAttribute('src') + ' does not load.', 'Pick an image that exists in the media library.')); }
    });
    out.checked.push('contrast');
    var seen = 0;
    Array.prototype.forEach.call(root.querySelectorAll('a, span, p, h1, h2, h3, h4, h5, h6, div, button, li'), function (el) {
      if (seen > 80 || !visible(el) || el.closest('.elementor-nav-menu--dropdown, .sub-menu, .wp-block-navigation__submenu-container, .elementor-menu-cart__container')) { return; }
      var own = Array.prototype.some.call(el.childNodes, function (n) { return n.nodeType === 3 && n.textContent.trim() !== ''; });
      if (!own) { return; }
      seen++;
      var cs = getComputedStyle(el), fg = rgba(cs.color), bgs = backdrop(el);
      if (!fg || !bgs) { return; }
      var size = parseFloat(cs.fontSize), bold = parseInt(cs.fontWeight, 10) >= 700;
      var min = size >= 24 || (size >= 18.66 && bold) ? 3 : 4.5, r = Infinity, bg = bgs[0];
      bgs.forEach(function (b) { var x = ratio(blend(fg, b), b); if (x < r) { r = x; bg = b; } });
      if (r < min) {
        out.findings.push(f('error', 'contrast', idOf(el), '"' + el.textContent.trim().slice(0, 40) + '" is ' + cs.color + ' on ' + 'rgb(' + bg.slice(0, 3).map(Math.round).join(',') + '): ' + r.toFixed(2) + ':1, below ' + min + ':1.', 'Darken the text or lighten the background (or the reverse) until it passes.'));
      }
    });
    if (!toggle) { return finish(out); }
    out.checked.push('menu_opens');
    toggle.click();
    setTimeout(function () {
      var open = document.querySelector('.elementor-menu-toggle.elementor-active + .elementor-nav-menu--dropdown, .wp-block-navigation__responsive-container.is-menu-open');
      var opened = open && open.getBoundingClientRect().height > 20;
      if (!opened) { out.findings.push(f('error', 'menu_does_not_open', idOf(toggle), 'The menu button at ' + W + 'px does not open the menu.', 'Check that the menu widget has its dropdown, and that nothing covers the button.')); }
      var close = document.querySelector('.wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation__responsive-container-close');
      (close || toggle).click();
      finish(out);
    }, 600);
  }
  function finish(out) {
    out.passed = !out.findings.some(function (x) { return x.severity === 'error'; });
    window.siteHeaderProbe = out;
    document.documentElement.setAttribute('data-wppilot-kit-header-probe', JSON.stringify(out));
    if (window.console) { console.info('wppilot-kit-header-probe ' + JSON.stringify(out)); }
  }
  function start() { var go = function () { setTimeout(run, 400); }; if (document.fonts && document.fonts.ready) { document.fonts.ready.then(go); } else { go(); } }
  if (document.readyState === 'complete') { start(); } else { window.addEventListener('load', start); }
})();
JS;
}
