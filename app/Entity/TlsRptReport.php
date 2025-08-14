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
class TlsRptReport
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $reporter = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $report_id = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $date_start = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $date_end = null;

    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $success_count = null;
    
    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $failure_count = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $details = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $received_at = null;

    #[manyToOne(targetEntity: Domain::class, inversedBy: 'tlsRptReports')]
    public ?Domain $domain = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getReporter(): ?string
    {
        return $this->reporter;
    }

    public function setReporter(?string $reporter): self
    {
        $this->reporter = $reporter;
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

    public function getSuccess_count(): ?int
    {
        return $this->success_count;
    }

    public function setSuccess_count(?int $success_count): self
    {
        $this->success_count = $success_count;
        return $this;
    }

    public function getFailure_count(): ?int
    {
        return $this->failure_count;
    }

    public function setFailure_count(?int $failure_count): self
    {
        $this->failure_count = $failure_count;
        return $this;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function setDetails(?array $details): self
    {
        $this->details = $details;
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