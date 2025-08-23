<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiKey;
use App\Entity\Company;
use App\Entity\Domain;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class ApiKeyController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
    ) {}

    /* ======================================================================
     * COMPANY-LEVEL ENDPOINTS
     * ====================================================================== */

    // GET /companies/{hash}/apikeys  — list all company keys
    #[Route(methods: 'GET', path: '/companies/{hash}/apikeys')]
    public function list(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $company = $this->resolveCompanyForUser(
            (string)$request->getAttribute('hash'),
            $userId
        );

        /** @var \App\Repository\ApiKeyRepository $keyRepo */
        $keyRepo = $this->repos->getRepository(ApiKey::class);
        /** @var ApiKey[] $keys */
        $keys = $keyRepo->findBy(['company_id' => $company->getId()]);

        $out = array_map(static fn(ApiKey $k) => [
            'id'            => $k->getId(),
            'label'         => $k->getLabel(),
            'prefix'        => $k->getPrefix(),
            'scopes'        => $k->getScopes(),
            'domain_id'     => $k->getDomain()?->getId(), // null means company-wide key
            'last_used_at'  => $k->getLast_used_at()?->format(\DateTimeInterface::ATOM),
            'revoked_at'    => $k->getRevoked_at()?->format(\DateTimeInterface::ATOM),
            'created_at'    => $k->getCreated_at()?->format(\DateTimeInterface::ATOM),
        ], $keys);

        return new JsonResponse($out);
    }

    // POST /companies/{hash}/apikeys  — create a company-level key (not bound to any domain)
    #[Route(methods: 'POST', path: '/companies/{hash}/apikeys')]
    public function create(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $company = $this->resolveCompanyForUser(
            (string)$request->getAttribute('hash'),
            $userId
        );

        $payload = json_decode((string)$request->getBody(), true, JSON_THROW_ON_ERROR);

        $label  = trim((string)($payload['label'] ?? ''));
        $scopes = $payload['scopes'] ?? [];

        // Token parts: show once
        $prefix   = substr(bin2hex(random_bytes(8)), 0, 16);
        $hashPart = bin2hex(random_bytes(32)); // 64-char secret part

        /** @var \App\Repository\ApiKeyRepository $repo */
        $repo = $this->repos->getRepository(ApiKey::class);

        $key = new ApiKey();
        $key->setLabel($label !== '' ? $label : null)
            ->setPrefix($prefix)
            ->setHash($hashPart)
            ->setScopes($scopes)
            ->setCreated_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setCompany($company)
            ->setDomain(null); // explicitly company-scoped

        $repo->save($key);

        return new JsonResponse([
            'secret'        => "{$prefix}.{$hashPart}", // only time the full secret is returned
            'id'            => $key->getId(),
            'label'         => $key->getLabel(),
            'scopes'        => $key->getScopes(),
            'domain_id'     => null,
            'created_at'    => $key->getCreated_at()?->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    // DELETE /companies/{hash}/apikeys/{id} — delete any key that belongs to the company
    #[Route(methods: 'DELETE', path: '/companies/{hash}/apikeys/{id}')]
    public function delete(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $hash    = (string) $request->getAttribute('hash');
        $company = $this->resolveCompanyForUser($hash, $userId);

        $keyId = (int) $request->getAttribute('id', 0);
        if ($keyId <= 0) {
            throw new RuntimeException('Invalid API key identifier', 400);
        }

        /** @var \App\Repository\ApiKeyRepository $repo */
        $repo = $this->repos->getRepository(ApiKey::class);

        $key = $repo->findOneBy(
            ['id' => $keyId, 'company_id' => $company->getId()],
            loadRelations: false
        );
        if (!$key) {
            throw new RuntimeException('API key not found', 404);
        }

        if ($repo->delete($key) === 0) {
            throw new RuntimeException('Failed to delete API key', 500);
        }

        return new JsonResponse(null, 204);
    }

    /* ======================================================================
     * DOMAIN-SCOPED ENDPOINTS
     * ====================================================================== */

    // GET /companies/{hash}/domains/{domainId}/apikeys — list keys bound to a domain
    #[Route(methods: 'GET', path: '/companies/{hash}/domains/{domainId}/apikeys')]
    public function listForDomain(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $hash     = (string)$request->getAttribute('hash');
        $domainId = (int)$request->getAttribute('domainId', 0);

        $company = $this->resolveCompanyForUser($hash, $userId);
        $domain  = $this->resolveDomainForCompany($company, $domainId);

        /** @var \App\Repository\ApiKeyRepository $repo */
        $repo = $this->repos->getRepository(ApiKey::class);
        /** @var ApiKey[] $keys */
        $keys = $repo->findBy([
            'company_id' => $company->getId(),
            'domain_id'  => $domain->getId(),
        ]);

        $out = array_map(static fn(ApiKey $k) => [
            'id'            => $k->getId(),
            'label'         => $k->getLabel(),
            'prefix'        => $k->getPrefix(),
            'scopes'        => $k->getScopes(),
            'domain_id'     => $domain->getId(),
            'last_used_at'  => $k->getLast_used_at()?->format(\DateTimeInterface::ATOM),
            'revoked_at'    => $k->getRevoked_at()?->format(\DateTimeInterface::ATOM),
            'created_at'    => $k->getCreated_at()?->format(\DateTimeInterface::ATOM),
        ], $keys);

        return new JsonResponse($out);
    }

    // POST /companies/{hash}/domains/{domainId}/apikeys — create a domain-bound key
    #[Route(methods: 'POST', path: '/companies/{hash}/domains/{domainId}/apikeys')]
    public function createForDomain(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $hash     = (string)$request->getAttribute('hash');
        $domainId = (int)$request->getAttribute('domainId', 0);

        $company = $this->resolveCompanyForUser($hash, $userId);
        $domain  = $this->resolveDomainForCompany($company, $domainId);

        $payload = json_decode((string)$request->getBody(), true, JSON_THROW_ON_ERROR);

        $label  = trim((string)($payload['label'] ?? ''));
        $scopes = $payload['scopes'] ?? [];

        // Token parts: show once
        $prefix   = substr(bin2hex(random_bytes(8)), 0, 16);
        $hashPart = bin2hex(random_bytes(32));

        /** @var \App\Repository\ApiKeyRepository $repo */
        $repo = $this->repos->getRepository(ApiKey::class);

        $key = new ApiKey();
        $key->setLabel($label !== '' ? $label : null)
            ->setPrefix($prefix)
            ->setHash($hashPart)
            ->setScopes($scopes)
            ->setCreated_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setCompany($company)
            ->setDomain($domain);

        $repo->save($key);

        return new JsonResponse([
            'secret'        => "{$prefix}.{$hashPart}", // only time the full secret is returned
            'id'            => $key->getId(),
            'label'         => $key->getLabel(),
            'scopes'        => $key->getScopes(),
            'domain_id'     => $domain->getId(),
            'created_at'    => $key->getCreated_at()?->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    // DELETE /companies/{hash}/domains/{domainId}/apikeys/{id} — delete a key explicitly under a domain path
    #[Route(methods: 'DELETE', path: '/companies/{hash}/domains/{domainId}/apikeys/{id}')]
    public function deleteForDomain(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $hash     = (string) $request->getAttribute('hash');
        $domainId = (int) $request->getAttribute('domainId', 0);
        $keyId    = (int) $request->getAttribute('id', 0);

        if ($keyId <= 0) {
            throw new RuntimeException('Invalid API key identifier', 400);
        }

        $company = $this->resolveCompanyForUser($hash, $userId);
        $domain  = $this->resolveDomainForCompany($company, $domainId);

        /** @var \App\Repository\ApiKeyRepository $repo */
        $repo = $this->repos->getRepository(ApiKey::class);

        $key = $repo->findOneBy(
            ['id' => $keyId, 'company_id' => $company->getId(), 'domain_id' => $domain->getId()],
            loadRelations: false
        );
        if (!$key) {
            throw new RuntimeException('API key not found', 404);
        }

        if ($repo->delete($key) === 0) {
            throw new RuntimeException('Failed to delete API key', 500);
        }

        return new JsonResponse(null, 204);
    }

    /* ======================================================================
     * HELPERS
     * ====================================================================== */

    /**
     * Resolve the company by hash and ensure the user belongs to it.
     *
     * @throws RuntimeException (400/403/404)
     */
    private function resolveCompanyForUser(string $hash, int $userId): Company
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('Invalid company identifier', 400);
        }

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        /** @var Company|null $company */
        $company = $companyRepo->findOneBy(['hash' => $hash]);

        if (!$company) {
            throw new RuntimeException('Company not found', 404);
        }

        $belongs = array_filter(
            $company->getUsers(),
            static fn($u) => $u->getId() === $userId
        );

        if (empty($belongs)) {
            throw new RuntimeException('Forbidden', 403);
        }

        return $company;
    }

    /**
     * Ensure the domain exists and belongs to the given company.
     *
     * @throws RuntimeException (400/404/403)
     */
    private function resolveDomainForCompany(Company $company, int $domainId): Domain
    {
        if ($domainId <= 0) {
            throw new RuntimeException('Invalid domain identifier', 400);
        }

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        /** @var Domain|null $domain */
        $domain = $domainRepo->find($domainId);

        if (!$domain) {
            throw new RuntimeException('Domain not found', 404);
        }
        if ($domain->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Domain does not belong to this company', 403);
        }

        return $domain;
    }
}
