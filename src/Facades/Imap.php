<?php

declare(strict_types=1);

namespace Maivio\Imap\Facades;

use Illuminate\Support\Facades\Facade;
use Maivio\Imap\Client\ImapClient;
use Maivio\Imap\ImapManager;

/**
 * @method static ImapClient connection(?string $name = null)
 * @method static ImapClient connect(array<string, mixed> $config)
 * @method static void disconnectAll()
 * @method static string getDefaultConnection()
 *
 * @see ImapManager
 * @see ImapClient
 */
class Imap extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ImapManager::class;
    }
}
