<?php

declare(strict_types=1);

namespace Maivio\Imap\DTO;

use InvalidArgumentException;
use RuntimeException;

class Attachment
{
    /**
     * @param resource $stream
     */
    public function __construct(
        protected string $id,
        protected string $filename,
        protected string $mimeType,
        protected int $size,
        protected $stream,
        protected bool $isInline = false,
        protected ?string $contentId = null,
    ) {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Attachment stream must be a valid resource.');
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
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
     * Return the underlying raw stream.
     *
     * @return resource
     */
    public function getStream()
    {
        rewind($this->stream);

        return $this->stream;
    }

    /**
     * Read the full content into a string.
     */
    public function getContents(): string
    {
        rewind($this->stream);

        $contents = stream_get_contents($this->stream);
        if ($contents === false) {
            throw new RuntimeException("Failed reading contents of attachment '{$this->filename}'.");
        }

        return $contents;
    }

    /**
     * Stream attachment directly into a file path or destination stream.
     *
     * @param string|resource $destination
     * @return int Bytes written
     */
    public function saveTo(mixed $destination): int
    {
        rewind($this->stream);

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
            $bytes = stream_copy_to_stream($this->stream, $destStream);
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
     * @return array{id: string, filename: string, mime_type: string, size_bytes: int, is_inline: bool, content_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->size,
            'is_inline' => $this->isInline,
            'content_id' => $this->contentId,
        ];
    }
}
