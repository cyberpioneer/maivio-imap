<?php

declare(strict_types=1);

namespace Maivio\Imap\Exceptions;

/**
 * Thrown before any download when RFC822.SIZE exceeds the configured maximum message size.
 */
class ImapMessageTooLargeException extends ImapException
{
    public function __construct(
        protected int $messageSize,
        protected int $maxSize,
    ) {
        parent::__construct("Message size {$messageSize} bytes exceeds the limit of {$maxSize} bytes.");
    }

    public function getMessageSize(): int
    {
        return $this->messageSize;
    }

    public function getMaxSize(): int
    {
        return $this->maxSize;
    }
}
