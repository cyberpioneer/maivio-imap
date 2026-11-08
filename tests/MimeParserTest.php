<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests;

use Maivio\Imap\Parser\MimeParser;

class MimeParserTest extends TestCase
{
    public function test_parses_simple_text_email(): void
    {
        $rawEmail = "From: John Doe <john@example.com>\r\n"
            ."To: Jane <jane@example.com>\r\n"
            ."Subject: Simple Test\r\n"
            ."Date: Fri, 30 Oct 2026 14:00:00 +0100\r\n"
            ."Message-ID: <test1234@example.com>\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n"
            ."\r\n"
            ."Hello Jane,\r\nThis is a simple email body.";

        $parser = new MimeParser;
        $message = $parser->parse($rawEmail, 42);

        $this->assertSame(42, $message->getUid());
        $this->assertSame('test1234@example.com', $message->getMessageId());
        $this->assertSame('Simple Test', $message->getSubject());
        $this->assertSame('john@example.com', $message->getSenderEmail());
        $this->assertSame('John Doe', $message->getSenderName());
        $this->assertStringContainsString('Hello Jane,', (string) $message->getBodyText());
        $this->assertNull($message->getBodyHtml());
        $this->assertCount(0, $message->getAttachments());
    }

    public function test_parses_multipart_email_with_base64_attachment(): void
    {
        $boundary = '----=_Part_12345';
        $rawEmail = "From: invoice@company.de\r\n"
            ."Subject: =?UTF-8?B?UmVjaG51bmcgZsO8ciBNYWl2aW8=?=\r\n" // Rechnung für Maivio
            ."Message-ID: <inv-99@company.de>\r\n"
            ."Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n"
            ."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n"
            ."\r\n"
            ."Anbei Ihre Rechnung.\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: application/pdf; name=\"rechnung.pdf\"\r\n"
            ."Content-Disposition: attachment; filename=\"rechnung.pdf\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n"
            ."\r\n"
            .base64_encode('PDF-INVOICE-BINARY-STREAM')
            ."\r\n--{$boundary}--\r\n";

        $parser = new MimeParser;
        $message = $parser->parse($rawEmail);

        $this->assertSame('Rechnung für Maivio', $message->getSubject());
        $this->assertSame('invoice@company.de', $message->getSenderEmail());
        $this->assertStringContainsString('Anbei Ihre Rechnung.', (string) $message->getBodyText());
        $this->assertCount(1, $message->getAttachments());

        $attachment = $message->getAttachments()[0];
        $this->assertSame('rechnung.pdf', $attachment->getFilename());
        $this->assertSame('application/pdf', $attachment->getMimeType());
        $this->assertSame('PDF-INVOICE-BINARY-STREAM', $attachment->getContents());
    }
}
