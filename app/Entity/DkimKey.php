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
class DkimKey
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $selector = null;
    
    #[Field(type: 'text', nullable: true)]
    public ?string $public_key_pem = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $private_key_ref = null;
    
    #[Field(type: 'boolean', nullable: true)]
    public ?bool $active = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $rotated_at = null;

    #[manyToOne(targetEntity: Domain::class, inversedBy: 'dkimKeys')]
    public ?Domain $domain = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $txt_value = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSelector(): ?string
    {
        return $this->selector;
    }

    public function setSelector(?string $selector): self
    {
        $this->selector = $selector;
        return $this;
    }

    public function getPublic_key_pem(): ?string
    {
        return $this->public_key_pem;
    }

    public function setPublic_key_pem(?string $public_key_pem): self
    {
        $this->public_key_pem = $public_key_pem;
        return $this;
    }

    public function getPrivate_key_ref(): ?string
    {
        return $this->private_key_ref;
    }

    public function setPrivate_key_ref(?string $private_key_ref): self
    {
        $this->private_key_ref = $private_key_ref;
        return $this;
    }

    public function getActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(?bool $active): self
    {
        $this->active = $active;
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

    public function getRotated_at(): ?\DateTimeImmutable
    {
        return $this->rotated_at;
    }

    public function setRotated_at(?\DateTimeImmutable $rotated_at): self
    {
        $this->rotated_at = $rotated_at;
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

    public function getTxt_value(): ?string
    {
        return $this->txt_value;
    }

    public function setTxt_value(?string $txt_value): self
    {
        $this->txt_value = $txt_value;
        return $this;
    }
}