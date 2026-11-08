<?php

declare(strict_types=1);

namespace Maivio\Imap\Protocol;

use InvalidArgumentException;

/**
 * The system flags that may be set or cleared.
 */
enum Flag: string
{
    case Seen = '\\Seen';
    case Flagged = '\\Flagged';
    case Answered = '\\Answered';
    case Deleted = '\\Deleted';
    case Draft = '\\Draft';

    public static function fromString(string $flag): self
    {
        $normalized = strtolower(ltrim(trim($flag), '\\'));

        foreach (self::cases() as $case) {
            if (strtolower(ltrim($case->value, '\\')) === $normalized) {
                return $case;
            }
        }

        throw new InvalidArgumentException('Unsupported IMAP flag. Allowed: \\Seen, \\Flagged, \\Answered, \\Deleted, \\Draft.');
    }

    /**
     * @param  self|string|list<self|string>  $flags
     * @return list<self>
     */
    public static function normalizeList(self|string|array $flags): array
    {
        $list = is_array($flags) ? $flags : [$flags];
        $result = [];

        foreach ($list as $flag) {
            $result[] = $flag instanceof self ? $flag : self::fromString($flag);
        }

        return array_values(array_unique($result, SORT_REGULAR));
    }
}
