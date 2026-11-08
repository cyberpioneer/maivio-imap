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
            // ssl | tls (implicit TLS), starttls, none (requires allow_insecure). Unknown values throw.
            'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
            'username' => env('IMAP_USERNAME', ''),
            'password' => env('IMAP_PASSWORD', ''),
            'timeout' => (float) env('IMAP_TIMEOUT', 30.0),
            // Disabling certificate validation is insecure (man-in-the-middle). Keep it true in production.
            'validate_cert' => (bool) env('IMAP_VALIDATE_CERT', true),
            // Per-read timeout in seconds (defaults to "timeout" when null).
            'read_timeout' => env('IMAP_READ_TIMEOUT'),
            // Only required for encryption "none". Credentials are sent in plaintext! Never enable in production.
            'allow_insecure' => (bool) env('IMAP_ALLOW_INSECURE', false),
            // Verify the certificate against this name while connecting to a pre-resolved IP (DNS-rebinding protection).
            'peer_name' => env('IMAP_PEER_NAME'),
            'sni_enabled' => (bool) env('IMAP_SNI_ENABLED', true),
            'sni_server_name' => env('IMAP_SNI_SERVER_NAME'),
            // Messages larger than this many bytes (RFC822.SIZE) are rejected before download. Null = unlimited.
            'max_message_size' => env('IMAP_MAX_MESSAGE_SIZE', 52_428_800),
        ],
    ],
];
