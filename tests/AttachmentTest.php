<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests;

use Maivio\Imap\DTO\Attachment;

class AttachmentTest extends TestCase
{
    public function test_attachment_streaming_and_saving(): void
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'Hello World PDF Content');
        rewind($stream);

        $attachment = new Attachment(
            id: 'att-123',
            filename: 'document.pdf',
            mimeType: 'application/pdf',
            size: 23,
            stream: $stream,
            isInline: false,
        );

        $this->assertSame('att-123', $attachment->getId());
        $this->assertSame('document.pdf', $attachment->getFilename());
        $this->assertSame('application/pdf', $attachment->getMimeType());
        $this->assertSame('Hello World PDF Content', $attachment->getContents());

        $targetFile = sys_get_temp_dir().'/maivio_test_'.uniqid().'.pdf';
        $bytes = $attachment->saveTo($targetFile);

        $this->assertSame(23, $bytes);
        $this->assertFileExists($targetFile);
        $this->assertSame('Hello World PDF Content', file_get_contents($targetFile));

        @unlink($targetFile);
    }
}
