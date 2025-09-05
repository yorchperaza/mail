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
class SegmentMembers
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'timestamp', nullable: true)]
    public ?\DateTimeImmutable $build_at = null;
    
    #[ManyToOne(targetEntity: Segment::class, inversedBy: 'segmentMembers')]
    public ?Segment $segment = null;

    #[ManyToOne(targetEntity: Contact::class, inversedBy: 'segmentMembers')]
    public ?Contact $contact = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getBuild_at(): ?\DateTimeImmutable
    {
        return $this->build_at;
    }

    public function setBuild_at(?\DateTimeImmutable $build_at): self
    {
        $this->build_at = $build_at;
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

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): self
    {
        $this->contact = $contact;
        return $this;
    }

    public function removeContact(): self
    {
        $this->contact = null;
        return $this;
    }
}