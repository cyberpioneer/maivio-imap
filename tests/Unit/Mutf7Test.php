<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Unit;

use InvalidArgumentException;
use Maivio\Imap\Client\ImapClient;
use Maivio\Imap\Protocol\Mutf7;
use Maivio\Imap\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class Mutf7Test extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function vectors(): array
    {
        return [
            'ascii' => ['INBOX/Sent', 'INBOX/Sent'],
            'ampersand' => ['Tom & Jerry', 'Tom &- Jerry'],
            'only ampersand' => ['&', '&-'],
            'double ampersand' => ['&&', '&-&-'],
            'umlaut' => ['Entwürfe', 'Entw&APw-rfe'],
            'umlaut and ampersand' => ['Entwürfe & Vorlagen', 'Entw&APw-rfe &- Vorlagen'],
            'rfc example' => ['~peter/mail/日本語/台北', '~peter/mail/&ZeVnLIqe-/&U,BTFw-'],
            'emoji (surrogate pair)' => ['Inbox 📬', 'Inbox &2D3c7A-'],
            'empty' => ['', ''],
            'non-ascii at start and end' => ['äbc ö', '&AOQ-bc &APY-'],
        ];
    }

    #[DataProvider('vectors')]
    public function test_encode_and_decode_match_the_rfc_vectors(string $utf8, string $encoded): void
    {
        $this->assertSame($encoded, Mutf7::encode($utf8));
        $this->assertSame($utf8, Mutf7::decode($encoded));
    }

    #[DataProvider('vectors')]
    public function test_roundtrip(string $utf8): void
    {
        $this->assertSame($utf8, Mutf7::decode(Mutf7::encode($utf8)));
    }

    public function test_client_wrappers_use_the_same_implementation(): void
    {
        $client = new ImapClient;

        $this->assertSame('Entw&APw-rfe &- Vorlagen', $client->encodeUtf7('Entwürfe & Vorlagen'));
        $this->assertSame('Entwürfe & Vorlagen', $client->decodeUtf7('Entw&APw-rfe &- Vorlagen'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEncoded(): array
    {
        return [
            'unterminated' => ['Foo&AOQ'],
            'bare ampersand' => ['Foo&'],
            'illegal base64 character' => ['&A!Q-'],
            'odd byte count' => ['&AOQA-'],
        ];
    }

    #[DataProvider('invalidEncoded')]
    public function test_decode_rejects_malformed_input(string $encoded): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mutf7::decode($encoded);
    }

    public function test_encode_rejects_invalid_utf8(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mutf7::encode("\xC3\x28");
    }
}
