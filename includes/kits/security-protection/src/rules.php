<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

if (!defined('ABSPATH')) {
    exit();
}

/** Characters of request values the firewall looks at, in all. */
const MAX_INSPECT_BYTES = 65536;
const MAX_INSPECT_VALUES = 500;

/**
 * The firewall rules. `in` says where each one looks: the decoded path, the query and form
 * values, the user agent, or uploaded file names. Patterns favour attacks over prose, so a
 * comment that mentions JavaScript or SQL isn't stopped.
 *
 * @return list<array{id: string, in: list<string>, pattern: string, label: string}>
 */
function rules(): array
{
    return [
        ['id' => 'traversal', 'in' => ['path', 'args'], 'pattern' => '#(?:^|[/\\\\])\.\.[/\\\\]#', 'label' => 'Path traversal'],
        [
            'id' => 'sensitive_file',
            'in' => ['path', 'args'],
            'pattern' => '#(?:wp-config\.php|/etc/(?:passwd|shadow)|/proc/self/environ|(?:^|/)\.env(?:$|[./])|(?:^|/)\.git/|(?:^|/)\.svn/|\.htpasswd)#i',
            'label' => 'Sensitive file',
        ],
        ['id' => 'php_wrapper', 'in' => ['args'], 'pattern' => '#\b(?:php|phar|expect|zip|glob|data)://#i', 'label' => 'PHP stream wrapper'],
        [
            'id' => 'sqli',
            'in' => ['args'],
            'pattern' => '#(?:\bunion\b[\s(/*!]{1,20}(?:all\s+)?select\b|\b(?:sleep|benchmark|pg_sleep)\s*\(\s*\d|information_schema|\bwaitfor\s+delay\b|[\'"]\s*(?:or|and)\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+|;\s*(?:drop|truncate)\s+table\b|\bextractvalue\s*\(|\bupdatexml\s*\()#i',
            'label' => 'SQL injection',
        ],
        [
            'id' => 'xss',
            'in' => ['args'],
            'pattern' => '#(?:<\s*script\b|<[^>]{0,200}\bon(?:error|load|mouseover|focus|toggle|animationstart)\s*=|(?:href|src|action|formaction)\s*=\s*[\'"]?\s*javascript:|<\s*iframe\b[^>]{0,200}\bsrcdoc\s*=)#i',
            'label' => 'Script injection',
        ],
        [
            'id' => 'code_exec',
            'in' => ['args'],
            'pattern' => '#(?:\b(?:e[v]al|a[s]sert|s[y]stem|s[h]ell_exec|p[a]ssthru|p[r]oc_open|p[o]pen|b[a]se64_decode|g[z]inflate|s[t]r_rot13)\s*\(\s*[\'"$\w]|[;|`]\s*(?:wget|curl|nc|bash|sh|python|perl)\s+-?\w|\$\{jndi:)#i',
            'label' => 'Code execution',
        ],
        ['id' => 'php_upload', 'in' => ['files'], 'pattern' => '#\.(?:php\d?|phtml|phar|phps|pht)(?:\.|$)#i', 'label' => 'PHP file upload'],
        [
            'id' => 'scanner',
            'in' => ['ua'],
            'pattern' => '#(?:sqlmap|nikto|acunetix|nessus|masscan|zgrab|nmap|nuclei|wpscan|dirbuster|gobuster|havij)#i',
            'label' => 'Attack tool',
        ],
        [
            'id' => 'backup_probe',
            'in' => ['path'],
            'pattern' => '#(?:wp-config\.php[._~-]|/phpinfo\.php$|\.(?:sql|bak|old|swp)(?:\.gz|\.zip)?$)#i',
            'label' => 'Backup or config probe',
        ],
    ];
}

/**
 * The parts of a request the firewall looks at, from PHP's superglobals.
 *
 * @param array<string, mixed> $server
 * @param array<mixed> $get
 * @param array<mixed> $post
 * @param array<mixed> $files
 * @return array{path: string, args: list<string>, ua: string, files: list<string>}
 */
function request_parts(array $server, array $get, array $post, array $files): array
{
    $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
    $path = (string) strtok($uri, '?');
    $values = [];
    $budget = MAX_INSPECT_BYTES;
    flatten($get, $values, $budget, 0);
    flatten($post, $values, $budget, 0);
    $names = [];
    array_walk_recursive($files, static function ($value, $key) use (&$names): void {
        if ($key === 'name' && is_string($value) && count($names) < 50) {
            $names[] = $value;
        }
    });
    return [
        'path' => rawurldecode($path),
        'args' => $values,
        'ua' => is_string($server['HTTP_USER_AGENT'] ?? null) ? substr($server['HTTP_USER_AGENT'], 0, 512) : '',
        'files' => $names,
    ];
}

/**
 * @param array<mixed> $in
 * @param list<string> $out
 */
function flatten(array $in, array &$out, int &$budget, int $depth): void
{
    if ($depth > 5) {
        return;
    }
    foreach ($in as $value) {
        if ($budget <= 0 || count($out) >= MAX_INSPECT_VALUES) {
            return;
        }
        if (is_array($value)) {
            flatten($value, $out, $budget, $depth + 1);
        } elseif (is_scalar($value)) {
            $s = substr((string) $value, 0, $budget);
            if ($s !== '') {
                // Values arrive decoded once; a second decode catches double-encoded attacks.
                $out[] = str_contains($s, '%') ? rawurldecode($s) : $s;
                $budget -= strlen($s);
            }
        }
    }
}

/**
 * The first rule the request breaks, or null.
 *
 * @param array{path: string, args: list<string>, ua: string, files: list<string>} $parts
 * @param list<string> $skip Rule ids not to apply (script injection for signed-in authors).
 * @return array{id: string, label: string, in: string}|null
 */
function firewall_match(array $parts, array $skip = []): ?array
{
    foreach (rules() as $rule) {
        if (in_array($rule['id'], $skip, true)) {
            continue;
        }
        foreach ($rule['in'] as $where) {
            $haystack = match ($where) {
                'path' => [$parts['path']],
                'args' => $parts['args'],
                'ua' => [$parts['ua']],
                'files' => $parts['files'],
                default => [],
            };
            foreach ($haystack as $value) {
                if ($value !== '' && preg_match($rule['pattern'], $value) === 1) {
                    return ['id' => $rule['id'], 'label' => $rule['label'], 'in' => $where];
                }
            }
        }
    }
    return null;
}
