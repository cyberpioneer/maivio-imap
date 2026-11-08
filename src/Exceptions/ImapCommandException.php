<?php

declare(strict_types=1);

namespace Maivio\Imap\Exceptions;

use Throwable;

/**
 * Thrown when the server answers a command with NO/BAD or a command cannot be executed safely.
 *
 * The server response text never contains credentials supplied by this library
 * (they are scrubbed before the exception is created).
 */
class ImapCommandException extends ImapException
{
    public function __construct(
        string $message = '',
        protected ?string $serverResponse = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The tagged status line returned by the server (e.g. "A0007 NO [ALREADYEXISTS] Mailbox exists"), if any.
     */
    public function getServerResponse(): ?string
    {
        return $this->serverResponse;
    }
}
