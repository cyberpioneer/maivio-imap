<?php

declare(strict_types=1);

namespace Maivio\Imap\Search;

use DateTimeInterface;
use InvalidArgumentException;
use Maivio\Imap\Protocol\Command;
use Maivio\Imap\Protocol\Encoder;

/**
 * Typed, injection-safe builder for IMAP SEARCH criteria.
 *
 * Only whitelisted search keys exist; every value is sent as a quoted string or literal.
 * The builder is immutable: each method returns a new instance.
 *
 *     SearchCriteria::new()->from('john@example.com')->unseen()->since(new DateTimeImmutable('-7 days'))
 */
final class SearchCriteria
{
    /** @var list<array{key: string, type: 'flag'|'string'|'date'|'number'|'uid', value: string|int|null}> */
    private array $criteria = [];

    private function __construct() {}

    public static function new(): self
    {
        return new self;
    }

    public function all(): self
    {
        return $this->with('ALL', 'flag');
    }

    public function from(string $value): self
    {
        return $this->with('FROM', 'string', $value);
    }

    public function to(string $value): self
    {
        return $this->with('TO', 'string', $value);
    }

    public function cc(string $value): self
    {
        return $this->with('CC', 'string', $value);
    }

    public function subject(string $value): self
    {
        return $this->with('SUBJECT', 'string', $value);
    }

    /** Search in the message body only. */
    public function body(string $value): self
    {
        return $this->with('BODY', 'string', $value);
    }

    /** Search in headers and body. */
    public function text(string $value): self
    {
        return $this->with('TEXT', 'string', $value);
    }

    /** Internal date on or after the given day (time and zone are ignored by IMAP). */
    public function since(DateTimeInterface $date): self
    {
        return $this->with('SINCE', 'date', $this->formatDate($date));
    }

    /** Internal date strictly before the given day. */
    public function before(DateTimeInterface $date): self
    {
        return $this->with('BEFORE', 'date', $this->formatDate($date));
    }

    public function on(DateTimeInterface $date): self
    {
        return $this->with('ON', 'date', $this->formatDate($date));
    }

    public function seen(): self
    {
        return $this->with('SEEN', 'flag');
    }

    public function unseen(): self
    {
        return $this->with('UNSEEN', 'flag');
    }

    public function flagged(): self
    {
        return $this->with('FLAGGED', 'flag');
    }

    public function unflagged(): self
    {
        return $this->with('UNFLAGGED', 'flag');
    }

    public function answered(): self
    {
        return $this->with('ANSWERED', 'flag');
    }

    public function unanswered(): self
    {
        return $this->with('UNANSWERED', 'flag');
    }

    public function deleted(): self
    {
        return $this->with('DELETED', 'flag');
    }

    public function undeleted(): self
    {
        return $this->with('UNDELETED', 'flag');
    }

    public function larger(int $bytes): self
    {
        return $this->with('LARGER', 'number', $this->positive($bytes, 'LARGER'));
    }

    public function smaller(int $bytes): self
    {
        return $this->with('SMALLER', 'number', $this->positive($bytes, 'SMALLER'));
    }

    /**
     * @param  int  $from  First UID (>= 1)
     * @param  int|null  $to  Last UID, or null for "*" (highest UID)
     */
    public function uidRange(int $from, ?int $to = null): self
    {
        $this->positive($from, 'UID range start');
        if ($to !== null && $to < $from) {
            throw new InvalidArgumentException('UID range end must not be smaller than its start.');
        }

        return $this->with('UID', 'uid', $from.':'.($to ?? '*'));
    }

    public function isEmpty(): bool
    {
        return $this->criteria === [];
    }

    /**
     * Build the complete "UID SEARCH" command. Non-ASCII values automatically add "CHARSET UTF-8".
     */
    public function toCommand(): Command
    {
        $command = Command::make('UID SEARCH');

        foreach ($this->criteria as $criterion) {
            if ($criterion['type'] === 'string' && preg_match('/[^\x20-\x7E]/', (string) $criterion['value']) === 1) {
                $command->atom('CHARSET UTF-8');
                break;
            }
        }

        if ($this->criteria === []) {
            return $command->atom('ALL');
        }

        foreach ($this->criteria as $criterion) {
            $command->atom($criterion['key']);

            match ($criterion['type']) {
                'string' => $command->string((string) $criterion['value'], 'search value'),
                'date', 'number', 'uid' => $command->atom((string) $criterion['value']),
                'flag' => null,
            };
        }

        return $command;
    }

    /**
     * @param  'flag'|'string'|'date'|'number'|'uid'  $type
     */
    private function with(string $key, string $type, string|int|null $value = null): self
    {
        if (is_string($value) && $type === 'string') {
            Encoder::assertSafe($value, 'search value');
        }

        $clone = clone $this;
        $clone->criteria[] = ['key' => $key, 'type' => $type, 'value' => $value];

        return $clone;
    }

    private function formatDate(DateTimeInterface $date): string
    {
        // PHP's "M" is always the English month abbreviation (independent of the locale), as IMAP requires.
        return $date->format('d-M-Y');
    }

    private function positive(int $value, string $what): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$what} must be a positive integer.");
        }

        return $value;
    }
}
