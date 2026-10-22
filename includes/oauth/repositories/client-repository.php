<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The OAuth clients table created in includes/oauth/schema.php has no WordPress API. Not cached: a client registered, renamed or deleted must be seen by the very next token or authorize request. Every query is prepared, with the table bound as a %i identifier.

namespace WPPilot\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

if (!defined('ABSPATH')) {
    exit();
}

// @mago-expect lint:single-class-per-file
// ClientEntity and ClientRepository are tightly coupled and intentionally in the same file.
final class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /** @param list<string> $uris */
    public function setRedirectUri(array $uris): void
    {
        $this->redirectUri = $uris;
    }

    public function setIsConfidential(bool $v): void
    {
        $this->isConfidential = $v;
    }
}

final class ClientRepository implements ClientRepositoryInterface
{
    /** @param list<string> $redirect_uris */
    // @mago-expect lint:no-boolean-flag-parameter
    public function create(
        string $client_name,
        array $redirect_uris,
        string $registered_by_ip,
        bool $admin_created = false,
    ): string {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $client_id = bin2hex(random_bytes(16));
        $wpdb->insert($wpdb->prefix . 'wppilot_oauth_clients', [
            'client_id' => $client_id,
            'client_name' => $client_name,
            'redirect_uris' => wp_json_encode($redirect_uris),
            'is_confidential' => 0,
            'client_secret_hash' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'last_used_at' => null,
            'registered_by_ip_hash' => hash('sha256', $registered_by_ip),
            'admin_created' => $admin_created ? 1 : 0,
        ]);
        return $client_id;
    }

    public function getClientEntity(mixed $clientIdentifier): ?ClientEntityInterface
    {
        // League's 8.5 ClientRepositoryInterface declares this parameter untyped, so the
        // signature must widen to mixed; narrow back to string for the query below.
        $clientIdentifier = (string) $clientIdentifier;
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $row = $wpdb->get_row($wpdb->prepare("SELECT client_id, client_name, redirect_uris, is_confidential
                 FROM %i
                 WHERE client_id = %s", $wpdb->prefix . 'wppilot_oauth_clients', $clientIdentifier), ARRAY_A);
        if (!is_array($row)) {
            // Not a locally registered client. Under MCP 2026-07-28 an HTTPS
            // client_id is a Client ID Metadata Document URL, which is resolved
            // by fetching it rather than by prior registration. Anything else
            // is an unknown Dynamic Client Registration identifier.
            return $this->metadata_document_client($clientIdentifier);
        }
        $entity = new ClientEntity();
        $entity->setIdentifier($row['client_id']);
        // @mago-expect analysis:mixed-argument
        $entity->setName($row['client_name']);
        // @mago-expect analysis:mixed-argument
        $uris = json_decode($row['redirect_uris'], associative: true);
        // A Dynamic Client Registration row stores its redirect URIs as posted,
        // and 1.10.1 began normalising the URI the client *sends* onto the
        // IPv4 literal before league compares the two. League's loopback match
        // ignores the port but still compares the host, so a client registered
        // as `http://localhost:6274/cb` and compared as `http://127.0.0.1:6274/cb`
        // was refused. The stored list is normalised the same way on the way
        // out, which also repairs rows written before this existed.
        require_once dirname(__DIR__) . '/client-id-metadata.php';
        $entity->setRedirectUri(\WPPilot\OAuth\ClientIdMetadata\normalize_loopback_uris(
            is_array($uris) ? array_values($uris) : [],
        ));
        // @mago-expect analysis:mixed-operand
        $entity->setIsConfidential((bool) $row['is_confidential']);
        return $entity;
    }

    /**
     * Resolve an HTTPS client_id by fetching its Client ID Metadata Document.
     *
     * Returns null for anything that is not an HTTPS URL, so Dynamic Client
     * Registration identifiers keep failing closed exactly as before. A fetch
     * or validation failure also returns null: the caller's contract is
     * "unknown client", and surfacing the underlying reason here would let an
     * unauthenticated caller use this endpoint to probe the internal network.
     *
     * The document is never written to the clients table. It is authoritative
     * at its URL, so persisting a copy would let a stale local row outlive a
     * client that has since changed or withdrawn its redirect URIs.
     */
    private function metadata_document_client(string $clientIdentifier): ?ClientEntityInterface
    {
        require_once dirname(__DIR__) . '/client-id-metadata.php';

        if (!\WPPilot\OAuth\ClientIdMetadata\is_metadata_document_client_id($clientIdentifier)) {
            return null;
        }

        $document = \WPPilot\OAuth\ClientIdMetadata\fetch_client_metadata($clientIdentifier);
        if (is_wp_error($document)) {
            return null;
        }

        $entity = new ClientEntity();
        $entity->setIdentifier($clientIdentifier);
        $entity->setName((string) $document['client_name']);
        /** @var list<string> $uris */
        $uris = $document['redirect_uris'];
        $entity->setRedirectUri($uris);
        // A CIMD client authenticates with PKCE alone: the document is public,
        // so it can never carry a secret and the client is always public.
        $entity->setIsConfidential(false);

        return $entity;
    }

    public function validateClient(mixed $clientIdentifier, mixed $clientSecret, mixed $grantType): bool
    {
        return $this->getClientEntity($clientIdentifier) !== null;
    }

    public function touchLastUsed(string $client_id): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->update(
            $wpdb->prefix . 'wppilot_oauth_clients',
            ['last_used_at' => gmdate('Y-m-d H:i:s')],
            ['client_id' => $client_id],
        );
    }

    public function revoke(string $client_id): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->delete($wpdb->prefix . 'wppilot_oauth_clients', ['client_id' => $client_id]);
    }

    /**
     * @return list<array{client_id: string, client_name: string, created_at: string, last_used_at: string|null}>
     */
    public function list_recent(int $limit = 50): array
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $rows = $wpdb->get_results($wpdb->prepare("SELECT client_id, client_name, created_at, last_used_at
                 FROM %i
                 ORDER BY created_at DESC LIMIT %d", $wpdb->prefix . 'wppilot_oauth_clients', $limit), ARRAY_A);
        // @mago-expect analysis:invalid-return-statement
        return $rows === null ? [] : $rows;
    }

    /**
     * Most recent admin-created client for this client_name, so regenerating from the
     * troubleshooter reuses the row instead of stacking new ones.
     */
    public function find_admin_client_id(string $client_name): ?string
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $id = $wpdb->get_var($wpdb->prepare("SELECT client_id FROM %i
                 WHERE admin_created = 1 AND client_name = %s
                 ORDER BY created_at DESC LIMIT 1", $wpdb->prefix . 'wppilot_oauth_clients', $client_name));
        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return list<array{client_id: string, client_name: string, created_at: string, last_used_at: string|null}>
     */
    public function list_admin_clients(): array
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $rows = $wpdb->get_results($wpdb->prepare("SELECT client_id, client_name, created_at, last_used_at
                 FROM %i
                 WHERE admin_created = 1
                 ORDER BY created_at DESC", $wpdb->prefix . 'wppilot_oauth_clients'), ARRAY_A);
        // @mago-expect analysis:invalid-return-statement
        return $rows === null ? [] : $rows;
    }
}
