<?php

declare(strict_types=1);

namespace Maivio\Imap\Client;

use BadMethodCallException;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Maivio\Imap\DTO\Folder;
use Maivio\Imap\DTO\FolderStatus;
use Maivio\Imap\DTO\Message;
use Maivio\Imap\DTO\MessageSummary;
use Maivio\Imap\Exceptions\ImapAuthenticationException;
use Maivio\Imap\Exceptions\ImapCommandException;
use Maivio\Imap\Exceptions\ImapConnectionException;
use Maivio\Imap\Exceptions\ImapMessageTooLargeException;
use Maivio\Imap\Parser\MimeParser;
use Maivio\Imap\Protocol\Command;
use Maivio\Imap\Protocol\ConnectionOptions;
use Maivio\Imap\Protocol\Encoder;
use Maivio\Imap\Protocol\Encryption;
use Maivio\Imap\Protocol\Flag;
use Maivio\Imap\Protocol\ImapParser;
use Maivio\Imap\Protocol\Literal;
use Maivio\Imap\Protocol\Mutf7;
use Maivio\Imap\Protocol\Response;
use Maivio\Imap\Search\SearchCriteria;
use Maivio\Imap\Transport\StreamTransport;
use Maivio\Imap\Transport\Transport;
use Throwable;

class ImapClient
{
    protected ?Transport $transport = null;

    protected int $tagCounter = 0;

    protected bool $connected = false;

    /** True once the connection is protected by TLS (or insecure mode was explicitly allowed). */
    protected bool $secure = false;

    protected bool $allowInsecure = false;

    /** @var list<string> Upper-cased capability tokens of the current connection state */
    protected array $capabilities = [];

    protected ?string $selectedMailbox = null;

    /** @var (Closure(string, string): void)|null */
    protected ?Closure $debugLogger = null;

    /** @var list<string> Credentials used on this connection; scrubbed from every server text that ends up in an exception */
    protected array $secrets = [];

    protected ?int $maxMessageSize = null;

    protected int $chunkSize = 524_288;

    /** Maximum size of a single literal that is read into memory (response metadata, not message bodies). */
    protected int $maxInlineLiteral = 16_777_216;

    /** Maximum total size of the untagged data belonging to one command. */
    protected int $maxResponseSize = 67_108_864;

    protected ?MimeParser $parser = null;

    /**
     * @param (Closure(ConnectionOptions): Transport)|null $transportFactory Replaces the real socket transport (used for tests)
     */
    public function __construct(?MimeParser $parser = null, protected ?Closure $transportFactory = null)
    {
        $this->parser = $parser ?? new MimeParser();
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Connect to the IMAP server.
     *
     * $encryption: "ssl"/"tls" (implicit TLS), "starttls" (upgrade via STARTTLS) or "none" (requires $allowInsecure).
     * Any other value throws; there is never a silent fallback to plaintext.
     *
     * @param string|null $peerName Name to verify the certificate against (use together with a pre-resolved IP in $host)
     * @param float|null $readTimeout Per-read timeout in seconds (defaults to $timeout)
     *
     * @throws InvalidArgumentException for unknown encryption modes or invalid host/port values
     * @throws ImapConnectionException when the connection cannot be established securely
     */
    public function connect(
        string $host,
        int $port = 993,
        string $encryption = 'ssl',
        float $timeout = 30.0,
        bool $validateCert = true,
        ?string $peerName = null,
        bool $allowInsecure = false,
        ?float $readTimeout = null,
        bool $sniEnabled = true,
        ?string $sniServerName = null,
    ): self {
        return $this->connectWith(new ConnectionOptions(
            host: $host,
            port: $port,
            encryption: Encryption::fromString($encryption),
            connectTimeout: $timeout,
            readTimeout: $readTimeout ?? $timeout,
            validateCert: $validateCert,
            peerName: $peerName,
            sniEnabled: $sniEnabled,
            sniServerName: $sniServerName,
            allowInsecure: $allowInsecure,
        ));
    }

    public function connectWith(ConnectionOptions $options): self
    {
        if ($this->connected) {
            $this->disconnect();
        }

        $this->transport = $this->transportFactory !== null
            ? ($this->transportFactory)($options)
            : StreamTransport::connect($options);

        $this->connected = true;
        $this->allowInsecure = $options->allowInsecure;
        $this->secure = $options->encryption === Encryption::Tls;
        $this->capabilities = [];
        $this->secrets = [];
        $this->selectedMailbox = null;

        try {
            $greeting = $this->readLine();
            if (! str_starts_with($greeting, '* OK')) {
                throw new ImapConnectionException("Unexpected IMAP greeting from {$options->host}: ".$this->scrub($greeting));
            }

            $this->refreshCapabilities();

            if ($options->encryption === Encryption::StartTls) {
                $this->upgradeToTls();
            }
        } catch (Throwable $e) {
            // Never continue (and never log in) on a connection that is not in the expected state.
            $this->closeQuietly();

            throw $e;
        }

        return $this;
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->transport !== null && $this->transport->isOpen();
    }

    /**
     * Capability tokens advertised by the server (upper-case).
     *
     * @return list<string>
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function hasCapability(string $capability): bool
    {
        return in_array(strtoupper($capability), $this->capabilities, true);
    }

    /**
     * Receive every command sent and every response line read, as ($direction, $line) with "C" or "S".
     * Credentials are never passed: LOGIN/AUTHENTICATE are redacted and literal payloads are omitted.
     *
     * @param (Closure(string, string): void)|null $logger
     */
    public function setDebugLogger(?Closure $logger): self
    {
        $this->debugLogger = $logger;

        return $this;
    }

    /**
     * Default upper limit (bytes) for getMessage()/fetchRawStream(). Null = unlimited.
     */
    public function setMaxMessageSize(?int $bytes): self
    {
        if ($bytes !== null && $bytes < 1) {
            throw new InvalidArgumentException('Maximum message size must be positive.');
        }

        $this->maxMessageSize = $bytes;

        return $this;
    }

    /**
     * Size (bytes) of each BODY.PEEK[]<offset.limit> request used to download a message.
     */
    public function setChunkSize(int $bytes): self
    {
        if ($bytes < 1024) {
            throw new InvalidArgumentException('Chunk size must be at least 1024 bytes.');
        }

        $this->chunkSize = $bytes;

        return $this;
    }

    /**
     * Authenticate using username and password (LOGIN). Refuses to send anything over a connection that is
     * neither TLS-protected nor explicitly allowed to be insecure, and when the server announces LOGINDISABLED.
     */
    public function login(#[\SensitiveParameter] string $username, #[\SensitiveParameter] string $password): self
    {
        $this->assertMayAuthenticate();

        if ($this->hasCapability('LOGINDISABLED')) {
            throw new ImapAuthenticationException('The server has disabled LOGIN (LOGINDISABLED).');
        }

        // Validation happens while building the command, before a single byte is sent.
        $command = Command::make('LOGIN')
            ->sensitive()
            ->string($username, 'username')
            ->string($password, 'password');

        $this->secrets = [...$this->secrets, $username, $password];

        $response = $this->execute($command);
        if (! $response->ok()) {
            throw new ImapAuthenticationException('LOGIN failed: '.$this->scrub($response->statusLine()));
        }

        $this->refreshCapabilities();

        return $this;
    }

    /**
     * Authenticate using SASL XOAUTH2 token (Gmail, Google Workspace, Microsoft 365, Outlook).
     */
    public function authenticateXOAuth2(#[\SensitiveParameter] string $username, #[\SensitiveParameter] string $accessToken): self
    {
        $this->assertMayAuthenticate();

        foreach ([$username, $accessToken] as $value) {
            Encoder::assertSafe($value, 'XOAUTH2 credential');
            if (str_contains($value, "\x01")) {
                throw new InvalidArgumentException('Invalid XOAUTH2 credential: control character not allowed.');
            }
        }

        // SASL XOAUTH2 format: "user=" {User} "^Aauth=Bearer " {Access Token} "^A^A"
        $payload = base64_encode("user={$username}\x01auth=Bearer {$accessToken}\x01\x01");
        $this->secrets = [...$this->secrets, $username, $accessToken, $payload];

        $command = Command::make('AUTHENTICATE')->sensitive()->atom('XOAUTH2');
        $sentInitialResponse = $this->hasCapability('SASL-IR');
        if ($sentInitialResponse) {
            $command->base64($payload);
        }

        $answered = false;
        $response = $this->execute($command, function () use (&$answered, $sentInitialResponse, $payload): string {
            // First "+" without SASL-IR asks for the payload; every further "+" is the error report: cancel with an empty line.
            if (! $sentInitialResponse && ! $answered) {
                $answered = true;

                return $payload;
            }

            return '';
        });

        if (! $response->ok()) {
            throw new ImapAuthenticationException('XOAUTH2 authentication failed: '.$this->scrub($response->statusLine()));
        }

        $this->refreshCapabilities();

        return $this;
    }

    /**
     * List mail folders including hierarchy delimiter and attributes (\Sent, \Trash, \Drafts, \Junk, \Archive, ...).
     * Uses LIST-EXTENDED RETURN (SPECIAL-USE) when the server supports it, otherwise the attributes of the plain LIST.
     *
     * @return list<Folder>
     */
    public function listFolders(string $reference = '', string $pattern = '*'): array
    {
        $command = Command::make('LIST')
            ->mailbox($reference, 'list reference')
            ->mailbox($pattern, 'list pattern');

        if ($this->hasCapability('LIST-EXTENDED') && $this->hasCapability('SPECIAL-USE')) {
            $command->atom('RETURN (SPECIAL-USE)');
        }

        $response = $this->execute($command);
        $this->assertOk($response, 'LIST failed');

        $folders = [];

        foreach ($response->untagged as $line) {
            if (preg_match('/\A\*\s+LIST\s+(.*)\z/is', $line, $match) !== 1) {
                continue;
            }

            $tokens = ImapParser::parse($match[1]);
            if (! isset($tokens[0], $tokens[2]) || ! is_array($tokens[0]) || ! is_string($tokens[2]) || $tokens[2] === '') {
                continue;
            }

            $attributes = array_values(array_filter($tokens[0], 'is_string'));
            $rawName = $tokens[2];

            try {
                $name = Mutf7::decode($rawName);
            } catch (InvalidArgumentException) {
                $name = $rawName;
            }

            $folders[] = new Folder(
                name: $name,
                rawName: $rawName,
                delimiter: is_string($tokens[1] ?? null) ? $tokens[1] : '',
                attributes: $attributes,
            );
        }

        return $folders;
    }

    /**
     * Create a folder. Names with wildcards (* %) or braces are rejected.
     */
    public function createFolder(string $name, bool $subscribe = false): void
    {
        $this->assertManageableFolderName($name);

        $this->assertOk($this->execute(Command::make('CREATE')->mailbox($name, 'folder name')), 'CREATE failed');

        if ($subscribe) {
            $this->subscribeFolder($name);
        }
    }

    /**
     * Rename a folder. INBOX can neither be the source nor the target.
     */
    public function renameFolder(string $from, string $to): void
    {
        $this->assertManageableFolderName($from);
        $this->assertManageableFolderName($to);
        $this->assertNotInbox($from, 'rename');
        $this->assertNotInbox($to, 'rename to');

        $this->assertOk(
            $this->execute(Command::make('RENAME')->mailbox($from, 'folder name')->mailbox($to, 'folder name')),
            'RENAME failed'
        );

        if ($this->selectedMailbox !== null && strcasecmp($this->selectedMailbox, $from) === 0) {
            $this->selectedMailbox = null;
        }
    }

    /**
     * Delete a folder. INBOX cannot be deleted.
     */
    public function deleteFolder(string $name, bool $unsubscribe = false): void
    {
        $this->assertManageableFolderName($name);
        $this->assertNotInbox($name, 'delete');

        $this->assertOk($this->execute(Command::make('DELETE')->mailbox($name, 'folder name')), 'DELETE failed');

        if ($this->selectedMailbox !== null && $this->selectedMailbox === $name) {
            $this->selectedMailbox = null;
        }

        if ($unsubscribe) {
            $this->unsubscribeFolder($name);
        }
    }

    public function subscribeFolder(string $name): void
    {
        $this->assertManageableFolderName($name);
        $this->assertOk($this->execute(Command::make('SUBSCRIBE')->mailbox($name, 'folder name')), 'SUBSCRIBE failed');
    }

    public function unsubscribeFolder(string $name): void
    {
        $this->assertManageableFolderName($name);
        $this->assertOk($this->execute(Command::make('UNSUBSCRIBE')->mailbox($name, 'folder name')), 'UNSUBSCRIBE failed');
    }

    /**
     * Folder counters via STATUS, without selecting the folder.
     */
    public function folderStatus(string $mailbox): FolderStatus
    {
        $command = Command::make('STATUS')->mailbox($mailbox)->atom('(MESSAGES UIDNEXT UIDVALIDITY UNSEEN)');
        $response = $this->execute($command);
        $this->assertOk($response, 'STATUS failed');

        foreach ($response->untagged as $line) {
            if (preg_match('/\A\*\s+STATUS\s+(.*)\z/is', $line, $match) !== 1) {
                continue;
            }

            $tokens = ImapParser::parse($match[1]);
            if (! isset($tokens[1]) || ! is_array($tokens[1])) {
                continue;
            }

            $values = $this->pairs($tokens[1]);

            return new FolderStatus(
                name: $mailbox,
                messages: (int) ($values['MESSAGES'] ?? 0),
                uidNext: isset($values['UIDNEXT']) ? (int) $values['UIDNEXT'] : null,
                uidValidity: isset($values['UIDVALIDITY']) ? (int) $values['UIDVALIDITY'] : null,
                unseen: isset($values['UNSEEN']) ? (int) $values['UNSEEN'] : null,
            );
        }

        throw new ImapCommandException('STATUS returned no data.', $response->statusLine());
    }

    /**
     * Select a folder and return its counters and UIDVALIDITY / UIDNEXT.
     *
     * @return array{exists: int, recent: int, uidvalidity: int|null, uidnext: int|null}
     */
    public function selectFolder(string $mailbox = 'INBOX'): array
    {
        $this->selectedMailbox = null;

        $response = $this->execute(Command::make('SELECT')->mailbox($mailbox));
        $this->assertOk($response, 'SELECT failed');
        $this->selectedMailbox = $mailbox;

        $info = ['exists' => 0, 'recent' => 0, 'uidvalidity' => null, 'uidnext' => null];

        foreach ($response->untagged as $line) {
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
     * Alias of selectFolder().
     *
     * @return array{exists: int, recent: int, uidvalidity: int|null, uidnext: int|null}
     */
    public function selectMailbox(string $mailbox = 'INBOX'): array
    {
        return $this->selectFolder($mailbox);
    }

    /**
     * Search with a typed criteria builder.
     *
     * @param SearchCriteria|string $criteria Raw strings are no longer supported (command injection risk) and fail immediately.
     * @param int|null $limit Return at most this many UIDs (the lowest ones). The server still sends the complete result.
     * @return list<int>
     *
     * @throws BadMethodCallException when a raw string is passed
     */
    public function search(SearchCriteria|string $criteria, ?int $limit = null): array
    {
        if (is_string($criteria)) {
            throw new BadMethodCallException(
                'ImapClient::search(string) was removed because raw criteria allow IMAP command injection. '
                .'Use ImapClient::search(SearchCriteria::new()->from(...)->unseen()) instead.'
            );
        }

        $response = $this->execute($criteria->toCommand());
        $this->assertOk($response, 'SEARCH failed');

        return $this->limit($this->extractUidsFromSearch($response), $limit);
    }

    /**
     * Search UIDs inside a UID range. Prefer this over searchAllUids() on large mailboxes.
     *
     * @param int|null $to Last UID or null for "everything from $from on"
     * @return list<int>
     */
    public function searchUidRange(int $from, ?int $to = null, ?int $limit = null): array
    {
        $uids = $this->search(SearchCriteria::new()->uidRange($from, $to));

        // "UID n:*" always includes the highest message even when it is below n; filter that quirk out.
        $uids = array_values(array_filter(
            $uids,
            static fn (int $uid): bool => $uid >= $from && ($to === null || $uid <= $to)
        ));

        return $this->limit($uids, $limit);
    }

    /**
     * Search unseen emails or emails with UID higher than $lastUid.
     *
     * @return list<int>
     */
    public function searchUnseenOrSinceUid(int $lastUid = 0): array
    {
        if ($lastUid > 0) {
            return $this->searchUidRange($lastUid + 1);
        }

        return $this->search(SearchCriteria::new()->unseen());
    }

    /**
     * Search emails received within the last N days.
     *
     * @return list<int>
     */
    public function searchSinceDays(int $days): array
    {
        $days = max(1, $days);

        return $this->search(SearchCriteria::new()->since(new DateTimeImmutable("-{$days} days", new DateTimeZone('UTC'))));
    }

    /**
     * Search all email UIDs in the currently selected mailbox.
     *
     * Warning: on very large mailboxes the response contains every UID. Use searchUidRange() or search() with a limit.
     *
     * @return list<int>
     */
    public function searchAllUids(): array
    {
        return $this->search(SearchCriteria::new()->all());
    }

    /**
     * Fetch UID, FLAGS, INTERNALDATE, RFC822.SIZE and the main headers without the body and without setting \Seen.
     *
     * @param int|list<int> $uids
     * @return list<MessageSummary>
     */
    public function fetchSummaries(int|array $uids): array
    {
        $summaries = [];

        foreach ($this->uidBatches($uids) as $set) {
            $command = Command::make('UID FETCH')->atom($set)->atom(
                '(UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES)])'
            );

            $response = $this->execute($command);
            $this->assertOk($response, 'FETCH failed');

            foreach ($this->parseFetchResponses($response) as $data) {
                $summary = $this->buildSummary($data);
                if ($summary !== null) {
                    $summaries[] = $summary;
                }
            }
        }

        return $summaries;
    }

    public function fetchSummary(int $uid): ?MessageSummary
    {
        return $this->fetchSummaries($uid)[0] ?? null;
    }

    /**
     * Download a message into a stream, chunk by chunk (BODY.PEEK[]<offset.limit>), never setting \Seen.
     *
     * RFC822.SIZE is checked first; an oversized message is rejected before any body data is downloaded.
     *
     * @param int|null $maxSize Limit in bytes (defaults to setMaxMessageSize())
     * @return resource Rewound php://temp stream (spills to disk) with the raw RFC 822 message
     *
     * @throws ImapMessageTooLargeException
     */
    public function fetchRawStream(int $uid, ?int $maxSize = null)
    {
        $this->assertUid($uid);

        $size = $this->fetchMessageSize($uid);
        $limit = $maxSize ?? $this->maxMessageSize;
        if ($limit !== null && $size > $limit) {
            throw new ImapMessageTooLargeException($size, $limit);
        }

        $stream = fopen('php://temp/maxmemory:2097152', 'r+b');
        if (! is_resource($stream)) {
            throw new ImapCommandException('Failed to allocate temporary stream.');
        }

        try {
            $offset = 0;
            while ($offset < $size) {
                $received = $this->fetchChunk($uid, $offset, min($this->chunkSize, $size - $offset), $stream);
                if ($received === 0) {
                    throw new ImapCommandException("Server returned no data for UID {$uid} at offset {$offset}.");
                }
                $offset += $received;
            }
        } catch (Throwable $e) {
            fclose($stream);

            throw $e;
        }

        rewind($stream);

        return $stream;
    }

    /**
     * Fetch and parse an email message by UID into a Message DTO.
     *
     * @param int|null $maxSize Maximum RFC822.SIZE in bytes; larger messages throw before any download
     * @param (Closure(\Maivio\Imap\DTO\Attachment): resource)|null $attachmentSink Receives attachment metadata and returns the stream
     *        the decoded attachment is written to (see MimeParser::parse())
     */
    public function getMessage(int $uid, ?int $maxSize = null, ?Closure $attachmentSink = null): Message
    {
        $rawStream = $this->fetchRawStream($uid, $maxSize);

        try {
            return ($this->parser ?? new MimeParser())->parse($rawStream, $uid, $attachmentSink);
        } finally {
            fclose($rawStream);
        }
    }

    /**
     * Add flags (\Seen, \Flagged, \Answered, \Deleted, \Draft) to messages of the selected folder.
     *
     * @param int|list<int> $uids
     * @param Flag|string|list<Flag|string> $flags
     */
    public function addFlags(int|array $uids, Flag|string|array $flags): void
    {
        $this->store($uids, '+FLAGS.SILENT', $flags);
    }

    /**
     * @param int|list<int> $uids
     * @param Flag|string|list<Flag|string> $flags
     */
    public function removeFlags(int|array $uids, Flag|string|array $flags): void
    {
        $this->store($uids, '-FLAGS.SILENT', $flags);
    }

    /**
     * Replace all flags of the messages with exactly this set.
     *
     * @param int|list<int> $uids
     * @param Flag|string|list<Flag|string> $flags
     */
    public function setFlags(int|array $uids, Flag|string|array $flags): void
    {
        $this->store($uids, 'FLAGS.SILENT', $flags);
    }

    public function markAsSeen(int $uid): void
    {
        $this->addFlags($uid, Flag::Seen);
    }

    public function markAsUnseen(int $uid): void
    {
        $this->removeFlags($uid, Flag::Seen);
    }

    /**
     * Copy a message to another folder.
     *
     * @return int|null UID in the target folder (needs UIDPLUS), otherwise null
     */
    public function copyMessage(string $sourceFolder, int $uid, string $targetFolder): ?int
    {
        $this->assertUid($uid);
        Encoder::assertSafe($targetFolder, 'mailbox name');
        $this->selectFolder($sourceFolder);

        $response = $this->execute(Command::make('UID COPY')->atom((string) $uid)->mailbox($targetFolder));
        $this->assertOk($response, "COPY of UID {$uid} failed");

        return $this->extractCopyUid($response, $uid);
    }

    /**
     * Move a message to another folder.
     *
     * Uses MOVE (RFC 6851) if offered, otherwise UID COPY + \Deleted + UID EXPUNGE (needs UIDPLUS).
     * Without MOVE and UIDPLUS an exception is thrown instead of issuing an EXPUNGE that would also remove
     * unrelated messages flagged \Deleted.
     *
     * @param string|null $messageId Used to look up the new UID when the server sends no COPYUID
     * @return int|null UID in the target folder when it could be determined
     */
    public function moveMessage(string $sourceFolder, int $uid, string $targetFolder, ?string $messageId = null): ?int
    {
        $this->assertUid($uid);
        Encoder::assertSafe($targetFolder, 'mailbox name');
        if ($messageId !== null) {
            Encoder::assertSafe($messageId, 'message id');
        }

        $this->selectFolder($sourceFolder);

        if ($this->hasCapability('MOVE')) {
            $response = $this->execute(Command::make('UID MOVE')->atom((string) $uid)->mailbox($targetFolder));
            $this->assertOk($response, "MOVE of UID {$uid} failed");
            $newUid = $this->extractCopyUid($response, $uid);
        } elseif ($this->hasCapability('UIDPLUS')) {
            $response = $this->execute(Command::make('UID COPY')->atom((string) $uid)->mailbox($targetFolder));
            $this->assertOk($response, "COPY of UID {$uid} failed");
            $newUid = $this->extractCopyUid($response, $uid);

            $this->addFlags($uid, Flag::Deleted);
            $this->uidExpunge($uid);
        } else {
            throw new ImapCommandException(
                'Cannot move safely: the server supports neither MOVE nor UIDPLUS. '
                .'A plain EXPUNGE would also delete other messages flagged \\Deleted.'
            );
        }

        if ($newUid === null && $messageId !== null && trim($messageId, '<> ') !== '') {
            $newUid = $this->findUidByMessageId($sourceFolder, $targetFolder, trim($messageId, '<> '));
        }

        return $newUid;
    }

    /**
     * Flag a message \Deleted in the selected folder and (by default) expunge exactly that message.
     *
     * Expunging a single message needs UIDPLUS; without it an exception is thrown rather than expunging
     * every message flagged \Deleted. Pass $expunge = false to only set the flag.
     */
    public function deleteMessage(int $uid, bool $expunge = true): void
    {
        $this->assertUid($uid);

        if ($expunge && ! $this->hasCapability('UIDPLUS')) {
            throw new ImapCommandException(
                'Cannot expunge a single message: the server does not support UIDPLUS. Use deleteMessage($uid, false) to only flag it.'
            );
        }

        $this->addFlags($uid, Flag::Deleted);

        if ($expunge) {
            $this->uidExpunge($uid);
        }
    }

    /**
     * Append a message (e.g. a copy into "Sent").
     *
     * @param string|resource $message Complete RFC 822 message with CRLF line endings; a readable seekable stream is sent in chunks
     * @param Flag|string|list<Flag|string> $flags
     * @return int|null UID of the new message (needs UIDPLUS)
     */
    public function appendMessage(
        string $mailbox,
        mixed $message,
        Flag|string|array $flags = [],
        ?DateTimeInterface $internalDate = null,
    ): ?int {
        if (is_string($message)) {
            $literal = Literal::fromString($message);
        } elseif (is_resource($message)) {
            $position = ftell($message);
            $stat = fstat($message);
            if ($position === false || $stat === false) {
                throw new InvalidArgumentException('Message stream must be seekable and have a known size.');
            }
            $literal = Literal::fromStream($message, max(0, $stat['size'] - $position));
        } else {
            throw new InvalidArgumentException('Message must be a string or a stream resource.');
        }

        $command = Command::make('APPEND')->mailbox($mailbox);

        $flagList = Flag::normalizeList($flags);
        if ($flagList !== []) {
            $command->atom('('.implode(' ', array_map(static fn (Flag $f): string => $f->value, $flagList)).')');
        }

        if ($internalDate !== null) {
            $command->string($internalDate->format('d-M-Y H:i:s O'), 'date');
        }

        $response = $this->execute($command->literal($literal));
        $this->assertOk($response, 'APPEND failed');

        if (preg_match('/\[APPENDUID\s+\d+\s+(\d+)\]/i', $response->text, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Automatically detect the trash / Papierkorb folder.
     */
    public function detectTrashFolder(): string
    {
        foreach ($this->listFolders() as $folder) {
            if ($folder->isTrash()) {
                return $folder->getName();
            }
        }

        return 'Trash';
    }

    public function disconnect(): void
    {
        if ($this->connected && $this->transport !== null && $this->transport->isOpen()) {
            try {
                $this->transport->write($this->nextTag()." LOGOUT\r\n");
            } catch (Throwable) {
            }
        }

        $this->closeQuietly();
    }

    /**
     * Encode a folder name to Modified UTF-7 (RFC 3501 section 5.1.3).
     */
    public function encodeUtf7(string $string): string
    {
        return Mutf7::encode($string);
    }

    /**
     * Decode a Modified UTF-7 folder name to UTF-8.
     */
    public function decodeUtf7(string $string): string
    {
        return Mutf7::decode($string);
    }

    // ---------------------------------------------------------------------------------------------
    // Protocol plumbing
    // ---------------------------------------------------------------------------------------------

    protected function upgradeToTls(): void
    {
        if (! $this->hasCapability('STARTTLS')) {
            throw new ImapConnectionException('The server does not offer STARTTLS; refusing to continue in plaintext.');
        }

        $response = $this->execute(Command::make('STARTTLS'));
        if (! $response->ok()) {
            throw new ImapConnectionException('The server rejected STARTTLS: '.$response->statusLine());
        }

        $this->requireTransport()->enableTls();
        $this->secure = true;

        // Capabilities announced before the handshake must not be trusted.
        $this->capabilities = [];
        $this->refreshCapabilities();
    }

    protected function refreshCapabilities(): void
    {
        $response = $this->execute(Command::make('CAPABILITY'));
        if (! $response->ok()) {
            throw new ImapConnectionException('CAPABILITY failed: '.$response->statusLine());
        }

        $capabilities = [];
        foreach ($response->untagged as $line) {
            if (preg_match('/\A\*\s+CAPABILITY\s+(.+)\z/i', trim($line), $m) === 1) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $token) {
                    $capabilities[] = strtoupper($token);
                }
            }
        }

        $this->capabilities = array_values(array_unique($capabilities));
    }

    protected function assertMayAuthenticate(): void
    {
        if (! $this->isConnected()) {
            throw new ImapConnectionException('Not connected to an IMAP server.');
        }

        if (! $this->secure && ! $this->allowInsecure) {
            throw new ImapAuthenticationException('Refusing to send credentials over an unencrypted connection.');
        }
    }

    protected function execute(Command $command, ?Closure $onContinuation = null): Response
    {
        $tag = $this->nextTag();
        $this->sendCommand($tag, $command);

        return $this->readResponse($tag, $onContinuation);
    }

    protected function sendCommand(string $tag, Command $command): void
    {
        $transport = $this->requireTransport();
        $this->log('C', $tag.' '.$command->describe());

        $buffer = $tag.' '.$command->verb();

        foreach ($command->parts() as $part) {
            if (! $part instanceof Literal) {
                $buffer .= ' '.$part;

                continue;
            }

            $nonSynchronizing = $this->hasCapability('LITERAL+');
            $transport->write($buffer.' {'.$part->length.($nonSynchronizing ? '+' : '')."}\r\n");
            $buffer = '';

            try {
                if (! $nonSynchronizing) {
                    $this->awaitContinuation($tag);
                }

                $part->writeTo($transport);
            } catch (Throwable $e) {
                // The server is waiting for literal bytes that will never arrive: the stream is out of sync.
                $this->closeQuietly();

                throw $e;
            }
        }

        $transport->write($buffer."\r\n");
    }

    protected function awaitContinuation(string $tag): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $line = $this->readLine();

            if (str_starts_with($line, '+')) {
                return;
            }

            if (str_starts_with($line, $tag.' ')) {
                throw new ImapCommandException('The server refused the command before accepting its literal: '.$this->scrub($line), $this->scrub($line));
            }
        }

        throw new ImapConnectionException('No continuation request received from the server.');
    }

    /**
     * @param (Closure(string): string)|null $onContinuation Called for "+" lines; returns the line to send in reply
     */
    protected function readResponse(string $tag, ?Closure $onContinuation = null): Response
    {
        $transport = $this->requireTransport();
        $untagged = [];
        $budget = $this->maxResponseSize;

        while (true) {
            $line = $this->readLogicalLine($budget);

            if ($line === '') {
                continue;
            }

            if ($line[0] === '+') {
                if ($onContinuation === null) {
                    throw new ImapConnectionException('Unexpected continuation request from the server.');
                }

                $transport->write($onContinuation($line)."\r\n");

                continue;
            }

            if (str_starts_with($line, $tag.' ')) {
                $parts = explode(' ', $line, 3);
                $status = strtoupper($parts[1] ?? '');

                return new Response($tag, $status, $parts[2] ?? '', $untagged);
            }

            if (str_starts_with($line, '* BYE')) {
                $this->closeQuietly();

                throw new ImapConnectionException('The server closed the connection: '.$this->scrub($line));
            }

            $untagged[] = $line;
        }
    }

    /**
     * Read one response line; literals ("{n}") are read and inlined as "{n}\r\n<bytes>" followed by the rest of the line.
     */
    protected function readLogicalLine(int &$budget): string
    {
        $segment = $this->readLine();
        $full = '';

        while (true) {
            $full .= $segment;
            $budget -= strlen($segment);

            if (preg_match('/\{(\d+)\}\z/', $segment, $match) !== 1) {
                break;
            }

            $size = (int) $match[1];
            if ($size > $this->maxInlineLiteral || $size > $budget) {
                throw new ImapConnectionException("Server literal of {$size} bytes exceeds the response limit.");
            }

            $full .= "\r\n".$this->readExact($size);
            $budget -= $size;
            $segment = $this->readLine();
        }

        if ($budget < 0) {
            throw new ImapConnectionException('Server response exceeds the size limit.');
        }

        return $full;
    }

    protected function readLine(): string
    {
        $line = $this->requireTransport()->readLine();
        $this->log('S', $line);

        return $line;
    }

    protected function readExact(int $size): string
    {
        $transport = $this->requireTransport();
        $data = '';

        while ($size > 0) {
            $chunk = $transport->read(min($size, 65536));
            $data .= $chunk;
            $size -= strlen($chunk);
        }

        return $data;
    }

    /**
     * Copy exactly $size bytes of a literal from the connection into $sink.
     *
     * @param resource $sink
     */
    protected function copyLiteral(int $size, $sink): void
    {
        $transport = $this->requireTransport();

        while ($size > 0) {
            $chunk = $transport->read(min($size, 65536));
            if (fwrite($sink, $chunk) !== strlen($chunk)) {
                throw new ImapCommandException('Failed writing message data to the temporary stream.');
            }
            $size -= strlen($chunk);
        }
    }

    /**
     * Download bytes [$offset, $offset + $length) of the message body into $sink.
     *
     * @param resource $sink
     * @return int Number of bytes received
     */
    protected function fetchChunk(int $uid, int $offset, int $length, $sink): int
    {
        $tag = $this->nextTag();
        $this->sendCommand($tag, Command::make('UID FETCH')->atom((string) $uid)->atom("(BODY.PEEK[]<{$offset}.{$length}>)"));

        $received = 0;

        while (true) {
            $line = $this->readLine();

            if (str_starts_with($line, $tag.' ')) {
                $parts = explode(' ', $line, 3);
                if (strtoupper($parts[1] ?? '') !== 'OK') {
                    throw new ImapCommandException("FETCH of UID {$uid} failed: ".$this->scrub($line), $this->scrub($line));
                }

                return $received;
            }

            $isBodyLine = $received === 0
                && preg_match('/\A\*\s+\d+\s+FETCH\b/i', $line) === 1
                && stripos($line, 'BODY[') !== false;

            while (preg_match('/\{(\d+)\}\z/', $line, $match) === 1) {
                $size = (int) $match[1];

                if ($isBodyLine) {
                    if ($size > $length) {
                        throw new ImapConnectionException('The server returned more data than requested.');
                    }

                    $this->copyLiteral($size, $sink);
                    $received += $size;
                    $isBodyLine = false;
                } else {
                    $this->copyLiteral($size, $this->nullSink());
                }

                $line = $this->readLine(); // remainder of the response line after the literal
            }
        }
    }

    /**
     * @return resource
     */
    protected function nullSink()
    {
        $sink = fopen('php://memory', 'wb');
        if (! is_resource($sink)) {
            throw new ImapCommandException('Failed to allocate stream.');
        }

        return $sink;
    }

    protected function fetchMessageSize(int $uid): int
    {
        $response = $this->execute(Command::make('UID FETCH')->atom((string) $uid)->atom('(RFC822.SIZE)'));
        $this->assertOk($response, "FETCH of UID {$uid} failed");

        foreach ($this->parseFetchResponses($response) as $data) {
            if (isset($data['RFC822.SIZE']) && is_string($data['RFC822.SIZE']) && ctype_digit($data['RFC822.SIZE'])) {
                return (int) $data['RFC822.SIZE'];
            }
        }

        throw new ImapCommandException("Message with UID {$uid} was not found.", $response->statusLine());
    }

    /**
     * @param int|list<int> $uids
     * @param Flag|string|list<Flag|string> $flags
     */
    protected function store(int|array $uids, string $mode, Flag|string|array $flags): void
    {
        $flagList = Flag::normalizeList($flags);
        if ($flagList === [] && $mode !== 'FLAGS.SILENT') {
            return;
        }

        $flagAtom = '('.implode(' ', array_map(static fn (Flag $f): string => $f->value, $flagList)).')';

        foreach ($this->uidBatches($uids) as $set) {
            $response = $this->execute(Command::make('UID STORE')->atom($set)->atom($mode)->atom($flagAtom));
            $this->assertOk($response, "STORE {$mode} failed");
        }
    }

    protected function uidExpunge(int $uid): void
    {
        $this->assertOk($this->execute(Command::make('UID EXPUNGE')->atom((string) $uid)), "EXPUNGE of UID {$uid} failed");
    }

    protected function findUidByMessageId(string $sourceFolder, string $targetFolder, string $messageId): ?int
    {
        try {
            $this->selectFolder($targetFolder);
            $response = $this->execute(
                Command::make('UID SEARCH')->atom('HEADER Message-ID')->string("<{$messageId}>", 'message id')
            );

            if ($response->ok()) {
                $uids = $this->extractUidsFromSearch($response);

                return $uids === [] ? null : end($uids);
            }
        } catch (ImapConnectionException $e) {
            throw $e;
        } catch (Throwable) {
            // best effort only
        } finally {
            try {
                $this->selectFolder($sourceFolder);
            } catch (Throwable) {
            }
        }

        return null;
    }

    protected function nextTag(): string
    {
        $this->tagCounter++;

        return sprintf('A%04d', $this->tagCounter);
    }

    protected function requireTransport(): Transport
    {
        if ($this->transport === null || ! $this->transport->isOpen()) {
            throw new ImapConnectionException('Socket is not connected or already closed.');
        }

        return $this->transport;
    }

    protected function closeQuietly(): void
    {
        $this->transport?->close();
        $this->transport = null;
        $this->connected = false;
        $this->secure = false;
        $this->tagCounter = 0;
        $this->capabilities = [];
        $this->selectedMailbox = null;
        $this->secrets = [];
    }

    protected function log(string $direction, string $line): void
    {
        if ($this->debugLogger !== null) {
            ($this->debugLogger)($direction, $this->scrub($line));
        }
    }

    /**
     * Replace credentials used on this connection by "***" in server supplied text.
     */
    protected function scrub(string $text): string
    {
        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '***', $text);
            }
        }

        return $text;
    }

    protected function assertOk(Response $response, string $message): void
    {
        if (! $response->ok()) {
            $status = $this->scrub($response->statusLine());

            throw new ImapCommandException("{$message}: {$status}", $status);
        }
    }

    protected function assertUid(int $uid): void
    {
        if ($uid < 1) {
            throw new InvalidArgumentException('UID must be a positive integer.');
        }
    }

    /**
     * Folder names that are modified (create/rename/delete/subscribe) must be plain names.
     */
    protected function assertManageableFolderName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Folder name must not be empty.');
        }

        Encoder::assertSafe($name, 'folder name');

        if (strpbrk($name, '*%{}') !== false) {
            throw new InvalidArgumentException('Folder names must not contain wildcards (* %) or braces.');
        }
    }

    protected function assertNotInbox(string $name, string $action): void
    {
        if (strcasecmp(trim($name), 'INBOX') === 0) {
            throw new InvalidArgumentException("The INBOX folder cannot be used to {$action}.");
        }
    }

    /**
     * @param int|list<int> $uids
     * @return list<string> UID sets (comma/range notation), at most 500 UIDs each
     */
    protected function uidBatches(int|array $uids): array
    {
        $list = is_array($uids) ? $uids : [$uids];

        foreach ($list as $uid) {
            if (! is_int($uid)) {
                throw new InvalidArgumentException('UIDs must be integers.');
            }
            $this->assertUid($uid);
        }

        if ($list === []) {
            return [];
        }

        $list = array_values(array_unique($list));
        sort($list);

        $batches = [];
        foreach (array_chunk($list, 500) as $chunk) {
            $ranges = [];
            $start = $previous = $chunk[0];

            foreach (array_slice($chunk, 1) as $uid) {
                if ($uid === $previous + 1) {
                    $previous = $uid;

                    continue;
                }

                $ranges[] = $start === $previous ? (string) $start : "{$start}:{$previous}";
                $start = $previous = $uid;
            }

            $ranges[] = $start === $previous ? (string) $start : "{$start}:{$previous}";
            $batches[] = implode(',', $ranges);
        }

        return $batches;
    }

    /**
     * @param list<int> $uids
     * @return list<int>
     */
    protected function limit(array $uids, ?int $limit): array
    {
        if ($limit === null) {
            return $uids;
        }

        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be a positive integer.');
        }

        return array_slice($uids, 0, $limit);
    }

    /**
     * @param list<mixed> $list
     * @return array<string, mixed> Upper-cased key => value
     */
    protected function pairs(array $list): array
    {
        $result = [];
        for ($i = 0; $i + 1 < count($list); $i += 2) {
            if (is_string($list[$i])) {
                $result[strtoupper($list[$i])] = $list[$i + 1];
            }
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>> One upper-cased key => value map per "* n FETCH (...)" line
     */
    protected function parseFetchResponses(Response $response): array
    {
        $result = [];

        foreach ($response->untagged as $line) {
            if (preg_match('/\A\*\s+\d+\s+FETCH\s+(.*)\z/is', $line, $match) !== 1) {
                continue;
            }

            $tokens = ImapParser::parse($match[1]);
            if (isset($tokens[0]) && is_array($tokens[0])) {
                $result[] = $this->pairs($tokens[0]);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function buildSummary(array $data): ?MessageSummary
    {
        if (! isset($data['UID']) || ! is_string($data['UID']) || ! ctype_digit($data['UID'])) {
            return null;
        }

        $headerBlock = '';
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'BODY[') && is_string($value)) {
                $headerBlock = $value;
            }
        }

        $parser = $this->parser ?? new MimeParser();
        $headers = $parser->parseHeaderBlock($headerBlock);
        [$senderEmail, $senderName] = isset($headers['from']) ? $parser->extractSender($headers['from']) : [null, null];

        $internalDate = null;
        if (isset($data['INTERNALDATE']) && is_string($data['INTERNALDATE'])) {
            $parsed = DateTimeImmutable::createFromFormat('j-M-Y H:i:s O', trim($data['INTERNALDATE']));
            $internalDate = $parsed !== false ? $parsed : null;
        }

        $flags = isset($data['FLAGS']) && is_array($data['FLAGS'])
            ? array_values(array_filter($data['FLAGS'], 'is_string'))
            : [];

        return new MessageSummary(
            uid: (int) $data['UID'],
            flags: $flags,
            internalDate: $internalDate,
            size: isset($data['RFC822.SIZE']) && is_string($data['RFC822.SIZE']) ? (int) $data['RFC822.SIZE'] : 0,
            messageId: isset($headers['message-id']) ? $parser->extractMessageId($headers['message-id']) : null,
            subject: isset($headers['subject']) ? $parser->decodeMimeHeader($headers['subject']) : null,
            senderEmail: $senderEmail,
            senderName: $senderName,
            date: $parser->extractDate($headers['date'] ?? null),
            headers: $headers,
        );
    }

    /**
     * @return list<int>
     */
    protected function extractUidsFromSearch(Response $response): array
    {
        $uids = [];

        foreach ($response->untagged as $line) {
            if (preg_match('/\A\*\s+SEARCH\b(.*)\z/is', $line, $match) !== 1) {
                continue;
            }

            if (preg_match_all('/\d+/', $match[1], $numbers) > 0) {
                foreach ($numbers[0] as $number) {
                    $uids[] = (int) $number;
                }
            }
        }

        $uids = array_values(array_unique($uids));
        sort($uids, SORT_NUMERIC);

        return $uids;
    }

    protected function extractCopyUid(Response $response, int $sourceUid): ?int
    {
        $lines = [...$response->untagged, $response->text];

        foreach ($lines as $line) {
            // [COPYUID <validity> <source-uids> <target-uids>]
            if (preg_match('/\[COPYUID\s+\d+\s+([\d:,*]+)\s+([\d:,*]+)\]/i', $line, $matches) === 1
                && $matches[1] === (string) $sourceUid
                && ctype_digit($matches[2])) {
                return (int) $matches[2];
            }
        }

        return null;
    }
}
