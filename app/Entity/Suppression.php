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
class Suppression
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $type = null;
    
    #[Field(type: 'string', length: 320, nullable: true)]
    public ?string $email = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $reason = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $expires_at = null;
    
    #[manyToOne(targetEntity: Company::class, inversedBy: 'suppressions')]
    public ?Company $company = null;

    #[manyToOne(targetEntity: Message::class, inversedBy: 'suppressions')]
    public ?Message $message = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;
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

    public function getExpires_at(): ?\DateTimeImmutable
    {
        return $this->expires_at;
    }

    public function setExpires_at(?\DateTimeImmutable $expires_at): self
    {
        $this->expires_at = $expires_at;
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