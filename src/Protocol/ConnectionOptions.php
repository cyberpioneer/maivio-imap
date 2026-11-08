<?php

declare(strict_types=1);

namespace Maivio\Imap\Protocol;

use InvalidArgumentException;
use Maivio\Imap\Exceptions\ImapConnectionException;

/**
 * Validated, immutable connection settings.
 */
final class ConnectionOptions
{
    /**
     * @param string $host Hostname or (pre-resolved) IP address. No scheme.
     * @param float $connectTimeout Seconds allowed for establishing the TCP connection.
     * @param float $readTimeout Seconds allowed per read/write on the socket.
     * @param string|null $peerName Name the certificate is verified against (SSL context "peer_name"). Use with a pre-resolved IP in $host.
     * @param bool $sniEnabled SSL context "SNI_enabled".
     * @param string|null $sniServerName SSL context "SNI_server_name" (defaults to $peerName, then $host).
     * @param bool $allowInsecure Must be true to use Encryption::None.
     * @param int $maxLineLength Maximum length of a single server response line in bytes.
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port = 993,
        public readonly Encryption $encryption = Encryption::Tls,
        public readonly float $connectTimeout = 30.0,
        public readonly float $readTimeout = 30.0,
        public readonly bool $validateCert = true,
        public readonly ?string $peerName = null,
        public readonly bool $sniEnabled = true,
        public readonly ?string $sniServerName = null,
        public readonly bool $allowInsecure = false,
        public readonly int $maxLineLength = 8_388_608,
    ) {
        if ($host === '' || preg_match('#[\x00-\x20\x7F/\\\\]|://#', $host) === 1) {
            throw new InvalidArgumentException('IMAP host must be a plain hostname or IP address without scheme, path or whitespace.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('IMAP port must be between 1 and 65535.');
        }

        if ($connectTimeout <= 0 || $readTimeout <= 0) {
            throw new InvalidArgumentException('IMAP timeouts must be greater than zero.');
        }

        foreach (['peer_name' => $peerName, 'sni_server_name' => $sniServerName] as $key => $name) {
            if ($name !== null && ($name === '' || preg_match('/[\x00-\x20\x7F\/\\\\]/', $name) === 1)) {
                throw new InvalidArgumentException("IMAP option {$key} must be a plain hostname.");
            }
        }

        if ($maxLineLength < 1024) {
            throw new InvalidArgumentException('IMAP max line length must be at least 1024 bytes.');
        }

        if ($encryption === Encryption::None && ! $allowInsecure) {
            throw new ImapConnectionException(
                'Refusing to connect without encryption. Set allow_insecure=true to explicitly permit plaintext IMAP.'
            );
        }
    }

    /**
     * Hostname used for certificate verification.
     */
    public function effectivePeerName(): string
    {
        return $this->peerName ?? $this->host;
    }

    public function effectiveSniServerName(): string
    {
        return $this->sniServerName ?? $this->effectivePeerName();
    }
}
