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
class DmarcAggregate
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $org_name = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $report_id = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $date_start = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $date_end = null;

    #[Field(type: 'enum', nullable: true)]
    public ?string $adkim = null;
    
    #[Field(type: 'enum', nullable: true)]
    public ?string $aspf = null;

    #[Field(type: 'enum', nullable: true)]
    public ?string $p = null;
    
    #[Field(type: 'enum', nullable: true)]
    public ?string $sp = null;

    #[Field(type: 'tinyInt', nullable: true)]
    public ?int $pct = null;
    
    #[Field(type: 'json', nullable: true)]
    public ?array $rows = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $received_at = null;
    
    #[manyToOne(targetEntity: Domain::class, inversedBy: 'dmarcAggregates')]
    public ?Domain $domain = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getOrg_name(): ?string
    {
        return $this->org_name;
    }

    public function setOrg_name(?string $org_name): self
    {
        $this->org_name = $org_name;
        return $this;
    }

    public function getReport_id(): ?string
    {
        return $this->report_id;
    }

    public function setReport_id(?string $report_id): self
    {
        $this->report_id = $report_id;
        return $this;
    }

    public function getDate_start(): ?\DateTimeImmutable
    {
        return $this->date_start;
    }

    public function setDate_start(?\DateTimeImmutable $date_start): self
    {
        $this->date_start = $date_start;
        return $this;
    }

    public function getDate_end(): ?\DateTimeImmutable
    {
        return $this->date_end;
    }

    public function setDate_end(?\DateTimeImmutable $date_end): self
    {
        $this->date_end = $date_end;
        return $this;
    }

    public function getAdkim(): ?string
    {
        return $this->adkim;
    }

    public function setAdkim(?string $adkim): self
    {
        $this->adkim = $adkim;
        return $this;
    }

    public function getAspf(): ?string
    {
        return $this->aspf;
    }

    public function setAspf(?string $aspf): self
    {
        $this->aspf = $aspf;
        return $this;
    }

    public function getP(): ?string
    {
        return $this->p;
    }

    public function setP(?string $p): self
    {
        $this->p = $p;
        return $this;
    }

    public function getSp(): ?string
    {
        return $this->sp;
    }

    public function setSp(?string $sp): self
    {
        $this->sp = $sp;
        return $this;
    }

    public function getPct(): ?int
    {
        return $this->pct;
    }

    public function setPct(?int $pct): self
    {
        $this->pct = $pct;
        return $this;
    }

    public function getRows(): ?array
    {
        return $this->rows;
    }

    public function setRows(?array $rows): self
    {
        $this->rows = $rows;
        return $this;
    }

    public function getReceived_at(): ?\DateTimeImmutable
    {
        return $this->received_at;
    }

    public function setReceived_at(?\DateTimeImmutable $received_at): self
    {
        $this->received_at = $received_at;
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