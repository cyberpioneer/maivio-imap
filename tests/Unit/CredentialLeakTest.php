<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Unit;

use Maivio\Imap\Exceptions\ImapAuthenticationException;
use Maivio\Imap\Exceptions\ImapCommandException;
use Maivio\Imap\Tests\Support\Fakes;
use Maivio\Imap\Tests\Support\FakeTransport;
use Maivio\Imap\Tests\TestCase;
use Throwable;

class CredentialLeakTest extends TestCase
{
    private const USER = 'carol.secretname@example.test';

    private const PASSWORD = 'Zx9!hunter2-TOPSECRET';

    private const TOKEN = 'ya29.TOKEN-TOPSECRET-abcdef';

    private function assertNoSecret(Throwable $e): void
    {
        $all = [$e->getMessage(), $e->getTraceAsString(), (string) $e];
        foreach ($all as $text) {
            foreach ([self::USER, self::PASSWORD, self::TOKEN, base64_encode('user='.self::USER."\x01auth=Bearer ".self::TOKEN."\x01\x01")] as $secret) {
                $this->assertStringNotContainsString($secret, $text);
            }
        }
    }

    public function test_failed_login_exception_contains_no_credentials_even_if_the_server_echoes_them(): void
    {
        [$client, $transport] = Fakes::client(function (FakeTransport $t): void {
            $t->loginOk = false;
            $t->loginFailureText = 'bad login for '.self::USER.' with password '.self::PASSWORD;
        });

        try {
            $client->login(self::USER, self::PASSWORD);
            $this->fail('Expected ImapAuthenticationException');
        } catch (ImapAuthenticationException $e) {
            $this->assertNoSecret($e);
            $this->assertStringContainsString('LOGIN failed', $e->getMessage());
        }
    }

    public function test_failed_xoauth2_exception_contains_no_token_or_username(): void
    {
        [$client] = Fakes::client(function (FakeTransport $t): void {
            $t->loginOk = false;
            $t->loginFailureText = 'rejected '.self::TOKEN.' for '.self::USER;
        });

        try {
            $client->authenticateXOAuth2(self::USER, self::TOKEN);
            $this->fail('Expected ImapAuthenticationException');
        } catch (ImapAuthenticationException $e) {
            $this->assertNoSecret($e);
        }
    }

    public function test_rejected_values_are_not_part_of_exception_messages_or_traces(): void
    {
        [$client] = Fakes::client();

        try {
            $client->login(self::USER, self::PASSWORD."\r\nA1 DELETE INBOX");
            $this->fail('Expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertNoSecret($e);
        }
    }

    public function test_debug_log_redacts_login_and_authenticate(): void
    {
        $log = [];
        [$client] = Fakes::client();
        $client->setDebugLogger(function (string $direction, string $line) use (&$log): void {
            $log[] = "{$direction}: {$line}";
        });

        $client->login(self::USER, self::PASSWORD);
        $client->authenticateXOAuth2(self::USER, self::TOKEN);

        $joined = implode("\n", $log);
        $this->assertStringContainsString('LOGIN ***', $joined);
        $this->assertStringContainsString('AUTHENTICATE ***', $joined);
        $this->assertStringNotContainsString(self::USER, $joined);
        $this->assertStringNotContainsString(self::PASSWORD, $joined);
        $this->assertStringNotContainsString(self::TOKEN, $joined);
        $this->assertStringNotContainsString(base64_encode('user='.self::USER."\x01auth=Bearer ".self::TOKEN."\x01\x01"), $joined);
    }

    public function test_debug_log_scrubs_credentials_echoed_by_the_server(): void
    {
        $log = [];
        [$client] = Fakes::client(function (FakeTransport $t): void {
            $t->loginOk = false;
            $t->loginFailureText = 'no such user '.self::USER;
        });
        $client->setDebugLogger(function (string $direction, string $line) use (&$log): void {
            $log[] = $line;
        });

        try {
            $client->login(self::USER, self::PASSWORD);
        } catch (ImapAuthenticationException) {
        }

        $this->assertStringNotContainsString(self::USER, implode("\n", $log));
    }

    public function test_command_exception_carries_server_text_without_credentials(): void
    {
        [$client, $transport] = Fakes::loggedIn(null, function (FakeTransport $t): void {
            $t->on('CREATE', fn (string $tag): string => "{$tag} NO [ALREADYEXISTS] Mailbox already exists\r\n");
        });

        try {
            $client->createFolder('Archive');
            $this->fail('Expected ImapCommandException');
        } catch (ImapCommandException $e) {
            $this->assertStringContainsString('ALREADYEXISTS', $e->getMessage());
            $this->assertStringContainsString('ALREADYEXISTS', (string) $e->getServerResponse());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function test_xoauth2_without_sasl_ir_answers_the_challenge(): void
    {
        $payload = base64_encode('user='.self::USER."\x01auth=Bearer ".self::TOKEN."\x01\x01");

        [$client, $transport] = Fakes::client(function (FakeTransport $t) use ($payload): void {
            $t->capabilities = ['IMAP4REV1', 'AUTH=XOAUTH2']; // no SASL-IR
            $t->on('AUTHENTICATE', function (string $tag, string $args) use ($t, $payload): string {
                $t->expectContinuation(fn (string $line): string => $line === $payload
                    ? "{$tag} OK authenticated\r\n"
                    : "{$tag} NO unexpected payload\r\n");

                return "+ \r\n";
            });
        });

        $client->authenticateXOAuth2(self::USER, self::TOKEN);

        $this->assertSame(['AUTHENTICATE XOAUTH2'], $transport->commandsWithVerb('AUTHENTICATE'));
    }

    public function test_xoauth2_failure_challenge_is_cancelled_with_an_empty_line(): void
    {
        $received = null;

        [$client] = Fakes::client(function (FakeTransport $t) use (&$received): void {
            $t->capabilities = ['IMAP4REV1', 'SASL-IR', 'AUTH=XOAUTH2'];
            $t->on('AUTHENTICATE', function (string $tag) use ($t, &$received): string {
                $t->expectContinuation(function (string $line) use ($tag, &$received): string {
                    $received = $line;

                    return "{$tag} NO [AUTHENTICATIONFAILED] Invalid credentials\r\n";
                });

                return '+ '.base64_encode('{"status":"401"}')."\r\n";
            });
        });

        try {
            $client->authenticateXOAuth2(self::USER, self::TOKEN);
            $this->fail('Expected ImapAuthenticationException');
        } catch (ImapAuthenticationException $e) {
            $this->assertNoSecret($e);
        }

        $this->assertSame('', $received, 'An empty line must acknowledge the error challenge.');
    }
}
