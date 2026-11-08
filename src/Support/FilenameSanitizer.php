<?php

declare(strict_types=1);

namespace Maivio\Imap\Support;

/**
 * Turns an attacker controlled attachment name into a harmless display name.
 *
 * The result is only a path-free name. It must still never be used to build a file path by the caller;
 * the library never does so itself.
 */
final class FilenameSanitizer
{
    public const DEFAULT_NAME = 'attachment.bin';

    /** Control characters (C0, DEL, C1), line/paragraph separators, zero-width and all BiDi formatting characters. */
    private const INVISIBLE_PATTERN = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{206F}\x{FEFF}\x{FFF9}-\x{FFFB}]/u';

    public static function sanitize(string $name, int $maxBytes = 255): string
    {
        $name = self::toValidUtf8($name);
        $name = preg_replace(self::INVISIBLE_PATTERN, '', $name) ?? '';

        // basename() semantics for both separator styles and drive prefixes.
        $name = str_replace('\\', '/', $name);
        $slash = strrpos($name, '/');
        if ($slash !== false) {
            $name = substr($name, $slash + 1);
        }
        $name = preg_replace('/\A[A-Za-z]:/', '', $name) ?? '';

        // Characters that are reserved on common file systems.
        $name = preg_replace('/[<>:"|?*]/', '_', $name) ?? '';

        $name = trim($name, " \t.");

        if ($name === '') {
            return self::DEFAULT_NAME;
        }

        return self::truncate($name, max(8, $maxBytes));
    }

    /**
     * Same cleanup of invisible characters and invalid UTF-8, but keeps path parts. Used for the "original" metadata value.
     */
    public static function cleanOriginal(string $name, int $maxBytes = 1024): string
    {
        $name = self::toValidUtf8($name);
        $name = preg_replace(self::INVISIBLE_PATTERN, '', $name) ?? '';

        return mb_strcut($name, 0, $maxBytes, 'UTF-8');
    }

    private static function toValidUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
    }

    private static function truncate(string $name, int $maxBytes): string
    {
        if (strlen($name) <= $maxBytes) {
            return $name;
        }

        $dot = strrpos($name, '.');
        $extension = '';
        if ($dot !== false && $dot > 0 && strlen($name) - $dot <= 16) {
            $extension = substr($name, $dot);
            $name = substr($name, 0, $dot);
        }

        $name = mb_strcut($name, 0, $maxBytes - strlen($extension), 'UTF-8');
        $name = rtrim($name, " .");

        return ($name === '' ? 'attachment' : $name).$extension;
    }
}
