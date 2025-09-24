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
class PasswordResetToken
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $tokenHash = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $createdAt = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $expiresAt = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $usedAt = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $requestIp = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $requestUa = null;

    #[ManyToOne(targetEntity: User::class, inversedBy: 'passwordResetTokens')]
    public ?User $user = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $email = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTokenHash(): ?string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(?string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(?\DateTimeImmutable $usedAt): self
    {
        $this->usedAt = $usedAt;
        return $this;
    }

    public function getRequestIp(): ?string
    {
        return $this->requestIp;
    }

    public function setRequestIp(?string $requestIp): self
    {
        $this->requestIp = $requestIp;
        return $this;
    }

    public function getRequestUa(): ?string
    {
        return $this->requestUa;
    }

    public function setRequestUa(?string $requestUa): self
    {
        $this->requestUa = $requestUa;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function removeUser(): self
    {
        $this->user = null;
        return $this;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
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
}