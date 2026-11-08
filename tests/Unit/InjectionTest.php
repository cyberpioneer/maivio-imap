<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Unit;

use BadMethodCallException;
use Closure;
use InvalidArgumentException;
use Maivio\Imap\Client\ImapClient;
use Maivio\Imap\Search\SearchCriteria;
use Maivio\Imap\Tests\Support\Fakes;
use Maivio\Imap\Tests\Support\FakeTransport;
use Maivio\Imap\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InjectionTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function payloads(): array
    {
        return [
            'crlf command' => ["\r\nA1 DELETE INBOX"],
            'trailing crlf' => ["name\r\nA1 DELETE INBOX\r\n"],
            'lf only' => ["name\nA1 DELETE INBOX"],
            'cr only' => ["name\rA1 DELETE INBOX"],
            'nul' => ["name\0A1 DELETE INBOX"],
            'literal announcement' => ["x\r\n{9999}\r\nA1 DELETE INBOX"],
        ];
    }

    /**
     * Every operation that takes user input, each given the payload in a different place.
     *
     * @return array<string, Closure(ImapClient, string): mixed>
     */
    private static function operations(): array
    {
        return [
            'select mailbox' => fn (ImapClient $c, string $p) => $c->selectFolder($p),
            'folder status' => fn (ImapClient $c, string $p) => $c->folderStatus($p),
            'create folder' => fn (ImapClient $c, string $p) => $c->createFolder($p),
            'rename source' => fn (ImapClient $c, string $p) => $c->renameFolder($p, 'Target'),
            'rename target' => fn (ImapClient $c, string $p) => $c->renameFolder('Source', $p),
            'delete folder' => fn (ImapClient $c, string $p) => $c->deleteFolder($p),
            'subscribe' => fn (ImapClient $c, string $p) => $c->subscribeFolder($p),
            'list reference' => fn (ImapClient $c, string $p) => $c->listFolders($p),
            'list pattern' => fn (ImapClient $c, string $p) => $c->listFolders('', $p),
            'copy target' => fn (ImapClient $c, string $p) => $c->copyMessage('INBOX', 1, $p),
            'move target' => fn (ImapClient $c, string $p) => $c->moveMessage('INBOX', 1, $p),
            'move source' => fn (ImapClient $c, string $p) => $c->moveMessage($p, 1, 'Archive'),
            'move message id' => fn (ImapClient $c, string $p) => $c->moveMessage('INBOX', 1, 'Archive', $p),
            'append mailbox' => fn (ImapClient $c, string $p) => $c->appendMessage($p, "Subject: x\r\n\r\nbody"),
            'search from' => fn (ImapClient $c, string $p) => $c->search(SearchCriteria::new()->from($p)),
            'search subject' => fn (ImapClient $c, string $p) => $c->search(SearchCriteria::new()->subject($p)),
            'search text' => fn (ImapClient $c, string $p) => $c->search(SearchCriteria::new()->text($p)),
            'search body' => fn (ImapClient $c, string $p) => $c->search(SearchCriteria::new()->body($p)),
            'flags' => fn (ImapClient $c, string $p) => $c->addFlags(1, $p),
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function operationsWithPayloads(): array
    {
        $cases = [];
        foreach (self::operations() as $name => $_) {
            foreach (self::payloads() as $payloadName => [$payload]) {
                $cases["{$name} / {$payloadName}"] = [$name, $payload];
            }
        }

        return $cases;
    }

    #[DataProvider('operationsWithPayloads')]
    public function test_injection_payload_is_rejected_and_nothing_is_sent(string $operation, string $payload): void
    {
        [$client, $transport] = Fakes::loggedIn();
        $sentBefore = $transport->written;
        $commandsBefore = count($transport->commands);

        try {
            self::operations()[$operation]($client, $payload);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('DELETE', $e->getMessage(), 'The payload must not be echoed.');
        }

        $this->assertSame($sentBefore, $transport->written, 'Not a single byte may be sent for a rejected value.');
        $this->assertCount($commandsBefore, $transport->commands);
        $this->assertNotContains('DELETE', $transport->verbs());
    }

    #[DataProvider('payloads')]
    public function test_injection_payload_in_username_is_rejected_and_nothing_is_sent(string $payload): void
    {
        [$client, $transport] = Fakes::client();
        $sentBefore = $transport->written;

        try {
            $client->login($payload, 'secret');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($sentBefore, $transport->written);
        $this->assertFalse($transport->sawVerb('LOGIN'));
    }

    #[DataProvider('payloads')]
    public function test_injection_payload_in_password_is_rejected_and_nothing_is_sent(string $payload): void
    {
        [$client, $transport] = Fakes::client();
        $sentBefore = $transport->written;

        try {
            $client->login('user@example.test', $payload);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($sentBefore, $transport->written);
        $this->assertFalse($transport->sawVerb('LOGIN'));
    }

    #[DataProvider('payloads')]
    public function test_injection_payload_in_xoauth2_credentials_is_rejected(string $payload): void
    {
        [$client, $transport] = Fakes::client();
        $sentBefore = $transport->written;

        foreach ([[$payload, 'token'], ['user@example.test', $payload]] as [$user, $token]) {
            try {
                $client->authenticateXOAuth2($user, $token);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($sentBefore, $transport->written);
    }

    public function test_search_builder_rejects_injection_at_build_time(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SearchCriteria::new()->from("a\r\nA1 DELETE INBOX");
    }

    public function test_raw_search_string_fails_immediately_without_sending(): void
    {
        [$client, $transport] = Fakes::loggedIn();
        $sentBefore = $transport->written;

        try {
            $client->search('ALL"\r\nA1 DELETE INBOX');
            $this->fail('Expected BadMethodCallException');
        } catch (BadMethodCallException $e) {
            $this->assertStringContainsString('SearchCriteria', $e->getMessage());
        }

        $this->assertSame($sentBefore, $transport->written);
    }

    public function test_special_characters_are_sent_as_correct_quoted_strings(): void
    {
        [$client, $transport] = Fakes::client();

        $client->login('us"er\\name', 'pa"ss\\word');

        $this->assertSame('LOGIN "us\\"er\\\\name" "pa\\"ss\\\\word"', $transport->commandsWithVerb('LOGIN')[0]);
    }

    public function test_quote_in_search_value_cannot_break_out_of_the_string(): void
    {
        [$client, $transport] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('UID SEARCH', fn (string $tag): string => "* SEARCH 4\r\n{$tag} OK done\r\n");
        });

        $client->search(SearchCriteria::new()->subject('x" ALL "'));

        $this->assertSame('UID SEARCH SUBJECT "x\\" ALL \\""', $transport->commandsWithVerb('UID SEARCH')[0]);
    }

    public function test_non_ascii_password_is_sent_as_synchronizing_literal(): void
    {
        [$client, $transport] = Fakes::client();

        $client->login('user', 'pässwörd');

        $this->assertSame("LOGIN \"user\" {10}\r\npässwörd", $transport->commandsWithVerb('LOGIN')[0]);
        $this->assertStringContainsString("{10}\r\n", $transport->written);
        $this->assertStringNotContainsString('{10+}', $transport->written, 'No LITERAL+ announced by the server.');
    }

    public function test_non_synchronizing_literal_is_only_used_when_server_announces_literal_plus(): void
    {
        [$client, $transport] = Fakes::client(function (FakeTransport $t): void {
            $t->capabilities = ['IMAP4REV1', 'LITERAL+'];
        });

        $client->login('user', 'pässwörd');

        $this->assertStringContainsString("{10+}\r\n", $transport->written);
    }

    public function test_non_ascii_mailbox_is_sent_as_modified_utf7(): void
    {
        [$client, $transport] = Fakes::loggedIn();

        $client->createFolder('Entwürfe & Notizen');

        $this->assertSame('CREATE "Entw&APw-rfe &- Notizen"', $transport->commandsWithVerb('CREATE')[0]);
    }

    public function test_non_ascii_search_value_uses_charset_and_literal(): void
    {
        [$client, $transport] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('UID SEARCH', fn (string $tag): string => "* SEARCH 4\r\n{$tag} OK done\r\n");
        });

        $client->search(SearchCriteria::new()->subject('Rechnung für März'));

        $this->assertSame("UID SEARCH CHARSET UTF-8 SUBJECT {19}\r\nRechnung für März", $transport->commandsWithVerb('UID SEARCH')[0]);
    }
}
