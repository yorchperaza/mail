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
class Message
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', length: 320, nullable: true)]
    public ?string $from_email = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $from_name = null;

    #[Field(type: 'string', length: 320, nullable: true)]
    public ?string $reply_to = null;
    #[Field(type: 'string', length: 998, nullable: true)]
    public ?string $subject = null;

    #[Field(type: 'longText', nullable: true)]
    public ?string $html_body = null;
    #[Field(type: 'longText', nullable: true)]
    public ?string $text_body = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $headers = null;
    #[Field(type: 'boolean', nullable: true)]
    public ?bool $click_tracking = null;

    #[Field(type: 'boolean', nullable: true)]
    public ?bool $open_tracking = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $message_id = null;

    #[Field(type: 'integer', nullable: true)]
    public ?int $bytes = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $mime_blob_ref = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $attachments = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $queued_at = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $sent_at = null;
    #[Field(type: 'enum', nullable: true)]
    public ?string $final_state = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;
    #[manyToOne(targetEntity: Company::class, inversedBy: 'messages')]
    public ?Company $company = null;

    #[manyToOne(targetEntity: Domain::class, inversedBy: 'messages')]
    public ?Domain $domain = null;
    #[manyToOne(targetEntity: IpPool::class, inversedBy: 'messages')]
    public ?IpPool $ipPool = null;
    /** @var MessageRecipient[] */
    #[oneToMany(targetEntity: MessageRecipient::class, mappedBy: 'message')]
    public array $messageRecipients = [];
    /** @var MessageEvent[] */
    #[oneToMany(targetEntity: MessageEvent::class, mappedBy: 'message')]
    public array $messageEvents = [];
    /** @var Suppression[] */
    #[oneToMany(targetEntity: Suppression::class, mappedBy: 'message')]
    public array $suppressions = [];
    
     /** @var ArcSeal[] */
    #[oneToMany(targetEntity: ArcSeal::class, mappedBy: 'message')]
    public array $arcSeals = [];

    public function __construct()
    {
        $this->messageRecipients = [];
        $this->messageEvents = [];
        $this->suppressions = [];
        $this->arcSeals = [];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getFrom_email(): ?string
    {
        return $this->from_email;
    }

    public function setFrom_email(?string $from_email): self
    {
        $this->from_email = $from_email;
        return $this;
    }

    public function getFrom_name(): ?string
    {
        return $this->from_name;
    }

    public function setFrom_name(?string $from_name): self
    {
        $this->from_name = $from_name;
        return $this;
    }

    public function getReply_to(): ?string
    {
        return $this->reply_to;
    }

    public function setReply_to(?string $reply_to): self
    {
        $this->reply_to = $reply_to;
        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getHtml_body(): ?string
    {
        return $this->html_body;
    }

    public function setHtml_body(?string $html_body): self
    {
        $this->html_body = $html_body;
        return $this;
    }

    public function getText_body(): ?string
    {
        return $this->text_body;
    }

    public function setText_body(?string $text_body): self
    {
        $this->text_body = $text_body;
        return $this;
    }

    public function getHeaders(): ?array
    {
        return $this->headers;
    }

    public function setHeaders(?array $headers): self
    {
        $this->headers = $headers;
        return $this;
    }

    public function getClick_tracking(): ?bool
    {
        return $this->click_tracking;
    }

    public function setClick_tracking(?bool $click_tracking): self
    {
        $this->click_tracking = $click_tracking;
        return $this;
    }

    public function getOpen_tracking(): ?bool
    {
        return $this->open_tracking;
    }

    public function setOpen_tracking(?bool $open_tracking): self
    {
        $this->open_tracking = $open_tracking;
        return $this;
    }

    public function getMessage_id(): ?string
    {
        return $this->message_id;
    }

    public function setMessage_id(?string $message_id): self
    {
        $this->message_id = $message_id;
        return $this;
    }

    public function getBytes(): ?int
    {
        return $this->bytes;
    }

    public function setBytes(?int $bytes): self
    {
        $this->bytes = $bytes;
        return $this;
    }

    public function getMime_blob_ref(): ?string
    {
        return $this->mime_blob_ref;
    }

    public function setMime_blob_ref(?string $mime_blob_ref): self
    {
        $this->mime_blob_ref = $mime_blob_ref;
        return $this;
    }

    public function getAttachments(): ?array
    {
        return $this->attachments;
    }

    public function setAttachments(?array $attachments): self
    {
        $this->attachments = $attachments;
        return $this;
    }

    public function getQueued_at(): ?\DateTimeImmutable
    {
        return $this->queued_at;
    }

    public function setQueued_at(?\DateTimeImmutable $queued_at): self
    {
        $this->queued_at = $queued_at;
        return $this;
    }

    public function getSent_at(): ?\DateTimeImmutable
    {
        return $this->sent_at;
    }

    public function setSent_at(?\DateTimeImmutable $sent_at): self
    {
        $this->sent_at = $sent_at;
        return $this;
    }

    public function getFinal_state(): ?string
    {
        return $this->final_state;
    }

    public function setFinal_state(?string $final_state): self
    {
        $this->final_state = $final_state;
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

    public function getIpPool(): ?IpPool
    {
        return $this->ipPool;
    }

    public function setIpPool(?IpPool $ipPool): self
    {
        $this->ipPool = $ipPool;
        return $this;
    }

    public function removeIpPool(): self
    {
        $this->ipPool = null;
        return $this;
    }

    public function addMessageRecipient(MessageRecipient $item): self
    {
        $this->messageRecipients[] = $item;
        return $this;
    }

    public function removeMessageRecipient(MessageRecipient $item): self
    {
        $this->messageRecipients = array_filter($this->messageRecipients, fn($i) => $i !== $item);
        return $this;
    }

    public function getMessageRecipients(): array
    {
        return $this->messageRecipients;
    }

    public function addMessageEvent(MessageEvent $item): self
    {
        $this->messageEvents[] = $item;
        return $this;
    }

    public function removeMessageEvent(MessageEvent $item): self
    {
        $this->messageEvents = array_filter($this->messageEvents, fn($i) => $i !== $item);
        return $this;
    }

    public function getMessageEvents(): array
    {
        return $this->messageEvents;
    }

    public function addSuppression(Suppression $item): self
    {
        $this->suppressions[] = $item;
        return $this;
    }

    public function removeSuppression(Suppression $item): self
    {
        $this->suppressions = array_filter($this->suppressions, fn($i) => $i !== $item);
        return $this;
    }

    public function getSuppressions(): array
    {
        return $this->suppressions;
    }

    public function addArcSeal(ArcSeal $item): self
    {
        $this->arcSeals[] = $item;
        return $this;
    }

    public function removeArcSeal(ArcSeal $item): self
    {
        $this->arcSeals = array_filter($this->arcSeals, fn($i) => $i !== $item);
        return $this;
    }

    public function getArcSeals(): array
    {
        return $this->arcSeals;
    }
}