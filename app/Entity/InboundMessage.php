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
class InboundMessage
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', length: 320, nullable: true)]
    public ?string $from_email = null;
    #[Field(type: 'string', length: 998, nullable: true)]
    public ?string $subject = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $raw_mime_ref = null;
    #[Field(type: 'decimal', nullable: true)]
    public ?float $spam_score = null;

    #[Field(type: 'enum', nullable: true)]
    public ?string $dkim_result = null;
    #[Field(type: 'enum', nullable: true)]
    public ?string $dmarc_result = null;

    #[Field(type: 'enum', nullable: true)]
    public ?string $arc_result = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $received_at = null;

    #[manyToOne(targetEntity: Company::class, inversedBy: 'inboundMessages')]
    public ?Company $company = null;
    #[manyToOne(targetEntity: Domain::class, inversedBy: 'inboundMessages')]
    public ?Domain $domain = null;
    
     /** @var InboundPart[] */
    #[oneToMany(targetEntity: InboundPart::class, mappedBy: 'inboundMessage')]
    public array $inboundParts = [];

    public function __construct()
    {
        $this->inboundParts = [];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getFrom_email(): ?string
    {
        return $this->from_email;
    }

    public function setFrom_email(?string $from_email): self
    {
        $this->from_email = $from_email;
        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getRaw_mime_ref(): ?string
    {
        return $this->raw_mime_ref;
    }

    public function setRaw_mime_ref(?string $raw_mime_ref): self
    {
        $this->raw_mime_ref = $raw_mime_ref;
        return $this;
    }

    public function getSpam_score(): ?float
    {
        return $this->spam_score;
    }

    public function setSpam_score(?float $spam_score): self
    {
        $this->spam_score = $spam_score;
        return $this;
    }

    public function getDkim_result(): ?string
    {
        return $this->dkim_result;
    }

    public function setDkim_result(?string $dkim_result): self
    {
        $this->dkim_result = $dkim_result;
        return $this;
    }

    public function getDmarc_result(): ?string
    {
        return $this->dmarc_result;
    }

    public function setDmarc_result(?string $dmarc_result): self
    {
        $this->dmarc_result = $dmarc_result;
        return $this;
    }

    public function getArc_result(): ?string
    {
        return $this->arc_result;
    }

    public function setArc_result(?string $arc_result): self
    {
        $this->arc_result = $arc_result;
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

    public function addInboundPart(InboundPart $item): self
    {
        $this->inboundParts[] = $item;
        return $this;
    }

    public function removeInboundPart(InboundPart $item): self
    {
        $this->inboundParts = array_filter($this->inboundParts, fn($i) => $i !== $item);
        return $this;
    }

    public function getInboundParts(): array
    {
        return $this->inboundParts;
    }
}