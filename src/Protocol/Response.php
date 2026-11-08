<?php

declare(strict_types=1);

namespace Maivio\Imap\Protocol;

/**
 * Completed server response to one tagged command.
 */
final class Response
{
    /**
     * @param string $status OK, NO or BAD
     * @param string $text Text following the status in the tagged line
     * @param list<string> $untagged Untagged response lines (literals are inlined as "{n}\r\n<bytes>")
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $status,
        public readonly string $text,
        public readonly array $untagged,
    ) {
    }

    public function ok(): bool
    {
        return $this->status === 'OK';
    }

    public function statusLine(): string
    {
        return trim("{$this->tag} {$this->status} {$this->text}");
    }
}
