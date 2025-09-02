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
class MessageRecipient
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $type = null;
    
    #[Field(type: 'string', length: 320, nullable: true)]
    public ?string $email = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $status = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $last_smtp_code = null;
    
    #[Field(type: 'string', length: 512, nullable: true)]
    public ?string $last_smtp_msg = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $delivered_at = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $bounced_at = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $complained_at = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $deferred_at = null;

    #[manyToOne(targetEntity: Message::class, inversedBy: 'messageRecipients')]
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getLast_smtp_code(): ?string
    {
        return $this->last_smtp_code;
    }

    public function setLast_smtp_code(?string $last_smtp_code): self
    {
        $this->last_smtp_code = $last_smtp_code;
        return $this;
    }

    public function getLast_smtp_msg(): ?string
    {
        return $this->last_smtp_msg;
    }

    public function setLast_smtp_msg(?string $last_smtp_msg): self
    {
        $this->last_smtp_msg = $last_smtp_msg;
        return $this;
    }

    public function getDelivered_at(): ?\DateTimeImmutable
    {
        return $this->delivered_at;
    }

    public function setDelivered_at(?\DateTimeImmutable $delivered_at): self
    {
        $this->delivered_at = $delivered_at;
        return $this;
    }

    public function getBounced_at(): ?\DateTimeImmutable
    {
        return $this->bounced_at;
    }

    public function setBounced_at(?\DateTimeImmutable $bounced_at): self
    {
        $this->bounced_at = $bounced_at;
        return $this;
    }

    public function getComplained_at(): ?\DateTimeImmutable
    {
        return $this->complained_at;
    }

    public function setComplained_at(?\DateTimeImmutable $complained_at): self
    {
        $this->complained_at = $complained_at;
        return $this;
    }

    public function getDeferred_at(): ?\DateTimeImmutable
    {
        return $this->deferred_at;
    }

    public function setDeferred_at(?\DateTimeImmutable $deferred_at): self
    {
        $this->deferred_at = $deferred_at;
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