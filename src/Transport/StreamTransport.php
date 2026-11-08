<?php

declare(strict_types=1);

namespace Maivio\Imap\Transport;

use Maivio\Imap\Exceptions\ImapConnectionException;
use Maivio\Imap\Protocol\ConnectionOptions;
use Maivio\Imap\Protocol\Encryption;

/**
 * Real transport based on stream_socket_client().
 */
final class StreamTransport implements Transport
{
    /** @var resource|null */
    private $socket;

    /**
     * @param  resource  $socket
     */
    private function __construct($socket, private readonly int $maxLineLength)
    {
        $this->socket = $socket;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param  array<string, mixed>  $sslOverrides  Additional SSL context options, e.g. ['cafile' => '/path/private-ca.pem'] when
     *                                              building the transport yourself through the ImapClient transport factory. Cannot weaken verification by accident:
     *                                              verify_peer/verify_peer_name are only changed if you pass them explicitly.
     *
     * @throws ImapConnectionException
     */
    public static function connect(ConnectionOptions $options, array $sslOverrides = []): self
    {
        $host = str_contains($options->host, ':') && ! str_starts_with($options->host, '[')
            ? '['.$options->host.']'
            : $options->host;

        // Implicit TLS uses the tls:// wrapper, everything else starts as plain TCP (STARTTLS upgrades later).
        $scheme = $options->encryption === Encryption::Tls ? 'tls://' : 'tcp://';
        $remote = "{$scheme}{$host}:{$options->port}";

        $context = stream_context_create(['ssl' => [...self::sslOptions($options), ...$sslOverrides]]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, $options->connectTimeout, STREAM_CLIENT_CONNECT, $context);

        if (! is_resource($socket)) {
            throw new ImapConnectionException("IMAP connection to {$remote} failed ({$errno}): {$errstr}");
        }

        $seconds = (int) floor($options->readTimeout);
        $micro = (int) round(($options->readTimeout - $seconds) * 1_000_000);
        stream_set_timeout($socket, $seconds, $micro);

        return new self($socket, $options->maxLineLength);
    }

    /**
     * @return array<string, mixed>
     */
    public static function sslOptions(ConnectionOptions $options): array
    {
        $ssl = [
            // verify_peer and verify_peer_name are only ever disabled by an explicit validate_cert=false.
            'verify_peer' => $options->validateCert,
            'verify_peer_name' => $options->validateCert,
            'allow_self_signed' => ! $options->validateCert,
            'peer_name' => $options->effectivePeerName(),
            'SNI_enabled' => $options->sniEnabled,
            'disable_compression' => true,
            'crypto_method' => self::cryptoMethod(),
        ];

        if ($options->sniEnabled) {
            $ssl['SNI_server_name'] = $options->effectiveSniServerName();
        }

        return $ssl;
    }

    private static function cryptoMethod(): int
    {
        return STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    }

    public function readLine(): string
    {
        $socket = $this->requireSocket();

        $line = fgets($socket, max(1, $this->maxLineLength) + 1);
        if ($line === false) {
            throw new ImapConnectionException($this->timedOut()
                ? 'Timed out while reading from IMAP socket.'
                : 'Connection lost while reading line from IMAP socket.');
        }

        if (! str_ends_with($line, "\n")) {
            if (feof($socket)) {
                throw new ImapConnectionException('Connection closed in the middle of a response line.');
            }

            throw new ImapConnectionException("IMAP response line exceeds the limit of {$this->maxLineLength} bytes.");
        }

        return rtrim($line, "\r\n");
    }

    public function read(int $length): string
    {
        $socket = $this->requireSocket();

        $chunk = fread($socket, $length);
        if ($chunk === false || $chunk === '') {
            throw new ImapConnectionException($this->timedOut()
                ? 'Timed out while reading from IMAP socket.'
                : 'Premature EOF while reading from IMAP socket.');
        }

        return $chunk;
    }

    public function write(string $data): void
    {
        $socket = $this->requireSocket();

        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($socket, substr($data, $offset, 65536));
            if ($written === false || $written === 0) {
                throw new ImapConnectionException('Failed to write to IMAP socket.');
            }
            $offset += $written;
        }
    }

    public function enableTls(): void
    {
        $socket = $this->requireSocket();

        // Defence against STARTTLS response injection: plaintext that arrived together with the
        // "OK Begin TLS" line must never be interpreted as if it came through the TLS channel.
        $meta = stream_get_meta_data($socket);
        if ($meta['unread_bytes'] > 0) {
            throw new ImapConnectionException('Unexpected data received before the TLS handshake (possible STARTTLS injection).');
        }

        $error = null;
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $result = stream_socket_enable_crypto($socket, true, self::cryptoMethod());
        } finally {
            restore_error_handler();
        }

        if ($result !== true) {
            throw new ImapConnectionException('TLS handshake failed'.($error !== null ? ": {$error}" : '.'));
        }
    }

    public function isOpen(): bool
    {
        return is_resource($this->socket);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
    }

    /**
     * @return resource
     */
    private function requireSocket()
    {
        if (! is_resource($this->socket)) {
            throw new ImapConnectionException('Socket is not connected or already closed.');
        }

        return $this->socket;
    }

    private function timedOut(): bool
    {
        if (! is_resource($this->socket)) {
            return false;
        }

        return stream_get_meta_data($this->socket)['timed_out'];
    }
}
