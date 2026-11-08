# Changelog

All notable changes to `maivio/maivio-imap` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.2.0] - Unreleased

Security hardening and functional extension for multi-tenant use.

### Breaking Changes

- **`encryption` is strict.** Supported values: `ssl`, `tls` (implicit TLS), `starttls`, `none`. Every other value
  (including typos such as `starttsl` and values like `tcp`) now throws `InvalidArgumentException`. In 0.1.x any unknown
  value, including `starttls`, silently connected in **plaintext** and never upgraded to TLS.
- **`none` requires `allow_insecure: true`** (`connect(..., allowInsecure: true)` or config key `allow_insecure`),
  otherwise `ImapConnectionException`.
- **`ImapClient::search(string $criteria)` was removed** because raw criteria allowed IMAP command injection. It now
  accepts a `SearchCriteria` builder; passing a string throws `BadMethodCallException` immediately. Use
  `SearchCriteria::new()->from(...)->subject(...)`.
- **CR, LF and NUL in any user value are rejected** with `InvalidArgumentException` (mailbox, username, password, search
  values, flags, folder names, message ids). Values that were previously passed through now fail.
- **`moveMessage()` no longer falls back silently.** A failing `UID MOVE` throws instead of retrying with COPY. Without
  `MOVE` it uses `UID COPY` + `\Deleted` + `UID EXPUNGE` (needs `UIDPLUS`); if neither `MOVE` nor `UIDPLUS` is available it
  throws `ImapCommandException` before copying. The unscoped `EXPUNGE` (which removed all `\Deleted` messages) is gone.
- **`deleteMessage($uid, true)` requires `UIDPLUS`** (`UID EXPUNGE`); otherwise it throws. Use `expunge: false` to only
  flag the message.
- **Attachment names are sanitized.** `Attachment::getFilename()` returns a path-free, control/BiDi-free name (max
  255 bytes). The decoded sender-supplied name is available as `getOriginalFilename()` and must never be used as a path.
  `Attachment::toArray()` has a new key `original_filename`; the constructor takes a nullable stream and an optional
  `originalFilename`.
- **`ImapClient::getMessage()` / `fetchRawStream()`** now query `RFC822.SIZE` first, download in chunks and accept
  `$maxSize`; `getMessage()` closes the raw stream. A default limit of 50 MiB (`max_message_size`) is set in the
  published Laravel config.
- **`MimeParser` limits.** Defaults: nesting depth 10, 500 parts, 100 MiB per attachment, 10 MiB per text part, 256 KiB per
  header block. Violations throw `MimeLimitExceededException`. Internal (protected) parser methods changed.
- **Login error messages no longer contain the username**; server texts have credentials scrubbed.
- `ImapCommandException` constructor is now `(string $message, ?string $serverResponse, ?Throwable $previous)`.
- `encodeUtf7()` / `decodeUtf7()` throw `InvalidArgumentException` for invalid input instead of silently returning it
  unchanged. They are now backed by a dedicated implementation instead of `mb_convert_encoding('UTF7-IMAP')`.
- `Folder::getDelimiter()` is an empty string for a `NIL` delimiter (flat namespace) instead of `/`.
- A `host` with a scheme (`tcp://host`), whitespace or slashes is rejected.
- Boolean config values are parsed strictly (`"false"` is false; unparsable values fall back to the secure default).
- `ImapManager` uses port 143 when `encryption` is `starttls`/`none` and no `port` is configured.
- The protected plumbing of `ImapClient` (`sendCommand()`, `readLine()`, `escapeString()`, ...) was replaced by an
  internal protocol layer.

### Added

- `starttls` support (greeting, `CAPABILITY`, `STARTTLS`, TLS 1.2+/1.3 handshake, `CAPABILITY` again). Missing
  `STARTTLS`, a rejected command, pending plaintext data or a failed handshake close the connection and throw.
- `login()` is refused on unencrypted connections (unless `allow_insecure`) and when `LOGINDISABLED` is announced.
- Options `peer_name`, `sni_enabled`, `sni_server_name`, `read_timeout`, `allow_insecure`, `max_message_size`, `chunk_size`.
- Central input validation and encoding (quoted string / literal, `{n+}` only with `LITERAL+`), modified UTF-7 for all
  mailbox names.
- `SearchCriteria` builder with a keyword whitelist; `searchUidRange()`; `limit` parameter for searches.
- Folder management: `createFolder()`, `renameFolder()`, `deleteFolder()`, `subscribeFolder()`, `unsubscribeFolder()`,
  `folderStatus()`, `selectFolder()` (alias `selectMailbox()` kept); `listFolders()` with delimiter and SPECIAL-USE
  (`LIST-EXTENDED`).
- Message operations: `fetchSummaries()` / `fetchSummary()` (`MessageSummary`: flags, internal date, size, headers),
  `addFlags()`, `removeFlags()`, `setFlags()`, `copyMessage()`, `appendMessage()`, `Flag` enum.
- Attachment sink: `getMessage($uid, $maxSize, $attachmentSink)` and `MimeParser::parse($stream, $uid, $sink)`.
- Chunked, size-checked message download; `ImapMessageTooLargeException`.
- RFC 2231 (extended values and continuations) and RFC 2047 file name decoding; quoted `;` in header parameters.
- Optional debug logger with redaction (`setDebugLogger()`); `ImapClient::getCapabilities()` / `hasCapability()`.
- `ImapCommandException::getServerResponse()`.
- Transport abstraction (`Transport`, `StreamTransport`) so the protocol layer can be tested without a network.
- `phpunit.xml`, `phpstan.neon` (level 8), `pint.json`, Composer scripts `test`, `analyse`, `format`, `format:check`.

### Security

- Removed the plaintext fallback for unknown `encryption` values (credentials were sent unencrypted).
- Fixed IMAP command injection via CR/LF/NUL in mailbox names, username, password and search criteria.
- Credentials are no longer part of exception messages, stack traces (`#[\SensitiveParameter]`) or debug output.
- `verify_peer` and `verify_peer_name` are only disabled by an explicit `validate_cert: false`.
- MIME bomb and oversize protection (depth, part count, sizes); attachment file names sanitized.

### Fixed

- Attachment content no longer gets a spurious trailing line break; base64 with line lengths that are not a multiple of 4
  is decoded correctly; quoted-printable soft line breaks across chunks are handled.
- Unknown text charsets no longer raise `ValueError`.
- XOAUTH2 error challenges are answered with an empty line instead of hanging until the read timeout; servers without
  `SASL-IR` receive the payload after the continuation request.
- The raw message stream opened by `getMessage()` is closed.

## [0.1.0]

- Initial release.
