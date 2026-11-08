<?php

declare(strict_types=1);

namespace Maivio\Imap\DTO;

use DateTimeImmutable;

/**
 * Lightweight message metadata fetched without the body (UID, FLAGS, INTERNALDATE, RFC822.SIZE and headers).
 */
class MessageSummary
{
    /**
     * @param list<string> $flags e.g. ['\Seen', '\Flagged']
     * @param array<string, string> $headers Lower-cased header name => decoded-on-demand raw value
     */
    public function __construct(
        protected int $uid,
        protected array $flags,
        protected ?DateTimeImmutable $internalDate,
        protected int $size,
        protected ?string $messageId,
        protected ?string $subject,
        protected ?string $senderEmail,
        protected ?string $senderName,
        protected ?DateTimeImmutable $date,
        protected array $headers = [],
    ) {
    }

    public function getUid(): int
    {
        return $this->uid;
    }

    /**
     * @return list<string>
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    public function hasFlag(string $flag): bool
    {
        $needle = strtolower(ltrim($flag, '\\'));
        foreach ($this->flags as $candidate) {
            if (strtolower(ltrim($candidate, '\\')) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function isSeen(): bool
    {
        return $this->hasFlag('Seen');
    }

    public function isFlagged(): bool
    {
        return $this->hasFlag('Flagged');
    }

    public function isAnswered(): bool
    {
        return $this->hasFlag('Answered');
    }

    public function isDeleted(): bool
    {
        return $this->hasFlag('Deleted');
    }

    public function isDraft(): bool
    {
        return $this->hasFlag('Draft');
    }

    public function getInternalDate(): ?DateTimeImmutable
    {
        return $this->internalDate;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getSenderEmail(): ?string
    {
        return $this->senderEmail;
    }

    public function getSenderName(): ?string
    {
        return $this->senderName;
    }

    public function getDate(): ?DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array{uid: int, flags: list<string>, internal_date: string|null, size: int, message_id: string|null, subject: string|null, sender_email: string|null, sender_name: string|null, date: string|null}
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'flags' => $this->flags,
            'internal_date' => $this->internalDate?->format(DateTimeImmutable::ATOM),
            'size' => $this->size,
            'message_id' => $this->messageId,
            'subject' => $this->subject,
            'sender_email' => $this->senderEmail,
            'sender_name' => $this->senderName,
            'date' => $this->date?->format(DateTimeImmutable::ATOM),
        ];
    }
}
