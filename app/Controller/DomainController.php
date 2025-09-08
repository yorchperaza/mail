<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Domain;
use App\Entity\DkimKey;
use App\Entity\Message;
use App\Entity\TlsRptReport;
use App\Entity\MtaStsPolicy;
use App\Entity\DmarcAggregate;
use App\Entity\BimiRecord;
use App\Entity\ReputationSample;
use App\Entity\InboundRoute;
use App\Entity\InboundMessage;
use App\Entity\Campaign;
use App\Service\CompanyResolver;
use App\Service\DomainConfig;
use App\Service\DomainDnsVerifier;
use App\Service\SmtpCredentialProvisioner;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;
use RuntimeException;
use ReflectionException;

final class DomainController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
        private CompanyResolver   $companyResolver,
        private DomainConfig      $domainConfig,
        private SmtpCredentialProvisioner $smtpProvisioner,
        private DomainDnsVerifier $domainDnsVerifier,
    ) {}

    /**
     * GET /companies/{hash}/domains
     *
     * List all domains for the given company.
     *
     * @throws ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/domains')]
    public function listByCompany(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $hash    = (string)$request->getAttribute('hash');
        $company = $this->companyResolver->resolveCompanyForUser($hash, $userId);
        if (! $company) {
            throw new RuntimeException('Company not found or access denied', 404);
        }

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        // ManyToOne → use findBy on the FK column
        $domains = $domainRepo->findBy(['company_id' => $company->getId()]);

        $out = array_map(fn(Domain $d) => [
            'id'     => $d->getId(),
            'domain' => $d->getDomain(),
            'statusDomain'=> $d->getStatus(),
        ], $domains);

        return new JsonResponse($out);
    }

    /**
     * GET /domains
     *
     * List all domains across all companies the user belongs to.
     *
     * @throws ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/domains')]
    public function listByUser(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // fetch all companies the user belongs to
        $companyRepo = $this->repos->getRepository(Company::class);
        $companies   = $companyRepo->findByRelation('users', $userId);

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);

        $out = [];
        foreach ($companies as $company) {
            $domains = $domainRepo->findBy(['company_id' => $company->getId()]);
            foreach ($domains as $d) {
                $out[] = [
                    'id'          => $d->getId(),
                    'domain'      => $d->getDomain(),
                    'statusDomain'=> $d->getStatus(),
                    'companyHash' => $company->getHash(),
                ];
            }
        }

        return new JsonResponse($out);
    }

    /**
     * POST /companies/{hash}/domains
     *
     * Create a new domain under the given company.
     *
     * @throws ReflectionException
     * @throws \JsonException
     * @throws \DateMalformedStringException
     * @throws RandomException
     * @throws \Throwable
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/domains')]
    public function createDomain(ServerRequestInterface $request): JsonResponse
    {
        $t0  = microtime(true);
        $rid = bin2hex(random_bytes(6)); // simple trace/correlation id for logs

        $mask = static function (?string $s, int $show = 2): string {
            if ($s === null || $s === '') return '';
            $len = strlen($s);
            if ($len <= $show) return str_repeat('*', $len);
            return substr($s, 0, $show) . str_repeat('*', max(0, $len - $show));
        };

        try {
            // 1) Auth
            $userId = (int)$request->getAttribute('user_id', 0);
            error_log("[createDomain][$rid] START user_id={$userId}");
            if ($userId <= 0) {
                error_log("[createDomain][$rid] Unauthorized: missing/invalid user_id");
                throw new RuntimeException('Unauthorized', 401);
            }

            // 2) Resolve + authorize company
            $hash = (string)$request->getAttribute('hash');
            error_log("[createDomain][$rid] Resolving company for hash={$hash}");
            $company = $this->companyResolver->resolveCompanyForUser($hash, $userId);
            if (!$company) {
                error_log("[createDomain][$rid] Company not found or access denied for hash={$hash}, user_id={$userId}");
                throw new RuntimeException('Company not found or access denied', 404);
            }
            $companyId = (int)$company->getId();
            error_log("[createDomain][$rid] Company resolved id={$companyId}");

            /** @var \App\Repository\DomainRepository $domainRepo */
            $domainRepo = $this->repos->getRepository(Domain::class);

            // ---- Enforce domain limits for Starter/Grow/No Plan ----
            $planName = null;
            try {
                $planName = $company->getPlan()?->getName();
                error_log("[createDomain][$rid] Plan (via relation) planName=" . ($planName ?? 'NULL'));
            } catch (\Throwable $e) {
                error_log("[createDomain][$rid] Plan relation access failed: " . $e->getMessage());
            }

            if ($planName === null || $planName === '') {
                try {
                    /** @var \App\Repository\CompanyRepository $companyRepo */
                    $companyRepo = $this->repos->getRepository(Company::class);
                    $row = (clone $companyRepo->qb)
                        ->select(['p.name AS name'])
                        ->from('company', 'c')
                        ->leftJoin('plan', 'p', 'p.id', '=', 'c.plan_id')
                        ->where('c.id', '=', $companyId)
                        ->fetch();
                    $planName = $row?->name ?? null;
                    error_log("[createDomain][$rid] Plan (via SQL) planName=" . ($planName ?? 'NULL'));
                } catch (\Throwable $e) {
                    error_log("[createDomain][$rid] Plan SQL lookup failed: " . $e->getMessage());
                }
            }

            $planKey = strtoupper((string)$planName);
            if ($planKey === '' || in_array($planKey, ['STARTER', 'GROW'], true)) {
                try {
                    $existing = $domainRepo->count(['company_id' => $companyId]);
                    error_log("[createDomain][$rid] Domain count for company_id={$companyId} is {$existing}");
                    if ($existing >= 1) {
                        error_log("[createDomain][$rid] Plan limit reached for planKey={$planKey} (>=1 domain)");
                        throw new RuntimeException(
                            'Your current plan allows only one domain. Upgrade to add more.',
                            403
                        );
                    }
                } catch (\Throwable $e) {
                    error_log("[createDomain][$rid] Counting domains failed: " . $e->getMessage());
                    throw new RuntimeException('Failed to check domain limits', 500);
                }
            }

            // 3) Parse & validate payload
            $rawBody = (string)$request->getBody();
            error_log("[createDomain][$rid] Raw body length=" . strlen($rawBody));
            try {
                $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                error_log("[createDomain][$rid] JSON parse error: " . $e->getMessage());
                throw new RuntimeException('Invalid JSON body', 400);
            }

            $name = strtolower(trim((string)($body['domain'] ?? '')));
            error_log("[createDomain][$rid] Parsed domain candidate='{$name}'");
            if ($name === '') {
                error_log("[createDomain][$rid] Validation failed: empty domain");
                throw new RuntimeException('Domain name is required', 400);
            }
            if (!preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~i', $name)) {
                error_log("[createDomain][$rid] Validation failed: invalid format '{$name}'");
                throw new RuntimeException('Invalid domain format', 400);
            }

            // 4) Create base entity
            $domain = (new Domain())
                ->setDomain($name)
                ->setStatus(Domain::STATUS_PENDING)
                ->setCreated_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setCompany($company);

            // 5) Persist domain
            try {
                $domainRepo->save($domain);
                error_log("[createDomain][$rid] Domain persisted id=" . (int)$domain->getId());
            } catch (\Throwable $e) {
                error_log("[createDomain][$rid] Domain save failed: " . $e->getMessage());
                throw new RuntimeException('Failed to create domain', 500);
            }

            // 5.1) Provision SMTP creds (mask in logs)
            try {
                error_log("[createDomain][$rid] Provisioning SMTP credentials (previewDomain={$name})");
                $creds = $this->smtpProvisioner->provisionForCompany($company, $name, 'smtpuser');
                error_log("[createDomain][$rid] SMTP provisioned: username=" . $mask($creds['username'] ?? '')
                    . " ip_pool=" . (($creds['ip_pool'] ?? null) === null ? 'NULL' : $creds['ip_pool']));
            } catch (\Throwable $e) {
                error_log("[createDomain][$rid] SMTP provision failed: " . $e->getMessage());
                throw new RuntimeException('Failed to provision SMTP credentials', 500);
            }

            // 6) Initialize DNS/SMTP expectations (soft-fail if OpenDKIM tables are not writable)
            error_log("[createDomain][$rid] Initializing DNS bootstrap for domain_id=" . (int)$domain->getId());
            $bootstrap = null;
            $opendkimError = null;

            try {
                $bootstrap = $this->domainConfig->initializeAndSave($domain);
                // Let DomainConfig optionally pass back a soft error (if it caught one)
                $opendkimError = $bootstrap['opendkim_error'] ?? null;

                // Count selectors if DKIM map present
                $dkimCount = is_array($bootstrap['dns']['dkim'] ?? null) ? count($bootstrap['dns']['dkim']) : 0;
                error_log("[createDomain][$rid] DNS bootstrap prepared: spf=" . (!empty($bootstrap['dns']['spf']) ? 'yes' : 'no') .
                    " dmarc=" . (!empty($bootstrap['dns']['dmarc']) ? 'yes' : 'no') .
                    " mx=" . (!empty($bootstrap['dns']['mx']) ? 'yes' : 'no') .
                    " dkim=" . ($dkimCount > 0 ? "{$dkimCount} selectors" : "none") .
                    ($opendkimError ? " (opendkim_error present)" : ""));
            } catch (\Throwable $e) {
                // Do NOT fail request: surface as warning and continue
                $opendkimError = $e->getMessage();
                error_log("[createDomain][$rid] DNS bootstrap soft-failed: " . $opendkimError);
                $bootstrap = [
                    'dns' => [
                        'txt'   => null,
                        'spf'   => null,
                        'dmarc' => null,
                        'mx'    => null,
                        'dkim'  => null,
                    ],
                ];
            }

            // ---- build response safely (ip_pool may be absent) ----
            $resp = new JsonResponse([
                'id'         => $domain->getId(),
                'domain'     => $domain->getDomain(),
                'status'     => $domain->getStatus(),
                'created_at' => $domain->getCreated_at()?->format(\DateTimeInterface::ATOM),
                'txt'        => $bootstrap['dns']['txt'] ?? null,
                'records'    => [
                    'spf_expected'   => $bootstrap['dns']['spf']   ?? null,
                    'dmarc_expected' => $bootstrap['dns']['dmarc'] ?? null,
                    'mx_expected'    => $bootstrap['dns']['mx']    ?? null,
                    // DomainConfig now returns a selector→{selector,value} map
                    'dkim'           => $bootstrap['dns']['dkim']  ?? null,
                ],
                'smtp'       => [
                    'host'     => 'smtp.monkeysmail.com',
                    'ip'       => '34.30.122.164',
                    'ports'    => [587, 465],
                    'tls'      => ['starttls' => true, 'implicit' => true],
                    'username' => $creds['username'] ?? null,
                    // keep full password in response, but DO NOT log it
                    'password' => $creds['password'] ?? null,
                    'ip_pool'  => $creds['ip_pool']  ?? null,   // safe default
                ],
                // Non-blocking warning the UI can display
                'opendkim_error' => $opendkimError,
            ], 201);

            $dt = number_format((microtime(true) - $t0) * 1000, 1);
            error_log("[createDomain][$rid] OK 201 company_id={$companyId} domain={$name} planKey={$planKey} dt_ms={$dt}");
            return $resp;

        } catch (\Throwable $e) {
            $dt = number_format((microtime(true) - $t0) * 1000, 1);
            // Log full exception with code + first line of trace for quick pinpointing
            $trace = explode("\n", $e->getTraceAsString())[0] ?? '';
            error_log("[createDomain][$rid] ERROR code={$e->getCode()} msg=" . $e->getMessage() . " at {$trace} dt_ms={$dt}");

            $code = $e instanceof \RuntimeException && $e->getCode() >= 400 ? $e->getCode() : 500;
            return new JsonResponse([
                'error'   => $e->getMessage(),
                'traceId' => $rid,
            ], $code);
        }
    }

    /**
     * GET /companies/{hash}/domains/{id}
     *
     * Return the detailed info for a single domain that belongs to the given company.
     *
     * @throws ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/domains/{id}')]
    public function getOne(ServerRequestInterface $request): JsonResponse
    {
        // 1) Auth
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) throw new RuntimeException('Unauthorized', 401);

        // 2) Company
        $hash    = (string)$request->getAttribute('hash');
        $company = $this->companyResolver->resolveCompanyForUser($hash, $userId);
        if (! $company) throw new RuntimeException('Company not found or access denied', 404);

        // 3) Domain id
        $id = (int)$request->getAttribute('id', 0);
        if ($id <= 0) throw new RuntimeException('Invalid domain id', 400);

        // 4) Load domain
        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        $domain     = $domainRepo->find($id);
        if (! $domain || $domain->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Domain not found', 404);
        }

        // ---- Build DKIM expected (from active DkimKey) ----
        $dkimExpected = null;
        $domainName   = (string)$domain->getDomain();

        $pemToDkimTxt = static function (string $pem): string {
            // If it's already a DKIM string containing p=..., keep as-is
            if (preg_match('~\bp=([A-Za-z0-9+/=]+)~', $pem)) {
                // ensure starts with v=DKIM1 (add if missing)
                $val = trim($pem);
                if (stripos($val, 'v=dkim1') !== 0) {
                    $val = 'v=DKIM1; k=rsa; ' . preg_replace('~^\s*;+~', '', $val);
                }
                return $val;
            }
            // Else extract base64 from PEM
            $clean = preg_replace('~-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+~', '', $pem);
            return 'v=DKIM1; k=rsa; p=' . $clean;
        };

        foreach ($domain->getDkimKeys() ?? [] as $k) {
            if ($k->getActive()) {
                $host  = sprintf('%s._domainkey.%s', $k->getSelector(), $domainName);
                $value = null;

                if (method_exists($k, 'getTxt_value') && $k->getTxt_value()) {
                    $value = trim((string)$k->getTxt_value());
                    // normalize to include v=DKIM1/k=rsa if missing
                    if (stripos($value, 'p=') !== false && stripos($value, 'v=dkim1') === false) {
                        $value = 'v=DKIM1; k=rsa; ' . ltrim($value, '; ');
                    }
                } else {
                    $value = $pemToDkimTxt((string)$k->getPublic_key_pem());
                }

                $dkimExpected = ['name' => $host, 'value' => $value];
                break;
            }
        }

        // 5) Response
        $out = [
            'id'              => $domain->getId(),
            'domain'          => $domain->getDomain(),
            'status'          => $domain->getStatus(),
            'created_at'      => $domain->getCreated_at()?->format(\DateTimeInterface::ATOM),
            'verified_at'     => $domain->getVerified_at()?->format(\DateTimeInterface::ATOM),

            // expose verification metadata for UI
            'last_checked_at'     => $domain->getLast_checked_at()?->format(\DateTimeInterface::ATOM),
            'verification_report' => $domain->getVerification_report(),

            // toggles
            'require_tls'     => $domain->getRequire_tls(),
            'arc_sign'        => $domain->getArc_sign(),
            'bimi_enabled'    => $domain->getBimi_enabled(),

            // expectations for setup cards
            'txt' => [
                'name'  => $domain->getTxt_name(),
                'value' => $domain->getTxt_value(),
            ],
            'records' => [
                'spf_expected'   => $domain->getSpf_expected(),
                'dmarc_expected' => $domain->getDmarc_expected(),
                'mx_expected'    => $domain->getMx_expected(),
                'dkim_expected'  => $dkimExpected,   // <-- now always publishable when key exists
            ],

            // counts
            'counts' => [
                'dkimKeys'        => count($domain->getDkimKeys() ?? []),
                'messages'        => count($domain->getMessages() ?? []),
                'tlsRptReports'   => count($domain->getTlsRptReports() ?? []),
                'mtaStsPolicies'  => count($domain->getMtaStsPolicies() ?? []),
                'dmarcAggregates' => count($domain->getDmarcAggregates() ?? []),
                'bimiRecords'     => count($domain->getBimiRecords() ?? []),
                'reputation'      => count($domain->getReputationSamples() ?? []),
                'inboundRoutes'   => count($domain->getInboundRoutes() ?? []),
                'inboundMessages' => count($domain->getInboundMessages() ?? []),
                'campaigns'       => count($domain->getCampaigns() ?? []),
            ],

            'company' => [
                'hash' => $company->getHash(),
                'name' => $company->getName(),
            ],
        ];

        return new JsonResponse($out);
    }

    /**
     * DELETE /companies/{hash}/domains/{id}
     *
     * Deletes a domain AND all dependent rows in a single transaction.
     * Returns 204 on success.
     * @throws ReflectionException
     */
    #[Route(methods: 'DELETE', path: '/companies/{hash}/domains/{id}')]
    public function deleteWithDependencies(ServerRequestInterface $request): JsonResponse
    {
        // 1) Auth
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) throw new RuntimeException('Unauthorized', 401);

        // 2) Company scope
        $hash    = (string)$request->getAttribute('hash');
        $company = $this->companyResolver->resolveCompanyForUser($hash, $userId);
        if (! $company) throw new RuntimeException('Company not found or access denied', 404);

        // 3) Domain id
        $id = (int)$request->getAttribute('id', 0);
        if ($id <= 0) throw new RuntimeException('Invalid domain id', 400);

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        $domain     = $domainRepo->find($id);
        if (! $domain || $domain->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Domain not found', 404);
        }

        // 4) Transaction
        $pdo = $this->qb->pdo();
        $pdo->beginTransaction();

        try {
            $this->deleteChildrenForDomain($id);

            // finally delete the domain
            if (method_exists($domainRepo, 'delete')) {
                $domainRepo->delete($domain);
            } elseif (method_exists($domainRepo, 'remove')) {
                $domainRepo->remove($domain);
            } else {
                // fallback via QB (assumes table name "domains")
                $this->qb->delete('domains')->where('id', '=', $id)->execute();
            }

            $pdo->commit();
            return new JsonResponse(null, 204);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException($e->getMessage(), 500, $e);
        }
    }

    /**
     * Best-effort bulk deletion of dependent rows using repo->deleteBy(['domain_id'=>X])
     * when available, else QueryBuilder fallback with your where API.
     * @throws ReflectionException
     */
    private function deleteChildrenForDomain(int $domainId): void
    {
        // 1) Repos first (unchanged)
        $repoMap = [
            DkimKey::class          => 'dkimRepo',
            Message::class          => 'msgRepo',
            TlsRptReport::class     => 'tlsRepo',
            MtaStsPolicy::class     => 'mtaRepo',
            DmarcAggregate::class   => 'dmarcRepo',
            BimiRecord::class       => 'bimiRepo',
            ReputationSample::class => 'repRepo',
            InboundRoute::class     => 'routeRepo',
            InboundMessage::class   => 'inMsgRepo',
            Campaign::class         => 'campRepo',
        ];

        foreach ($repoMap as $entity => $var) {
            $repo = $this->repos->getRepository($entity);
            if (method_exists($repo, 'deleteBy')) {
                $repo->deleteBy(['domain_id' => $domainId]);
                continue;
            }
            if (method_exists($repo, 'findBy') && method_exists($repo, 'remove')) {
                foreach ($repo->findBy(['domain_id' => $domainId]) as $row) {
                    $repo->remove($row);
                }
            }
        }

        // 2) Fallback: raw SQL per table with a fresh prepared stmt each time
        $pdo = $this->qb->pdo(); // you already have this in the controller
        $tables = [
            'dkimkey',
            'message',
            'tlsrptreport',
            'mtastspolicy',
            'dmarcaggregate',
            'bimirecord',
            'reputationsample',
            'inboundroute',
            'inboundmessage',
            'campaign',
        ];

        foreach ($tables as $table) {
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE domain_id = :id");
            $stmt->execute([':id' => $domainId]);
        }
    }

    #[Route(methods: 'POST', path: '/companies/{hash}/domains/{id}/verify')]
    public function verifyDomain(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) throw new \RuntimeException('Unauthorized', 401);

        $hash    = (string)$request->getAttribute('hash');
        $company = $this->companyResolver->resolveCompanyForUser($hash, $userId);
        if (!$company) throw new \RuntimeException('Company not found or access denied', 404);

        $id = (int)$request->getAttribute('id', 0);
        if ($id <= 0) throw new \RuntimeException('Invalid domain id', 400);

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        $domain     = $domainRepo->find($id);
        if (! $domain || $domain->getCompany()?->getId() !== $company->getId()) {
            throw new \RuntimeException('Domain not found', 404);
        }

        $report = $this->domainDnsVerifier->verifyAndPersist($domain);

        return new JsonResponse([
            'id'      => $domain->getId(),
            'status'  => $domain->getStatus(),
            'summary' => $report['summary'] ?? [],
            'records' => $report['records'] ?? [],
            'checked_at' => $report['checked_at'] ?? null,
            'verified_at'=> $domain->getVerified_at()?->format(\DateTimeInterface::ATOM),
        ]);
    }

}
