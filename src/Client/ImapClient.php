<?php

declare(strict_types=1);

namespace Maivio\Imap\Client;

use Maivio\Imap\DTO\Folder;
use Maivio\Imap\DTO\Message;
use Maivio\Imap\Exceptions\ImapAuthenticationException;
use Maivio\Imap\Exceptions\ImapCommandException;
use Maivio\Imap\Exceptions\ImapConnectionException;
use Maivio\Imap\Parser\MimeParser;

class ImapClient
{
    /**
     * @var resource|null
     */
    protected $socket = null;

    protected int $tagCounter = 0;

    protected bool $connected = false;

    protected ?MimeParser $parser = null;

    public function __construct(?MimeParser $parser = null)
    {
        $this->parser = $parser ?? new MimeParser();
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Connect to the IMAP server via stream_socket_client.
     */
    public function connect(
        string $host,
        int $port = 993,
        string $encryption = 'ssl',
        float $timeout = 30.0,
        bool $validateCert = true
    ): self {
        if ($this->connected && is_resource($this->socket)) {
            $this->disconnect();
        }

        $cleanHost = preg_replace('#^(ssl|tls|tcp)://#i', '', $host) ?? $host;
        $scheme = match (strtolower($encryption)) {
            'ssl', 'ssl://' => 'ssl://',
            'tls', 'tls://' => 'tls://',
            default => 'tcp://',
        };

        $remoteSocket = "{$scheme}{$cleanHost}:{$port}";

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $validateCert,
                'verify_peer_name' => $validateCert,
                'allow_self_signed' => ! $validateCert,
            ],
        ]);

        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            $remoteSocket,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! is_resource($socket)) {
            throw new ImapConnectionException("IMAP connection to {$remoteSocket} failed ({$errno}): {$errstr}");
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, (int) $timeout);
        $this->connected = true;

        $greeting = $this->readLine();
        if (! str_starts_with($greeting, '* OK')) {
            $this->disconnect();
            throw new ImapConnectionException("Unexpected IMAP greeting from {$remoteSocket}: {$greeting}");
        }

        return $this;
    }

    public function isConnected(): bool
    {
        return $this->connected && is_resource($this->socket);
    }

    /**
     * Authenticate using traditional username and password (LOGIN).
     */
    public function login(string $username, string $password): self
    {
        $escapedUser = $this->escapeString($username);
        $escapedPass = $this->escapeString($password);

        $response = $this->sendCommand("LOGIN {$escapedUser} {$escapedPass}");
        if (! $response['ok']) {
            throw new ImapAuthenticationException("LOGIN failed for user '{$username}': {$response['status']}");
        }

        return $this;
    }

    /**
     * Authenticate using SASL XOAUTH2 token (Gmail, Google Workspace, Microsoft 365, Outlook).
     */
    public function authenticateXOAuth2(string $username, string $accessToken): self
    {
        // SASL XOAUTH2 format: "user=" {User} "^Aauth=Bearer " {Access Token} "^A^A"
        $authString = "user={$username}\x01auth=Bearer {$accessToken}\x01\x01";
        $encoded = base64_encode($authString);

        $response = $this->sendCommand("AUTHENTICATE XOAUTH2 {$encoded}");
        if (! $response['ok']) {
            throw new ImapAuthenticationException("XOAUTH2 authentication failed for user '{$username}': {$response['status']}");
        }

        return $this;
    }

    /**
     * List mail folders.
     *
     * @return list<Folder>
     */
    public function listFolders(string $reference = '', string $pattern = '*'): array
    {
        $escapedRef = $this->escapeString($this->encodeUtf7($reference));
        $escapedPattern = $this->escapeString($this->encodeUtf7($pattern));

        $response = $this->sendCommand("LIST {$escapedRef} {$escapedPattern}");
        $this->assertTaggedOk($response, 'LIST failed');

        $folders = [];

        foreach ($response['lines'] as $line) {
            if (! str_starts_with($line, '* LIST')) {
                continue;
            }

            // Matches e.g.: * LIST (\HasNoChildren \Trash) "/" "INBOX/Trash"
            if (preg_match('/^\*\s+LIST\s+\(([^)]*)\)\s+(?:"([^"]*)"|NIL)\s+(?:"([^"]+)"|(\S+))$/i', trim($line), $matches)) {
                $attrsRaw = trim($matches[1]);
                $attributes = $attrsRaw !== '' ? preg_split('/\s+/', $attrsRaw) ?: [] : [];
                $delimiter = ! empty($matches[2]) ? $matches[2] : '/';
                $rawFolder = ! empty($matches[3]) ? $matches[3] : ($matches[4] ?? '');

                if ($rawFolder !== '') {
                    $decodedName = $this->decodeUtf7($rawFolder);
                    $folders[] = new Folder(
                        name: $decodedName,
                        rawName: $rawFolder,
                        delimiter: $delimiter,
                        attributes: $attributes
                    );
                }
            }
        }

        return $folders;
    }

    /**
     * Select a mailbox folder.
     *
     * @return array{exists: int, recent: int, uidvalidity: int|null, uidnext: int|null}
     */
    public function selectMailbox(string $mailbox = 'INBOX'): array
    {
        $rawMailbox = $this->encodeUtf7($mailbox);
        $escaped = $this->escapeString($rawMailbox);
        $response = $this->sendCommand("SELECT {$escaped}");
        $this->assertTaggedOk($response, "SELECT {$mailbox} failed");

        $info = [
            'exists' => 0,
            'recent' => 0,
            'uidvalidity' => null,
            'uidnext' => null,
        ];

        foreach ($response['lines'] as $line) {
            if (preg_match('/^\*\s+(\d+)\s+EXISTS/i', $line, $m)) {
                $info['exists'] = (int) $m[1];
            } elseif (preg_match('/^\*\s+(\d+)\s+RECENT/i', $line, $m)) {
                $info['recent'] = (int) $m[1];
            } elseif (preg_match('/\[UIDVALIDITY\s+(\d+)\]/i', $line, $m)) {
                $info['uidvalidity'] = (int) $m[1];
            } elseif (preg_match('/\[UIDNEXT\s+(\d+)\]/i', $line, $m)) {
                $info['uidnext'] = (int) $m[1];
            }
        }

        return $info;
    }

    /**
     * Search unseen emails or emails with UID higher than $lastUid.
     *
     * @return list<int>
     */
    public function searchUnseenOrSinceUid(int $lastUid = 0): array
    {
        $command = $lastUid > 0
            ? 'UID SEARCH UID '.($lastUid + 1).':*'
            : 'UID SEARCH UNSEEN';

        $response = $this->sendCommand($command);
        $this->assertTaggedOk($response, 'SEARCH failed');

        return $this->extractUidsFromSearch($response, $lastUid);
    }

    /**
     * Search emails received within the last N days.
     *
     * @return list<int>
     */
    public function searchSinceDays(int $days): array
    {
        $days = max(1, $days);
        $date = gmdate('d-M-Y', strtotime("-{$days} days"));

        $response = $this->sendCommand("UID SEARCH SINCE {$date}");
        $this->assertTaggedOk($response, "SEARCH SINCE failed for {$days} days");

        return $this->extractUidsFromSearch($response);
    }

    /**
     * Search all email UIDs in the currently selected mailbox.
     *
     * @return list<int>
     */
    public function searchAllUids(): array
    {
        $response = $this->sendCommand('UID SEARCH ALL');
        $this->assertTaggedOk($response, 'SEARCH ALL failed');

        return $this->extractUidsFromSearch($response);
    }

    /**
     * Search emails by raw criteria (e.g. 'FROM "john" SUBJECT "invoice"').
     *
     * @return list<int>
     */
    public function search(string $criteria): array
    {
        $response = $this->sendCommand("UID SEARCH {$criteria}");
        $this->assertTaggedOk($response, "UID SEARCH {$criteria} failed");

        return $this->extractUidsFromSearch($response);
    }

    /**
     * Fetch raw message stream for a specific UID.
     *
     * @return resource Stream containing raw RFC 822 email
     */
    public function fetchRawStream(int $uid)
    {
        $tag = $this->nextTag();
        $this->writeLine("{$tag} UID FETCH {$uid} (BODY.PEEK[])");

        $stream = fopen('php://temp', 'r+b');
        if (! is_resource($stream)) {
            throw new ImapCommandException('Failed to allocate temporary stream.');
        }

        $tagFound = false;
        $tagStatus = '';

        while (! feof($this->socket ?? STDERR)) {
            $line = $this->readLine();

            if (str_starts_with($line, $tag.' ')) {
                $tagFound = true;
                $tagStatus = $line;
                break;
            }

            // Check if line indicates literal size: {1234}
            if (preg_match('/\{(\d+)\}\s*$/', $line, $matches)) {
                $literalSize = (int) $matches[1];
                $this->readBytesIntoStream($stream, $literalSize);
            }
        }

        if (! $tagFound) {
            while (! feof($this->socket ?? STDERR)) {
                $line = $this->readLine();
                if (str_starts_with($line, $tag.' ')) {
                    $tagFound = true;
                    $tagStatus = $line;
                    break;
                }
            }
        }

        if (! str_starts_with($tagStatus, "{$tag} OK")) {
            fclose($stream);
            throw new ImapCommandException("UID FETCH {$uid} failed: {$tagStatus}");
        }

        rewind($stream);

        return $stream;
    }

    /**
     * Fetch and parse an email message by UID into a Message DTO.
     */
    public function getMessage(int $uid): Message
    {
        $rawStream = $this->fetchRawStream($uid);

        return $this->parser->parse($rawStream, $uid);
    }

    public function markAsSeen(int $uid): void
    {
        $response = $this->sendCommand("UID STORE {$uid} +FLAGS (\\Seen)");
        $this->assertTaggedOk($response, "markAsSeen for UID {$uid} failed");
    }

    public function markAsUnseen(int $uid): void
    {
        $response = $this->sendCommand("UID STORE {$uid} -FLAGS (\\Seen)");
        $this->assertTaggedOk($response, "markAsUnseen for UID {$uid} failed");
    }

    /**
     * Move an email message from source folder to target folder.
     */
    public function moveMessage(string $sourceFolder, int $uid, string $targetFolder, ?string $messageId = null): ?int
    {
        $this->selectMailbox($sourceFolder);

        $escapedTarget = $this->escapeString($this->encodeUtf7($targetFolder));
        $newUid = null;

        // Try RFC 6851 UID MOVE first
        $moveResponse = $this->sendCommand("UID MOVE {$uid} {$escapedTarget}");
        $isMoveOk = str_starts_with($moveResponse['status'], "{$moveResponse['tag']} OK");

        if ($isMoveOk) {
            $newUid = $this->extractCopyUid($moveResponse, $uid);
        } else {
            // Fallback: RFC 3501 UID COPY + UID STORE +FLAGS (\Deleted) + EXPUNGE
            $copyResponse = $this->sendCommand("UID COPY {$uid} {$escapedTarget}");
            $this->assertTaggedOk($copyResponse, "UID COPY {$uid} to {$targetFolder} failed");

            $newUid = $this->extractCopyUid($copyResponse, $uid);

            $storeResponse = $this->sendCommand("UID STORE {$uid} +FLAGS (\\Deleted)");
            $this->assertTaggedOk($storeResponse, "UID STORE \\Deleted for UID {$uid} failed");

            $expungeResponse = $this->sendCommand('EXPUNGE');
            $this->assertTaggedOk($expungeResponse, 'EXPUNGE failed after copy');
        }

        if ($newUid === null && ! empty($messageId)) {
            try {
                $this->selectMailbox($targetFolder);
                $cleanMessageId = trim($messageId, '<>');
                $escapedMessageId = $this->escapeString("<{$cleanMessageId}>");
                $searchResponse = $this->sendCommand("UID SEARCH HEADER Message-ID {$escapedMessageId}");
                if (str_starts_with($searchResponse['status'], "{$searchResponse['tag']} OK")) {
                    $uids = $this->extractUidsFromSearch($searchResponse);
                    if (! empty($uids)) {
                        $newUid = end($uids);
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $newUid;
    }

    public function deleteMessage(int $uid, bool $expunge = true): void
    {
        $response = $this->sendCommand("UID STORE {$uid} +FLAGS (\\Deleted)");
        $this->assertTaggedOk($response, "deleteMessage for UID {$uid} failed");

        if ($expunge) {
            $expungeResponse = $this->sendCommand('EXPUNGE');
            $this->assertTaggedOk($expungeResponse, 'EXPUNGE failed');
        }
    }

    /**
     * Automatically detect the trash / Papierkorb folder.
     */
    public function detectTrashFolder(): string
    {
        $folders = $this->listFolders();
        foreach ($folders as $folder) {
            if ($folder->isTrash()) {
                return $folder->getName();
            }
        }

        return 'Trash';
    }

    public function disconnect(): void
    {
        if ($this->connected && is_resource($this->socket)) {
            try {
                $tag = $this->nextTag();
                $this->writeLine("{$tag} LOGOUT");
            } catch (\Throwable) {
            }
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->connected = false;
        $this->tagCounter = 0;
    }

    /**
     * Encode folder string to Modified UTF-7 (RFC 3501 Section 5.1.3).
     */
    public function encodeUtf7(string $string): string
    {
        if ($string === '') {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($string, 'UTF7-IMAP', 'UTF-8');
            if ($converted !== false) {
                return $converted;
            }
        }

        return $string;
    }

    /**
     * Decode Modified UTF-7 folder string to UTF-8.
     */
    public function decodeUtf7(string $string): string
    {
        if ($string === '') {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($string, 'UTF-8', 'UTF7-IMAP');
            if ($converted !== false) {
                return $converted;
            }
        }

        return $string;
    }

    /**
     * @return array{tag: string, ok: bool, status: string, lines: list<string>}
     */
    protected function sendCommand(string $command): array
    {
        $tag = $this->nextTag();
        $this->writeLine("{$tag} {$command}");

        $lines = [];
        $taggedStatus = '';

        while (! feof($this->socket ?? STDERR)) {
            $line = $this->readLine();
            $lines[] = $line;

            if (str_starts_with($line, $tag.' ')) {
                $taggedStatus = $line;
                break;
            }
        }

        $isOk = str_starts_with($taggedStatus, "{$tag} OK");

        return [
            'tag' => $tag,
            'ok' => $isOk,
            'status' => $taggedStatus,
            'lines' => $lines,
        ];
    }

    protected function nextTag(): string
    {
        $this->tagCounter++;

        return sprintf('A%04d', $this->tagCounter);
    }

    protected function writeLine(string $line): void
    {
        if (! is_resource($this->socket)) {
            throw new ImapConnectionException('Socket is not connected or already closed.');
        }

        $data = $line."\r\n";
        $length = strlen($data);
        $written = @fwrite($this->socket, $data);

        if ($written !== $length) {
            throw new ImapConnectionException("Failed to write command to IMAP socket: {$line}");
        }
    }

    protected function readLine(): string
    {
        if (! is_resource($this->socket)) {
            throw new ImapConnectionException('Socket is not connected or already closed.');
        }

        $line = fgets($this->socket);
        if ($line === false) {
            throw new ImapConnectionException('Connection lost or timed out while reading line from IMAP socket.');
        }

        return rtrim($line, "\r\n");
    }

    /**
     * @param resource $destStream
     */
    protected function readBytesIntoStream($destStream, int $bytesToRead): void
    {
        if (! is_resource($this->socket)) {
            throw new ImapConnectionException('Socket is not connected.');
        }

        $remaining = $bytesToRead;
        $chunkSize = 8192;

        while ($remaining > 0 && ! feof($this->socket)) {
            $readLength = min($remaining, $chunkSize);
            $chunk = fread($this->socket, $readLength);

            if ($chunk === false || $chunk === '') {
                throw new ImapConnectionException("Premature EOF while reading literal ({$remaining} bytes remaining).");
            }

            fwrite($destStream, $chunk);
            $remaining -= strlen($chunk);
        }
    }

    /**
     * @param array{tag: string, ok: bool, status: string, lines: list<string>} $response
     */
    protected function assertTaggedOk(array $response, string $errorMessage): void
    {
        if (! $response['ok']) {
            throw new ImapCommandException("{$errorMessage}: {$response['status']}");
        }
    }

    protected function escapeString(string $string): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $string);

        return "\"{$escaped}\"";
    }

    /**
     * @param array{tag: string, ok: bool, lines: list<string>} $response
     * @return list<int>
     */
    protected function extractUidsFromSearch(array $response, int $minUid = 0): array
    {
        $uids = [];

        foreach ($response['lines'] as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                $parts = preg_split('/\s+/', trim($line));
                if (is_array($parts)) {
                    array_shift($parts); // remove '*'
                    array_shift($parts); // remove 'SEARCH'

                    foreach ($parts as $part) {
                        if (is_numeric($part)) {
                            $uid = (int) $part;
                            if ($minUid <= 0 || $uid > $minUid) {
                                $uids[] = $uid;
                            }
                        }
                    }
                }
            }
        }

        sort($uids, SORT_NUMERIC);

        return array_values(array_unique($uids));
    }

    /**
     * @param array{tag: string, ok: bool, status: string, lines: list<string>} $response
     */
    protected function extractCopyUid(array $response, int $sourceUid): ?int
    {
        foreach ($response['lines'] as $line) {
            // Format: [COPYUID <validity> <source-uid> <target-uid>]
            if (preg_match('/\[COPYUID\s+\d+\s+(\d+)\s+(\d+)\]/i', $line, $matches)) {
                if ((int) $matches[1] === $sourceUid) {
                    return (int) $matches[2];
                }
            }
        }

        return null;
    }
}
