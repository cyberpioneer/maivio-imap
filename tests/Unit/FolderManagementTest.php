<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Unit;

use InvalidArgumentException;
use Maivio\Imap\Exceptions\ImapCommandException;
use Maivio\Imap\Tests\Support\Fakes;
use Maivio\Imap\Tests\Support\FakeTransport;
use Maivio\Imap\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class FolderManagementTest extends TestCase
{
    public function test_list_folders_returns_delimiter_attributes_and_decoded_names(): void
    {
        [$client, $transport] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('LIST', fn (string $tag): string => implode("\r\n", [
                '* LIST (\\HasNoChildren) "/" INBOX',
                '* LIST (\\HasNoChildren \\Sent) "/" "Sent Items"',
                '* LIST (\\HasNoChildren \\Drafts) "/" Entw&APw-rfe',
                '* LIST (\\HasNoChildren \\Trash) "." {7}',
                'Papierk',
                '* LIST (\\Noselect \\HasChildren) NIL "Flat \\"Quoted\\""',
                "{$tag} OK LIST completed",
            ])."\r\n");
        });

        $folders = $client->listFolders();

        $this->assertCount(5, $folders);
        $this->assertSame('INBOX', $folders[0]->getName());
        $this->assertSame('/', $folders[0]->getDelimiter());

        $this->assertSame('Sent Items', $folders[1]->getName());
        $this->assertTrue($folders[1]->isSent());
        $this->assertSame(['\\HasNoChildren', '\\Sent'], $folders[1]->getAttributes());

        $this->assertSame('Entwürfe', $folders[2]->getName());
        $this->assertSame('Entw&APw-rfe', $folders[2]->getRawName());
        $this->assertTrue($folders[2]->isDrafts());

        $this->assertSame('Papierk', $folders[3]->getName());
        $this->assertSame('.', $folders[3]->getDelimiter());
        $this->assertTrue($folders[3]->isTrash());

        $this->assertSame('Flat "Quoted"', $folders[4]->getName());
        $this->assertSame('', $folders[4]->getDelimiter(), 'NIL delimiter means a flat namespace.');

        $this->assertSame('LIST "" "*"', $transport->commandsWithVerb('LIST')[0]);
    }

    public function test_list_uses_special_use_return_option_only_when_supported(): void
    {
        [$client, $transport] = Fakes::loggedIn(['IMAP4REV1', 'LIST-EXTENDED', 'SPECIAL-USE']);
        $client->listFolders();
        $this->assertSame('LIST "" "*" RETURN (SPECIAL-USE)', $transport->commandsWithVerb('LIST')[0]);

        [$client, $transport] = Fakes::loggedIn(['IMAP4REV1', 'SPECIAL-USE']);
        $client->listFolders();
        $this->assertSame('LIST "" "*"', $transport->commandsWithVerb('LIST')[0]);
    }

    public function test_special_use_attributes_are_detected_for_all_roles(): void
    {
        [$client] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('LIST', fn (string $tag): string => implode("\r\n", [
                '* LIST (\\Sent) "/" A',
                '* LIST (\\Trash) "/" B',
                '* LIST (\\Drafts) "/" C',
                '* LIST (\\Junk) "/" D',
                '* LIST (\\Archive) "/" E',
                "{$tag} OK done",
            ])."\r\n");
        });

        [$sent, $trash, $drafts, $junk, $archive] = $client->listFolders();

        $this->assertTrue($sent->isSent());
        $this->assertTrue($trash->isTrash());
        $this->assertTrue($drafts->isDrafts());
        $this->assertTrue($junk->isJunk());
        $this->assertTrue($archive->isArchive());
    }

    public function test_create_rename_delete_send_the_expected_commands(): void
    {
        [$client, $transport] = Fakes::loggedIn();

        $client->createFolder('Projekte/Müller', subscribe: true);
        $client->renameFolder('Projekte/Müller', 'Projekte/Meier');
        $client->deleteFolder('Projekte/Meier', unsubscribe: true);

        $this->assertSame('CREATE "Projekte/M&APw-ller"', $transport->commandsWithVerb('CREATE')[0]);
        $this->assertSame('SUBSCRIBE "Projekte/M&APw-ller"', $transport->commandsWithVerb('SUBSCRIBE')[0]);
        $this->assertSame('RENAME "Projekte/M&APw-ller" "Projekte/Meier"', $transport->commandsWithVerb('RENAME')[0]);
        $this->assertSame('DELETE "Projekte/Meier"', $transport->commandsWithVerb('DELETE')[0]);
        $this->assertSame('UNSUBSCRIBE "Projekte/Meier"', $transport->commandsWithVerb('UNSUBSCRIBE')[0]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function inboxNames(): array
    {
        return ['upper' => ['INBOX'], 'lower' => ['inbox'], 'mixed' => ['InBox'], 'padded' => [' INBOX ']];
    }

    #[DataProvider('inboxNames')]
    public function test_inbox_cannot_be_deleted_or_renamed(string $inbox): void
    {
        [$client, $transport] = Fakes::loggedIn();
        $before = $transport->written;

        foreach ([
            fn () => $client->deleteFolder($inbox),
            fn () => $client->renameFolder($inbox, 'Old'),
            fn () => $client->renameFolder('Other', $inbox),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('INBOX', $e->getMessage());
            }
        }

        $this->assertSame($before, $transport->written);
    }

    public function test_sub_folders_of_inbox_are_allowed(): void
    {
        [$client, $transport] = Fakes::loggedIn();

        $client->deleteFolder('INBOX/Old');

        $this->assertSame('DELETE "INBOX/Old"', $transport->commandsWithVerb('DELETE')[0]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenNames(): array
    {
        return [
            'asterisk' => ['Foo*'],
            'percent' => ['%'],
            'brace open' => ['Foo{1}'],
            'brace close' => ['Foo}'],
            'empty' => [''],
            'blank' => ['   '],
        ];
    }

    #[DataProvider('forbiddenNames')]
    public function test_wildcards_braces_and_empty_names_are_rejected_for_modifying_operations(string $name): void
    {
        [$client, $transport] = Fakes::loggedIn();
        $before = $transport->written;

        foreach ([
            fn () => $client->createFolder($name),
            fn () => $client->deleteFolder($name),
            fn () => $client->renameFolder($name, 'X'),
            fn () => $client->renameFolder('X', $name),
            fn () => $client->subscribeFolder($name),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($before, $transport->written);
    }

    public function test_server_errors_become_typed_exceptions_with_the_server_text(): void
    {
        [$client] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('DELETE', fn (string $tag): string => "{$tag} NO [INUSE] Mailbox is in use\r\n");
        });

        try {
            $client->deleteFolder('Busy');
            $this->fail('Expected ImapCommandException');
        } catch (ImapCommandException $e) {
            $this->assertStringContainsString('DELETE failed', $e->getMessage());
            $this->assertStringContainsString('[INUSE] Mailbox is in use', $e->getServerResponse() ?? '');
        }
    }

    public function test_folder_status_uses_status_without_select(): void
    {
        [$client, $transport] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('STATUS', fn (string $tag): string => "* STATUS \"Entw&APw-rfe\" (MESSAGES 231 UIDNEXT 44292 UIDVALIDITY 3857529045 UNSEEN 12)\r\n{$tag} OK done\r\n");
        });

        $status = $client->folderStatus('Entwürfe');

        $this->assertSame(231, $status->getMessages());
        $this->assertSame(44292, $status->getUidNext());
        $this->assertSame(3857529045, $status->getUidValidity());
        $this->assertSame(12, $status->getUnseen());
        $this->assertSame('STATUS "Entw&APw-rfe" (MESSAGES UIDNEXT UIDVALIDITY UNSEEN)', $transport->commandsWithVerb('STATUS')[0]);
        $this->assertFalse($transport->sawVerb('SELECT'));
    }

    public function test_select_folder_returns_uidvalidity_and_uidnext(): void
    {
        [$client] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('SELECT', fn (string $tag): string => implode("\r\n", [
                '* 172 EXISTS',
                '* 1 RECENT',
                '* OK [UNSEEN 12] Message 12 is first unseen',
                '* OK [UIDVALIDITY 3857529045] UIDs valid',
                '* OK [UIDNEXT 4392] Predicted next UID',
                "{$tag} OK [READ-WRITE] SELECT completed",
            ])."\r\n");
        });

        $info = $client->selectFolder('INBOX');

        $this->assertSame(['exists' => 172, 'recent' => 1, 'uidvalidity' => 3857529045, 'uidnext' => 4392], $info);
        $this->assertSame($info, $client->selectMailbox('INBOX'), 'selectMailbox() is an alias.');
    }
}
