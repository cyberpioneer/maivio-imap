<?php

declare(strict_types=1);

namespace Maivio\Imap\Parser;

/**
 * Reads a stream in bounded chunks (at most CHUNK bytes per call), so even a hostile 1 GB "line" never sits in memory.
 *
 * @internal
 */
final class LineReader
{
    public const CHUNK = 8192;

    private bool $atLineStart = true;

    /**
     * @param  resource  $stream
     */
    public function __construct(private $stream) {}

    /**
     * @return array{data: string, lineStart: bool, eol: bool}|null null at end of stream
     */
    public function next(): ?array
    {
        $data = fgets($this->stream, self::CHUNK + 1);
        if ($data === false || $data === '') {
            return null;
        }

        $lineStart = $this->atLineStart;
        $eol = str_ends_with($data, "\n");
        $this->atLineStart = $eol;

        return ['data' => $data, 'lineStart' => $lineStart, 'eol' => $eol];
    }
}
