<?php

declare(strict_types=1);

namespace Maivio\Imap\Protocol;

use InvalidArgumentException;

enum Encryption: string
{
    /** Implicit TLS (TLS from the first byte, usually port 993). Accepts "ssl" and "tls". */
    case Tls = 'tls';

    /** Plain connection upgraded with the STARTTLS command (usually port 143). */
    case StartTls = 'starttls';

    /** No encryption at all. Requires the explicit option allow_insecure. */
    case None = 'none';

    /**
     * @throws InvalidArgumentException for any unknown value (there is never a silent plaintext fallback)
     */
    public static function fromString(string $value): self
    {
        return match (strtolower(trim($value))) {
            'ssl', 'tls' => self::Tls,
            'starttls' => self::StartTls,
            'none' => self::None,
            default => throw new InvalidArgumentException(sprintf(
                "Unsupported IMAP encryption mode '%s'. Supported: ssl, tls, starttls, none.",
                preg_replace('/[^\x20-\x7E]/', '?', substr($value, 0, 32))
            )),
        };
    }
}
