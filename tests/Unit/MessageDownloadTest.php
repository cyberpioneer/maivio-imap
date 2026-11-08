<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Unit;

use Maivio\Imap\Exceptions\ImapCommandException;
use Maivio\Imap\Exceptions\ImapConnectionException;
use Maivio\Imap\Exceptions\ImapMessageTooLargeException;
use Maivio\Imap\Tests\Support\Fakes;
use Maivio\Imap\Tests\Support\FakeTransport;
use Maivio\Imap\Tests\TestCase;

class MessageDownloadTest extends TestCase
{
    private function mailWithAttachment(int $attachmentBytes = 0): string
    {
        $attachment = $attachmentBytes > 0 ? chunk_split(base64_encode(str_repeat('A', $attachmentBytes)), 76, "\r\n") : base64_encode('hello');

        return "From: a@example.test\r\nSubject: Test\r\nMessage-ID: <m1@example.test>\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
            ."--b1\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nHallo Welt\r\n"
            ."--b1\r\nContent-Type: application/pdf; name=\"x.pdf\"\r\nContent-Disposition: attachment; filename=\"x.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .$attachment."\r\n--b1--\r\n";
    }

    /**
     * @return \Closure(FakeTransport): void
     */
    private function serveMessage(string $raw): \Closure
    {
        return function (FakeTransport $t) use ($raw): void {
            $t->on('UID FETCH', function (string $tag, string $args) use ($raw): string {
                if (str_contains($args, 'RFC822.SIZE')) {
                    return '* 1 FETCH (UID 5 RFC822.SIZE '.strlen($raw).")\r\n{$tag} OK done\r\n";
                }

                if (preg_match('/BODY\.PEEK\[\]<(\d+)\.(\d+)>/', $args, $m) !== 1) {
                    return "{$tag} BAD unsupported\r\n";
                }

                $chunk = substr($raw, (int) $m[1], (int) $m[2]);

                return '* 1 FETCH (UID 5 BODY[]<'.$m[1].'> {'.strlen($chunk)."}\r\n{$chunk})\r\n{$tag} OK done\r\n";
            });
        };
    }

    public function test_oversized_message_is_rejected_before_any_body_is_downloaded(): void
    {
        $raw = $this->mailWithAttachment(50_000);
        [$client, $transport] = Fakes::loggedIn(null, $this->serveMessage($raw));

        try {
            $client->getMessage(5, maxSize: 10_000);
            $this->fail('Expected ImapMessageTooLargeException');
        } catch (ImapMessageTooLargeException $e) {
            $this->assertSame(strlen($raw), $e->getMessageSize());
            $this->assertSame(10_000, $e->getMaxSize());
        }

        $fetches = $transport->commandsWithVerb('UID FETCH');
        $this->assertSame(['UID FETCH 5 (RFC822.SIZE)'], $fetches, 'Only the size query may be sent, no BODY.PEEK request.');
    }

    public function test_client_wide_default_limit_applies_and_explicit_limit_overrides_it(): void
    {
        $raw = $this->mailWithAttachment(2_000);
        [$client] = Fakes::loggedIn(null, $this->serveMessage($raw));
        $client->setMaxMessageSize(100);

        try {
            $client->getMessage(5);
            $this->fail('Expected ImapMessageTooLargeException');
        } catch (ImapMessageTooLargeException) {
            $this->addToAssertionCount(1);
        }

        $message = $client->getMessage(5, maxSize: 1_000_000);
        $this->assertSame('Test', $message->getSubject());
    }

    public function test_message_is_downloaded_in_chunks_with_body_peek(): void
    {
        $raw = $this->mailWithAttachment(6_000);
        [$client, $transport] = Fakes::loggedIn(null, $this->serveMessage($raw));
        $client->setChunkSize(1024);

        $stream = $client->fetchRawStream(5);

        $this->assertSame($raw, stream_get_contents($stream));

        $fetches = $transport->commandsWithVerb('UID FETCH');
        $expectedChunks = (int) ceil(strlen($raw) / 1024);
        $this->assertCount(1 + $expectedChunks, $fetches);
        $this->assertSame('UID FETCH 5 (BODY.PEEK[]<0.1024>)', $fetches[1]);
        $this->assertSame('UID FETCH 5 (BODY.PEEK[]<1024.1024>)', $fetches[2]);

        foreach ($fetches as $fetch) {
            $this->assertStringNotContainsString(' BODY[', $fetch, 'BODY[] without PEEK would mark the message as read.');
        }
    }

    public function test_get_message_parses_text_and_streams_attachment_into_the_sink(): void
    {
        $raw = $this->mailWithAttachment(3_000);
        [$client] = Fakes::loggedIn(null, $this->serveMessage($raw));
        $client->setChunkSize(1024);

        $sinkMeta = null;
        $target = $this->tempStream('', 'php://temp', 'w+b');

        $message = $client->getMessage(5, null, function ($meta) use (&$sinkMeta, $target) {
            $sinkMeta = $meta;

            return $target;
        });

        $this->assertStringContainsString('Hallo Welt', (string) $message->getBodyText());
        $this->assertNotNull($sinkMeta);
        $this->assertSame('x.pdf', $sinkMeta->getFilename());
        $this->assertFalse($sinkMeta->hasContent(), 'The sink receives metadata only.');
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame(3_000, $message->getAttachments()[0]->getSize());

        rewind($target);
        $this->assertSame(str_repeat('A', 3_000), stream_get_contents($target));
    }

    public function test_unknown_uid_throws_a_typed_exception(): void
    {
        [$client] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('UID FETCH', fn (string $tag): string => "{$tag} OK done\r\n");
        });

        $this->expectException(ImapCommandException::class);
        $this->expectExceptionMessage('not found');

        $client->getMessage(99);
    }

    public function test_server_returning_more_than_requested_is_rejected(): void
    {
        [$client] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('UID FETCH', function (string $tag, string $args): string {
                if (str_contains($args, 'RFC822.SIZE')) {
                    return "* 1 FETCH (UID 5 RFC822.SIZE 2000)\r\n{$tag} OK done\r\n";
                }

                return '* 1 FETCH (UID 5 BODY[]<0> {5000}'."\r\n".str_repeat('x', 5000).")\r\n{$tag} OK done\r\n";
            });
        });
        $client->setChunkSize(1024);

        $this->expectException(ImapConnectionException::class);
        $this->expectExceptionMessage('more data than requested');

        $client->fetchRawStream(5);
    }

    public function test_empty_chunk_response_does_not_loop_forever(): void
    {
        [$client] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('UID FETCH', function (string $tag, string $args): string {
                if (str_contains($args, 'RFC822.SIZE')) {
                    return "* 1 FETCH (UID 5 RFC822.SIZE 2000)\r\n{$tag} OK done\r\n";
                }

                return "* 1 FETCH (UID 5 BODY[]<0> \"\")\r\n{$tag} OK done\r\n";
            });
        });

        $this->expectException(ImapCommandException::class);

        $client->fetchRawStream(5);
    }

    public function test_hostile_literal_size_in_untagged_response_is_refused(): void
    {
        [$client] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('LIST', fn (string $tag): string => "* LIST () \"/\" {999999999}\r\nx\r\n{$tag} OK done\r\n");
        });

        $this->expectException(ImapConnectionException::class);

        $client->listFolders();
    }
}
