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
class SegmentBuild
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'timestamp', nullable: true)]
    public ?\DateTimeImmutable $built_at = null;
    
    #[Field(type: 'integer', nullable: true)]
    public ?int $matches = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $hash = null;
    
    #[ManyToOne(targetEntity: Segment::class, inversedBy: 'segmentBuilds')]
    public ?Segment $segment = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getBuilt_at(): ?\DateTimeImmutable
    {
        return $this->built_at;
    }

    public function setBuilt_at(?\DateTimeImmutable $built_at): self
    {
        $this->built_at = $built_at;
        return $this;
    }

    public function getMatches(): ?int
    {
        return $this->matches;
    }

    public function setMatches(?int $matches): self
    {
        $this->matches = $matches;
        return $this;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(?string $hash): self
    {
        $this->hash = $hash;
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
}