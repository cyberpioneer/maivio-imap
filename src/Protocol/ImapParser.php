<?php

declare(strict_types=1);

namespace Maivio\Imap\Protocol;

use Maivio\Imap\Exceptions\ImapConnectionException;

/**
 * Minimal tokenizer for IMAP response data (atoms, quoted strings, literals, parenthesized lists, NIL).
 *
 * Literals must be inlined as "{n}\r\n<n bytes>" (this is how ImapClient hands over untagged responses).
 */
final class ImapParser
{
    private const MAX_DEPTH = 32;

    /**
     * @return list<mixed> Tokens: string, null (NIL) or nested list
     *
     * @throws ImapConnectionException on malformed data
     */
    public static function parse(string $input): array
    {
        $pos = 0;
        $length = strlen($input);
        $tokens = [];

        while (true) {
            self::skipSpace($input, $pos, $length);
            if ($pos >= $length) {
                break;
            }

            if ($input[$pos] === ')') {
                throw new ImapConnectionException('Malformed IMAP response: unbalanced parenthesis.');
            }

            $tokens[] = self::readValue($input, $pos, $length, 0);
        }

        return $tokens;
    }

    private static function skipSpace(string $input, int &$pos, int $length): void
    {
        while ($pos < $length && ($input[$pos] === ' ' || $input[$pos] === "\t" || $input[$pos] === "\r" || $input[$pos] === "\n")) {
            $pos++;
        }
    }

    private static function readValue(string $input, int &$pos, int $length, int $depth): mixed
    {
        $char = $input[$pos];

        if ($char === '(') {
            if ($depth >= self::MAX_DEPTH) {
                throw new ImapConnectionException('Malformed IMAP response: nesting too deep.');
            }

            $pos++;
            $list = [];

            while (true) {
                self::skipSpace($input, $pos, $length);
                if ($pos >= $length) {
                    throw new ImapConnectionException('Malformed IMAP response: unterminated list.');
                }

                if ($input[$pos] === ')') {
                    $pos++;

                    return $list;
                }

                $list[] = self::readValue($input, $pos, $length, $depth + 1);
            }
        }

        if ($char === '"') {
            $pos++;
            $buffer = '';

            while ($pos < $length) {
                $current = $input[$pos];
                if ($current === '\\' && $pos + 1 < $length) {
                    $buffer .= $input[$pos + 1];
                    $pos += 2;

                    continue;
                }

                if ($current === '"') {
                    $pos++;

                    return $buffer;
                }

                $buffer .= $current;
                $pos++;
            }

            throw new ImapConnectionException('Malformed IMAP response: unterminated quoted string.');
        }

        if ($char === '{' && preg_match('/\G\{(\d+)\}\r?\n/', $input, $match, 0, $pos) === 1) {
            $size = (int) $match[1];
            $pos += strlen($match[0]);

            if ($pos + $size > $length) {
                throw new ImapConnectionException('Malformed IMAP response: literal exceeds available data.');
            }

            $data = substr($input, $pos, $size);
            $pos += $size;

            return $data;
        }

        $start = $pos;
        while ($pos < $length) {
            $current = $input[$pos];

            if ($current === ' ' || $current === '(' || $current === ')' || $current === "\r" || $current === "\n") {
                break;
            }

            if ($current === '[') {
                $close = strpos($input, ']', $pos);
                if ($close === false) {
                    throw new ImapConnectionException('Malformed IMAP response: unterminated bracket.');
                }

                $pos = $close + 1;

                continue;
            }

            $pos++;
        }

        $atom = substr($input, $start, $pos - $start);

        return strcasecmp($atom, 'NIL') === 0 ? null : $atom;
    }
}
