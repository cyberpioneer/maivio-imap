<?php

declare(strict_types=1);

namespace Maivio\Imap;

use InvalidArgumentException;
use Maivio\Imap\Client\ImapClient;
use Maivio\Imap\Parser\MimeParser;
use Maivio\Imap\Protocol\Encryption;

class ImapManager
{
    /**
     * @var array<string, ImapClient>
     */
    protected array $connections = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        #[\SensitiveParameter] protected array $config = [],
        protected ?MimeParser $parser = null,
    ) {
        $this->parser ??= new MimeParser;
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
     *     read_timeout?: float,
     *     validate_cert?: bool|string|null,
     *     allow_insecure?: bool|string|null,
     *     peer_name?: string|null,
     *     sni_enabled?: bool|string|null,
     *     sni_server_name?: string|null,
     *     max_message_size?: int|null,
     *     chunk_size?: int
     * } $config
     */
    public function connect(#[\SensitiveParameter] array $config): ImapClient
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
     * @param  array<string, mixed>  $config
     */
    protected function makeClient(#[\SensitiveParameter] array $config): ImapClient
    {
        $client = new ImapClient($this->parser);

        $encryption = (string) ($config['encryption'] ?? 'ssl');
        $mode = Encryption::fromString($encryption);

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? ($mode === Encryption::Tls ? 993 : 143));
        $timeout = (float) ($config['timeout'] ?? 30.0);
        $readTimeout = isset($config['read_timeout']) ? (float) $config['read_timeout'] : null;

        // Unrecognised boolean strings fall back to the secure default.
        $validateCert = $this->toBool($config['validate_cert'] ?? null, true);
        $allowInsecure = $this->toBool($config['allow_insecure'] ?? null, false);
        $sniEnabled = $this->toBool($config['sni_enabled'] ?? null, true);

        $peerName = isset($config['peer_name']) && $config['peer_name'] !== '' ? (string) $config['peer_name'] : null;
        $sniServerName = isset($config['sni_server_name']) && $config['sni_server_name'] !== '' ? (string) $config['sni_server_name'] : null;

        if (isset($config['max_message_size']) && (int) $config['max_message_size'] > 0) {
            $client->setMaxMessageSize((int) $config['max_message_size']);
        }

        if (isset($config['chunk_size']) && (int) $config['chunk_size'] > 0) {
            $client->setChunkSize((int) $config['chunk_size']);
        }

        $client->connect(
            host: $host,
            port: $port,
            encryption: $encryption,
            timeout: $timeout,
            validateCert: $validateCert,
            peerName: $peerName,
            allowInsecure: $allowInsecure,
            readTimeout: $readTimeout,
            sniEnabled: $sniEnabled,
            sniServerName: $sniServerName,
        );

        try {
            if (! empty($config['access_token']) && ! empty($config['username'])) {
                $client->authenticateXOAuth2((string) $config['username'], (string) $config['access_token']);
            } elseif (! empty($config['username']) && isset($config['password'])) {
                $client->login((string) $config['username'], (string) $config['password']);
            }
        } catch (\Throwable $e) {
            $client->disconnect();

            throw $e;
        }

        return $client;
    }

    /**
     * Strict boolean parsing: "false"/"0"/"off" are false, "true"/"1"/"on" are true, anything else uses the default.
     */
    protected function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Dynamically pass methods to the default connection.
     *
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, #[\SensitiveParameter] array $parameters): mixed
    {
        return $this->connection()->$method(...$parameters);
    }
}
