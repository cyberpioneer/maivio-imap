<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use Maivio\Imap\Exceptions\ImapCommandException;
use Maivio\Imap\Protocol\Flag;
use Maivio\Imap\Search\SearchCriteria;
use Maivio\Imap\Tests\Support\Fakes;
use Maivio\Imap\Tests\Support\FakeTransport;
use Maivio\Imap\Tests\TestCase;

class MessageOperationsTest extends TestCase
{
    public function test_fetch_summaries_reads_flags_internaldate_size_and_headers_with_body_peek(): void
    {
        $headers = "From: =?UTF-8?Q?J=C3=BCrgen?= <Juergen@Example.test>\r\nSubject: =?UTF-8?B?UmVjaG51bmcgZsO8cg==?=\r\nMessage-ID: <abc@example.test>\r\nDate: Fri, 30 Oct 2026 14:00:00 +0100\r\n\r\n";

        [$client, $transport] = Fakes::loggedIn(null, function (FakeTransport $t) use ($headers): void {
            $t->on('UID FETCH', fn (string $tag): string => '* 1 FETCH (UID 7 FLAGS (\\Seen \\Flagged) INTERNALDATE " 7-Jul-2026 02:44:25 +0200" RFC822.SIZE 4286 '
                .'BODY[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES)] {'.strlen($headers)."}\r\n{$headers})\r\n"
                ."* 2 FETCH (UID 9 FLAGS () INTERNALDATE \"08-Jul-2026 10:00:00 +0000\" RFC822.SIZE 10 BODY[HEADER.FIELDS (FROM)] \"\")\r\n"
                ."{$tag} OK done\r\n");
        });

        $summaries = $client->fetchSummaries([7, 8, 9]);

        $this->assertCount(2, $summaries);
        $first = $summaries[0];
        $this->assertSame(7, $first->getUid());
        $this->assertSame(['\\Seen', '\\Flagged'], $first->getFlags());
        $this->assertTrue($first->isSeen());
        $this->assertTrue($first->isFlagged());
        $this->assertFalse($first->isDeleted());
        $this->assertSame(4286, $first->getSize());
        $this->assertSame('2026-07-07 02:44:25', $first->getInternalDate()?->format('Y-m-d H:i:s'));
        $this->assertSame('Rechnung für', $first->getSubject());
        $this->assertSame('juergen@example.test', $first->getSenderEmail());
        $this->assertSame('Jürgen', $first->getSenderName());
        $this->assertSame('abc@example.test', $first->getMessageId());
        $this->assertSame([], $summaries[1]->getFlags());

        $command = $transport->commandsWithVerb('UID FETCH')[0];
        $this->assertStringContainsString('BODY.PEEK[HEADER.FIELDS', $command);
        $this->assertStringNotContainsString(' BODY[', $command, 'A plain BODY[...] fetch would set \\Seen.');
        $this->assertStringStartsWith('UID FETCH 7:9 (UID FLAGS INTERNALDATE RFC822.SIZE', $command);
    }

    public function test_flag_commands(): void
    {
        [$client, $transport] = Fakes::loggedIn();

        $client->addFlags(5, [Flag::Seen, 'flagged']);
        $client->removeFlags([5, 6, 7, 20], '\\Answered');
        $client->setFlags(5, [Flag::Deleted, Flag::Draft]);
        $client->markAsSeen(8);
        $client->markAsUnseen(8);

        $this->assertSame([
            'UID STORE 5 +FLAGS.SILENT (\\Seen \\Flagged)',
            'UID STORE 5:7,20 -FLAGS.SILENT (\\Answered)',
            'UID STORE 5 FLAGS.SILENT (\\Deleted \\Draft)',
            'UID STORE 8 +FLAGS.SILENT (\\Seen)',
            'UID STORE 8 -FLAGS.SILENT (\\Seen)',
        ], $transport->commandsWithVerb('UID STORE'));
    }

    public function test_unknown_flags_are_rejected(): void
    {
        [$client, $transport] = Fakes::loggedIn();
        $before = $transport->written;

        foreach (['\\Recent', 'Seen) \\Deleted', '$Forwarded', '\\*'] as $flag) {
            try {
                $client->addFlags(1, $flag);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($before, $transport->written);
    }

    public function test_invalid_uids_are_rejected(): void
    {
        [$client, $transport] = Fakes::loggedIn();
        $before = $transport->written;

        foreach ([0, -3] as $uid) {
            try {
                $client->addFlags($uid, Flag::Seen);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($before, $transport->written);
    }

    public function test_copy_returns_the_new_uid(): void
    {
        [$client, $transport] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('UID COPY', fn (string $tag): string => "{$tag} OK [COPYUID 38505 304 3956] Done\r\n");
        });

        $this->assertSame(3956, $client->copyMessage('INBOX', 304, 'Archiv/Überblick'));
        $this->assertSame('UID COPY 304 "Archiv/&ANw-berblick"', $transport->commandsWithVerb('UID COPY')[0]);
    }

    public function test_move_uses_the_move_extension_when_offered(): void
    {
        [$client, $transport] = Fakes::loggedIn(['IMAP4REV1', 'MOVE', 'UIDPLUS'], function (FakeTransport $t): void {
            $t->on('UID MOVE', fn (string $tag): string => "* OK [COPYUID 38505 304 3956] Moved\r\n{$tag} OK Move completed\r\n");
        });

        $this->assertSame(3956, $client->moveMessage('INBOX', 304, 'Archive'));

        $this->assertSame(['UID MOVE 304 "Archive"'], $transport->commandsWithVerb('UID MOVE'));
        $this->assertFalse($transport->sawVerb('UID COPY'));
        $this->assertFalse($transport->sawVerb('EXPUNGE'));
    }

    public function test_move_failure_with_move_extension_does_not_fall_back_to_copy(): void
    {
        [$client, $transport] = Fakes::loggedIn(['IMAP4REV1', 'MOVE', 'UIDPLUS'], function (FakeTransport $t): void {
            $t->on('UID MOVE', fn (string $tag): string => "{$tag} NO [TRYCREATE] No such mailbox\r\n");
        });

        $this->expectException(ImapCommandException::class);

        try {
            $client->moveMessage('INBOX', 304, 'Missing');
        } finally {
            $this->assertFalse($transport->sawVerb('UID COPY'));
        }
    }

    public function test_move_fallback_with_uidplus_copies_flags_and_expunges_only_that_uid(): void
    {
        [$client, $transport] = Fakes::loggedIn(['IMAP4REV1', 'UIDPLUS'], function (FakeTransport $t): void {
            $t->on('UID COPY', fn (string $tag): string => "{$tag} OK [COPYUID 1 304 77] Done\r\n");
        });

        $this->assertSame(77, $client->moveMessage('INBOX', 304, 'Archive'));

        $this->assertSame(['UID COPY 304 "Archive"'], $transport->commandsWithVerb('UID COPY'));
        $this->assertSame(['UID STORE 304 +FLAGS.SILENT (\\Deleted)'], $transport->commandsWithVerb('UID STORE'));
        $this->assertSame(['UID EXPUNGE 304'], $transport->commandsWithVerb('UID EXPUNGE'));
        $this->assertFalse($transport->sawVerb('EXPUNGE'), 'A plain EXPUNGE would remove unrelated \\Deleted messages.');
        $this->assertFalse($transport->sawVerb('UID MOVE'));
    }

    public function test_move_without_move_and_uidplus_throws_before_copying_anything(): void
    {
        [$client, $transport] = Fakes::loggedIn(['IMAP4REV1']);

        try {
            $client->moveMessage('INBOX', 304, 'Archive');
            $this->fail('Expected ImapCommandException');
        } catch (ImapCommandException $e) {
            $this->assertStringContainsString('neither MOVE nor UIDPLUS', $e->getMessage());
        }

        $this->assertFalse($transport->sawVerb('UID COPY'));
        $this->assertFalse($transport->sawVerb('UID STORE'));
        $this->assertFalse($transport->sawVerb('EXPUNGE'));
        $this->assertFalse($transport->sawVerb('UID EXPUNGE'));
    }

    public function test_move_looks_up_new_uid_by_message_id_when_server_sends_no_copyuid(): void
    {
        [$client, $transport] = Fakes::loggedIn(['IMAP4REV1', 'MOVE'], function (FakeTransport $t): void {
            $t->on('UID SEARCH', fn (string $tag): string => "* SEARCH 5 9\r\n{$tag} OK done\r\n");
        });

        $this->assertSame(9, $client->moveMessage('INBOX', 304, 'Archive', '<abc@example.test>'));

        $this->assertSame('UID SEARCH HEADER Message-ID "<abc@example.test>"', $transport->commandsWithVerb('UID SEARCH')[0]);
        $selects = $transport->commandsWithVerb('SELECT');
        $this->assertSame('SELECT "INBOX"', end($selects), 'The source folder is selected again afterwards.');
    }

    public function test_delete_message_expunges_only_that_uid(): void
    {
        [$client, $transport] = Fakes::loggedIn(['IMAP4REV1', 'UIDPLUS']);

        $client->deleteMessage(12);

        $this->assertSame(['UID STORE 12 +FLAGS.SILENT (\\Deleted)'], $transport->commandsWithVerb('UID STORE'));
        $this->assertSame(['UID EXPUNGE 12'], $transport->commandsWithVerb('UID EXPUNGE'));
        $this->assertFalse($transport->sawVerb('EXPUNGE'));
    }

    public function test_delete_message_without_uidplus_refuses_before_changing_anything(): void
    {
        [$client, $transport] = Fakes::loggedIn(['IMAP4REV1']);
        $before = $transport->written;

        try {
            $client->deleteMessage(12);
            $this->fail('Expected ImapCommandException');
        } catch (ImapCommandException $e) {
            $this->assertStringContainsString('UIDPLUS', $e->getMessage());
        }

        $this->assertSame($before, $transport->written);

        $client->deleteMessage(12, expunge: false);
        $this->assertSame(['UID STORE 12 +FLAGS.SILENT (\\Deleted)'], $transport->commandsWithVerb('UID STORE'));
        $this->assertFalse($transport->sawVerb('EXPUNGE'));
    }

    public function test_append_message_sends_flags_date_and_literal_and_returns_uid(): void
    {
        $message = "From: me@example.test\r\nSubject: Sent copy\r\n\r\nHi";

        [$client, $transport] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('APPEND', fn (string $tag): string => "{$tag} OK [APPENDUID 38505 3955] APPEND completed\r\n");
        });

        $uid = $client->appendMessage('Gesendet', $message, [Flag::Seen], new DateTimeImmutable('2026-07-07 10:00:00 +0200'));

        $this->assertSame(3955, $uid);
        $this->assertSame(
            'APPEND "Gesendet" (\\Seen) "07-Jul-2026 10:00:00 +0200" {'.strlen($message)."}\r\n".$message,
            $transport->commandsWithVerb('APPEND')[0]
        );
    }

    public function test_append_message_streams_from_a_resource(): void
    {
        $message = "Subject: stream\r\n\r\n".str_repeat('x', 200_000);
        $stream = $this->tempStream('', 'php://temp', 'r+b');
        fwrite($stream, $message);
        rewind($stream);

        [$client, $transport] = Fakes::loggedIn(['IMAP4REV1', 'LITERAL+']);

        $client->appendMessage('Sent', $stream);

        $this->assertSame('APPEND "Sent" {'.strlen($message)."+}\r\n".$message, $transport->commandsWithVerb('APPEND')[0]);
    }

    public function test_search_builder_commands_and_uid_filtering(): void
    {
        [$client, $transport] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('UID SEARCH', fn (string $tag): string => "* SEARCH 3 150 151 152 900\r\n{$tag} OK done\r\n");
        });

        $criteria = SearchCriteria::new()
            ->from('john@example.test')
            ->to('jane@example.test')
            ->subject('Invoice')
            ->since(new DateTimeImmutable('2026-01-05'))
            ->before(new DateTimeImmutable('2026-02-01'))
            ->unseen()
            ->text('payment')
            ->uidRange(100, 500);

        $this->assertSame([3, 150, 151, 152, 900], $client->search($criteria));
        $this->assertSame(
            'UID SEARCH FROM "john@example.test" TO "jane@example.test" SUBJECT "Invoice" SINCE 05-Jan-2026 BEFORE 01-Feb-2026 UNSEEN TEXT "payment" UID 100:500',
            $transport->commandsWithVerb('UID SEARCH')[0]
        );

        // UID n:* quirk: the highest message is returned even when it is below n.
        $this->assertSame([150, 151, 152], $client->searchUidRange(100, 200));
        $this->assertSame([150, 151, 152, 900], $client->searchUidRange(100));
        $this->assertSame([150, 151], $client->searchUidRange(100, null, 2));
        $this->assertSame([3, 150], $client->search(SearchCriteria::new()->all(), 2));
        $commands = $transport->commandsWithVerb('UID SEARCH');
        $this->assertSame('UID SEARCH UID 100:200', $commands[1]);
        $this->assertSame('UID SEARCH UID 100:*', $commands[2]);
    }

    public function test_empty_criteria_search_all_and_legacy_helpers(): void
    {
        [$client, $transport] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('UID SEARCH', fn (string $tag): string => "* SEARCH 4 5\r\n{$tag} OK done\r\n");
        });

        $this->assertSame([4, 5], $client->search(SearchCriteria::new()));
        $this->assertSame([4, 5], $client->searchAllUids());
        $this->assertSame([4, 5], $client->searchUnseenOrSinceUid());
        $client->searchSinceDays(3);

        $commands = $transport->commandsWithVerb('UID SEARCH');
        $this->assertSame('UID SEARCH ALL', $commands[0]);
        $this->assertSame('UID SEARCH ALL', $commands[1]);
        $this->assertSame('UID SEARCH UNSEEN', $commands[2]);
        $this->assertMatchesRegularExpression('/^UID SEARCH SINCE \d{2}-[A-Z][a-z]{2}-\d{4}$/', $commands[3]);
    }
}
