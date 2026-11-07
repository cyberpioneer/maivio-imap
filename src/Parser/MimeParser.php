<?php

declare(strict_types=1);

namespace Maivio\Imap\Parser;

use DateTimeImmutable;
use InvalidArgumentException;
use Maivio\Imap\DTO\Attachment;
use Maivio\Imap\DTO\Message;

class MimeParser
{
    protected ?string $lastBoundaryLine = null;

    /**
     * Parse an RFC 822 email stream or raw string into a Message DTO.
     *
     * @param resource|string $stream
     */
    public function parse(mixed $stream, ?int $uid = null): Message
    {
        $closeOnExit = false;

        if (is_string($stream)) {
            $temp = fopen('php://temp', 'r+');
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
            return $this->parseEmailStream($stream, $uid);
        } finally {
            if ($closeOnExit) {
                fclose($stream);
            }
        }
    }

    /**
     * @param resource $stream
     */
    protected function parseEmailStream($stream, ?int $uid = null): Message
    {
        $headers = $this->readHeaders($stream);

        $messageId = $this->extractMessageId($headers['message-id'] ?? null);
        $subject = isset($headers['subject']) ? $this->decodeMimeHeader($headers['subject']) : null;
        [$senderEmail, $senderName] = $this->extractSender($headers['from'] ?? '');
        $date = $this->extractDate($headers['date'] ?? null);

        $contentTypeHeader = $headers['content-type'] ?? 'text/plain';
        [$mimeType, $params] = $this->parseHeaderWithParams($contentTypeHeader);
        $transferEncoding = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));

        $bodyText = null;
        $bodyHtml = null;
        $attachments = [];

        if (str_starts_with($mimeType, 'multipart/')) {
            $boundary = $params['boundary'] ?? null;
            if ($boundary !== null) {
                $this->parseMultipart($stream, $boundary, $bodyText, $bodyHtml, $attachments);
            } else {
                $rawContent = $this->readRemainingStreamContent($stream);
                $bodyText = $this->decodeContent($rawContent, $transferEncoding);
            }
        } elseif ($mimeType === 'text/html') {
            $rawContent = $this->readRemainingStreamContent($stream);
            $charset = $params['charset'] ?? 'utf-8';
            $decoded = $this->decodeContent($rawContent, $transferEncoding);
            $bodyHtml = $this->convertToUtf8($decoded, $charset);
        } else {
            $rawContent = $this->readRemainingStreamContent($stream);
            $charset = $params['charset'] ?? 'utf-8';
            $decoded = $this->decodeContent($rawContent, $transferEncoding);
            $bodyText = $this->convertToUtf8($decoded, $charset);
        }

        return new Message(
            uid: $uid,
            messageId: $messageId,
            subject: $subject,
            senderEmail: $senderEmail,
            senderName: $senderName,
            date: $date,
            bodyText: $bodyText,
            bodyHtml: $bodyHtml,
            headers: $headers,
            attachments: $attachments,
        );
    }

    /**
     * @param resource $stream
     * @param list<Attachment> $attachments
     */
    protected function parseMultipart(
        $stream,
        string $boundary,
        ?string &$bodyText,
        ?string &$bodyHtml,
        array &$attachments,
    ): void {
        $delimiter = '--'.$boundary;
        $closeDelimiter = '--'.$boundary.'--';

        // Fast-forward stream to first boundary
        while (! feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                break;
            }
            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === $delimiter || $trimmed === $closeDelimiter) {
                $this->lastBoundaryLine = $trimmed;
                break;
            }
        }

        while (! feof($stream)) {
            if ($this->lastBoundaryLine === $closeDelimiter) {
                break;
            }

            $partHeaders = $this->readHeaders($stream);
            $partContentType = $partHeaders['content-type'] ?? 'text/plain';
            [$partMime, $partParams] = $this->parseHeaderWithParams($partContentType);
            $partEncoding = strtolower(trim($partHeaders['content-transfer-encoding'] ?? '7bit'));

            $contentDisposition = $partHeaders['content-disposition'] ?? '';
            [$dispositionType, $dispParams] = $this->parseHeaderWithParams($contentDisposition);

            $filename = $dispParams['filename'] ?? ($partParams['name'] ?? null);
            $isInline = $dispositionType === 'inline';
            $contentId = isset($partHeaders['content-id']) ? trim($partHeaders['content-id'], '<> ') : null;

            if (str_starts_with($partMime, 'multipart/')) {
                $nestedBoundary = $partParams['boundary'] ?? null;
                if ($nestedBoundary !== null) {
                    $this->parseMultipart($stream, $nestedBoundary, $bodyText, $bodyHtml, $attachments);
                    // Read up to current boundary
                    while (! feof($stream)) {
                        $line = fgets($stream);
                        if ($line === false) {
                            break;
                        }
                        $trimmed = rtrim($line, "\r\n");
                        if ($trimmed === $delimiter || $trimmed === $closeDelimiter) {
                            $this->lastBoundaryLine = $trimmed;
                            break;
                        }
                    }
                    continue;
                }
            }

            $isExplicitAttachment = ($dispositionType === 'attachment') || (! empty($filename) && ! in_array($partMime, ['text/plain', 'text/html'], true));

            if ($isExplicitAttachment || (! empty($filename) && $isInline)) {
                $attachment = $this->streamAttachmentToDto(
                    $stream,
                    $boundary,
                    $partEncoding,
                    $filename,
                    $partMime,
                    $isInline,
                    $contentId
                );

                if ($attachment !== null) {
                    $attachments[] = $attachment;
                }
            } else {
                // Read text or html body part
                $partRaw = $this->readPartContentUntilBoundary($stream, $boundary);
                $partCharset = $partParams['charset'] ?? 'utf-8';
                $decoded = $this->decodeContent($partRaw, $partEncoding);
                $utf8Content = $this->convertToUtf8($decoded, $partCharset);

                if ($partMime === 'text/html') {
                    if ($bodyHtml === null) {
                        $bodyHtml = $utf8Content;
                    }
                } elseif ($partMime === 'text/plain') {
                    if ($bodyText === null) {
                        $bodyText = $utf8Content;
                    }
                }
            }
        }
    }

    /**
     * Stream an attachment directly into a temporary stream resource and return an Attachment DTO.
     *
     * @param resource $stream
     */
    protected function streamAttachmentToDto(
        $stream,
        ?string $boundary,
        string $encoding,
        ?string $filename,
        string $mimeType,
        bool $isInline,
        ?string $contentId
    ): ?Attachment {
        $id = bin2hex(random_bytes(16));
        $dest = fopen('php://temp', 'r+b');
        if (! is_resource($dest)) {
            return null;
        }

        $delimiter = $boundary !== null ? '--'.$boundary : null;
        $closeDelimiter = $boundary !== null ? '--'.$boundary.'--' : null;
        $this->lastBoundaryLine = null;

        $buffer = '';

        while (! feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                break;
            }

            $trimmed = rtrim($line, "\r\n");
            if ($boundary !== null && ($trimmed === $delimiter || $trimmed === $closeDelimiter)) {
                $this->lastBoundaryLine = $trimmed;
                break;
            }

            if ($encoding === 'base64') {
                $decodedChunk = base64_decode(trim($line), true);
                if ($decodedChunk !== false) {
                    fwrite($dest, $decodedChunk);
                }
            } elseif ($encoding === 'quoted-printable') {
                $buffer .= $line;
                if (strlen($buffer) > 4096) {
                    fwrite($dest, quoted_printable_decode($buffer));
                    $buffer = '';
                }
            } else {
                fwrite($dest, $line);
            }
        }

        if ($encoding === 'quoted-printable' && $buffer !== '') {
            fwrite($dest, quoted_printable_decode($buffer));
        }

        $size = (int) ftell($dest);
        rewind($dest);

        $originalFilename = $filename !== null && $filename !== ''
            ? $this->decodeMimeHeader($filename)
            : 'attachment.bin';

        return new Attachment(
            id: $id,
            filename: $originalFilename,
            mimeType: $mimeType !== '' ? $mimeType : 'application/octet-stream',
            size: $size,
            stream: $dest,
            isInline: $isInline,
            contentId: $contentId,
        );
    }

    /**
     * @param resource $stream
     * @return array<string, string>
     */
    protected function readHeaders($stream): array
    {
        $headers = [];
        $currentHeader = null;

        while (! feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                break;
            }

            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === '') {
                break;
            }

            if ($currentHeader !== null && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
                $headers[$currentHeader] .= ' '.trim($line);
                continue;
            }

            $colonPos = strpos($trimmed, ':');
            if ($colonPos !== false) {
                $key = strtolower(trim(substr($trimmed, 0, $colonPos)));
                $value = trim(substr($trimmed, $colonPos + 1));
                $headers[$key] = $value;
                $currentHeader = $key;
            }
        }

        return $headers;
    }

    /**
     * @param resource $stream
     */
    protected function readPartContentUntilBoundary($stream, string $boundary): string
    {
        $delimiter = '--'.$boundary;
        $closeDelimiter = '--'.$boundary.'--';
        $content = '';
        $this->lastBoundaryLine = null;

        while (! feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                break;
            }

            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === $delimiter || $trimmed === $closeDelimiter) {
                $this->lastBoundaryLine = $trimmed;
                break;
            }

            $content .= $line;
        }

        return rtrim($content, "\r\n");
    }

    /**
     * @param resource $stream
     */
    protected function readRemainingStreamContent($stream): string
    {
        $content = '';
        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                break;
            }
            $content .= $chunk;
        }

        return $content;
    }

    protected function decodeContent(string $content, string $encoding): string
    {
        return match ($encoding) {
            'base64' => base64_decode(trim($content)) ?: '',
            'quoted-printable' => quoted_printable_decode($content),
            default => $content,
        };
    }

    protected function convertToUtf8(string $content, string $charset): string
    {
        $cleanCharset = strtoupper(trim($charset, '"\' '));
        if ($cleanCharset === '' || $cleanCharset === 'UTF-8' || $cleanCharset === 'US-ASCII') {
            return $content;
        }

        $converted = @mb_convert_encoding($content, 'UTF-8', $cleanCharset);

        return $converted !== false ? $converted : $content;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    protected function parseHeaderWithParams(string $headerValue): array
    {
        $parts = explode(';', $headerValue);
        $mainValue = strtolower(trim(array_shift($parts) ?? ''));
        $params = [];

        foreach ($parts as $part) {
            $colonPos = strpos($part, '=');
            if ($colonPos === false) {
                continue;
            }

            $paramName = strtolower(trim(substr($part, 0, $colonPos)));
            $paramValue = trim(substr($part, $colonPos + 1));
            $paramValue = trim($paramValue, '"\' ');
            $params[$paramName] = $paramValue;
        }

        return [$mainValue, $params];
    }

    public function decodeMimeHeader(string $encoded): string
    {
        $clean = trim($encoded);
        if (! str_contains($clean, '=?')) {
            return $clean;
        }

        $decoded = iconv_mime_decode($clean, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded !== false) {
            return $decoded;
        }

        $mbDecoded = mb_decode_mimeheader($clean);
        return $mbDecoded ?: $clean;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    protected function extractSender(string $fromHeader): array
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

    protected function extractDate(?string $dateHeader): ?DateTimeImmutable
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

    protected function extractMessageId(?string $messageIdHeader): string
    {
        if ($messageIdHeader !== null && trim($messageIdHeader) !== '') {
            return trim($messageIdHeader, '<> ');
        }

        return bin2hex(random_bytes(16)).'@generated.maivio';
    }
}
