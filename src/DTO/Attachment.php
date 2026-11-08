<?php

declare(strict_types=1);

namespace Maivio\Imap\DTO;

use InvalidArgumentException;
use Maivio\Imap\Support\FilenameSanitizer;
use RuntimeException;

class Attachment
{
    protected string $filename;

    protected string $originalFilename;

    /**
     * @param resource|null $stream Decoded content. Null for the metadata-only object passed to an attachment sink.
     * @param string|null $originalFilename Decoded name as sent by the remote side (control characters removed).
     *                                      Metadata only: never use it as a file path.
     */
    public function __construct(
        protected string $id,
        string $filename,
        protected string $mimeType,
        protected int $size,
        protected $stream = null,
        protected bool $isInline = false,
        protected ?string $contentId = null,
        ?string $originalFilename = null,
    ) {
        if ($stream !== null && ! is_resource($stream)) {
            throw new InvalidArgumentException('Attachment stream must be a valid resource.');
        }

        // The public filename is always path-free and free of control/BiDi characters.
        $this->filename = FilenameSanitizer::sanitize($filename);
        $this->originalFilename = FilenameSanitizer::cleanOriginal($originalFilename ?? $filename);
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Sanitized display name: no path components, no control or BiDi characters, at most 255 bytes.
     */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * Name as sent by the sender (decoded). Untrusted metadata, never use it to build a path.
     */
    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function isInline(): bool
    {
        return $this->isInline;
    }

    public function getContentId(): ?string
    {
        return $this->contentId;
    }

    /**
     * Whether a (still open) content stream is attached.
     */
    public function hasContent(): bool
    {
        return is_resource($this->stream);
    }

    /**
     * Return the underlying decoded stream (rewound when seekable).
     *
     * @return resource
     */
    public function getStream()
    {
        $stream = $this->requireStream();

        if (stream_get_meta_data($stream)['seekable']) {
            rewind($stream);
        }

        return $stream;
    }

    /**
     * Read the full content into a string. Only use for small attachments.
     */
    public function getContents(): string
    {
        $contents = stream_get_contents($this->getStream());
        if ($contents === false) {
            throw new RuntimeException("Failed reading contents of attachment '{$this->filename}'.");
        }

        return $contents;
    }

    /**
     * Stream attachment directly into a file path or destination stream.
     *
     * @param string|resource $destination Path chosen by the application (never derived from the attachment name) or a stream
     * @return int Bytes written
     */
    public function saveTo(mixed $destination): int
    {
        $source = $this->getStream();

        $closeOnExit = false;
        if (is_string($destination)) {
            $dir = dirname($destination);
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Directory '{$dir}' could not be created.");
            }

            $destStream = fopen($destination, 'w+b');
            if (! is_resource($destStream)) {
                throw new RuntimeException("Could not open destination file '{$destination}' for writing.");
            }
            $closeOnExit = true;
        } elseif (is_resource($destination)) {
            $destStream = $destination;
        } else {
            throw new InvalidArgumentException('Destination must be a file path string or stream resource.');
        }

        try {
            $bytes = stream_copy_to_stream($source, $destStream);
            if ($bytes === false) {
                throw new RuntimeException("Failed streaming attachment '{$this->filename}' to destination.");
            }

            return $bytes;
        } finally {
            if ($closeOnExit) {
                fclose($destStream);
            }
        }
    }

    /**
     * @return array{id: string, filename: string, original_filename: string, mime_type: string, size_bytes: int, is_inline: bool, content_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'original_filename' => $this->originalFilename,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->size,
            'is_inline' => $this->isInline,
            'content_id' => $this->contentId,
        ];
    }

    /**
     * @return resource
     */
    protected function requireStream()
    {
        if (! is_resource($this->stream)) {
            throw new RuntimeException("Attachment '{$this->filename}' has no readable content stream.");
        }

        return $this->stream;
    }
}
