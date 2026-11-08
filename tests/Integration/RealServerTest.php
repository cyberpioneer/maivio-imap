<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Integration;

use Maivio\Imap\Client\ImapClient;
use Maivio\Imap\Search\SearchCriteria;
use Maivio\Imap\Tests\TestCase;

/**
 * Optional test against a real IMAP server. Skipped unless IMAP_TEST_HOST is set.
 *
 * Environment: IMAP_TEST_HOST, IMAP_TEST_PORT (default 993), IMAP_TEST_ENCRYPTION (default ssl),
 * IMAP_TEST_USERNAME, IMAP_TEST_PASSWORD. Use a dedicated throw-away account: the test creates and deletes a folder.
 */
class RealServerTest extends TestCase
{
    private function client(): ImapClient
    {
        $host = getenv('IMAP_TEST_HOST');
        $username = getenv('IMAP_TEST_USERNAME');
        $password = getenv('IMAP_TEST_PASSWORD');

        if ($host === false || $host === '' || $username === false || $password === false) {
            $this->markTestSkipped('Set IMAP_TEST_HOST, IMAP_TEST_USERNAME and IMAP_TEST_PASSWORD to run this test.');
        }

        $client = new ImapClient;
        $client->connect(
            $host,
            (int) (getenv('IMAP_TEST_PORT') ?: 993),
            getenv('IMAP_TEST_ENCRYPTION') ?: 'ssl',
        );
        $client->login($username, $password);

        return $client;
    }

    public function test_folder_lifecycle_and_search(): void
    {
        $client = $this->client();
        $name = 'maivio-imap-test-'.bin2hex(random_bytes(4)).' Übung';

        try {
            $client->createFolder($name, subscribe: true);

            $names = array_map(fn ($f) => $f->getName(), $client->listFolders());
            $this->assertContains($name, $names);

            $status = $client->folderStatus($name);
            $this->assertSame(0, $status->getMessages());

            $client->selectFolder($name);
            $this->assertSame([], $client->search(SearchCriteria::new()->unseen()));
        } finally {
            $client->deleteFolder($name, unsubscribe: true);
            $client->disconnect();
        }
    }
}
