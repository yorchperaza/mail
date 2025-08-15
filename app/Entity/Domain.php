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
class Domain
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FAILED = 'failed';
    private const ALLOWED = [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_FAILED];
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $domain = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $txt_name = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $txt_value = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $spf_expected = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $dmarc_expected = null;
    #[Field(type: 'json', nullable: true)]
    public ?array $mx_expected = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $verified_at = null;
    #[Field(type: 'boolean', nullable: true)]
    public ?bool $require_tls = null;

    #[Field(type: 'boolean', nullable: true)]
    public ?bool $arc_sign = null;
    #[Field(type: 'boolean', nullable: true)]
    public ?bool $bimi_enabled = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;
    #[Field(type: 'string', length: 20, nullable: true, default: 'pending')]
    public ?string $status = 'pending';
    /** @var DkimKey[] */
    #[OneToMany(targetEntity: DkimKey::class, mappedBy: 'domain')]
    public array $dkimKeys = [];
    /** @var Message[] */
    #[OneToMany(targetEntity: Message::class, mappedBy: 'domain')]
    public array $messages = [];
    /** @var TlsRptReport[] */
    #[OneToMany(targetEntity: TlsRptReport::class, mappedBy: 'domain')]
    public array $tlsRptReports = [];
    /** @var MtaStsPolicy[] */
    #[OneToMany(targetEntity: MtaStsPolicy::class, mappedBy: 'domain')]
    public array $mtaStsPolicies = [];
    /** @var DmarcAggregate[] */
    #[OneToMany(targetEntity: DmarcAggregate::class, mappedBy: 'domain')]
    public array $dmarcAggregates = [];
    /** @var BimiRecord[] */
    #[OneToMany(targetEntity: BimiRecord::class, mappedBy: 'domain')]
    public array $bimiRecords = [];
    /** @var ReputationSample[] */
    #[OneToMany(targetEntity: ReputationSample::class, mappedBy: 'domain')]
    public array $reputationSamples = [];
    /** @var InboundRoute[] */
    #[OneToMany(targetEntity: InboundRoute::class, mappedBy: 'domain')]
    public array $inboundRoutes = [];
    /** @var InboundMessage[] */
    #[OneToMany(targetEntity: InboundMessage::class, mappedBy: 'domain')]
    public array $inboundMessages = [];
    /** @var Campaign[] */
    #[OneToMany(targetEntity: Campaign::class, mappedBy: 'domain')]
    public array $campaigns = [];

    #[ManyToOne(targetEntity: Company::class, inversedBy: 'domains')]
    public ?Company $company = null;
    
    #[Field(type: 'json', nullable: true)]
    public ?array $verification_report = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $last_checked_at = null;

    public function __construct()
    {
        $this->dkimKeys = [];
        $this->messages = [];
        $this->tlsRptReports = [];
        $this->mtaStsPolicies = [];
        $this->dmarcAggregates = [];
        $this->bimiRecords = [];
        $this->reputationSamples = [];
        $this->inboundRoutes = [];
        $this->inboundMessages = [];
        $this->campaigns = [];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(?string $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    public function getTxt_name(): ?string
    {
        return $this->txt_name;
    }

    public function setTxt_name(?string $txt_name): self
    {
        $this->txt_name = $txt_name;
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

    public function getSpf_expected(): ?string
    {
        return $this->spf_expected;
    }

    public function setSpf_expected(?string $spf_expected): self
    {
        $this->spf_expected = $spf_expected;
        return $this;
    }

    public function getDmarc_expected(): ?string
    {
        return $this->dmarc_expected;
    }

    public function setDmarc_expected(?string $dmarc_expected): self
    {
        $this->dmarc_expected = $dmarc_expected;
        return $this;
    }

    public function getMx_expected(): ?array
    {
        return $this->mx_expected;
    }

    public function setMx_expected(?array $mx_expected): self
    {
        $this->mx_expected = $mx_expected;
        return $this;
    }

    public function getVerified_at(): ?\DateTimeImmutable
    {
        return $this->verified_at;
    }

    public function setVerified_at(?\DateTimeImmutable $verified_at): self
    {
        $this->verified_at = $verified_at;
        return $this;
    }

    public function getRequire_tls(): ?bool
    {
        return $this->require_tls;
    }

    public function setRequire_tls(?bool $require_tls): self
    {
        $this->require_tls = $require_tls;
        return $this;
    }

    public function getArc_sign(): ?bool
    {
        return $this->arc_sign;
    }

    public function setArc_sign(?bool $arc_sign): self
    {
        $this->arc_sign = $arc_sign;
        return $this;
    }

    public function getBimi_enabled(): ?bool
    {
        return $this->bimi_enabled;
    }

    public function setBimi_enabled(?bool $bimi_enabled): self
    {
        $this->bimi_enabled = $bimi_enabled;
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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        if ($status !== null && !in_array($status, self::ALLOWED, true)) {
            throw new \InvalidArgumentException("Invalid status '{$status}'");
        }
        $this->status = $status;
        return $this;
    }

    public function addDkimKey(DkimKey $item): self
    {
        $this->dkimKeys[] = $item;
        return $this;
    }

    public function removeDkimKey(DkimKey $item): self
    {
        $this->dkimKeys = array_filter($this->dkimKeys, fn($i) => $i !== $item);
        return $this;
    }

    public function getDkimKeys(): array
    {
        return $this->dkimKeys;
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

    public function addTlsRptReport(TlsRptReport $item): self
    {
        $this->tlsRptReports[] = $item;
        return $this;
    }

    public function removeTlsRptReport(TlsRptReport $item): self
    {
        $this->tlsRptReports = array_filter($this->tlsRptReports, fn($i) => $i !== $item);
        return $this;
    }

    public function getTlsRptReports(): array
    {
        return $this->tlsRptReports;
    }

    public function addMtaStsPolicy(MtaStsPolicy $item): self
    {
        $this->mtaStsPolicies[] = $item;
        return $this;
    }

    public function removeMtaStsPolicy(MtaStsPolicy $item): self
    {
        $this->mtaStsPolicies = array_filter($this->mtaStsPolicies, fn($i) => $i !== $item);
        return $this;
    }

    public function getMtaStsPolicies(): array
    {
        return $this->mtaStsPolicies;
    }

    public function addDmarcAggregate(DmarcAggregate $item): self
    {
        $this->dmarcAggregates[] = $item;
        return $this;
    }

    public function removeDmarcAggregate(DmarcAggregate $item): self
    {
        $this->dmarcAggregates = array_filter($this->dmarcAggregates, fn($i) => $i !== $item);
        return $this;
    }

    public function getDmarcAggregates(): array
    {
        return $this->dmarcAggregates;
    }

    public function addBimiRecord(BimiRecord $item): self
    {
        $this->bimiRecords[] = $item;
        return $this;
    }

    public function removeBimiRecord(BimiRecord $item): self
    {
        $this->bimiRecords = array_filter($this->bimiRecords, fn($i) => $i !== $item);
        return $this;
    }

    public function getBimiRecords(): array
    {
        return $this->bimiRecords;
    }

    public function addReputationSample(ReputationSample $item): self
    {
        $this->reputationSamples[] = $item;
        return $this;
    }

    public function removeReputationSample(ReputationSample $item): self
    {
        $this->reputationSamples = array_filter($this->reputationSamples, fn($i) => $i !== $item);
        return $this;
    }

    public function getReputationSamples(): array
    {
        return $this->reputationSamples;
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

    public function getVerification_report(): ?array
    {
        return $this->verification_report;
    }

    public function setVerification_report(?array $verification_report): self
    {
        $this->verification_report = $verification_report;
        return $this;
    }

    public function getLast_checked_at(): ?\DateTimeImmutable
    {
        return $this->last_checked_at;
    }

    public function setLast_checked_at(?\DateTimeImmutable $last_checked_at): self
    {
        $this->last_checked_at = $last_checked_at;
        return $this;
    }
}