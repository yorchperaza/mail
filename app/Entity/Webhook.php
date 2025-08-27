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
class Webhook
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'text', nullable: true)]
    public ?string $url = null;
    #[Field(type: 'json', nullable: true)]
    public ?array $events = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $secret = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $status = null;

    #[Field(type: 'smallInt', nullable: true)]
    public ?int $batch_size = null;
    #[Field(type: 'tinyInt', nullable: true)]
    public ?int $max_retries = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $retry_backoff = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;

    #[manyToOne(targetEntity: Company::class, inversedBy: 'webhooks')]
    public ?Company $company = null;
    
     /** @var WebhookDelivery[] */
    #[oneToMany(targetEntity: WebhookDelivery::class, mappedBy: 'webhook')]
    public array $webhookDeliveries = [];

    public function __construct()
    {
        $this->webhookDeliveries = [];
    }

    public function getId(): int
    {
        return $this->id;
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

    public function getEvents(): ?array
    {
        return $this->events;
    }

    public function setEvents(?array $events): self
    {
        $this->events = $events;
        return $this;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function setSecret(?string $secret): self
    {
        $this->secret = $secret;
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

    public function getBatch_size(): ?int
    {
        return $this->batch_size;
    }

    public function setBatch_size(?int $batch_size): self
    {
        $this->batch_size = $batch_size;
        return $this;
    }

    public function getMax_retries(): ?int
    {
        return $this->max_retries;
    }

    public function setMax_retries(?int $max_retries): self
    {
        $this->max_retries = $max_retries;
        return $this;
    }

    public function getRetry_backoff(): ?string
    {
        return $this->retry_backoff;
    }

    public function setRetry_backoff(?string $retry_backoff): self
    {
        $this->retry_backoff = $retry_backoff;
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

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): self
    {
        $this->company = $company;
        return $this;
    }

    public function removeCompany(): self
    {
        $this->company = null;
        return $this;
    }

    public function addWebhookDelivery(WebhookDelivery $item): self
    {
        $this->webhookDeliveries[] = $item;
        return $this;
    }

    public function removeWebhookDelivery(WebhookDelivery $item): self
    {
        $this->webhookDeliveries = array_filter($this->webhookDeliveries, fn($i) => $i !== $item);
        return $this;
    }

    public function getWebhookDeliveries(): array
    {
        return $this->webhookDeliveries;
    }
}