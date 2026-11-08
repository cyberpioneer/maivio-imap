<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Integration;

use Maivio\Imap\Client\ImapClient;
use Maivio\Imap\Exceptions\ImapConnectionException;
use Maivio\Imap\ImapManager;
use Maivio\Imap\Protocol\ConnectionOptions;
use Maivio\Imap\Tests\Support\LoopbackServer;
use Maivio\Imap\Tests\TestCase;
use Maivio\Imap\Transport\StreamTransport;

/**
 * Exercises the real StreamTransport (sockets + TLS) against a local test server on 127.0.0.1.
 * No external network is used. Skipped when the openssl CLI is not available.
 */
class LoopbackTlsTest extends TestCase
{
    private ?LoopbackServer $server = null;

    protected function setUp(): void
    {
        if (! function_exists('proc_open') || ! extension_loaded('openssl') || trim((string) shell_exec('command -v openssl')) === '') {
            $this->markTestSkipped('proc_open, ext-openssl and the openssl CLI are required.');
        }
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function start(string $mode, string $san = 'DNS:imap.test'): LoopbackServer
    {
        return $this->server = LoopbackServer::start($mode, $san);
    }

    /**
     * Client whose transport trusts exactly the test server's self-signed certificate (verification stays fully enabled).
     */
    private function trustingClient(LoopbackServer $server): ImapClient
    {
        return new ImapClient(null, fn (ConnectionOptions $options) => StreamTransport::connect($options, ['cafile' => $server->certFile]));
    }

    public function test_starttls_over_a_real_socket_logs_in_only_after_the_tls_handshake(): void
    {
        $server = $this->start('starttls');

        $client = new ImapClient;
        $client->connect('127.0.0.1', $server->port, 'starttls', timeout: 10, validateCert: false);
        $client->login('user', 'secret');
        $client->disconnect();

        $log = $server->finish();

        $this->assertSame([
            'CMD enc=0 CAPABILITY',
            'CMD enc=0 STARTTLS',
            'TLS ok',
            'CMD enc=1 CAPABILITY',
            'CMD enc=1 LOGIN',
            'CMD enc=1 CAPABILITY',
        ], array_slice($log, 0, 6));
        $this->assertNotContains('CMD enc=0 LOGIN', $log);
    }

    public function test_implicit_tls_over_a_real_socket(): void
    {
        $server = $this->start('implicit');

        $client = new ImapClient;
        $client->connect('127.0.0.1', $server->port, 'ssl', timeout: 10, validateCert: false);
        $client->login('user', 'secret');
        $client->disconnect();

        $log = $server->finish();

        $this->assertContains('CMD enc=1 LOGIN', $log);
    }

    public function test_certificate_validation_is_on_by_default_and_blocks_login(): void
    {
        $server = $this->start('starttls');

        $client = new ImapClient;

        try {
            // Self-signed and not trusted: the handshake has to fail, there is no fallback.
            $client->connect('127.0.0.1', $server->port, 'starttls', timeout: 10);
            $this->fail('Expected ImapConnectionException');
        } catch (ImapConnectionException $e) {
            $this->assertStringContainsString('TLS handshake failed', $e->getMessage());
        }

        $this->assertFalse($client->isConnected());
        $log = $server->finish();
        $this->assertNotContains('CMD enc=0 LOGIN', $log);
        $this->assertNotContains('CMD enc=1 LOGIN', $log);
    }

    public function test_implicit_tls_validation_failure_aborts_the_connection(): void
    {
        $server = $this->start('implicit');

        $client = new ImapClient;

        $this->expectException(ImapConnectionException::class);

        try {
            $client->connect('127.0.0.1', $server->port, 'ssl', timeout: 10);
        } finally {
            $this->assertFalse($client->isConnected());
        }
    }

    public function test_peer_name_allows_connecting_to_a_pre_resolved_ip_with_hostname_verification(): void
    {
        $server = $this->start('starttls', 'DNS:imap.test');

        $client = $this->trustingClient($server);
        $client->connect('127.0.0.1', $server->port, 'starttls', timeout: 10, peerName: 'imap.test');
        $client->login('user', 'secret');
        $client->disconnect();

        $this->assertContains('CMD enc=1 LOGIN', $server->finish());
    }

    public function test_wrong_peer_name_is_rejected_even_if_the_certificate_is_trusted(): void
    {
        $server = $this->start('starttls', 'DNS:imap.test');

        $client = $this->trustingClient($server);

        try {
            $client->connect('127.0.0.1', $server->port, 'starttls', timeout: 10, peerName: 'evil.example');
            $this->fail('Expected ImapConnectionException');
        } catch (ImapConnectionException $e) {
            $this->assertStringContainsString('TLS handshake failed', $e->getMessage());
        }

        $this->assertNotContains('CMD enc=1 LOGIN', $server->finish());
    }

    public function test_connecting_by_ip_without_peer_name_fails_hostname_verification(): void
    {
        $server = $this->start('starttls', 'DNS:imap.test');

        $client = $this->trustingClient($server);

        try {
            $client->connect('127.0.0.1', $server->port, 'starttls', timeout: 10);
            $this->fail('Expected ImapConnectionException');
        } catch (ImapConnectionException $e) {
            $this->assertStringContainsString('TLS handshake failed', $e->getMessage());
        }

        $this->assertNotContains('CMD enc=1 LOGIN', $server->finish());
    }

    public function test_server_without_starttls_never_receives_credentials_over_a_real_socket(): void
    {
        $server = $this->start('nostarttls');

        $client = new ImapClient;

        try {
            $client->connect('127.0.0.1', $server->port, 'starttls', timeout: 10, validateCert: false);
            $this->fail('Expected ImapConnectionException');
        } catch (ImapConnectionException $e) {
            $this->assertStringContainsString('STARTTLS', $e->getMessage());
        }

        $log = $server->finish();
        $this->assertSame(['CMD enc=0 CAPABILITY'], $log);
    }

    public function test_plaintext_none_mode_requires_explicit_opt_in_even_on_a_real_socket(): void
    {
        $server = $this->start('nostarttls');

        $client = new ImapClient;

        try {
            $client->connect('127.0.0.1', $server->port, 'none', timeout: 10);
            $this->fail('Expected ImapConnectionException');
        } catch (ImapConnectionException) {
            $this->addToAssertionCount(1);
        }

        $server->stop();
        $this->server = null;
    }

    public function test_none_mode_with_allow_insecure_works_in_plaintext(): void
    {
        $server = $this->start('nostarttls');

        $client = new ImapClient;
        $client->connect('127.0.0.1', $server->port, 'none', timeout: 10, allowInsecure: true);
        $client->login('user', 'secret');
        $client->disconnect();

        $this->assertContains('CMD enc=0 LOGIN', $server->finish());
    }

    public function test_manager_parses_boolean_strings_strictly(): void
    {
        $server = $this->start('implicit');

        // "false" as string must disable validation (self-signed test certificate => connects).
        $client = (new ImapManager)->connect([
            'host' => '127.0.0.1',
            'port' => $server->port,
            'encryption' => 'ssl',
            'validate_cert' => 'false',
            'timeout' => 10,
            'username' => 'user',
            'password' => 'secret',
        ]);
        $client->disconnect();
        $this->assertContains('CMD enc=1 LOGIN', $server->finish());

        // An unparsable value must fall back to the secure default (validation on => handshake fails).
        $server = $this->start('implicit');

        try {
            (new ImapManager)->connect([
                'host' => '127.0.0.1',
                'port' => $server->port,
                'encryption' => 'ssl',
                'validate_cert' => 'nonsense',
                'timeout' => 10,
            ]);
            $this->fail('Expected ImapConnectionException');
        } catch (ImapConnectionException) {
            $this->addToAssertionCount(1);
        }
    }
}
