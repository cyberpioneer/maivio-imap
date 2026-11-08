<?php

declare(strict_types=1);

namespace Maivio\Imap\Transport;

use Maivio\Imap\Exceptions\ImapConnectionException;

/**
 * Byte transport between client and IMAP server. Abstracted so the protocol layer can be tested
 * against an in-memory fake without any network.
 */
interface Transport
{
    /**
     * Read one line (terminated by LF) and return it without the trailing CRLF.
     *
     * @throws ImapConnectionException on EOF, timeout or an over-long line
     */
    public function readLine(): string;

    /**
     * Read between 1 and $length bytes.
     *
     * @param  int<1, max>  $length
     *
     * @throws ImapConnectionException on EOF or timeout
     */
    public function read(int $length): string;

    /**
     * @throws ImapConnectionException when the data cannot be written completely
     */
    public function write(string $data): void;

    /**
     * Upgrade the connection to TLS (minimum TLS 1.2).
     *
     * @throws ImapConnectionException when the handshake fails or unread plaintext data is pending
     */
    public function enableTls(): void;

    public function isOpen(): bool;

    public function close(): void;
}
