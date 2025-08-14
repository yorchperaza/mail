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
class ReputationSample
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $provider = null;
    
    #[Field(type: 'tinyInt', nullable: true)]
    public ?int $score = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $sampled_at = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $notes = null;

    #[manyToOne(targetEntity: IpPool::class, inversedBy: 'reputationSamples')]
    public ?IpPool $ipPool = null;
    
    #[manyToOne(targetEntity: Domain::class, inversedBy: 'reputationSamples')]
    public ?Domain $domain = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): self
    {
        $this->score = $score;
        return $this;
    }

    public function getSampled_at(): ?\DateTimeImmutable
    {
        return $this->sampled_at;
    }

    public function setSampled_at(?\DateTimeImmutable $sampled_at): self
    {
        $this->sampled_at = $sampled_at;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getIpPool(): ?IpPool
    {
        return $this->ipPool;
    }

    public function setIpPool(?IpPool $ipPool): self
    {
        $this->ipPool = $ipPool;
        return $this;
    }

    public function removeIpPool(): self
    {
        $this->ipPool = null;
        return $this;
    }

    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    public function setDomain(?Domain $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    public function removeDomain(): self
    {
        $this->domain = null;
        return $this;
    }
}