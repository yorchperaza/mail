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
        // 1) Auth
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // 2) Resolve + authorize company
        $hash    = (string)$request->getAttribute('hash');
        $company = $this->companyResolver->resolveCompanyForUser($hash, $userId);
        if (! $company) {
            throw new RuntimeException('Company not found or access denied', 404);
        }

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);

        // ---- NEW: Enforce domain limits for Starter/Grow/No Plan ----
        $planName = null;
        try {
            $planName = $company->getPlan()?->getName();
        } catch (\Throwable $ignored) {}

        // Fallback if plan relation isn’t loaded
        if ($planName === null || $planName === '') {
            /** @var \App\Repository\CompanyRepository $companyRepo */
            $companyRepo = $this->repos->getRepository(Company::class);
            $row = (clone $companyRepo->qb)
                ->select(['p.name AS name'])
                ->from('company', 'c')
                ->leftJoin('plan', 'p', 'p.id', '=', 'c.plan_id')
                ->where('c.id', '=', (int)$company->getId())
                ->fetch();
            $planName = $row?->name ?? null;
        }

        $planKey = strtoupper((string)$planName);
        if ($planKey === '' || in_array($planKey, ['STARTER', 'GROW'], true)) {
            $existing = $domainRepo->count(['company_id' => (int)$company->getId()]);
            if ($existing >= 1) {
                throw new RuntimeException(
                    'Your current plan allows only one domain. Upgrade to add more.',
                    403
                );
            }
        }

        // 3) Parse & validate payload
        $body = json_decode((string)$request->getBody(), true, JSON_THROW_ON_ERROR);
        $name = strtolower(trim((string)($body['domain'] ?? '')));
        if ($name === '') {
            throw new RuntimeException('Domain name is required', 400);
        }
        if (!preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~i', $name)) {
            throw new RuntimeException('Invalid domain format', 400);
        }

        // 4) Create base entity
        $domain = new Domain()
            ->setDomain($name)
            ->setStatus(Domain::STATUS_PENDING)
            ->setCreated_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setCompany($company);

        // 5) Persist
        $domainRepo->save($domain);
        // one login per company; username shown with this domain
        $creds = $this->smtpProvisioner->provisionForCompany($company, $name, 'smtpuser');
        // 6) Initialize DNS/SMTP expectations via the service (and persist updates)
        $bootstrap = $this->domainConfig->initializeAndSave($domain);

        return new JsonResponse([
            'id'         => $domain->getId(),
            'domain'     => $domain->getDomain(),
            'status'     => $domain->getStatus(),
            'created_at' => $domain->getCreated_at()?->format(\DateTimeInterface::ATOM),
            'txt'        => $bootstrap['dns']['txt'],
            'records'    => [
                'spf_expected'   => $bootstrap['dns']['spf'],
                'dmarc_expected' => $bootstrap['dns']['dmarc'],
                'mx_expected'    => $bootstrap['dns']['mx'],
                'dkim'           => $bootstrap['dns']['dkim'] ?? null,
            ],
            'smtp'       => [
                'host'     => 'smtp.monkeysmail.com',
                'ip'       => '34.30.122.164',
                'ports'    => [587, 465],
                'tls'      => ['starttls' => true, 'implicit' => true],
                'username' => $creds['username'],
                'password' => $creds['password'],
                'ip_pool'  => $creds['ip_pool'],
            ],
        ], 201);
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