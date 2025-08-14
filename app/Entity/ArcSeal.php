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
class ArcSeal
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'tinyInt', nullable: true)]
    public ?int $instance = null;
    
    #[Field(type: 'enum', nullable: true)]
    public ?string $cv = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $seal_result = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $timestamp = null;

    #[manyToOne(targetEntity: Message::class, inversedBy: 'arcSeals')]
    public ?Message $message = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getInstance(): ?int
    {
        return $this->instance;
    }

    public function setInstance(?int $instance): self
    {
        $this->instance = $instance;
        return $this;
    }

    public function getCv(): ?string
    {
        return $this->cv;
    }

    public function setCv(?string $cv): self
    {
        $this->cv = $cv;
        return $this;
    }

    public function getSeal_result(): ?string
    {
        return $this->seal_result;
    }

    public function setSeal_result(?string $seal_result): self
    {
        $this->seal_result = $seal_result;
        return $this;
    }

    public function getTimestamp(): ?\DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function setTimestamp(?\DateTimeImmutable $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function removeMessage(): self
    {
        $this->message = null;
        return $this;
    }
}