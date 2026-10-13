<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WooCommerce's live product counters stay out of post before-images, so undoing a product edit
 * (WooCommerce's own product-update ability included) neither rewinds the sales count nor fails
 * its own check because an order arrived in between.
 */
final class ProductCounterSnapshotTest extends TestCase
{
    public function test_only_product_counters_are_live(): void
    {
        $this->assertTrue(wppilot_post_meta_is_live_counter('product', 'total_sales'));
        $this->assertTrue(wppilot_post_meta_is_live_counter('product_variation', '_wc_average_rating'));
        $this->assertFalse(wppilot_post_meta_is_live_counter('product', '_regular_price'));
        $this->assertFalse(wppilot_post_meta_is_live_counter('post', 'total_sales'));
    }

    public function test_the_fingerprint_ignores_counters_an_older_before_image_still_holds(): void
    {
        $post = ['ID' => 5, 'post_type' => 'product', 'post_title' => 'Loaf'];
        $old = ['post' => $post, 'meta' => ['_price' => ['3'], 'total_sales' => ['4'], '_wc_review_count' => ['1']], 'terms' => []];
        $now = ['post' => $post, 'meta' => ['_price' => ['3'], 'total_sales' => ['9']], 'terms' => []];

        $this->assertSame(wppilot_post_snapshot_fingerprint($old), wppilot_post_snapshot_fingerprint($now));
        $this->assertNotSame(
            wppilot_post_snapshot_fingerprint($old),
            wppilot_post_snapshot_fingerprint(['post' => $post, 'meta' => ['_price' => ['4']], 'terms' => []]),
        );
    }
}
