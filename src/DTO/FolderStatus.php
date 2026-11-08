<?php

declare(strict_types=1);

namespace Maivio\Imap\DTO;

/**
 * Result of the IMAP STATUS command (no SELECT required).
 */
class FolderStatus
{
    public function __construct(
        protected string $name,
        protected int $messages = 0,
        protected ?int $uidNext = null,
        protected ?int $uidValidity = null,
        protected ?int $unseen = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getMessages(): int
    {
        return $this->messages;
    }

    public function getUidNext(): ?int
    {
        return $this->uidNext;
    }

    public function getUidValidity(): ?int
    {
        return $this->uidValidity;
    }

    public function getUnseen(): ?int
    {
        return $this->unseen;
    }

    /**
     * @return array{name: string, messages: int, uid_next: int|null, uid_validity: int|null, unseen: int|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'messages' => $this->messages,
            'uid_next' => $this->uidNext,
            'uid_validity' => $this->uidValidity,
            'unseen' => $this->unseen,
        ];
    }
}
