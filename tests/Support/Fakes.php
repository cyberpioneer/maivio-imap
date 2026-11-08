<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Support;

use Closure;
use Maivio\Imap\Client\ImapClient;
use Maivio\Imap\Protocol\ConnectionOptions;

/**
 * Helpers to build clients connected to a FakeTransport.
 */
final class Fakes
{
    /**
     * @param  Closure(FakeTransport): void|null  $configure
     * @return array{0: ImapClient, 1: FakeTransport}
     */
    public static function client(?Closure $configure = null, string $encryption = 'ssl', bool $allowInsecure = false, bool $connect = true): array
    {
        $transport = FakeTransport::make($configure);

        $client = new ImapClient(null, static fn (ConnectionOptions $options): FakeTransport => $transport);

        if ($connect) {
            $client->connect('mail.example.test', 993, $encryption, allowInsecure: $allowInsecure);
        }

        return [$client, $transport];
    }

    /**
     * A connected, logged-in client with MOVE/UIDPLUS and no extra capabilities unless overridden.
     *
     * @param  list<string>|null  $capabilities
     * @param  Closure(FakeTransport): void|null  $configure
     * @return array{0: ImapClient, 1: FakeTransport}
     */
    public static function loggedIn(?array $capabilities = null, ?Closure $configure = null): array
    {
        [$client, $transport] = self::client(static function (FakeTransport $t) use ($capabilities, $configure): void {
            if ($capabilities !== null) {
                $t->capabilities = $capabilities;
            }
            if ($configure !== null) {
                $configure($t);
            }
        });

        $client->login('user@example.test', 'secret');

        return [$client, $transport];
    }
}
