<?php

declare(strict_types=1);

namespace Maivio\Imap\Exceptions;

/**
 * Thrown by the MIME parser when a resource limit (depth, part count, part size) is exceeded.
 */
class MimeLimitExceededException extends ImapException
{
}
