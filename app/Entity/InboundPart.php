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
class InboundPart
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $content_type = null;
    
    #[Field(type: 'integer', nullable: true)]
    public ?int $size_bytes = null;

    #[Field(type: 'enum', nullable: true)]
    public ?string $disposition = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $filename = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $blob_ref = null;
    
    #[manyToOne(targetEntity: InboundMessage::class, inversedBy: 'inboundParts')]
    public ?InboundMessage $inboundMessage = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getContent_type(): ?string
    {
        return $this->content_type;
    }

    public function setContent_type(?string $content_type): self
    {
        $this->content_type = $content_type;
        return $this;
    }

    public function getSize_bytes(): ?int
    {
        return $this->size_bytes;
    }

    public function setSize_bytes(?int $size_bytes): self
    {
        $this->size_bytes = $size_bytes;
        return $this;
    }

    public function getDisposition(): ?string
    {
        return $this->disposition;
    }

    public function setDisposition(?string $disposition): self
    {
        $this->disposition = $disposition;
        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): self
    {
        $this->filename = $filename;
        return $this;
    }

    public function getBlob_ref(): ?string
    {
        return $this->blob_ref;
    }

    public function setBlob_ref(?string $blob_ref): self
    {
        $this->blob_ref = $blob_ref;
        return $this;
    }

    public function getInboundMessage(): ?InboundMessage
    {
        return $this->inboundMessage;
    }

    public function setInboundMessage(?InboundMessage $inboundMessage): self
    {
        $this->inboundMessage = $inboundMessage;
        return $this;
    }

    public function removeInboundMessage(): self
    {
        $this->inboundMessage = null;
        return $this;
    }
}