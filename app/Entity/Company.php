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
class Company
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;
    /** @var User[] */
    #[ManyToMany(targetEntity: User::class, mappedBy: 'companies')]
    public array $users = [];

    #[Field(type: 'boolean', nullable: true)]
    public ?bool $status = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $createdAt = null;

    #[ManyToOne(targetEntity: Plan::class, inversedBy: 'companies')]
    public ?Plan $plan = null;
    #[Field(type: 'json', nullable: true)]
    public ?array $address = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $phone_number = null;
    /** @var IpPool[] */
    #[OneToMany(targetEntity: IpPool::class, mappedBy: 'company')]
    public array $ipPools = [];
    /** @var Message[] */
    #[OneToMany(targetEntity: Message::class, mappedBy: 'company')]
    public array $messages = [];
    /** @var Suppression[] */
    #[OneToMany(targetEntity: Suppression::class, mappedBy: 'company')]
    public array $suppressions = [];
    /** @var Webhook[] */
    #[OneToMany(targetEntity: Webhook::class, mappedBy: 'company')]
    public array $webhooks = [];
    /** @var Invoice[] */
    #[OneToMany(targetEntity: Invoice::class, mappedBy: 'company')]
    public array $invoices = [];
    /** @var UsageAggregate[] */
    #[OneToMany(targetEntity: UsageAggregate::class, mappedBy: 'company')]
    public array $usageAggregates = [];
    /** @var InboundRoute[] */
    #[OneToMany(targetEntity: InboundRoute::class, mappedBy: 'company')]
    public array $inboundRoutes = [];
    /** @var InboundMessage[] */
    #[OneToMany(targetEntity: InboundMessage::class, mappedBy: 'company')]
    public array $inboundMessages = [];
    /** @var Template[] */
    #[OneToMany(targetEntity: Template::class, mappedBy: 'company')]
    public array $templates = [];
    /** @var ListGroup[] */
    #[OneToMany(targetEntity: ListGroup::class, mappedBy: 'company')]
    public array $listGroups = [];
    /** @var Contact[] */
    #[OneToMany(targetEntity: Contact::class, mappedBy: 'company')]
    public array $contacts = [];
    /** @var Segment[] */
    #[OneToMany(targetEntity: Segment::class, mappedBy: 'company')]
    public array $segments = [];
    /** @var Campaign[] */
    #[OneToMany(targetEntity: Campaign::class, mappedBy: 'company')]
    public array $campaigns = [];
    /** @var Automation[] */
    #[OneToMany(targetEntity: Automation::class, mappedBy: 'company')]
    public array $automations = [];
    /** @var RateLimitCounter[] */
    #[OneToMany(targetEntity: RateLimitCounter::class, mappedBy: 'company')]
    public array $rateLimitCounters = [];
    /** @var Domain[] */
    #[OneToMany(targetEntity: Domain::class, mappedBy: 'company')]
    public array $domains = [];

    #[Field(type: 'string')]
    public string $hash;
    /** @var ApiKey[] */
    #[OneToMany(targetEntity: ApiKey::class, mappedBy: 'company')]
    public array $apiKeys = [];
    
     /** @var SmtpCredential[] */
    #[OneToMany(targetEntity: SmtpCredential::class, mappedBy: 'company')]
    public array $smtpCredentials = [];

    public function __construct()
    {
        $this->hash = bin2hex(random_bytes(32));
        $this->ipPools = [];
        $this->smtpCredentials = [];
        $this->messages = [];
        $this->suppressions = [];
        $this->webhooks = [];
        $this->invoices = [];
        $this->usageAggregates = [];
        $this->inboundRoutes = [];
        $this->inboundMessages = [];
        $this->templates = [];
        $this->listGroups = [];
        $this->contacts = [];
        $this->segments = [];
        $this->campaigns = [];
        $this->automations = [];
        $this->rateLimitCounters = [];
        $this->domains = [];
        $this->apiKeys = [];
    }

    public function getId(): int
    {
        return $this->id;
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

    public function getStatus(): ?bool
    {
        return $this->status;
    }

    public function setStatus(?bool $status): self
    {
        $this->status = $status;
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

    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    public function setPlan(?Plan $plan): self
    {
        $this->plan = $plan;
        return $this;
    }

    public function removePlan(): self
    {
        $this->plan = null;
        return $this;
    }

    public function getAddress(): ?array
    {
        return $this->address;
    }

    public function setAddress(?array $address): self
    {
        $this->address = $address;
        return $this;
    }

    public function getPhone_number(): ?string
    {
        return $this->phone_number;
    }

    public function setPhone_number(?string $phone_number): self
    {
        $this->phone_number = $phone_number;
        return $this;
    }

    public function addIpPool(IpPool $item): self
    {
        $this->ipPools[] = $item;
        return $this;
    }

    public function removeIpPool(IpPool $item): self
    {
        $this->ipPools = array_filter($this->ipPools, fn($i) => $i !== $item);
        return $this;
    }

    public function getIpPools(): array
    {
        return $this->ipPools;
    }

    public function addSmtpCredential(SmtpCredential $item): self
    {
        $this->smtpCredentials[] = $item;
        return $this;
    }

    public function removeSmtpCredential(SmtpCredential $item): self
    {
        $this->smtpCredentials = array_filter($this->smtpCredentials, fn($i) => $i !== $item);
        return $this;
    }

    public function getSmtpCredentials(): array
    {
        return $this->smtpCredentials;
    }

    public function addMessage(Message $item): self
    {
        $this->messages[] = $item;
        return $this;
    }

    public function removeMessage(Message $item): self
    {
        $this->messages = array_filter($this->messages, fn($i) => $i !== $item);
        return $this;
    }

    public function getMessages(): array
    {
        return $this->messages;
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

    public function addWebhook(Webhook $item): self
    {
        $this->webhooks[] = $item;
        return $this;
    }

    public function removeWebhook(Webhook $item): self
    {
        $this->webhooks = array_filter($this->webhooks, fn($i) => $i !== $item);
        return $this;
    }

    public function getWebhooks(): array
    {
        return $this->webhooks;
    }

    public function addInvoice(Invoice $item): self
    {
        $this->invoices[] = $item;
        return $this;
    }

    public function removeInvoice(Invoice $item): self
    {
        $this->invoices = array_filter($this->invoices, fn($i) => $i !== $item);
        return $this;
    }

    public function getInvoices(): array
    {
        return $this->invoices;
    }

    public function addUsageAggregate(UsageAggregate $item): self
    {
        $this->usageAggregates[] = $item;
        return $this;
    }

    public function removeUsageAggregate(UsageAggregate $item): self
    {
        $this->usageAggregates = array_filter($this->usageAggregates, fn($i) => $i !== $item);
        return $this;
    }

    public function getUsageAggregates(): array
    {
        return $this->usageAggregates;
    }

    public function addInboundRoute(InboundRoute $item): self
    {
        $this->inboundRoutes[] = $item;
        return $this;
    }

    public function removeInboundRoute(InboundRoute $item): self
    {
        $this->inboundRoutes = array_filter($this->inboundRoutes, fn($i) => $i !== $item);
        return $this;
    }

    public function getInboundRoutes(): array
    {
        return $this->inboundRoutes;
    }

    public function addInboundMessage(InboundMessage $item): self
    {
        $this->inboundMessages[] = $item;
        return $this;
    }

    public function removeInboundMessage(InboundMessage $item): self
    {
        $this->inboundMessages = array_filter($this->inboundMessages, fn($i) => $i !== $item);
        return $this;
    }

    public function getInboundMessages(): array
    {
        return $this->inboundMessages;
    }

    public function addTemplate(Template $item): self
    {
        $this->templates[] = $item;
        return $this;
    }

    public function removeTemplate(Template $item): self
    {
        $this->templates = array_filter($this->templates, fn($i) => $i !== $item);
        return $this;
    }

    public function getTemplates(): array
    {
        return $this->templates;
    }

    public function addListGroup(ListGroup $item): self
    {
        $this->listGroups[] = $item;
        return $this;
    }

    public function removeListGroup(ListGroup $item): self
    {
        $this->listGroups = array_filter($this->listGroups, fn($i) => $i !== $item);
        return $this;
    }

    public function getListGroups(): array
    {
        return $this->listGroups;
    }

    public function addContact(Contact $item): self
    {
        $this->contacts[] = $item;
        return $this;
    }

    public function removeContact(Contact $item): self
    {
        $this->contacts = array_filter($this->contacts, fn($i) => $i !== $item);
        return $this;
    }

    public function getContacts(): array
    {
        return $this->contacts;
    }

    public function addSegment(Segment $item): self
    {
        $this->segments[] = $item;
        return $this;
    }

    public function removeSegment(Segment $item): self
    {
        $this->segments = array_filter($this->segments, fn($i) => $i !== $item);
        return $this;
    }

    public function getSegments(): array
    {
        return $this->segments;
    }

    public function addCampaign(Campaign $item): self
    {
        $this->campaigns[] = $item;
        return $this;
    }

    public function removeCampaign(Campaign $item): self
    {
        $this->campaigns = array_filter($this->campaigns, fn($i) => $i !== $item);
        return $this;
    }

    public function getCampaigns(): array
    {
        return $this->campaigns;
    }

    public function addAutomation(Automation $item): self
    {
        $this->automations[] = $item;
        return $this;
    }

    public function removeAutomation(Automation $item): self
    {
        $this->automations = array_filter($this->automations, fn($i) => $i !== $item);
        return $this;
    }

    public function getAutomations(): array
    {
        return $this->automations;
    }

    public function addRateLimitCounter(RateLimitCounter $item): self
    {
        $this->rateLimitCounters[] = $item;
        return $this;
    }

    public function removeRateLimitCounter(RateLimitCounter $item): self
    {
        $this->rateLimitCounters = array_filter($this->rateLimitCounters, fn($i) => $i !== $item);
        return $this;
    }

    public function getRateLimitCounters(): array
    {
        return $this->rateLimitCounters;
    }

    public function addDomain(Domain $item): self
    {
        $this->domains[] = $item;
        return $this;
    }

    public function removeDomain(Domain $item): self
    {
        $this->domains = array_filter($this->domains, fn($i) => $i !== $item);
        return $this;
    }

    public function getDomains(): array
    {
        return $this->domains;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function setHash(string $hash): self
    {
        $this->hash = $hash;
        return $this;
    }

    public function addApiKey(ApiKey $item): self
    {
        $this->apiKeys[] = $item;
        return $this;
    }

    public function removeApiKey(ApiKey $item): self
    {
        $this->apiKeys = array_filter($this->apiKeys, fn($i) => $i !== $item);
        return $this;
    }

    public function getApiKeys(): array
    {
        return $this->apiKeys;
    }
}