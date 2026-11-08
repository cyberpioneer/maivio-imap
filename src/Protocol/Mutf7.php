<?php

declare(strict_types=1);

namespace Maivio\Imap\Protocol;

use InvalidArgumentException;

/**
 * Modified UTF-7 as used for IMAP mailbox names (RFC 3501 section 5.1.3).
 */
final class Mutf7
{
    /**
     * @throws InvalidArgumentException when the input is not valid UTF-8
     */
    public static function encode(string $utf8): string
    {
        if ($utf8 === '') {
            return '';
        }

        if (! mb_check_encoding($utf8, 'UTF-8')) {
            throw new InvalidArgumentException('Mailbox name must be valid UTF-8.');
        }

        // Runs of printable ASCII (0x20-0x7E) stay literal ("&" becomes "&-"), every other run is base64 UTF-16BE.
        $result = preg_replace_callback(
            '/[^\x20-\x7E]+|&/u',
            static function (array $match): string {
                if ($match[0] === '&') {
                    return '&-';
                }

                $utf16 = mb_convert_encoding($match[0], 'UTF-16BE', 'UTF-8');

                return '&'.rtrim(strtr(base64_encode($utf16), '/', ','), '=').'-';
            },
            $utf8
        );

        if ($result === null) {
            throw new InvalidArgumentException('Mailbox name could not be encoded.');
        }

        return $result;
    }

    /**
     * @throws InvalidArgumentException when the input is not valid modified UTF-7
     */
    public static function decode(string $encoded): string
    {
        if ($encoded === '' || ! str_contains($encoded, '&')) {
            return $encoded;
        }

        $decoded = preg_replace_callback(
            '/&([^&\-]*)(-)?/',
            static function (array $match): string {
                if (! isset($match[2])) {
                    throw new InvalidArgumentException('Unterminated modified UTF-7 sequence.');
                }

                if ($match[1] === '') {
                    return '&';
                }

                if (preg_match('/\A[A-Za-z0-9+,]+\z/', $match[1]) !== 1) {
                    throw new InvalidArgumentException('Invalid modified UTF-7 sequence.');
                }

                $binary = base64_decode(strtr($match[1], ',', '/'), true);
                if ($binary === false || strlen($binary) % 2 !== 0) {
                    throw new InvalidArgumentException('Invalid modified UTF-7 sequence.');
                }

                $utf8 = mb_convert_encoding($binary, 'UTF-8', 'UTF-16BE');
                if (! mb_check_encoding($utf8, 'UTF-8')) {
                    throw new InvalidArgumentException('Invalid modified UTF-7 sequence.');
                }

                return $utf8;
            },
            $encoded
        );

        if ($decoded === null) {
            throw new InvalidArgumentException('Invalid modified UTF-7 sequence.');
        }

        return $decoded;
    }
}
