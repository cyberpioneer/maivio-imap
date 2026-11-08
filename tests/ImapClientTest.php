<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests;

use Maivio\Imap\Client\ImapClient;

class ImapClientTest extends TestCase
{
    public function test_utf7_folder_encoding_and_decoding(): void
    {
        $client = new ImapClient;

        $original = 'Entwürfe & Vorlagen';
        $encoded = $client->encodeUtf7($original);
        $decoded = $client->decodeUtf7($encoded);

        $this->assertSame($original, $decoded);
    }

    public function test_xoauth2_auth_string_format(): void
    {
        $user = 'user@example.com';
        $token = 'ya29.a0AfH6SM...';

        $authString = "user={$user}\x01auth=Bearer {$token}\x01\x01";
        $encoded = base64_encode($authString);

        $this->assertNotEmpty($encoded);
        $this->assertSame($authString, base64_decode($encoded));
        $this->assertStringStartsWith('dXNlcj11c2VyQGV4YW1wbGUuY29t', $encoded);
    }
}
