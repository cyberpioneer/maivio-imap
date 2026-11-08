<?php

declare(strict_types=1);

namespace Maivio\Imap\Protocol;

use InvalidArgumentException;
use Maivio\Imap\Transport\Transport;

/**
 * An IMAP literal ("{n}" CRLF followed by n octets), backed by a string or a readable stream.
 */
final class Literal
{
    /** @var resource|null */
    private $stream;

    /**
     * @param  resource|null  $stream
     */
    private function __construct(
        private readonly ?string $data,
        $stream,
        public readonly int $length,
    ) {
        $this->stream = $stream;
    }

    public static function fromString(#[\SensitiveParameter] string $data): self
    {
        // NUL is not allowed in IMAP4rev1 literals (CHAR8 excludes it).
        if (str_contains($data, "\0")) {
            throw new InvalidArgumentException('IMAP literal must not contain NUL bytes.');
        }

        return new self($data, null, strlen($data));
    }

    /**
     * @param  resource  $stream  Readable stream positioned at the first byte to send
     * @param  int  $length  Exact number of bytes that will be sent from the stream
     */
    public static function fromStream($stream, int $length): self
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Literal stream must be a valid resource.');
        }

        if ($length < 0) {
            throw new InvalidArgumentException('Literal length must not be negative.');
        }

        return new self(null, $stream, $length);
    }

    /**
     * Write the literal payload in bounded chunks.
     */
    public function writeTo(Transport $transport): void
    {
        if ($this->data !== null) {
            $transport->write($this->data);

            return;
        }

        $remaining = $this->length;
        $stream = $this->stream;

        while ($remaining > 0 && is_resource($stream)) {
            $chunk = fread($stream, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                throw new InvalidArgumentException('Literal stream ended before the announced length was reached.');
            }

            $transport->write($chunk);
            $remaining -= strlen($chunk);
        }

        if ($remaining > 0) {
            throw new InvalidArgumentException('Literal stream is no longer readable.');
        }
    }
}
