<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\OAuth\Bridge;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

function to_psr7(WP_REST_Request $req): ServerRequestInterface
{
    $headers = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($req->get_headers() as $name => $values) {
        $headers[$name] = $values;
    }
    $psr = new ServerRequest($req->get_method(), rest_url($req->get_route()), $headers);
    $psr = $psr
        ->withQueryParams($req->get_query_params())
        ->withParsedBody($req->get_body_params())
        ->withCookieParams($_COOKIE);
    $body = $req->get_body();
    if ($body !== '') {
        $psr = $psr->withBody(Stream::create($body));
    }
    return $psr;
}

function from_psr7(ResponseInterface $resp): WP_REST_Response
{
    $body = (string) $resp->getBody();
    // @mago-expect analysis:mixed-assignment
    $data = json_decode($body, associative: true);
    if (!is_array($data)) {
        $data = ['raw' => $body];
    }
    $out = new WP_REST_Response($data, $resp->getStatusCode());
    foreach ($resp->getHeaders() as $name => $values) {
        $out->header((string) $name, implode(', ', $values));
    }
    return $out;
}

function new_psr7_response(): ResponseInterface
{
    return (new Psr17Factory())->createResponse(200);
}

/**
 * Build a minimal PSR-7 request from PHP superglobals.
 * Used in the Bearer middleware where WP_REST_Request is not available.
 */
function psr7_from_globals(): ServerRequestInterface
{
    $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '';
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : 'localhost';
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The request URI is handed unchanged to the OAuth library's PSR-7 request; sanitize_text_field() would strip its percent-encoded octets and change the query parameters it carries.
    $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
    $uri = ($isHttps ? 'https' : 'http') . '://' . $host . $path;

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (!str_starts_with($key, 'HTTP_')) {
            continue;
        }
        if (!is_string($value)) {
            continue;
        }
        $name = str_replace(search: '_', replace: '-', subject: strtolower(substr(string: $key, offset: 5)));
        $headers[$name] = [$value];
    }

    return new ServerRequest($method, $uri, $headers);
}
