<?php

declare(strict_types=1);

namespace Maivio\Imap\DTO;

use DateTimeImmutable;

class Message
{
    /**
     * @param  array<string, string>  $headers
     * @param  list<Attachment>  $attachments
     */
    public function __construct(
        protected ?int $uid,
        protected ?string $messageId,
        protected ?string $subject,
        protected string $senderEmail,
        protected ?string $senderName,
        protected ?DateTimeImmutable $date,
        protected ?string $bodyText,
        protected ?string $bodyHtml,
        protected array $headers = [],
        protected array $attachments = [],
    ) {}

    public function getUid(): ?int
    {
        return $this->uid;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getSenderEmail(): string
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

    public function getBodyText(): ?string
    {
        return $this->bodyText;
    }

    public function getBodyHtml(): ?string
    {
        return $this->bodyHtml;
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
     * @return list<Attachment>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function hasAttachments(): bool
    {
        return ! empty($this->attachments);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'message_id' => $this->messageId,
            'subject' => $this->subject,
            'sender_email' => $this->senderEmail,
            'sender_name' => $this->senderName,
            'date' => $this->date?->format(DateTimeImmutable::ATOM),
            'body_text' => $this->bodyText,
            'body_html' => $this->bodyHtml,
            'attachments' => array_map(fn (Attachment $att): array => $att->toArray(), $this->attachments),
        ];
    }
}
