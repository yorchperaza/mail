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
class WebhookDelivery
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'tinyInt', nullable: true)]
    public ?int $attempt = null;
    
    #[Field(type: 'enum', nullable: true)]
    public ?string $status = null;

    #[Field(type: 'smallInt', nullable: true)]
    public ?int $response_code = null;
    
    #[Field(type: 'integer', nullable: true)]
    public ?int $response_time_ms = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $payload_snapshot = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $next_retry_at = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;
    
    #[manyToOne(targetEntity: Webhook::class, inversedBy: 'webhookDeliveries')]
    public ?Webhook $webhook = null;

    #[manyToOne(targetEntity: Event::class, inversedBy: 'webhookDeliveries')]
    public ?Event $event = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getAttempt(): ?int
    {
        return $this->attempt;
    }

    public function setAttempt(?int $attempt): self
    {
        $this->attempt = $attempt;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getResponse_code(): ?int
    {
        return $this->response_code;
    }

    public function setResponse_code(?int $response_code): self
    {
        $this->response_code = $response_code;
        return $this;
    }

    public function getResponse_time_ms(): ?int
    {
        return $this->response_time_ms;
    }

    public function setResponse_time_ms(?int $response_time_ms): self
    {
        $this->response_time_ms = $response_time_ms;
        return $this;
    }

    public function getPayload_snapshot(): ?array
    {
        return $this->payload_snapshot;
    }

    public function setPayload_snapshot(?array $payload_snapshot): self
    {
        $this->payload_snapshot = $payload_snapshot;
        return $this;
    }

    public function getNext_retry_at(): ?\DateTimeImmutable
    {
        return $this->next_retry_at;
    }

    public function setNext_retry_at(?\DateTimeImmutable $next_retry_at): self
    {
        $this->next_retry_at = $next_retry_at;
        return $this;
    }

    public function getCreated_at(): ?\DateTimeImmutable
    {
        return $this->created_at;
    }

    public function setCreated_at(?\DateTimeImmutable $created_at): self
    {
        $this->created_at = $created_at;
        return $this;
    }

    public function getWebhook(): ?Webhook
    {
        return $this->webhook;
    }

    public function setWebhook(?Webhook $webhook): self
    {
        $this->webhook = $webhook;
        return $this;
    }

    public function removeWebhook(): self
    {
        $this->webhook = null;
        return $this;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): self
    {
        $this->event = $event;
        return $this;
    }

    public function removeEvent(): self
    {
        $this->event = null;
        return $this;
    }
}