<?php

declare(strict_types=1);

namespace Maivio\Imap\Protocol;

use InvalidArgumentException;

/**
 * Builder for one IMAP command (without tag).
 *
 * Every dynamic value must pass through string()/mailbox()/base64(); atom() only accepts a strict
 * whitelist of characters (no quotes, braces, CR, LF, NUL), so a command can never be extended by input.
 */
final class Command
{
    /** Characters allowed in a trusted, library-controlled token such as "(\Seen)" or "1:5,9" or "BODY.PEEK[]<0.100>". */
    private const ATOM_PATTERN = '/\A[A-Za-z0-9 .:,*\\\\+\-\[\]()<>_$=]+\z/';

    /** @var list<string|Literal> */
    private array $parts = [];

    private bool $sensitive = false;

    private function __construct(private readonly string $verb)
    {
    }

    public static function make(string $verb): self
    {
        if (preg_match('/\A[A-Z]+(?: [A-Z0-9\-]+)*\z/', $verb) !== 1) {
            throw new InvalidArgumentException('Invalid IMAP command verb.');
        }

        return new self($verb);
    }

    /**
     * Append a trusted token. Never pass user input here.
     */
    public function atom(string $atom): self
    {
        if (preg_match(self::ATOM_PATTERN, $atom) !== 1) {
            throw new InvalidArgumentException('Invalid IMAP atom.');
        }

        $this->parts[] = $atom;

        return $this;
    }

    /**
     * Append a user string as quoted string or literal.
     */
    public function string(#[\SensitiveParameter] string $value, string $name = 'argument'): self
    {
        $this->parts[] = Encoder::string($value, $name);

        return $this;
    }

    /**
     * Append a mailbox name (UTF-8 in, modified UTF-7 out).
     */
    public function mailbox(string $name, string $label = 'mailbox name'): self
    {
        Encoder::assertSafe($name, $label);
        $this->parts[] = Encoder::string(Mutf7::encode($name), $label);

        return $this;
    }

    /**
     * Append an already base64 encoded value (SASL payloads) and mark the command as sensitive.
     */
    public function base64(#[\SensitiveParameter] string $encoded): self
    {
        if (preg_match('/\A[A-Za-z0-9+\/=]*\z/', $encoded) !== 1) {
            throw new InvalidArgumentException('Invalid base64 payload.');
        }

        $this->parts[] = $encoded === '' ? '=' : $encoded;
        $this->sensitive = true;

        return $this;
    }

    public function literal(Literal $literal): self
    {
        $this->parts[] = $literal;

        return $this;
    }

    /**
     * Hide all arguments in debug output (LOGIN, AUTHENTICATE).
     */
    public function sensitive(): self
    {
        $this->sensitive = true;

        return $this;
    }

    public function isSensitive(): bool
    {
        return $this->sensitive;
    }

    public function verb(): string
    {
        return $this->verb;
    }

    /**
     * @return list<string|Literal>
     */
    public function parts(): array
    {
        return $this->parts;
    }

    public function hasLiteral(): bool
    {
        foreach ($this->parts as $part) {
            if ($part instanceof Literal) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log-safe representation: sensitive commands are redacted and literal payloads are never printed.
     */
    public function describe(): string
    {
        if ($this->sensitive) {
            return $this->verb.' ***';
        }

        $out = [$this->verb];
        foreach ($this->parts as $part) {
            $out[] = $part instanceof Literal ? '{'.$part->length.'}' : $part;
        }

        return implode(' ', $out);
    }
}
