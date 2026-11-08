<?php

declare(strict_types=1);

namespace Maivio\Imap\Support;

use ValueError;

/**
 * Parser for structured header values such as "attachment; filename*0*=UTF-8''a%20; filename*1=b.pdf".
 *
 * Handles quoted strings (including ";" and escaped quotes inside), RFC 2231 continuations
 * (name*0, name*1) and extended values (name*=charset'lang'percent-encoded).
 */
final class HeaderParameters
{
    private const MAX_PARAMETERS = 64;

    /**
     * @return array{0: string, 1: array<string, string>} [lower-cased main value, parameters with decoded values]
     */
    public static function parse(string $header): array
    {
        $length = strlen($header);
        $pos = 0;

        $main = '';
        while ($pos < $length && $header[$pos] !== ';') {
            $main .= $header[$pos++];
        }

        /** @var array<string, array<int, array{value: string, encoded: bool}>> $collected */
        $collected = [];
        $count = 0;

        while ($pos < $length && $count < self::MAX_PARAMETERS) {
            $pos++; // skip ';'
            while ($pos < $length && ctype_space($header[$pos])) {
                $pos++;
            }

            $name = '';
            while ($pos < $length && $header[$pos] !== '=' && $header[$pos] !== ';') {
                $name .= $header[$pos++];
            }

            if ($pos >= $length || $header[$pos] === ';') {
                continue; // parameter without value
            }

            $pos++; // skip '='
            while ($pos < $length && ($header[$pos] === ' ' || $header[$pos] === "\t")) {
                $pos++;
            }

            $value = '';
            if ($pos < $length && $header[$pos] === '"') {
                $pos++;
                while ($pos < $length && $header[$pos] !== '"') {
                    if ($header[$pos] === '\\' && $pos + 1 < $length) {
                        $pos++;
                    }
                    $value .= $header[$pos++];
                }
                $pos++; // closing quote
                while ($pos < $length && $header[$pos] !== ';') {
                    $pos++;
                }
            } else {
                while ($pos < $length && $header[$pos] !== ';') {
                    $value .= $header[$pos++];
                }
                $value = trim($value);
            }

            $name = strtolower(trim($name));
            if ($name === '') {
                continue;
            }

            $count++;

            if (preg_match('/\A(.+?)\*(\d+)(\*)?\z/', $name, $m) === 1) {
                $collected[$m[1]][(int) $m[2]] = ['value' => $value, 'encoded' => ($m[3] ?? '') === '*'];
            } elseif (preg_match('/\A(.+)\*\z/', $name, $m) === 1) {
                $collected[$m[1]][0] = ['value' => $value, 'encoded' => true];
            } else {
                $collected[$name][-1] = ['value' => $value, 'encoded' => false];
            }
        }

        $params = [];
        foreach ($collected as $name => $segments) {
            $params[$name] = self::assemble($segments);
        }

        return [strtolower(trim($main)), $params];
    }

    /**
     * @param array<int, array{value: string, encoded: bool}> $segments
     */
    private static function assemble(array $segments): string
    {
        // A plain "name=" is only used when no RFC 2231 variant exists.
        if (count($segments) === 1 && isset($segments[-1])) {
            return $segments[-1]['value'];
        }

        unset($segments[-1]);
        ksort($segments);

        $charset = null;
        $data = '';
        $first = true;

        foreach ($segments as $segment) {
            $value = $segment['value'];

            if ($segment['encoded']) {
                if ($first && preg_match("/\\A([^']*)'[^']*'(.*)\\z/s", $value, $m) === 1) {
                    $charset = $m[1] !== '' ? $m[1] : null;
                    $value = $m[2];
                }
                $value = rawurldecode($value);
            }

            $data .= $value;
            $first = false;
        }

        return $charset !== null ? self::toUtf8($data, $charset) : $data;
    }

    private static function toUtf8(string $data, string $charset): string
    {
        $charset = strtoupper(trim($charset));
        if ($charset === 'UTF-8' || $charset === 'US-ASCII') {
            return $data;
        }

        try {
            return mb_convert_encoding($data, 'UTF-8', $charset);
        } catch (ValueError) {
            return $data;
        }
    }
}
