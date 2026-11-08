<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests\Support;

use RuntimeException;

/**
 * Starts tests/Support/loopback_imap_server.php as a child process (loopback only, self-signed certificate).
 */
final class LoopbackServer
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    public readonly int $port;

    public readonly string $certFile;

    private function __construct(string $mode, string $certFile)
    {
        $this->certFile = $certFile;

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/loopback_imap_server.php', $mode, $certFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the loopback IMAP server.');
        }

        $this->process = $process;
        stream_set_timeout($this->pipes[1], 10);

        $port = fgets($this->pipes[1]);
        if ($port === false || ! ctype_digit(trim($port))) {
            throw new RuntimeException('Loopback IMAP server did not report a port: '.stream_get_contents($this->pipes[2]));
        }

        $this->port = (int) trim($port);
    }

    /**
     * @param  string  $subjectAltName  e.g. "DNS:imap.test"
     */
    public static function start(string $mode, string $subjectAltName = 'DNS:imap.test'): self
    {
        return new self($mode, self::createCertificate($subjectAltName));
    }

    /**
     * Wait for the server process to finish and return the commands it saw.
     *
     * @return list<string> e.g. ["CMD enc=0 CAPABILITY", "TLS ok", "CMD enc=1 LOGIN"]
     */
    public function finish(): array
    {
        $output = stream_get_contents($this->pipes[1]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        proc_close($this->process);

        return array_values(array_filter(explode("\n", (string) $output), static fn (string $l): bool => $l !== ''));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
        }

        @unlink($this->certFile);
    }

    private static function createCertificate(string $subjectAltName): string
    {
        $dir = sys_get_temp_dir();
        $base = $dir.'/maivio_imap_'.bin2hex(random_bytes(6));
        $config = $base.'.cnf';

        file_put_contents($config, <<<CNF
[req]
distinguished_name = dn
x509_extensions = ext
prompt = no
[dn]
CN = imap.test
[ext]
subjectAltName = {$subjectAltName}
basicConstraints = critical,CA:TRUE
CNF);

        $cmd = sprintf(
            'openssl req -x509 -newkey rsa:2048 -nodes -days 2 -config %s -keyout %s -out %s 2>&1',
            escapeshellarg($config),
            escapeshellarg($base.'.key'),
            escapeshellarg($base.'.crt')
        );
        exec($cmd, $output, $code);
        @unlink($config);

        if ($code !== 0) {
            throw new RuntimeException('openssl failed: '.implode("\n", $output));
        }

        file_put_contents($base.'.pem', (string) file_get_contents($base.'.crt').(string) file_get_contents($base.'.key'));
        @unlink($base.'.key');

        return $base.'.pem';
    }
}
