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
class ListContact
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $subscribed_at = null;
    
    #[manyToOne(targetEntity: ListGroup::class, inversedBy: 'listContacts')]
    public ?ListGroup $listGroup = null;

    #[manyToOne(targetEntity: Contact::class, inversedBy: 'listContacts')]
    public ?Contact $contact = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSubscribed_at(): ?\DateTimeImmutable
    {
        return $this->subscribed_at;
    }

    public function setSubscribed_at(?\DateTimeImmutable $subscribed_at): self
    {
        $this->subscribed_at = $subscribed_at;
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