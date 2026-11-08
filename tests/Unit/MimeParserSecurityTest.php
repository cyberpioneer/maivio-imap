<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Unit;

use InvalidArgumentException;
use Maivio\Imap\DTO\Attachment;
use Maivio\Imap\Exceptions\MimeLimitExceededException;
use Maivio\Imap\Parser\MimeParser;
use Maivio\Imap\Tests\TestCase;

class MimeParserSecurityTest extends TestCase
{
    private function invalidSink(): \Closure
    {
        return fn () => 'not a resource';
    }

    private function multipart(string $parts, string $boundary = 'b1', string $headers = ''): string
    {
        return "From: a@example.test\r\nSubject: T\r\n{$headers}Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n{$parts}--{$boundary}--\r\n";
    }

    private function attachmentPart(string $name, string $body, string $encoding = 'base64', string $extra = ''): string
    {
        return "--b1\r\nContent-Type: application/octet-stream; name=\"{$name}\"\r\nContent-Disposition: attachment; filename=\"{$name}\"{$extra}\r\nContent-Transfer-Encoding: {$encoding}\r\n\r\n{$body}\r\n";
    }

    public function test_large_attachment_is_streamed_into_the_sink_with_bounded_memory(): void
    {
        $megabytes = 40;
        $raw = $this->tempStream('', 'php://temp/maxmemory:1048576', 'r+b');
        $hash = hash_init('sha256');

        fwrite($raw, "From: a@example.test\r\nSubject: Big\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n");
        fwrite($raw, "--b1\r\nContent-Type: text/plain\r\n\r\nSee attachment\r\n");
        fwrite($raw, "--b1\r\nContent-Type: application/zip; name=\"big.zip\"\r\nContent-Disposition: attachment; filename=\"big.zip\"\r\nContent-Transfer-Encoding: base64\r\n\r\n");

        $block = random_bytes(57 * 1024); // 57 bytes -> exactly 76 base64 characters
        for ($i = 0; $i < $megabytes * 18; $i++) { // ~1 MiB per 18 blocks
            hash_update($hash, $block);
            fwrite($raw, chunk_split(base64_encode($block), 76, "\r\n"));
        }
        fwrite($raw, "\r\n--b1--\r\n");
        rewind($raw);

        $expectedHash = hash_final($hash);
        $expectedSize = $megabytes * 18 * 57 * 1024;

        $target = tempnam(sys_get_temp_dir(), 'maivio_att_');
        $this->assertIsString($target);

        try {
            $parser = new MimeParser(maxAttachmentSize: 200 * 1024 * 1024);

            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = memory_get_usage();

            $sinkCalls = 0;
            $message = $parser->parse($raw, 1, function (Attachment $meta) use ($target, &$sinkCalls) {
                $sinkCalls++;
                $this->assertSame('big.zip', $meta->getFilename());

                return $this->tempStream('', $target, 'wb');
            });

            $peakGrowth = memory_get_peak_usage() - $before;

            $this->assertSame(1, $sinkCalls);
            $this->assertSame($expectedSize, $message->getAttachments()[0]->getSize());
            $this->assertSame($expectedSize, filesize($target));
            $this->assertSame($expectedHash, hash_file('sha256', $target));
            $this->assertLessThan(
                6 * 1024 * 1024,
                $peakGrowth,
                sprintf('Parsing a %d MiB attachment must not need more than a few MiB of memory (peak growth: %.1f MiB).', $megabytes, $peakGrowth / 1048576)
            );
        } finally {
            @unlink($target);
        }
    }

    public function test_sink_stream_is_flushed_but_not_closed_and_must_be_a_resource(): void
    {
        $raw = $this->multipart($this->attachmentPart('a.bin', base64_encode('payload')));
        $sink = $this->tempStream('', 'php://temp', 'w+b');

        $message = (new MimeParser)->parse($raw, null, fn () => $sink);

        $this->assertTrue(is_resource($sink), 'The caller owns the sink stream.');
        $this->assertSame('payload', $message->getAttachments()[0]->getContents());

        $this->expectException(InvalidArgumentException::class);
        (new MimeParser)->parse($raw, null, $this->invalidSink());
    }

    public function test_attachment_larger_than_the_limit_is_rejected(): void
    {
        $raw = $this->multipart($this->attachmentPart('a.bin', chunk_split(base64_encode(str_repeat('x', 5000)))));

        $this->expectException(MimeLimitExceededException::class);
        $this->expectExceptionMessage('Attachment exceeds');

        (new MimeParser(maxAttachmentSize: 1000))->parse($raw);
    }

    public function test_limit_exception_with_temp_streams_does_not_leak_open_files(): void
    {
        $raw = $this->multipart($this->attachmentPart('a.bin', chunk_split(base64_encode(str_repeat('x', 5000)))));
        $parser = new MimeParser(maxAttachmentSize: 1000);

        for ($i = 0; $i < 50; $i++) {
            try {
                $parser->parse($raw);
            } catch (MimeLimitExceededException) {
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_too_many_parts_are_rejected(): void
    {
        $parts = '';
        for ($i = 0; $i < 30; $i++) {
            $parts .= $this->attachmentPart("f{$i}.txt", base64_encode('x'));
        }

        $this->expectException(MimeLimitExceededException::class);
        $this->expectExceptionMessage('MIME parts');

        (new MimeParser(maxParts: 20))->parse($this->multipart($parts));
    }

    public function test_deeply_nested_multiparts_are_rejected(): void
    {
        $depth = 200;
        $raw = "From: a@example.test\r\nContent-Type: multipart/mixed; boundary=\"b0\"\r\n\r\n";
        for ($i = 0; $i < $depth; $i++) {
            $next = $i + 1;
            $raw .= "--b{$i}\r\nContent-Type: multipart/mixed; boundary=\"b{$next}\"\r\n\r\n";
        }
        $raw .= "--b{$depth}\r\nContent-Type: text/plain\r\n\r\ndeep\r\n";

        $this->expectException(MimeLimitExceededException::class);
        $this->expectExceptionMessage('nesting');

        (new MimeParser(maxDepth: 10))->parse($raw);
    }

    public function test_nesting_within_the_limit_is_parsed(): void
    {
        $raw = "From: a@example.test\r\nContent-Type: multipart/mixed; boundary=\"b0\"\r\n\r\n";
        for ($i = 0; $i < 4; $i++) {
            $raw .= "--b{$i}\r\nContent-Type: multipart/mixed; boundary=\"b".($i + 1)."\"\r\n\r\n";
        }
        $raw .= "--b4\r\nContent-Type: text/plain\r\n\r\ndeep\r\n--b4--\r\n--b3--\r\n--b2--\r\n--b1--\r\n--b0--\r\n";

        $message = (new MimeParser(maxDepth: 10))->parse($raw);

        $this->assertSame('deep', $message->getBodyText());
    }

    public function test_oversized_text_part_is_rejected(): void
    {
        $raw = "From: a@example.test\r\nContent-Type: text/plain\r\n\r\n".str_repeat("line of text\r\n", 5000);

        $this->expectException(MimeLimitExceededException::class);

        (new MimeParser(maxTextPartSize: 1000))->parse($raw);
    }

    public function test_oversized_header_block_is_rejected(): void
    {
        $raw = "From: a@example.test\r\nX-Big: ".str_repeat('a', 10_000)."\r\n\r\nbody";

        $this->expectException(MimeLimitExceededException::class);

        (new MimeParser(maxHeaderSize: 2048))->parse($raw);
    }

    public function test_hostile_attachment_names_are_sanitized_but_kept_as_original_metadata(): void
    {
        $parts = $this->attachmentPart('../../etc/passwd', base64_encode('a'))
            .$this->attachmentPart("evil\u{202E}fdp.exe", base64_encode('b'))
            .$this->attachmentPart(str_repeat('n', 600).'.pdf', base64_encode('c'));

        $attachments = (new MimeParser)->parse($this->multipart($parts))->getAttachments();

        $this->assertSame('passwd', $attachments[0]->getFilename());
        $this->assertSame('../../etc/passwd', $attachments[0]->getOriginalFilename());
        $this->assertSame('evilfdp.exe', $attachments[1]->getFilename());
        $this->assertSame(255, strlen($attachments[2]->getFilename()));
        $this->assertStringEndsWith('.pdf', $attachments[2]->getFilename());
    }

    public function test_rfc_2231_extended_and_continued_filenames_are_decoded(): void
    {
        $raw = $this->multipart(
            "--b1\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename*=UTF-8''Rechnung%20f%C3%BCr%20M%C3%A4rz.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('1')."\r\n"
            ."--b1\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment;\r\n filename*0*=UTF-8''lange%20;\r\n filename*1*=Datei%C3%A4;\r\n filename*2=\".pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('2')."\r\n"
            ."--b1\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename*=iso-8859-1''K%FCche.txt\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('3')."\r\n"
        );

        $attachments = (new MimeParser)->parse($raw)->getAttachments();

        $this->assertSame('Rechnung für März.pdf', $attachments[0]->getFilename());
        $this->assertSame('lange Dateiä.pdf', $attachments[1]->getFilename());
        $this->assertSame('Küche.txt', $attachments[2]->getFilename());
    }

    public function test_rfc_2047_encoded_filenames_and_quoted_semicolons(): void
    {
        $raw = $this->multipart(
            "--b1\r\nContent-Type: application/pdf; name=\"=?UTF-8?B?UmVjaG51bmcgZsO8ci5wZGY=?=\"\r\nContent-Disposition: attachment\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('1')."\r\n"
            ."--b1\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"a;b \\\"c\\\".txt\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('2')."\r\n"
        );

        $attachments = (new MimeParser)->parse($raw)->getAttachments();

        $this->assertSame('Rechnung für.pdf', $attachments[0]->getFilename());
        $this->assertSame('a;b "c".txt', $attachments[1]->getOriginalFilename());
    }

    public function test_missing_filename_falls_back_to_default(): void
    {
        $raw = $this->multipart("--b1\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('x')."\r\n");

        $attachments = (new MimeParser)->parse($raw)->getAttachments();

        $this->assertSame('attachment.bin', $attachments[0]->getFilename());
    }

    public function test_base64_with_irregular_line_lengths_and_padding_is_decoded_exactly(): void
    {
        $payload = random_bytes(1000);
        $encoded = base64_encode($payload);
        $irregular = '';
        foreach (str_split($encoded, 77) as $i => $line) { // 77 is not a multiple of 4
            $irregular .= $line.($i % 2 === 0 ? "\r\n" : "\n");
        }

        $raw = $this->multipart($this->attachmentPart('r.bin', $irregular));

        $this->assertSame($payload, (new MimeParser)->parse($raw)->getAttachments()[0]->getContents());
    }

    public function test_quoted_printable_attachment_with_soft_breaks_is_decoded_exactly(): void
    {
        $original = "Zeile eins mit Umlaut ä\r\nZweite Zeile ist sehr lang und wird weich umgebrochen, damit sie hier ankommt";
        $encoded = "Zeile eins mit Umlaut =C3=A4\r\nZweite Zeile ist sehr lang und wird weich umgebrochen, =\r\ndamit sie hier ankommt";

        $raw = $this->multipart($this->attachmentPart('t.txt', $encoded, 'quoted-printable'));

        $this->assertSame(
            "Zeile eins mit Umlaut ä\r\nZweite Zeile ist sehr lang und wird weich umgebrochen, damit sie hier ankommt",
            (new MimeParser)->parse($raw)->getAttachments()[0]->getContents()
        );
        $this->assertNotSame('', $original);
    }

    public function test_binary_8bit_attachment_keeps_its_bytes_without_the_boundary_line_break(): void
    {
        $payload = "binary\r\n\0data\xFF\xFE\r\nwith lines";
        $raw = $this->multipart($this->attachmentPart('b.bin', $payload, 'binary'));

        $this->assertSame($payload, (new MimeParser)->parse($raw)->getAttachments()[0]->getContents());
    }

    public function test_alternative_body_inside_mixed_with_attachment_after_it(): void
    {
        $raw = "From: a@example.test\r\nSubject: Nested\r\nContent-Type: multipart/mixed; boundary=\"outer\"\r\n\r\n"
            ."preamble ignored\r\n"
            ."--outer\r\nContent-Type: multipart/alternative; boundary=\"inner\"\r\n\r\n"
            ."--inner\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nPlain =C3=A4\r\n"
            ."--inner\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>HTML</p>\r\n"
            ."--inner--\r\nepilogue ignored\r\n"
            ."--outer\r\nContent-Type: text/plain; name=\"n.txt\"\r\nContent-Disposition: attachment; filename=\"n.txt\"\r\n\r\nattached text\r\n"
            ."--outer--\r\n";

        $message = (new MimeParser)->parse($raw);

        $this->assertSame('Plain ä', $message->getBodyText());
        $this->assertSame('<p>HTML</p>', $message->getBodyHtml());
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame('attached text', $message->getAttachments()[0]->getContents());
    }

    public function test_boundary_lookalike_inside_attachment_content_is_not_a_boundary(): void
    {
        $content = "line one\r\n--b1x not a boundary\r\n --b1\r\nline four";
        $raw = $this->multipart($this->attachmentPart('x.txt', $content, '7bit'));

        $this->assertSame($content, (new MimeParser)->parse($raw)->getAttachments()[0]->getContents());
    }

    public function test_truncated_message_without_closing_boundary_does_not_hang(): void
    {
        $raw = "From: a@example.test\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
            ."--b1\r\nContent-Type: text/plain\r\n\r\nstill here";

        $this->assertSame('still here', (new MimeParser)->parse($raw)->getBodyText());
    }

    public function test_unknown_charset_does_not_crash(): void
    {
        $raw = "From: a@example.test\r\nContent-Type: text/plain; charset=x-does-not-exist\r\n\r\nhello";

        $this->assertSame('hello', (new MimeParser)->parse($raw)->getBodyText());
    }

    public function test_legacy_charset_is_converted(): void
    {
        $raw = "From: a@example.test\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nK=FCche";

        $this->assertSame('Küche', (new MimeParser)->parse($raw)->getBodyText());
    }
}
