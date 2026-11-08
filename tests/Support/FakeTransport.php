<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Support;

use Closure;
use Maivio\Imap\Exceptions\ImapConnectionException;
use Maivio\Imap\Transport\Transport;

/**
 * In-memory IMAP "server" behind the Transport interface. No sockets, no network.
 *
 * Commands written by the client are parsed (including literals), recorded in $commands and answered
 * by a handler registered with on(). CAPABILITY, STARTTLS, LOGIN and LOGOUT have configurable defaults.
 */
final class FakeTransport implements Transport
{
    /** @var list<string> */
    public array $capabilities = ['IMAP4REV1', 'STARTTLS', 'UIDPLUS', 'MOVE'];

    /** Capabilities announced after the TLS handshake (defaults to $capabilities minus STARTTLS). */
    public ?array $capabilitiesAfterTls = null;

    public string $greeting = "* OK IMAP ready\r\n";

    public bool $starttlsOk = true;

    public bool $failTls = false;

    public bool $loginOk = true;

    public string $loginFailureText = 'authentication failed';

    public bool $tlsEnabled = false;

    public int $tlsCalls = 0;

    public bool $closed = false;

    /** Every byte the client wrote. */
    public string $written = '';

    /** @var list<array{tag: string, command: string, encrypted: bool}> */
    public array $commands = [];

    private string $out = '';

    private string $in = '';

    private string $current = '';

    private int $literalRemaining = 0;

    /** @var array<string, Closure(string, string, string): string> */
    private array $handlers = [];

    public function __construct()
    {
        $this->out = $this->greeting;
    }

    public static function make(?Closure $configure = null): self
    {
        $transport = new self();
        if ($configure !== null) {
            $configure($transport);
        }
        $transport->out = $transport->greeting;

        return $transport;
    }

    /**
     * @param Closure(string $tag, string $args, string $full): string $handler Returns raw server output including the tagged line
     */
    public function on(string $verb, Closure $handler): self
    {
        $this->handlers[strtoupper($verb)] = $handler;

        return $this;
    }

    /**
     * @return list<string> Verbs ("LOGIN", "UID FETCH", ...) of all received commands
     */
    public function verbs(): array
    {
        return array_map(static fn (array $c): string => self::verbOf($c['command']), $this->commands);
    }

    public function sawVerb(string $verb): bool
    {
        return in_array(strtoupper($verb), $this->verbs(), true);
    }

    /**
     * @return list<string> Full command texts (without tag) with the given verb
     */
    public function commandsWithVerb(string $verb): array
    {
        $result = [];
        foreach ($this->commands as $command) {
            if (self::verbOf($command['command']) === strtoupper($verb)) {
                $result[] = $command['command'];
            }
        }

        return $result;
    }

    /**
     * Queue unsolicited bytes for the client (e.g. to simulate injected data).
     */
    public function pushOutput(string $data): void
    {
        $this->out .= $data;
    }

    public function readLine(): string
    {
        $pos = strpos($this->out, "\n");
        if ($pos === false) {
            throw new ImapConnectionException('Fake server: no more data (EOF).');
        }

        $line = substr($this->out, 0, $pos + 1);
        $this->out = substr($this->out, $pos + 1);

        return rtrim($line, "\r\n");
    }

    public function read(int $length): string
    {
        if ($this->out === '') {
            throw new ImapConnectionException('Fake server: no more data (EOF).');
        }

        $chunk = substr($this->out, 0, $length);
        $this->out = substr($this->out, strlen($chunk));

        return $chunk;
    }

    public function write(string $data): void
    {
        if ($this->closed) {
            throw new ImapConnectionException('Fake server: connection closed.');
        }

        $this->written .= $data;
        $this->in .= $data;
        $this->process();
    }

    public function enableTls(): void
    {
        $this->tlsCalls++;

        if ($this->failTls) {
            throw new ImapConnectionException('TLS handshake failed (fake).');
        }

        $this->tlsEnabled = true;
    }

    public function isOpen(): bool
    {
        return ! $this->closed;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    private function process(): void
    {
        while (true) {
            if ($this->literalRemaining > 0) {
                $take = min($this->literalRemaining, strlen($this->in));
                if ($take === 0) {
                    return;
                }

                $this->current .= substr($this->in, 0, $take);
                $this->in = substr($this->in, $take);
                $this->literalRemaining -= $take;

                continue;
            }

            $pos = strpos($this->in, "\r\n");
            if ($pos === false) {
                return;
            }

            $line = substr($this->in, 0, $pos);
            $this->in = substr($this->in, $pos + 2);
            $this->current .= $line;

            if (preg_match('/\{(\d+)(\+)?\}\z/', $line, $m) === 1) {
                $this->literalRemaining = (int) $m[1];
                $this->current .= "\r\n";
                if (($m[2] ?? '') === '') {
                    $this->out .= "+ Ready for literal data\r\n";
                }

                continue;
            }

            $command = $this->current;
            $this->current = '';
            $this->dispatch($command);
        }
    }

    private function dispatch(string $line): void
    {
        $parts = explode(' ', $line, 2);
        $tag = $parts[0];
        $rest = $parts[1] ?? '';

        $this->commands[] = ['tag' => $tag, 'command' => $rest, 'encrypted' => $this->tlsEnabled];

        $verb = self::verbOf($rest);
        $args = trim(substr($rest, strlen($verb)));

        if (isset($this->handlers[$verb])) {
            $this->out .= ($this->handlers[$verb])($tag, $args, $rest);

            return;
        }

        $this->out .= match ($verb) {
            'CAPABILITY' => '* CAPABILITY '.implode(' ', $this->currentCapabilities())."\r\n{$tag} OK CAPABILITY completed\r\n",
            'STARTTLS' => $this->starttlsOk ? "{$tag} OK Begin TLS negotiation now\r\n" : "{$tag} NO STARTTLS not available\r\n",
            'LOGIN', 'AUTHENTICATE' => $this->loginOk ? "{$tag} OK Logged in\r\n" : "{$tag} NO [AUTHENTICATIONFAILED] {$this->loginFailureText}\r\n",
            'LOGOUT' => "* BYE bye\r\n{$tag} OK Logout completed\r\n",
            default => "{$tag} OK {$verb} completed\r\n",
        };
    }

    /**
     * @return list<string>
     */
    private function currentCapabilities(): array
    {
        if ($this->tlsEnabled) {
            return $this->capabilitiesAfterTls
                ?? array_values(array_filter($this->capabilities, static fn (string $c): bool => $c !== 'STARTTLS'));
        }

        return $this->capabilities;
    }

    private static function verbOf(string $command): string
    {
        $words = explode(' ', ltrim($command), 3);
        $first = strtoupper($words[0]);

        if ($first === 'UID' && isset($words[1])) {
            return 'UID '.strtoupper($words[1]);
        }

        return $first;
    }
}
