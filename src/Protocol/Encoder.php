<?php

declare(strict_types=1);

namespace Maivio\Imap\Protocol;

use InvalidArgumentException;

/**
 * Central encoding and validation of every user supplied value that ends up in an IMAP command.
 */
final class Encoder
{
    /**
     * Reject CR, LF and NUL. These characters can terminate the current command and start a new one.
     *
     * The value itself is never part of the exception message.
     *
     * @throws InvalidArgumentException
     */
    public static function assertSafe(#[\SensitiveParameter] string $value, string $name = 'argument'): void
    {
        if (strpbrk($value, "\r\n\0") !== false) {
            throw new InvalidArgumentException("Invalid IMAP {$name}: CR, LF and NUL characters are not allowed.");
        }
    }

    /**
     * Encode as quoted string when that is lossless, otherwise as literal.
     */
    public static function string(#[\SensitiveParameter] string $value, string $name = 'argument'): string|Literal
    {
        self::assertSafe($value, $name);

        if ($value === '') {
            return '""';
        }

        // Quoted strings are limited to printable 7-bit ASCII here; everything else becomes a literal.
        if (preg_match('/\A[\x20-\x7E]+\z/', $value) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return Literal::fromString($value);
    }
}
