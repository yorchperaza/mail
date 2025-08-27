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
class IpPool
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;
    #[Field(type: 'json', nullable: true)]
    public ?array $ips = null;

    #[Field(type: 'integer', nullable: true)]
    public ?int $reputation_score = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $warmup_state = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;
    #[manyToOne(targetEntity: Company::class, inversedBy: 'ipPools')]
    public ?Company $company = null;
    /** @var SmtpCredential[] */
    #[oneToMany(targetEntity: SmtpCredential::class, mappedBy: 'ipPool')]
    public array $smtpCredentials = [];
    /** @var Message[] */
    #[oneToMany(targetEntity: Message::class, mappedBy: 'ipPool')]
    public array $messages = [];
    
     /** @var ReputationSample[] */
    #[oneToMany(targetEntity: ReputationSample::class, mappedBy: 'ipPool')]
    public array $reputationSamples = [];

    public function __construct()
    {
        $this->smtpCredentials = [];
        $this->messages = [];
        $this->reputationSamples = [];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getIps(): ?array
    {
        return $this->ips;
    }

    public function setIps(?array $ips): self
    {
        $this->ips = $ips;
        return $this;
    }

    public function getReputation_score(): ?int
    {
        return $this->reputation_score;
    }

    public function setReputation_score(?int $reputation_score): self
    {
        $this->reputation_score = $reputation_score;
        return $this;
    }

    public function getWarmup_state(): ?string
    {
        return $this->warmup_state;
    }

    public function setWarmup_state(?string $warmup_state): self
    {
        $this->warmup_state = $warmup_state;
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

    public function addSmtpCredential(SmtpCredential $item): self
    {
        $this->smtpCredentials[] = $item;
        return $this;
    }

    public function removeSmtpCredential(SmtpCredential $item): self
    {
        $this->smtpCredentials = array_filter($this->smtpCredentials, fn($i) => $i !== $item);
        return $this;
    }

    public function getSmtpCredentials(): array
    {
        return $this->smtpCredentials;
    }

    public function addMessage(Message $item): self
    {
        $this->messages[] = $item;
        return $this;
    }

    public function removeMessage(Message $item): self
    {
        $this->messages = array_filter($this->messages, fn($i) => $i !== $item);
        return $this;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function addReputationSample(ReputationSample $item): self
    {
        $this->reputationSamples[] = $item;
        return $this;
    }

    public function removeReputationSample(ReputationSample $item): self
    {
        $this->reputationSamples = array_filter($this->reputationSamples, fn($i) => $i !== $item);
        return $this;
    }

    public function getReputationSamples(): array
    {
        return $this->reputationSamples;
    }
}