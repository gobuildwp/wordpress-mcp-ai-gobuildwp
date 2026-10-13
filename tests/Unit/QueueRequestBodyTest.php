<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

use function WPPilot\Abilities\Gutenberg\rest_claim_next_item;
use function WPPilot\Abilities\Gutenberg\rest_complete_item;
use function WPPilot\Abilities\Gutenberg\rest_fail_item;
use function WPPilot\Abilities\Gutenberg\rest_json_params;

// The Block Editor Queue's REST layer, loaded here rather than in the bootstrap so its ability
// registrations stay out of the boot snapshot the surface tests assert against.
require_once dirname(__DIR__, levels: 2) . '/includes/abilities/gutenberg/bootstrap.php';
require_once dirname(__DIR__, levels: 2) . '/includes/abilities/gutenberg/rest.php';

/**
 * What the queue endpoints do with a request that carries no usable body.
 *
 * These three endpoints run against an item that is already leased, so how they fail matters more
 * than for most: a fatal here takes the process down mid-lease and leaves the batch in the running
 * state with nothing alive to complete or release it. The lease then has to time out before anybody
 * can touch that batch again.
 *
 * get_json_params() answers null for a request with no body, for one whose body did not parse, and
 * for one sent without the JSON content type. All three have to come back as a refusal.
 */
final class QueueRequestBodyTest extends TestCase
{
    public function test_absent_json_body_is_refused_with_a_client_error(): void
    {
        $result = rest_json_params(new WP_REST_Request(json_params: null));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('gutenberg_invalid_body', $result->get_error_code());
        self::assertSame(400, $result->get_error_data()['status'] ?? null);
    }

    /**
     * A body that decodes to something other than an object is refused too. json_decode() answers a
     * scalar for `4` or `"x"` and a list for `[]`, none of which carries the named fields these
     * endpoints read.
     */
    public function test_non_object_json_body_is_refused(): void
    {
        foreach ([4, 'x', true] as $decoded) {
            self::assertInstanceOf(WP_Error::class, rest_json_params(new WP_REST_Request($decoded)));
        }
    }

    public function test_object_body_is_read_and_keeps_only_named_fields(): void
    {
        $params = rest_json_params(new WP_REST_Request(['lease_owner' => 'runtime-1', 7 => 'positional']));

        self::assertSame(['lease_owner' => 'runtime-1'], $params);
    }

    /**
     * The endpoints themselves stop at the refusal rather than carrying on with empty values, which
     * is what turns a missing body into a failed item report instead of a fatal.
     */
    public function test_lease_endpoints_return_the_refusal_instead_of_running(): void
    {
        foreach (
            [
                'claim-next' => rest_claim_next_item(...),
                'complete' => rest_complete_item(...),
                'fail' => rest_fail_item(...),
            ] as $label => $endpoint
        ) {
            $result = $endpoint(new WP_REST_Request(json_params: null));

            self::assertInstanceOf(WP_Error::class, $result, sprintf('%s accepted a body-less request', $label));
            self::assertSame('gutenberg_invalid_body', $result->get_error_code());
        }
    }
}
