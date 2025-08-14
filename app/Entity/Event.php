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
class Event
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;
    
     /** @var WebhookDelivery[] */
    #[oneToMany(targetEntity: WebhookDelivery::class, mappedBy: 'event')]
    public array $webhookDeliveries = [];

    public function __construct()
    {
        $this->webhookDeliveries = [];
    }

    public function getId(): int
    {
        return $this->id;
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