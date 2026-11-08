<?php

declare(strict_types=1);

namespace Maivio\Imap\Parser;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Maivio\Imap\DTO\Attachment;
use Maivio\Imap\DTO\Message;
use Maivio\Imap\Exceptions\ImapException;
use Maivio\Imap\Exceptions\MimeLimitExceededException;
use Maivio\Imap\Support\FilenameSanitizer;
use Maivio\Imap\Support\HeaderParameters;
use ValueError;

/**
 * Streaming RFC 822 / MIME parser with hard resource limits.
 *
 * Attachments are decoded (base64 / quoted-printable) chunk by chunk and written either to a stream
 * supplied by the application (attachment sink) or to a php://temp stream. Text bodies are held in
 * memory and therefore capped by $maxTextPartSize.
 */
class MimeParser
{
    /**
     * @param int $maxDepth Maximum multipart nesting depth (protects against MIME bombs)
     * @param int $maxParts Maximum number of MIME parts per message
     * @param int $maxAttachmentSize Maximum decoded size of a single attachment in bytes
     * @param int $maxTextPartSize Maximum decoded size of a single text part / body in bytes
     * @param int $maxHeaderSize Maximum size of one header block in bytes
     */
    public function __construct(
        protected int $maxDepth = 10,
        protected int $maxParts = 500,
        protected int $maxAttachmentSize = 104_857_600,
        protected int $maxTextPartSize = 10_485_760,
        protected int $maxHeaderSize = 262_144,
    ) {
    }

    /**
     * Parse an RFC 822 email stream or raw string into a Message DTO.
     *
     * @param resource|string $stream
     * @param (Closure(Attachment): resource)|null $attachmentSink Receives a metadata-only Attachment and must return a
     *        writable stream. The decoded attachment content is written to it chunk by chunk. The parser flushes but never
     *        closes that stream; it stays owned by the caller.
     *
     * @throws MimeLimitExceededException when a depth, part count or size limit is exceeded
     */
    public function parse(mixed $stream, ?int $uid = null, ?Closure $attachmentSink = null): Message
    {
        $closeOnExit = false;

        if (is_string($stream)) {
            $temp = fopen('php://temp', 'r+b');
            if ($temp === false) {
                throw new InvalidArgumentException('Failed to allocate memory stream for string input.');
            }
            fwrite($temp, $stream);
            rewind($temp);
            $stream = $temp;
            $closeOnExit = true;
        }

        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Expected a valid stream resource or string.');
        }

        try {
            return $this->parseEmailStream($stream, $uid, new ParseContext($attachmentSink));
        } finally {
            if ($closeOnExit) {
                fclose($stream);
            }
        }
    }

    /**
     * Parse a raw header block (e.g. the result of BODY.PEEK[HEADER.FIELDS (...)]).
     *
     * @return array<string, string> lower-cased header name => unfolded raw value
     */
    public function parseHeaderBlock(string $raw): array
    {
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new InvalidArgumentException('Failed to allocate memory stream.');
        }

        try {
            fwrite($stream, $raw);
            rewind($stream);

            return $this->readHeaders(new LineReader($stream));
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param resource $stream
     */
    protected function parseEmailStream($stream, ?int $uid, ParseContext $ctx): Message
    {
        $reader = new LineReader($stream);
        $headers = $this->readHeaders($reader);

        $messageId = $this->extractMessageId($headers['message-id'] ?? null);
        $subject = isset($headers['subject']) ? $this->decodeMimeHeader($headers['subject']) : null;
        [$senderEmail, $senderName] = $this->extractSender($headers['from'] ?? '');
        $date = $this->extractDate($headers['date'] ?? null);

        [$mimeType, $params] = HeaderParameters::parse($headers['content-type'] ?? 'text/plain');
        $transferEncoding = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));
        $boundary = $params['boundary'] ?? '';

        $ctx->partCount = 1;

        if (str_starts_with($mimeType, 'multipart/') && $boundary !== '') {
            $this->parseMultipart($reader, [$boundary], 1, $ctx);
        } else {
            $raw = $this->readTextPart($reader, [], $transferEncoding, $ctx);
            $text = $this->convertToUtf8($raw, $params['charset'] ?? 'utf-8');

            if ($mimeType === 'text/html') {
                $ctx->bodyHtml = $text;
            } else {
                $ctx->bodyText = $text;
            }
        }

        return new Message(
            uid: $uid,
            messageId: $messageId,
            subject: $subject,
            senderEmail: $senderEmail,
            senderName: $senderName,
            date: $date,
            bodyText: $ctx->bodyText,
            bodyHtml: $ctx->bodyHtml,
            headers: $headers,
            attachments: $ctx->attachments,
        );
    }

    /**
     * @param list<string> $boundaries Boundary stack, the last entry belongs to this multipart
     */
    protected function parseMultipart(LineReader $reader, array $boundaries, int $depth, ParseContext $ctx): void
    {
        $index = count($boundaries) - 1;

        // Preamble: skip everything up to the first delimiter.
        $this->skipToBoundary($reader, $boundaries, $ctx);

        while (true) {
            $hit = $ctx->hit;

            if ($hit === null || $hit['index'] < $index || $hit['close']) {
                return; // EOF, boundary of an ancestor, or our own closing delimiter
            }

            $ctx->hit = null;
            $this->parsePart($reader, $boundaries, $depth, $ctx);
        }
    }

    /**
     * @param list<string> $boundaries
     */
    protected function parsePart(LineReader $reader, array $boundaries, int $depth, ParseContext $ctx): void
    {
        $ctx->partCount++;
        if ($ctx->partCount > $this->maxParts) {
            throw new MimeLimitExceededException("Message has more than {$this->maxParts} MIME parts.");
        }

        $headers = $this->readHeaders($reader);

        [$mime, $params] = HeaderParameters::parse($headers['content-type'] ?? 'text/plain');
        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));
        [$disposition, $dispositionParams] = HeaderParameters::parse($headers['content-disposition'] ?? '');

        $filename = $dispositionParams['filename'] ?? ($params['name'] ?? null);
        $isInline = $disposition === 'inline';
        $contentId = isset($headers['content-id']) ? trim($headers['content-id'], '<> ') : null;

        $nestedBoundary = $params['boundary'] ?? '';
        if (str_starts_with($mime, 'multipart/') && $nestedBoundary !== '') {
            if ($depth + 1 > $this->maxDepth) {
                throw new MimeLimitExceededException("MIME nesting is deeper than {$this->maxDepth} levels.");
            }

            $nested = [...$boundaries, $nestedBoundary];
            $this->parseMultipart($reader, $nested, $depth + 1, $ctx);

            // The child closed itself: skip its epilogue up to the next boundary of ours (or an ancestor).
            if ($ctx->hit !== null && $ctx->hit['index'] === count($boundaries)) {
                $this->skipToBoundary($reader, $boundaries, $ctx);
            }

            return;
        }

        $isAttachment = $disposition === 'attachment'
            || ($filename !== null && $filename !== '' && ! in_array($mime, ['text/plain', 'text/html'], true))
            || ($filename !== null && $filename !== '' && $isInline);

        if ($isAttachment) {
            $this->readAttachment($reader, $boundaries, $encoding, $filename, $mime, $isInline, $contentId, $ctx);

            return;
        }

        if ($mime === 'text/html' || $mime === 'text/plain') {
            $isHtml = $mime === 'text/html';
            $alreadyHave = $isHtml ? $ctx->bodyHtml !== null : $ctx->bodyText !== null;

            if ($alreadyHave) {
                $this->discardPart($reader, $boundaries, $ctx);

                return;
            }

            $text = $this->convertToUtf8(
                $this->readTextPart($reader, $boundaries, $encoding, $ctx),
                $params['charset'] ?? 'utf-8'
            );

            if ($isHtml) {
                $ctx->bodyHtml = $text;
            } else {
                $ctx->bodyText = $text;
            }

            return;
        }

        $this->discardPart($reader, $boundaries, $ctx);
    }

    /**
     * @param list<string> $boundaries
     */
    protected function readAttachment(
        LineReader $reader,
        array $boundaries,
        string $encoding,
        ?string $rawFilename,
        string $mimeType,
        bool $isInline,
        ?string $contentId,
        ParseContext $ctx,
    ): void {
        $decodedName = $rawFilename !== null && $rawFilename !== '' ? $this->decodeMimeHeader($rawFilename) : '';
        $mimeType = $mimeType !== '' ? $mimeType : 'application/octet-stream';
        $id = bin2hex(random_bytes(16));

        $meta = new Attachment(
            id: $id,
            filename: $decodedName !== '' ? $decodedName : FilenameSanitizer::DEFAULT_NAME,
            mimeType: $mimeType,
            size: 0,
            stream: null,
            isInline: $isInline,
            contentId: $contentId,
            originalFilename: $decodedName,
        );

        $ownsStream = $ctx->attachmentSink === null;
        if ($ctx->attachmentSink !== null) {
            $dest = ($ctx->attachmentSink)($meta);
        } else {
            $dest = fopen('php://temp/maxmemory:1048576', 'r+b');
        }

        if (! is_resource($dest)) {
            throw new InvalidArgumentException('The attachment sink must return a stream resource.');
        }

        $size = 0;
        $max = $this->maxAttachmentSize;

        try {
            $this->streamPart($reader, $boundaries, $encoding, $ctx, function (string $bytes) use ($dest, &$size, $max): void {
                $size += strlen($bytes);
                if ($size > $max) {
                    throw new MimeLimitExceededException("Attachment exceeds the limit of {$max} bytes.");
                }

                $this->writeAll($dest, $bytes);
            });
        } catch (\Throwable $e) {
            if ($ownsStream) {
                fclose($dest);
            }

            throw $e;
        }

        fflush($dest);
        if ($ownsStream) {
            rewind($dest);
        }

        $ctx->attachments[] = new Attachment(
            id: $id,
            filename: $decodedName !== '' ? $decodedName : FilenameSanitizer::DEFAULT_NAME,
            mimeType: $mimeType,
            size: $size,
            stream: $dest,
            isInline: $isInline,
            contentId: $contentId,
            originalFilename: $decodedName,
        );
    }

    /**
     * @param list<string> $boundaries
     */
    protected function readTextPart(LineReader $reader, array $boundaries, string $encoding, ParseContext $ctx): string
    {
        $buffer = '';
        $max = $this->maxTextPartSize;

        $this->streamPart($reader, $boundaries, $encoding, $ctx, function (string $bytes) use (&$buffer, $max): void {
            $buffer .= $bytes;
            if (strlen($buffer) > $max) {
                throw new MimeLimitExceededException("Text part exceeds the limit of {$max} bytes.");
            }
        });

        return $buffer;
    }

    /**
     * @param list<string> $boundaries
     */
    protected function discardPart(LineReader $reader, array $boundaries, ParseContext $ctx): void
    {
        $this->skipToBoundary($reader, $boundaries, $ctx);
    }

    /**
     * Read the content of one part up to the next boundary and hand the transfer-decoded bytes to $write in small chunks.
     *
     * @param list<string> $boundaries
     * @param Closure(string): void $write
     */
    protected function streamPart(LineReader $reader, array $boundaries, string $encoding, ParseContext $ctx, Closure $write): void
    {
        $ctx->hit = null;
        $pendingEol = '';
        $base64 = '';
        $carry = '';

        while (($chunk = $reader->next()) !== null) {
            $data = $chunk['data'];

            if ($chunk['lineStart'] && ($hit = $this->matchBoundary($data, $boundaries)) !== null) {
                $ctx->hit = $hit;
                break;
            }

            if ($encoding === 'base64') {
                $base64 .= preg_replace('/[^A-Za-z0-9+\/]/', '', $data);
                $usable = strlen($base64) - (strlen($base64) % 4);
                if ($usable > 0) {
                    $decoded = base64_decode(substr($base64, 0, $usable), true);
                    if ($decoded !== false && $decoded !== '') {
                        $write($decoded);
                    }
                    $base64 = substr($base64, $usable);
                }

                continue;
            }

            [$content, $eol] = $chunk['eol'] ? $this->splitEol($data) : [$data, ''];

            if ($encoding === 'quoted-printable') {
                $content = $carry.$content;
                $carry = '';

                if ($chunk['eol']) {
                    if (str_ends_with($content, '=')) { // soft line break
                        $content = substr($content, 0, -1);
                        $eol = '';
                    }
                } elseif (preg_match('/=[0-9A-Fa-f]?\z/', $content, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $carry = $match[0][0];
                    $content = substr($content, 0, $match[0][1]);
                }

                $content = quoted_printable_decode($content);
            }

            if ($pendingEol !== '') {
                $write($pendingEol);
            }
            if ($content !== '') {
                $write($content);
            }
            $pendingEol = $eol;
        }

        if ($encoding === 'base64' && $base64 !== '') {
            $decoded = base64_decode(str_pad($base64, strlen($base64) + (4 - strlen($base64) % 4) % 4, '='), false);
            if ($decoded !== false && $decoded !== '') {
                $write($decoded);
            }
        } elseif ($encoding === 'quoted-printable' && $carry !== '') {
            $write(quoted_printable_decode($carry));
        }
    }

    /**
     * @param list<string> $boundaries
     */
    protected function skipToBoundary(LineReader $reader, array $boundaries, ParseContext $ctx): void
    {
        $ctx->hit = null;

        while (($chunk = $reader->next()) !== null) {
            if ($chunk['lineStart'] && ($hit = $this->matchBoundary($chunk['data'], $boundaries)) !== null) {
                $ctx->hit = $hit;

                return;
            }
        }
    }

    /**
     * @param list<string> $boundaries
     * @return array{index: int, close: bool}|null
     */
    protected function matchBoundary(string $line, array $boundaries): ?array
    {
        if (! str_starts_with($line, '--') || strlen($line) > 1024) {
            return null;
        }

        $trimmed = rtrim($line, " \t\r\n");

        // Innermost boundary first.
        for ($i = count($boundaries) - 1; $i >= 0; $i--) {
            $delimiter = '--'.$boundaries[$i];

            if ($trimmed === $delimiter) {
                return ['index' => $i, 'close' => false];
            }

            if ($trimmed === $delimiter.'--') {
                return ['index' => $i, 'close' => true];
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitEol(string $data): array
    {
        if (str_ends_with($data, "\r\n")) {
            return [substr($data, 0, -2), "\r\n"];
        }

        return [substr($data, 0, -1), "\n"];
    }

    /**
     * @param resource $dest
     */
    protected function writeAll($dest, string $bytes): void
    {
        $length = strlen($bytes);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($dest, $offset === 0 ? $bytes : substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new ImapException('Failed to write attachment data to the destination stream.');
            }
            $offset += $written;
        }
    }

    /**
     * @return array<string, string>
     */
    protected function readHeaders(LineReader $reader): array
    {
        $headers = [];
        $currentHeader = null;
        $logical = '';
        $total = 0;

        $flush = function () use (&$headers, &$currentHeader, &$logical): void {
            if ($logical === '') {
                return;
            }

            $trimmed = rtrim($logical, "\r\n");
            $logical = '';

            if ($currentHeader !== null && ($trimmed[0] === ' ' || $trimmed[0] === "\t")) {
                $headers[$currentHeader] .= ' '.trim($trimmed);

                return;
            }

            $colon = strpos($trimmed, ':');
            if ($colon !== false) {
                $key = strtolower(trim(substr($trimmed, 0, $colon)));
                $headers[$key] = trim(substr($trimmed, $colon + 1));
                $currentHeader = $key;
            }
        };

        while (($chunk = $reader->next()) !== null) {
            $total += strlen($chunk['data']);
            if ($total > $this->maxHeaderSize) {
                throw new MimeLimitExceededException("Header block exceeds the limit of {$this->maxHeaderSize} bytes.");
            }

            $logical .= $chunk['data'];
            if (! $chunk['eol']) {
                continue; // line longer than one chunk
            }

            if (rtrim($logical, "\r\n") === '') {
                $logical = '';

                break; // empty line ends the header block
            }

            $flush();
        }

        $flush();

        return $headers;
    }

    protected function convertToUtf8(string $content, string $charset): string
    {
        $cleanCharset = strtoupper(trim($charset, '"\' '));
        if ($cleanCharset === '' || $cleanCharset === 'UTF-8' || $cleanCharset === 'US-ASCII') {
            return $content;
        }

        try {
            return mb_convert_encoding($content, 'UTF-8', $cleanCharset);
        } catch (ValueError) {
            return $content; // unknown charset
        }
    }

    public function decodeMimeHeader(string $encoded): string
    {
        $clean = trim($encoded);
        if (! str_contains($clean, '=?')) {
            return $clean;
        }

        $decoded = @iconv_mime_decode($clean, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded !== false) {
            return $decoded;
        }

        $mbDecoded = mb_decode_mimeheader($clean);

        return $mbDecoded !== '' ? $mbDecoded : $clean;
    }

    /**
     * @return array{0: string, 1: string|null} [lower-cased address, display name]
     */
    public function extractSender(string $fromHeader): array
    {
        $raw = trim($fromHeader);
        if (preg_match('/^(.*?)\s*<([^>]+)>/', $raw, $matches)) {
            $name = trim($matches[1], '"\' ');
            $email = trim($matches[2]);

            return [
                strtolower($email),
                $name !== '' ? $this->decodeMimeHeader($name) : null,
            ];
        }

        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return [strtolower($raw), null];
        }

        return [$raw !== '' ? strtolower($raw) : 'unknown@unknown', null];
    }

    public function extractDate(?string $dateHeader): ?DateTimeImmutable
    {
        if ($dateHeader === null || trim($dateHeader) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($dateHeader);
        } catch (\Throwable) {
            return null;
        }
    }

    public function extractMessageId(?string $messageIdHeader): string
    {
        if ($messageIdHeader !== null && trim($messageIdHeader) !== '') {
            return trim($messageIdHeader, '<> ');
        }

        return bin2hex(random_bytes(16)).'@generated.maivio';
    }
}
