<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default IMAP Connection
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the IMAP connections below you wish
    | to use as your default connection for mail reading operations.
    |
    */
    'default' => env('IMAP_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | IMAP Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the IMAP connections setup for your application.
    |
    */
    'connections' => [
        'default' => [
            'host' => env('IMAP_HOST', '127.0.0.1'),
            'port' => (int) env('IMAP_PORT', 993),
            'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
            'username' => env('IMAP_USERNAME', ''),
            'password' => env('IMAP_PASSWORD', ''),
            'timeout' => (float) env('IMAP_TIMEOUT', 30.0),
            'validate_cert' => (bool) env('IMAP_VALIDATE_CERT', true),
        ],
    ],
];
