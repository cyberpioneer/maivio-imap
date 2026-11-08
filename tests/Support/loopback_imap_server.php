<?php

// Minimal single-connection IMAP test server on 127.0.0.1 used by tests/Integration.
// Usage: php loopback_imap_server.php <mode: starttls|nostarttls|implicit> <combined-pem-file>
// Prints the port on the first stdout line, then one "CMD enc=<0|1> <verb>" line per received command.

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];
$mode = (string) ($arguments[1] ?? 'starttls');
$pem = (string) ($arguments[2] ?? '');

$context = stream_context_create(['ssl' => [
    'local_cert' => $pem,
    'allow_self_signed' => true,
    'verify_peer' => false,
]]);

$scheme = $mode === 'implicit' ? 'tls' : 'tcp';
$server = stream_socket_server("{$scheme}://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}

$port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
fwrite(STDOUT, $port."\n");
fflush(STDOUT);

$connection = @stream_socket_accept($server, 10);
if ($connection === false) {
    exit(0);
}

$encrypted = $mode === 'implicit';
fwrite($connection, "* OK loopback ready\r\n");

while (($line = fgets($connection)) !== false) {
    $line = rtrim($line, "\r\n");
    $parts = explode(' ', $line, 3);
    $tag = $parts[0];
    $verb = strtoupper($parts[1] ?? '');
    fwrite(STDOUT, 'CMD enc='.($encrypted ? '1' : '0').' '.$verb."\n");
    fflush(STDOUT);

    switch ($verb) {
        case 'CAPABILITY':
            $caps = 'IMAP4rev1';
            if (! $encrypted && $mode === 'starttls') {
                $caps .= ' STARTTLS LOGINDISABLED';
            }
            if (! $encrypted && $mode === 'nostarttls') {
                $caps .= ' AUTH=PLAIN';
            }
            fwrite($connection, "* CAPABILITY {$caps}\r\n{$tag} OK done\r\n");
            break;

        case 'STARTTLS':
            fwrite($connection, "{$tag} OK Begin TLS negotiation now\r\n");
            $ok = @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
            fwrite(STDOUT, 'TLS '.($ok === true ? 'ok' : 'failed')."\n");
            fflush(STDOUT);
            if ($ok !== true) {
                exit(0);
            }
            $encrypted = true;
            break;

        case 'LOGIN':
            fwrite($connection, "{$tag} OK logged in\r\n");
            break;

        case 'LOGOUT':
            fwrite($connection, "* BYE bye\r\n{$tag} OK done\r\n");
            exit(0);

        default:
            fwrite($connection, "{$tag} OK {$verb}\r\n");
    }
}
