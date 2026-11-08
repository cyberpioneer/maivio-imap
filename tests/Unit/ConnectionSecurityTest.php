<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Unit;

use InvalidArgumentException;
use Maivio\Imap\Client\ImapClient;
use Maivio\Imap\Exceptions\ImapAuthenticationException;
use Maivio\Imap\Exceptions\ImapConnectionException;
use Maivio\Imap\ImapManager;
use Maivio\Imap\Protocol\ConnectionOptions;
use Maivio\Imap\Protocol\Encryption;
use Maivio\Imap\Tests\Support\Fakes;
use Maivio\Imap\Tests\Support\FakeTransport;
use Maivio\Imap\Tests\TestCase;
use Maivio\Imap\Transport\StreamTransport;
use PHPUnit\Framework\Attributes\DataProvider;

class ConnectionSecurityTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function unknownModes(): array
    {
        return [
            'typo' => ['starttsl'],
            'empty' => [''],
            'tcp' => ['tcp'],
            'plain' => ['plain'],
            'false' => ['false'],
            'off' => ['off'],
            'scheme' => ['tcp://'],
        ];
    }

    #[DataProvider('unknownModes')]
    public function test_unknown_encryption_value_throws_and_never_opens_a_connection(string $mode): void
    {
        $factoryCalled = false;
        $client = new ImapClient(null, function () use (&$factoryCalled): never {
            $factoryCalled = true;
            throw new \LogicException('must not be called');
        });

        try {
            $client->connect('mail.example.test', 143, $mode);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unsupported IMAP encryption mode', $e->getMessage());
        }

        $this->assertFalse($factoryCalled, 'No transport may be created for an unknown encryption mode.');
        $this->assertFalse($client->isConnected());
    }

    public function test_none_without_allow_insecure_throws_before_connecting(): void
    {
        $factoryCalled = false;
        $client = new ImapClient(null, function () use (&$factoryCalled): never {
            $factoryCalled = true;
            throw new \LogicException('must not be called');
        });

        $this->expectException(ImapConnectionException::class);
        $this->expectExceptionMessage('allow_insecure');

        try {
            $client->connect('mail.example.test', 143, 'none');
        } finally {
            $this->assertFalse($factoryCalled);
        }
    }

    public function test_none_with_allow_insecure_connects_and_may_log_in(): void
    {
        [$client, $transport] = Fakes::client(encryption: 'none', allowInsecure: true);

        $client->login('user', 'pass');

        $this->assertSame(0, $transport->tlsCalls);
        $this->assertTrue($transport->sawVerb('LOGIN'));
    }

    public function test_host_with_scheme_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConnectionOptions(host: 'tcp://mail.example.test');
    }

    public function test_ssl_and_tls_are_implicit_tls_and_never_issue_starttls(): void
    {
        foreach (['ssl', 'tls', 'SSL'] as $mode) {
            [$client, $transport] = Fakes::client(encryption: $mode);
            $client->login('user', 'pass');

            $this->assertFalse($transport->sawVerb('STARTTLS'));
            $this->assertTrue($transport->sawVerb('LOGIN'));
        }
    }

    public function test_starttls_upgrades_before_any_credentials_are_sent(): void
    {
        [$client, $transport] = Fakes::client(encryption: 'starttls');
        $client->login('user@example.test', 'secret');

        $this->assertSame(['CAPABILITY', 'STARTTLS', 'CAPABILITY', 'LOGIN', 'CAPABILITY'], $transport->verbs());
        $this->assertSame(1, $transport->tlsCalls);

        // LOGIN (and the final CAPABILITY) travelled over the encrypted channel, everything before it did not.
        $encrypted = array_column($transport->commands, 'encrypted');
        $this->assertSame([false, false, true, true, true], $encrypted);

        // Capabilities were re-read after the handshake: STARTTLS is no longer advertised.
        $this->assertFalse($client->hasCapability('STARTTLS'));
    }

    public function test_starttls_downgrade_when_server_does_not_offer_starttls(): void
    {
        [$client, $transport] = Fakes::client(connect: false, configure: function (FakeTransport $t): void {
            $t->capabilities = ['IMAP4REV1', 'AUTH=PLAIN'];
        }, encryption: 'starttls');

        try {
            $client->connect('mail.example.test', 143, 'starttls');
            $this->fail('Expected ImapConnectionException');
        } catch (ImapConnectionException $e) {
            $this->assertStringContainsString('STARTTLS', $e->getMessage());
        }

        $this->assertFalse($transport->sawVerb('LOGIN'));
        $this->assertFalse($transport->sawVerb('STARTTLS'));
        $this->assertSame(0, $transport->tlsCalls);
        $this->assertTrue($transport->closed, 'Connection must be closed.');
        $this->assertFalse($client->isConnected());

        // Even trying to log in afterwards sends nothing.
        $bytes = strlen($transport->written);
        try {
            $client->login('user', 'secret');
        } catch (ImapConnectionException) {
        }
        $this->assertSame($bytes, strlen($transport->written));
    }

    public function test_starttls_rejected_by_server_closes_connection_without_login(): void
    {
        [$client, $transport] = Fakes::client(connect: false, configure: function (FakeTransport $t): void {
            $t->starttlsOk = false;
        });

        $this->expectException(ImapConnectionException::class);

        try {
            $client->connect('mail.example.test', 143, 'starttls');
        } finally {
            $this->assertFalse($transport->sawVerb('LOGIN'));
            $this->assertTrue($transport->closed);
            $this->assertSame(0, $transport->tlsCalls);
        }
    }

    public function test_failing_tls_upgrade_closes_connection_without_login(): void
    {
        [$client, $transport] = Fakes::client(connect: false, configure: function (FakeTransport $t): void {
            $t->failTls = true;
        });

        try {
            $client->connect('mail.example.test', 143, 'starttls');
            $this->fail('Expected ImapConnectionException');
        } catch (ImapConnectionException $e) {
            $this->assertStringContainsString('TLS handshake failed', $e->getMessage());
        }

        $this->assertSame(1, $transport->tlsCalls);
        $this->assertFalse($transport->sawVerb('LOGIN'));
        $this->assertTrue($transport->closed);
        $this->assertFalse($client->isConnected());
    }

    public function test_login_is_refused_when_server_announces_logindisabled(): void
    {
        [$client, $transport] = Fakes::client(configure: function (FakeTransport $t): void {
            $t->capabilities = ['IMAP4REV1', 'LOGINDISABLED'];
        });

        $before = strlen($transport->written);

        try {
            $client->login('user', 'secret');
            $this->fail('Expected ImapAuthenticationException');
        } catch (ImapAuthenticationException $e) {
            $this->assertStringContainsString('LOGINDISABLED', $e->getMessage());
        }

        $this->assertSame($before, strlen($transport->written), 'No byte may be sent.');
    }

    public function test_login_requires_a_connection(): void
    {
        $client = new ImapClient;

        $this->expectException(ImapConnectionException::class);
        $client->login('user', 'secret');
    }

    public function test_manager_rejects_unknown_encryption_and_unparsable_allow_insecure(): void
    {
        $manager = new ImapManager(['connections' => ['x' => ['host' => 'mail.example.test', 'encryption' => 'starttsl']]]);

        try {
            $manager->connection('x');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        // The string "false" must not be cast to boolean true.
        foreach (['false', '0', 'off', 'garbage', null, ''] as $value) {
            try {
                $manager->connect(['host' => 'mail.example.test', 'encryption' => 'none', 'allow_insecure' => $value]);
                $this->fail('Expected ImapConnectionException for allow_insecure='.var_export($value, true));
            } catch (ImapConnectionException $e) {
                $this->assertStringContainsString('allow_insecure', $e->getMessage());
            }
        }
    }

    public function test_tls_context_keeps_verification_enabled_and_supports_peer_name_and_sni(): void
    {
        $options = new ConnectionOptions(
            host: '203.0.113.10',
            port: 993,
            encryption: Encryption::Tls,
            peerName: 'imap.example.org',
        );

        $ssl = StreamTransport::sslOptions($options);

        $this->assertTrue($ssl['verify_peer']);
        $this->assertTrue($ssl['verify_peer_name']);
        $this->assertFalse($ssl['allow_self_signed']);
        $this->assertSame('imap.example.org', $ssl['peer_name']);
        $this->assertTrue($ssl['SNI_enabled']);
        $this->assertSame('imap.example.org', $ssl['SNI_server_name']);
        $this->assertSame(
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            $ssl['crypto_method'],
            'TLS must be limited to 1.2 and 1.3.'
        );

        $custom = StreamTransport::sslOptions(new ConnectionOptions(
            host: '203.0.113.10',
            peerName: 'imap.example.org',
            sniEnabled: true,
            sniServerName: 'sni.example.org',
        ));
        $this->assertSame('sni.example.org', $custom['SNI_server_name']);

        $disabled = StreamTransport::sslOptions(new ConnectionOptions(host: 'mail.example.test', validateCert: false));
        $this->assertFalse($disabled['verify_peer']);
        $this->assertFalse($disabled['verify_peer_name']);
    }
}
