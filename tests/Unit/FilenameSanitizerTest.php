<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Unit;

use Maivio\Imap\DTO\Attachment;
use Maivio\Imap\Support\FilenameSanitizer;
use Maivio\Imap\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class FilenameSanitizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'plain' => ['rechnung.pdf', 'rechnung.pdf'],
            'umlaut' => ['Übersicht März.xlsx', 'Übersicht März.xlsx'],
            'parent traversal' => ['../../x', 'x'],
            'deep traversal' => ['../../../../etc/passwd', 'passwd'],
            'windows traversal' => ['..\\..\\windows\\system32\\evil.dll', 'evil.dll'],
            'absolute path' => ['/etc/cron.d/job', 'job'],
            'drive letter' => ['C:\\Users\\bob\\a.txt', 'a.txt'],
            'drive letter only prefix' => ['C:evil.exe', 'evil.exe'],
            'nul byte' => ["inv\0oice.pdf", 'invoice.pdf'],
            'nul truncation attempt' => ["safe.txt\0.php", 'safe.txt.php'],
            'crlf' => ["a\r\nContent-Type: text/html\r\n\r\n.txt", 'html.txt'],
            'rtl override' => ["invoice\u{202E}fdp.exe", 'invoicefdp.exe'],
            'bidi isolates and marks' => ["a\u{2066}b\u{2069}c\u{200F}d\u{200E}.txt", 'abcd.txt'],
            'zero width' => ["pa\u{200B}ss\u{FEFF}.txt", 'pass.txt'],
            'c1 control' => ["a\u{0085}b.txt", 'ab.txt'],
            'tab and bell' => ["a\tb\x07.txt", 'ab.txt'],
            'only dots' => ['..', 'attachment.bin'],
            'single dot' => ['.', 'attachment.bin'],
            'empty' => ['', 'attachment.bin'],
            'only separators' => ['///', 'attachment.bin'],
            'only spaces' => ['   ', 'attachment.bin'],
            'trailing dots and spaces' => ['report.pdf. . ', 'report.pdf'],
            'leading dots' => ['...hidden', 'hidden'],
            'reserved characters' => ['a<b>c:"d|e?f*.txt', 'a_b_c__d_e_f_.txt'],
            'invalid utf-8' => ["bad\xFF\xFEname.txt", 'bad__name.txt'],
        ];
    }

    #[DataProvider('names')]
    public function test_sanitize(string $input, string $expected): void
    {
        $result = FilenameSanitizer::sanitize($input);

        $this->assertSame($expected, $result);
        $this->assertDoesNotMatchRegularExpression('#[/\\\\\x00-\x1F\x7F]#', $result);
    }

    public function test_very_long_names_are_truncated_to_255_bytes_and_keep_the_extension(): void
    {
        $result = FilenameSanitizer::sanitize(str_repeat('a', 5000).'.pdf');

        $this->assertSame(255, strlen($result));
        $this->assertStringEndsWith('.pdf', $result);
    }

    public function test_truncation_never_splits_a_multibyte_character(): void
    {
        $result = FilenameSanitizer::sanitize(str_repeat('ü', 400).'.txt');

        $this->assertLessThanOrEqual(255, strlen($result));
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
        $this->assertStringEndsWith('.txt', $result);
    }

    public function test_very_long_extension_is_cut_too(): void
    {
        $result = FilenameSanitizer::sanitize('a.'.str_repeat('b', 1000));

        $this->assertLessThanOrEqual(255, strlen($result));
    }

    public function test_attachment_dto_always_exposes_a_sanitized_name_and_keeps_the_original_as_metadata(): void
    {
        $stream = $this->tempStream('', 'php://temp', 'r+b');
        $attachment = new Attachment('id', "../../x\u{202E}\r\n.php", 'text/plain', 0, $stream);

        $this->assertSame('x.php', $attachment->getFilename());
        $this->assertSame('../../x.php', $attachment->getOriginalFilename(), 'Original keeps its path text but loses control and BiDi characters.');
        $this->assertSame('x.php', $attachment->toArray()['filename']);
    }
}
