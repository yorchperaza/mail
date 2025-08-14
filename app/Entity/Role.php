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
class Role
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string')]
    public string $name;
    #[Field(type: 'string')]
    public string $slug;
    
     /** @var User[] */
    #[manyToMany(targetEntity: User::class, mappedBy: 'roles')]
    public array $users = [];

    public function __construct()
    {
        $this->users = [];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function addUser(User $item): self
    {
        $this->users[] = $item;
        return $this;
    }

    public function removeUser(User $item): self
    {
        $this->users = array_filter($this->users, fn($i) => $i !== $item);
        return $this;
    }

    public function getUsers(): array
    {
        return $this->users;
    }
}