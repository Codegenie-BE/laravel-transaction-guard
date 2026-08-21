<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    |
    | Transaction Guard focuses on application code. Migrations are deliberately
    | not scanned by default because schema changes have different transaction
    | semantics and are already owned by Laravel's migration system.
    |
    */
    'paths' => [
        'app',
        'routes',
    ],

    'exclude' => [
        'vendor',
        'storage',
        'bootstrap/cache',
        'tests',
    ],

    /*
    | The default queue connection and its after_commit setting are detected
    | from config/queue.php. Set this to true/false only to override detection.
    */
    'queue_after_commit' => null,

    /*
    | Outbound GET/HEAD requests are read-only by convention and are therefore
    | ignored by default. Enable this for strict I/O isolation.
    */
    'detect_read_http_calls' => false,

    /*
    | Disable a rule only when its risk is intentionally accepted project-wide.
    | Prefer a baseline or an inline suppression for isolated legacy cases.
    */
    'disabled_rules' => [],

    /*
    | Additional regular expressions for project-specific irreversible effects,
    | e.g. '/SmsGateway::send\s*\(/' or '/StripeClient->capture\s*\(/'.
    */
    'custom_side_effect_patterns' => [],

    'baseline' => '.transaction-guard-baseline.json',

    /* info | warning | error | critical | never */
    'fail_on' => 'warning',
];
