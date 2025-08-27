<?php

declare(strict_types=1);

namespace App\Entity;

use MonkeysLegion\Entity\Attributes\Entity;
use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\OneToOne;
use MonkeysLegion\Entity\Attributes\OneToMany;
use MonkeysLegion\Entity\Attributes\ManyToOne;
use MonkeysLegion\Entity\Attributes\ManyToMany;
use MonkeysLegion\Entity\Attributes\JoinTable;

#[Entity]
class MessageEvent
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', length: 320, nullable: true)]
    public ?string $recipient_email = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $event = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $occurred_at = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $ip = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $user_agent = null;
    #[Field(type: 'text', nullable: true)]
    public ?string $url = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $smtp_code = null;
    #[Field(type: 'text', nullable: true)]
    public ?string $smtp_response = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $provider_hint = null;
    #[manyToOne(targetEntity: Message::class, inversedBy: 'messageEvents')]
    public ?Message $message = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $payload = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRecipient_email(): ?string
    {
        return $this->recipient_email;
    }

    public function setRecipient_email(?string $recipient_email): self
    {
        $this->recipient_email = $recipient_email;
        return $this;
    }

    public function getEvent(): ?string
    {
        return $this->event;
    }

    public function setEvent(?string $event): self
    {
        $this->event = $event;
        return $this;
    }

    public function getOccurred_at(): ?\DateTimeImmutable
    {
        return $this->occurred_at;
    }

    public function setOccurred_at(?\DateTimeImmutable $occurred_at): self
    {
        $this->occurred_at = $occurred_at;
        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): self
    {
        $this->ip = $ip;
        return $this;
    }

    public function getUser_agent(): ?string
    {
        return $this->user_agent;
    }

    public function setUser_agent(?string $user_agent): self
    {
        $this->user_agent = $user_agent;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function getSmtp_code(): ?string
    {
        return $this->smtp_code;
    }

    public function setSmtp_code(?string $smtp_code): self
    {
        $this->smtp_code = $smtp_code;
        return $this;
    }

    public function getSmtp_response(): ?string
    {
        return $this->smtp_response;
    }

    public function setSmtp_response(?string $smtp_response): self
    {
        $this->smtp_response = $smtp_response;
        return $this;
    }

    public function getProvider_hint(): ?string
    {
        return $this->provider_hint;
    }

    public function setProvider_hint(?string $provider_hint): self
    {
        $this->provider_hint = $provider_hint;
        return $this;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function removeMessage(): self
    {
        $this->message = null;
        return $this;
    }

    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;
        return $this;
    }
}