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
class Campaign
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $subject = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $send_mode = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $scheduled_at = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $target = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $status = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;
    #[manyToOne(targetEntity: Company::class, inversedBy: 'campaigns')]
    public ?Company $company = null;

    #[manyToOne(targetEntity: Template::class, inversedBy: 'campaigns')]
    public ?Template $template = null;
    #[manyToOne(targetEntity: Domain::class, inversedBy: 'campaigns')]
    public ?Domain $domain = null;

    #[manyToOne(targetEntity: ListGroup::class, inversedBy: 'campaigns')]
    public ?ListGroup $listGroup = null;
    #[manyToOne(targetEntity: Segment::class, inversedBy: 'campaigns')]
    public ?Segment $segment = null;

    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $sent = null;
    
    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $delivered = null;

    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $opens = null;
    
    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $clicks = null;

    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $bounces = null;
    
    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $complaints = null;

    public function __construct()
    {
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

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getSend_mode(): ?string
    {
        return $this->send_mode;
    }

    public function setSend_mode(?string $send_mode): self
    {
        $this->send_mode = $send_mode;
        return $this;
    }

    public function getScheduled_at(): ?\DateTimeImmutable
    {
        return $this->scheduled_at;
    }

    public function setScheduled_at(?\DateTimeImmutable $scheduled_at): self
    {
        $this->scheduled_at = $scheduled_at;
        return $this;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function setTarget(?string $target): self
    {
        $this->target = $target;
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

    public function getTemplate(): ?Template
    {
        return $this->template;
    }

    public function setTemplate(?Template $template): self
    {
        $this->template = $template;
        return $this;
    }

    public function removeTemplate(): self
    {
        $this->template = null;
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

    public function getListGroup(): ?ListGroup
    {
        return $this->listGroup;
    }

    public function setListGroup(?ListGroup $listGroup): self
    {
        $this->listGroup = $listGroup;
        return $this;
    }

    public function removeListGroup(): self
    {
        $this->listGroup = null;
        return $this;
    }

    public function getSegment(): ?Segment
    {
        return $this->segment;
    }

    public function setSegment(?Segment $segment): self
    {
        $this->segment = $segment;
        return $this;
    }

    public function removeSegment(): self
    {
        $this->segment = null;
        return $this;
    }

    public function getSent(): ?int
    {
        return $this->sent;
    }

    public function setSent(?int $sent): self
    {
        $this->sent = $sent;
        return $this;
    }

    public function getDelivered(): ?int
    {
        return $this->delivered;
    }

    public function setDelivered(?int $delivered): self
    {
        $this->delivered = $delivered;
        return $this;
    }

    public function getOpens(): ?int
    {
        return $this->opens;
    }

    public function setOpens(?int $opens): self
    {
        $this->opens = $opens;
        return $this;
    }

    public function getClicks(): ?int
    {
        return $this->clicks;
    }

    public function setClicks(?int $clicks): self
    {
        $this->clicks = $clicks;
        return $this;
    }

    public function getBounces(): ?int
    {
        return $this->bounces;
    }

    public function setBounces(?int $bounces): self
    {
        $this->bounces = $bounces;
        return $this;
    }

    public function getComplaints(): ?int
    {
        return $this->complaints;
    }

    public function setComplaints(?int $complaints): self
    {
        $this->complaints = $complaints;
        return $this;
    }
}