<?php

declare(strict_types=1);

namespace Maivio\Imap\DTO;

class Folder
{
    /**
     * @param  list<string>  $attributes
     */
    public function __construct(
        protected string $name,
        protected string $rawName,
        protected string $delimiter = '/',
        protected array $attributes = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getRawName(): string
    {
        return $this->rawName;
    }

    public function getDelimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * @return list<string>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function hasAttribute(string $attribute): bool
    {
        $normalized = strtolower(ltrim($attribute, '\\'));
        foreach ($this->attributes as $attr) {
            if (strtolower(ltrim($attr, '\\')) === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function isTrash(): bool
    {
        if ($this->hasAttribute('trash')) {
            return true;
        }

        $lower = strtolower($this->name);

        return in_array($lower, ['trash', 'papierkorb', 'deleted messages', 'gelöschte elemente'], true)
            || str_ends_with($lower, '/trash')
            || str_ends_with($lower, '.trash')
            || str_ends_with($lower, '/papierkorb');
    }

    public function isSent(): bool
    {
        if ($this->hasAttribute('sent')) {
            return true;
        }

        $lower = strtolower($this->name);

        return in_array($lower, ['sent', 'gesendet', 'sent messages', 'gesendete elemente'], true)
            || str_ends_with($lower, '/sent')
            || str_ends_with($lower, '.sent');
    }

    public function isDrafts(): bool
    {
        if ($this->hasAttribute('drafts')) {
            return true;
        }

        $lower = strtolower($this->name);

        return in_array($lower, ['drafts', 'entwürfe'], true)
            || str_ends_with($lower, '/drafts')
            || str_ends_with($lower, '.drafts');
    }

    public function isJunk(): bool
    {
        if ($this->hasAttribute('junk') || $this->hasAttribute('spam')) {
            return true;
        }

        $lower = strtolower($this->name);

        return in_array($lower, ['junk', 'spam'], true)
            || str_ends_with($lower, '/junk')
            || str_ends_with($lower, '/spam');
    }

    public function isArchive(): bool
    {
        if ($this->hasAttribute('archive')) {
            return true;
        }

        $lower = strtolower($this->name);

        return in_array($lower, ['archive', 'archiv'], true)
            || str_ends_with($lower, '/archive')
            || str_ends_with($lower, '.archive');
    }

    /**
     * @return array{name: string, raw_name: string, delimiter: string, attributes: list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'raw_name' => $this->rawName,
            'delimiter' => $this->delimiter,
            'attributes' => $this->attributes,
        ];
    }
}
