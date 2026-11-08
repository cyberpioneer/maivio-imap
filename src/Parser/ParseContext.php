<?php

declare(strict_types=1);

namespace Maivio\Imap\Parser;

use Closure;
use Maivio\Imap\DTO\Attachment;

/**
 * Mutable state of one parse() run.
 *
 * @internal
 */
final class ParseContext
{
    public int $partCount = 0;

    public ?string $bodyText = null;

    public ?string $bodyHtml = null;

    /** @var list<Attachment> */
    public array $attachments = [];

    /**
     * Last boundary line met while reading: index into the boundary stack and whether it was the closing delimiter.
     *
     * @var array{index: int, close: bool}|null
     */
    public ?array $hit = null;

    /**
     * @param  (Closure(Attachment): resource)|null  $attachmentSink
     */
    public function __construct(public readonly ?Closure $attachmentSink = null) {}
}
