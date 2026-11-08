# maivio-imap

[![Latest Version on Packagist](https://img.shields.io/packagist/v/maivio/maivio-imap.svg?style=flat-square)](https://packagist.org/packages/maivio/maivio-imap)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2%20%7C%7C%20%5E8.3%20%7C%7C%20%5E8.4%20%7C%7C%20%5E8.5-blue.svg?style=flat-square)](https://php.net)

> **Modern, stream-based, zero-dependency IMAP client and RFC 822 MIME parser for PHP and Laravel.**
> Completely independent of `ext-imap` (which is deprecated in PHP 8.4 and removed in PHP 8.5+).

---

## Highlights

- **Secure by default:** TLS 1.2+ with certificate and hostname verification. `starttls` upgrades *before* any credential is sent. **There is no silent plaintext fallback**: unknown `encryption` values throw, and `none` needs an explicit `allow_insecure`.
- **Injection-proof commands:** CR, LF and NUL are rejected in every user value before a single byte is sent; values go out as quoted strings or literals. Searches use a typed `SearchCriteria` builder, not raw strings.
- **Zero C-extension dependencies:** raw sockets (`stream_socket_client`), `peer_name`/SNI support (connect to a pre-resolved IP and still verify the hostname).
- **Stream-based, bounded memory:** messages are downloaded in `BODY.PEEK[]<offset.limit>` chunks after an `RFC822.SIZE` check; attachments are decoded chunk by chunk straight into a sink you provide.
- **Hard limits for hostile mail:** maximum message size, attachment size, MIME depth and part count.
- **Folder management:** list (with SPECIAL-USE attributes), create, rename, delete, subscribe, `STATUS`, `SELECT` with UIDVALIDITY/UIDNEXT; modified UTF-7 (RFC 3501 §5.1.3) built in.
- **Message operations:** metadata fetch without body (`FLAGS`, `INTERNALDATE`, `RFC822.SIZE`, headers), `STORE` flags, `COPY`, `MOVE` (with safe fallback), delete, `APPEND`.
- **Authentication:** `LOGIN` and **SASL XOAUTH2** (Google Workspace, Microsoft 365, Outlook).
- **Credentials never leak:** `#[\SensitiveParameter]` everywhere, redacted debug log, scrubbed exception messages.
- **First-class Laravel integration:** service provider, facade (`Imap::...`), publishable config.

---

## Requirements

- **PHP:** `^8.2`, `^8.3`, `^8.4` or `^8.5`
- **Extensions:** `openssl`, `mbstring`, `iconv` (standard in almost all PHP installations)

---

## Installation

```bash
composer require maivio/maivio-imap
```

### Laravel Setup

The service provider and `Imap` facade are registered automatically via package discovery. Publish the optional configuration:

```bash
php artisan vendor:publish --tag=maivio-imap-config
```

---

## Quickstart

```php
use Maivio\Imap\Client\ImapClient;
use Maivio\Imap\Search\SearchCriteria;

$client = new ImapClient();
$client->connect('imap.example.com', 993, 'ssl');   // or 143 + 'starttls'
$client->login('user@example.com', 'secret-password');

$client->selectFolder('INBOX');

foreach ($client->search(SearchCriteria::new()->unseen()->since(new DateTimeImmutable('-7 days'))) as $uid) {
    $message = $client->getMessage($uid, maxSize: 25 * 1024 * 1024);

    echo $message->getSubject(), PHP_EOL;

    foreach ($message->getAttachments() as $attachment) {
        // $attachment->getFilename() is sanitized, but never build a path from it yourself:
        $attachment->saveTo('/storage/attachments/'.bin2hex(random_bytes(8)));
    }

    $client->markAsSeen($uid);
}

$client->disconnect();
```

---

## Connection and security options

```php
$client->connect(
    host: 'imap.example.com',
    port: 143,
    encryption: 'starttls',
    timeout: 30.0,          // connect timeout (seconds)
    validateCert: true,     // default; see warning below
    peerName: null,         // certificate is verified against this name instead of $host
    allowInsecure: false,   // only relevant for encryption 'none'
    readTimeout: 30.0,      // per-read timeout, defaults to $timeout
    sniEnabled: true,
    sniServerName: null,    // defaults to peerName, then host
);
```

| `encryption` | Behaviour |
|---|---|
| `ssl` / `tls` | Implicit TLS from the first byte (port 993). |
| `starttls` | Plain connect, greeting, `CAPABILITY`, `STARTTLS`, TLS handshake (**TLS 1.2 or 1.3**), `CAPABILITY` again. If the server does not offer `STARTTLS`, rejects it, or the handshake fails, the connection is **closed and an `ImapConnectionException` is thrown**. No `LOGIN` is ever sent in plaintext. |
| `none` | No encryption. Throws `ImapConnectionException` unless `allowInsecure: true` is set. Credentials travel in plaintext, never use this outside of local development. |
| anything else | `InvalidArgumentException`. There is no fallback to plaintext for typos such as `starttsl`. |

Additional guarantees:

- `login()` refuses to run on an unencrypted connection (unless `allow_insecure`) and when the server announces `LOGINDISABLED`. In both cases nothing is sent.
- A `host` containing a scheme (`tcp://...`) or whitespace/slashes is rejected.
- If plaintext data arrives together with the `STARTTLS` OK response (STARTTLS injection), the upgrade is aborted.
- TLS is limited to 1.2/1.3 and compression is disabled.

> **Warning: `validate_cert: false` is insecure.** It disables `verify_peer` and `verify_peer_name` and accepts self-signed certificates, which makes the connection (and your credentials) vulnerable to man-in-the-middle attacks. By default both checks are always on. Only disable them for local development.

### Connecting to a pre-resolved IP (DNS rebinding protection)

Resolve and validate the hostname yourself, connect to the IP, and still verify the certificate against the hostname:

```php
$client->connect(
    host: '203.0.113.25',        // IP you validated and resolved
    port: 993,
    encryption: 'ssl',
    peerName: 'imap.example.com' // certificate name check + default SNI name
);
```

### Laravel configuration (`config/maivio-imap.php`)

| Key | Env | Default | Meaning |
|---|---|---|---|
| `host`, `port` | `IMAP_HOST`, `IMAP_PORT` | `127.0.0.1`, `993` | Server. If `port` is omitted, 993 (TLS) or 143 (`starttls`/`none`) is used. |
| `encryption` | `IMAP_ENCRYPTION` | `ssl` | `ssl`, `tls`, `starttls`, `none` |
| `username`, `password` / `access_token` | | | `LOGIN` or XOAUTH2 |
| `timeout` | `IMAP_TIMEOUT` | `30.0` | Connect timeout |
| `read_timeout` | `IMAP_READ_TIMEOUT` | `timeout` | Per-read timeout |
| `validate_cert` | `IMAP_VALIDATE_CERT` | `true` | **Insecure when false** |
| `allow_insecure` | `IMAP_ALLOW_INSECURE` | `false` | Needed for `encryption: none` |
| `peer_name` | `IMAP_PEER_NAME` | `null` | Certificate name to verify |
| `sni_enabled`, `sni_server_name` | `IMAP_SNI_ENABLED`, `IMAP_SNI_SERVER_NAME` | `true`, `null` | SNI |
| `max_message_size` | `IMAP_MAX_MESSAGE_SIZE` | `52428800` (50 MiB) | `null` = unlimited |

Boolean options are parsed strictly: `"false"`, `"0"`, `"off"` are false; an unparsable value falls back to the **secure** default (it never turns into `true` for `allow_insecure`).

```php
use Maivio\Imap\Facades\Imap;

$client = Imap::connect([
    'host' => '203.0.113.25',
    'peer_name' => 'imap.mail.me.com',
    'encryption' => 'ssl',
    'username' => 'user@icloud.com',
    'password' => config('services.icloud.app_password'),
]);
```

---

## Injection protection

Every value that ends up in a command (mailbox names, usernames, passwords, search values, flags, folder names) goes through one central encoder:

- **CR, LF and NUL anywhere in a value** throw an `InvalidArgumentException` *before* anything is sent. The exception message never contains the rejected value.
- Printable ASCII is sent as a correctly escaped quoted string. Everything else (umlauts, non-ASCII passwords) is sent as a literal; the non-synchronizing form `{n+}` is only used if the server announced `LITERAL+`.
- Mailbox names are encoded to modified UTF-7 (`&` becomes `&-`).
- Flags are limited to `\Seen`, `\Flagged`, `\Answered`, `\Deleted`, `\Draft`.

### Searching

```php
use Maivio\Imap\Search\SearchCriteria;

$uids = $client->search(
    SearchCriteria::new()
        ->from('billing@example.com')
        ->subject('Rechnung')       // non-ASCII values automatically use CHARSET UTF-8
        ->since(new DateTimeImmutable('2026-01-01'))
        ->unseen(),
    limit: 100,
);
```

Available: `all()`, `from()`, `to()`, `cc()`, `subject()`, `body()`, `text()`, `since()`, `before()`, `on()`, `seen()`, `unseen()`, `flagged()`, `unflagged()`, `answered()`, `unanswered()`, `deleted()`, `undeleted()`, `larger()`, `smaller()`, `uidRange()`.

> **Large mailboxes:** `searchAllUids()` makes the server return *every* UID in one response line (the client reads at most 8 MiB per line). On big mailboxes use `searchUidRange($from, $to, $limit)` or `search(...)` with narrow criteria and iterate in batches.

### Credentials stay out of logs and exceptions

- `#[\SensitiveParameter]` is set on every parameter that carries credentials, so stack traces show `Object(SensitiveParameterValue)`.
- `LOGIN` and `AUTHENTICATE` are shown as `LOGIN ***` in the optional debug log; literal payloads are never logged; any credential echoed by the server is replaced with `***` in logs and in exception messages.

```php
$client->setDebugLogger(function (string $direction, string $line): void {
    logger()->debug("IMAP {$direction}: {$line}"); // "C" = client, "S" = server
});
```

---

## Folders

```php
foreach ($client->listFolders() as $folder) {
    // getName() is decoded UTF-8, getRawName() the on-the-wire modified UTF-7
    echo $folder->getName(), ' (delimiter "', $folder->getDelimiter(), '") ', implode(' ', $folder->getAttributes()), PHP_EOL;
    // isSent(), isTrash(), isDrafts(), isJunk(), isArchive() use \Sent, \Trash, ... first, localized names as fallback
}

$client->createFolder('Projekte/Müller', subscribe: true);
$client->renameFolder('Projekte/Müller', 'Projekte/Meier');
$client->deleteFolder('Projekte/Meier', unsubscribe: true);

$status = $client->folderStatus('INBOX');   // STATUS without SELECT
$status->getMessages(); $status->getUnseen(); $status->getUidNext(); $status->getUidValidity();

$info = $client->selectFolder('INBOX');     // ['exists', 'recent', 'uidvalidity', 'uidnext']
```

`listFolders()` uses `LIST ... RETURN (SPECIAL-USE)` when the server announces `LIST-EXTENDED` and `SPECIAL-USE`, otherwise the attributes of the plain `LIST`.

`INBOX` can neither be deleted nor renamed (case-insensitive), and `*`, `%`, `{`, `}` are rejected in folder names for create/rename/delete/subscribe (`InvalidArgumentException`). Server errors are thrown as `ImapCommandException`; `getServerResponse()` returns the tagged status line (e.g. `A0007 NO [ALREADYEXISTS] ...`).

---

## Messages

```php
// Metadata only (BODY.PEEK, never sets \Seen)
foreach ($client->fetchSummaries($uids) as $summary) {
    $summary->getUid(); $summary->getFlags(); $summary->isSeen(); $summary->getInternalDate();
    $summary->getSize(); $summary->getSubject(); $summary->getSenderEmail(); $summary->getMessageId();
}

// Flags
use Maivio\Imap\Protocol\Flag;
$client->addFlags($uid, Flag::Flagged);
$client->removeFlags([$uid1, $uid2], [Flag::Seen, Flag::Flagged]);
$client->setFlags($uid, ['\\Seen', '\\Answered']);   // replaces all flags
$client->markAsSeen($uid);

// Copy / move / delete (operate on UIDs)
$newUid = $client->copyMessage('INBOX', $uid, 'Archive');
$newUid = $client->moveMessage('INBOX', $uid, $client->detectTrashFolder());
$client->selectFolder('INBOX');
$client->deleteMessage($uid);               // \Deleted + UID EXPUNGE

// Save a copy into "Sent"
$client->appendMessage('Sent', $rawRfc822String, [Flag::Seen]);
```

`moveMessage()` uses `UID MOVE` (RFC 6851) when offered. Otherwise it uses `UID COPY`, `\Deleted` and **`UID EXPUNGE`** (requires `UIDPLUS`). If the server supports neither, an `ImapCommandException` is thrown **before anything is copied**: a plain `EXPUNGE` would also delete unrelated messages that happen to be flagged `\Deleted`. `deleteMessage($uid)` follows the same rule; use `deleteMessage($uid, expunge: false)` to only set the flag.

---

## Large messages and attachments

```php
$message = $client->getMessage(
    uid: $uid,
    maxSize: 20 * 1024 * 1024,   // checked via RFC822.SIZE; throws ImapMessageTooLargeException without downloading
    attachmentSink: function (Maivio\Imap\DTO\Attachment $meta) {
        // $meta carries id, sanitized filename, mime type; you decide where the bytes go.
        return fopen('/storage/attachments/'.$meta->getId(), 'wb');
    },
);
```

- The message is fetched in chunks (`setChunkSize()`, default 512 KiB) into a `php://temp` stream that spills to disk; it is never held completely in memory.
- With a sink, attachment content is decoded (base64 / quoted-printable) **chunk by chunk** straight into your stream. The parser flushes but does not close it. Without a sink each attachment goes to its own `php://temp` stream (`getStream()`, `saveTo()`).
- Text bodies are held in memory and capped (default 10 MiB per part).
- `setMaxMessageSize($bytes)` sets a client-wide default limit.

### MIME parser limits

```php
new Maivio\Imap\Parser\MimeParser(
    maxDepth: 10,                  // multipart nesting
    maxParts: 500,                 // MIME parts per message
    maxAttachmentSize: 104857600,  // 100 MiB decoded, per attachment
    maxTextPartSize: 10485760,     // 10 MiB per text part
    maxHeaderSize: 262144,         // per header block
);
```

Exceeding a limit throws `MimeLimitExceededException`.

### Attachment file names

Remote file names are attacker controlled. `Attachment::getFilename()` is always sanitized: no path components (`../../x` → `x`), no control/NUL/BiDi characters, reserved characters replaced, at most 255 bytes (extension preserved). RFC 2231 (`filename*=UTF-8''...`, continuations) and RFC 2047 names are decoded first. `getOriginalFilename()` returns the decoded name for display only, **never use it (or the sanitized name) to build a file path**; generate your own storage name.

---

## Microsoft 365 & Google Workspace (XOAUTH2)

```php
$client = new ImapClient();
$client->connect('outlook.office365.com', 993, 'ssl');
$client->authenticateXOAuth2(username: 'user@company.com', accessToken: $oauthToken);
```

---

## Migration from 0.1.x

See [CHANGELOG.md](CHANGELOG.md) for the full list of breaking changes.

```php
// Raw search strings were removed (command injection):
- $uids = $client->search('FROM "john" SUBJECT "invoice"');
+ $uids = $client->search(SearchCriteria::new()->from('john')->subject('invoice'));

// Unknown encryption values used to fall back to plaintext. Now they throw:
- $client->connect($host, 143, 'starttls');          // was silently plaintext!
+ $client->connect($host, 143, 'starttls');          // now really upgrades via STARTTLS
- $client->connect($host, 143, 'none');
+ $client->connect($host, 143, 'none', allowInsecure: true);

// Attachment names are sanitized; do not use them as paths
- $attachment->saveTo('/storage/'.$attachment->getFilename());
+ $attachment->saveTo('/storage/'.bin2hex(random_bytes(8)));
```

---

## Testing and quality

```bash
composer test            # phpunit: unit tests (fake IMAP server, no network) + loopback TLS tests
composer analyse         # phpstan, level 8
composer format:check    # pint
```

Unit tests never touch the network. The loopback tests start a throw-away server on `127.0.0.1` with a self-signed certificate (requires the `openssl` CLI, otherwise skipped). An optional test against a real server runs only if you set `IMAP_TEST_HOST`, `IMAP_TEST_USERNAME`, `IMAP_TEST_PASSWORD` (and optionally `IMAP_TEST_PORT`, `IMAP_TEST_ENCRYPTION`) in your environment. It creates and deletes a folder, so use a dedicated account. No credentials belong in the repository.

---

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
