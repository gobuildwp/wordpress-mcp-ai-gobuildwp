<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- This IS the Developer Full Access PHP executor - a feature, gated behind the developer profile, an explicit capability check, and a confirmation flag. eval and error handling of arbitrary code are its purpose.

/**
 * Ability: Execute PHP code.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/execute-php', [
    'label' => __('Execute PHP Code', domain: 'wppilot'),
    'description' => __(
        'Executes PHP code on the WordPress server. The full WordPress environment is available including $wpdb, all WordPress functions, and loaded plugins. Returns the return value, any echoed output, and captured warnings/notices.',
        domain: 'wppilot',
    ),
    'category' => 'code-execution',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'code' => [
                'type' => 'string',
                'description' => 'PHP code to execute. Do NOT include <?php tags. Use "return $value;" to return data for inspection.',
                'minLength' => 1,
            ],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean', 'description' => 'Whether the code executed without throwing.'],
            'return_value' => [
                'type' => ['string', 'integer', 'number', 'boolean', 'array', 'object', 'null'],
                'description' => 'The value returned by the evaluated code.',
            ],
            'output' => ['type' => 'string', 'description' => 'Any output captured via echo/print.'],
            'errors' => [
                'type' => 'array',
                'description' => 'PHP warnings, notices, and deprecations captured during execution.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'file' => ['type' => 'string'],
                        'line' => ['type' => 'integer'],
                    ],
                ],
            ],
            'error_message' => ['type' => 'string', 'description' => 'Error message if execution failed.'],
            'error_class' => [
                'type' => 'string',
                'description' => 'Exception/Error class name if execution failed.',
            ],
            'execution_time_ms' => [
                'type' => 'number',
                'description' => 'Wall-clock execution time in milliseconds.',
            ],
        ],
    ],
    'execute_callback' => 'wppilot_execute_php',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'IMPORTANT SAFETY RULES:',
                '- Never call exit() or die() — this kills the entire PHP process.',
                '- Never create infinite loops — there is a 30-second time limit.',
                '- Do NOT include <?php opening tags.',
                '- Use "return $value;" to inspect values (the return value is captured).',
                '- Any echo/print output is captured separately in the "output" field.',
                '- The full WordPress API is available: $wpdb, get_option(), WP_Query, etc.',
                '- All loaded plugin APIs are available.',
                '- Execution has a ' . WPPILOT_MAX_EXECUTION_TIME . ' second time limit.',
                '',
                'SANDBOX CONTEXT:',
                '- To create persistent PHP functionality, write files to the sandbox',
                '  (wp-content/wppilot-sandbox/) using the write-file ability.',
                '- Code executed here via eval() is temporary and does not persist across requests.',
                '- Do NOT use eval to require/include files that may have errors — use write-file',
                '  to persist them instead.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Execute PHP code.
 *
 * @param array $input Input with 'code' key.
 * @return array|WP_Error
 */
function wppilot_execute_php($input)
{
    $code = (string) $input['code'];
    $errors = [];

    // Save and set time limit.
    $original_time_limit = (int) ini_get('max_execution_time');
    set_time_limit(WPPILOT_MAX_EXECUTION_TIME);

    // Set up error handler to capture warnings/notices.
    $error_types = [
        E_WARNING => 'Warning',
        E_NOTICE => 'Notice',
        E_DEPRECATED => 'Deprecated',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_USER_DEPRECATED => 'User Deprecated',
    ];
    set_error_handler(static function ($errno, $errstr, $errfile, $errline) use (&$errors, $error_types) {
        $errors[] = [
            'type' => $error_types[$errno] ?? 'Unknown (' . (int) $errno . ')',
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
        ];
        return true;
    });

    ob_start();
    $start = microtime(true);

    $return_value = null;
    $success = true;
    $error_message = null;
    $error_class = null;

    try {
        // @mago-expect lint:no-eval
        /** @var mixed $return_value */
        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Running the submitted PHP is this ability. Available only under the Developer Full Access profile (includes/safety.php), requires the manage capability, and is documented as development/staging only.
        $return_value = eval($code);
    } catch (\Throwable $e) {
        $success = false;
        $error_message = $e->getMessage();
        $error_class = get_class($e);
    }

    $execution_time_ms = round(num: (microtime(true) - $start) * 1000, precision: 2);
    $output = ob_get_clean();

    restore_error_handler();
    set_time_limit($original_time_limit);

    // Ensure return value is JSON-serializable.
    if ($return_value !== null && json_encode($return_value) === false) {
        $return_value = print_r(value: $return_value, return: true);
    }

    $result = [
        'success' => $success,
        'return_value' => $return_value,
        'output' => $output,
        'errors' => $errors,
        'execution_time_ms' => $execution_time_ms,
    ];

    if ($error_message !== null) {
        $result['error_message'] = $error_message;
        $result['error_class'] = $error_class;
    }

    return $result;
}
