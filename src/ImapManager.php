<?php

declare(strict_types=1);

namespace Maivio\Imap;

use InvalidArgumentException;
use Maivio\Imap\Client\ImapClient;
use Maivio\Imap\Parser\MimeParser;

class ImapManager
{
    /**
     * @var array<string, ImapClient>
     */
    protected array $connections = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected array $config = [],
        protected ?MimeParser $parser = null,
    ) {
        $this->parser ??= new MimeParser();
    }

    /**
     * Get or create a named IMAP client connection.
     */
    public function connection(?string $name = null): ImapClient
    {
        $name ??= $this->getDefaultConnection();

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $connectionConfig = $this->config['connections'][$name] ?? null;
        if (! is_array($connectionConfig)) {
            throw new InvalidArgumentException("IMAP connection [{$name}] is not configured.");
        }

        $client = $this->makeClient($connectionConfig);
        $this->connections[$name] = $client;

        return $client;
    }

    /**
     * Create an ad-hoc connected client from configuration array.
     *
     * @param array{
     *     host: string,
     *     port?: int,
     *     encryption?: string,
     *     username?: string,
     *     password?: string,
     *     access_token?: string,
     *     timeout?: float,
     *     validate_cert?: bool
     * } $config
     */
    public function connect(array $config): ImapClient
    {
        return $this->makeClient($config);
    }

    /**
     * Disconnect all active connections.
     */
    public function disconnectAll(): void
    {
        foreach ($this->connections as $client) {
            $client->disconnect();
        }

        $this->connections = [];
    }

    public function getDefaultConnection(): string
    {
        return (string) ($this->config['default'] ?? 'default');
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function makeClient(array $config): ImapClient
    {
        $client = new ImapClient($this->parser);

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 993);
        $encryption = (string) ($config['encryption'] ?? 'ssl');
        $timeout = (float) ($config['timeout'] ?? 30.0);
        $validateCert = (bool) ($config['validate_cert'] ?? true);

        $client->connect($host, $port, $encryption, $timeout, $validateCert);

        if (! empty($config['access_token']) && ! empty($config['username'])) {
            $client->authenticateXOAuth2((string) $config['username'], (string) $config['access_token']);
        } elseif (! empty($config['username']) && isset($config['password'])) {
            $client->login((string) $config['username'], (string) $config['password']);
        }

        return $client;
    }

    /**
     * Dynamically pass methods to the default connection.
     *
     * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection()->$method(...$parameters);
    }
}
