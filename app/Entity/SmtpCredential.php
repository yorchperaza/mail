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
class SmtpCredential
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $username_prefix = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $password_hash = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $scopes = null;
    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $max_msgs_min = null;

    #[Field(type: 'integer', nullable: true, default: 100)]
    public ?int $max_rcpt_msg = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;

    #[ManyToOne(targetEntity: IpPool::class, inversedBy: 'smtpCredentials')]
    public ?IpPool $ippool = null;
    
    #[ManyToOne(targetEntity: Company::class, inversedBy: 'smtpCredentials')]
    public ?Company $company = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUsername_prefix(): ?string
    {
        return $this->username_prefix;
    }

    public function setUsername_prefix(?string $username_prefix): self
    {
        $this->username_prefix = $username_prefix;
        return $this;
    }

    public function getPassword_hash(): ?string
    {
        return $this->password_hash;
    }

    public function setPassword_hash(?string $password_hash): self
    {
        $this->password_hash = $password_hash;
        return $this;
    }

    public function getScopes(): ?array
    {
        return $this->scopes;
    }

    public function setScopes(?array $scopes): self
    {
        $this->scopes = $scopes;
        return $this;
    }

    public function getMax_msgs_min(): ?int
    {
        return $this->max_msgs_min;
    }

    public function setMax_msgs_min(?int $max_msgs_min): self
    {
        $this->max_msgs_min = $max_msgs_min;
        return $this;
    }

    public function getMax_rcpt_msg(): ?int
    {
        return $this->max_rcpt_msg;
    }

    public function setMax_rcpt_msg(?int $max_rcpt_msg): self
    {
        $this->max_rcpt_msg = $max_rcpt_msg;
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

    public function getIpPool(): ?IpPool
    {
        return $this->ippool;
    }

    public function setIpPool(?IpPool $ippool): self
    {
        $this->ippool = $ippool;
        return $this;
    }

    public function removeIpPool(): self
    {
        $this->ippool = null;
        return $this;
    }
}