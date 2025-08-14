<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiKey;
use App\Entity\Company;
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

    // ──────────────────────────────────────────────────────────────
    //  GET  /companies/{hash}/apikeys
    //  Return every key that belongs to the company
    // ──────────────────────────────────────────────────────────────
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

        $keyRepo = $this->repos->getRepository(ApiKey::class);
        /** @var ApiKey[] $keys */
        $keys = $keyRepo->findBy(['company_id' => $company->getId()]);

        $out = array_map(static fn(ApiKey $k) => [
            'id'            => $k->getId(),
            'label'         => $k->getLabel(),
            'prefix'        => $k->getPrefix(),
            'scopes'        => $k->getScopes(),
            'last_used_at'  => $k->getLast_used_at()?->format(\DateTimeInterface::ATOM),
            'revoked_at'    => $k->getRevoked_at()?->format(\DateTimeInterface::ATOM),
            'created_at'    => $k->getCreated_at()?->format(\DateTimeInterface::ATOM),
        ], $keys);

        return new JsonResponse($out);
    }

    // ──────────────────────────────────────────────────────────────
    //  POST /companies/{hash}/apikeys
    //  Create a new key and attach it to the company
    // ──────────────────────────────────────────────────────────────
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

        // generate token parts
        $prefix = substr(bin2hex(random_bytes(8)), 0, 16);
        $hash   = bin2hex(random_bytes(32));                 // 64-char hash for storage

        /** @var \App\Repository\ApiKeyRepository $repo */
        $repo = $this->repos->getRepository(ApiKey::class);

        $key = (new ApiKey())
            ->setLabel($label !== '' ? $label : null)
            ->setPrefix($prefix)
            ->setHash($hash)
            ->setScopes($scopes)
            ->setCreated_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setCompany($company);

        $repo->save($key);

        return new JsonResponse([
            // return the **only** time the full secret is shown
            'secret'        => "{$prefix}.{$hash}",
            'id'            => $key->getId(),
            'label'         => $key->getLabel(),
            'scopes'        => $key->getScopes(),
            'created_at'    => $key->getCreated_at()?->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Resolve the company by hash and ensure the user belongs to it.
     *
     * @throws RuntimeException (403/404) when access is forbidden.
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
     * DELETE /companies/{hash}/apikeys/{id}
     *
     * @throws RuntimeException 401/400/403/404
     * @throws \JsonException
     * @throws \Throwable
     */

    #[Route(methods: 'DELETE', path: '/companies/{hash}/apikeys/{id}')]
    public function delete(ServerRequestInterface $request): JsonResponse
    {
        /* ── 1) Authenticate ───────────────────────────────────────────── */
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        /* ── 2) Resolve company membership ─────────────────────────────── */
        $hash    = (string) $request->getAttribute('hash');
        $company = $this->resolveCompanyForUser($hash, $userId);
        if (!$company) {
            throw new RuntimeException('Company not found', 404);
        }

        /* ── 3) Validate API-key ID ────────────────────────────────────── */
        $keyId = (int) $request->getAttribute('id', 0);
        if ($keyId <= 0) {
            throw new RuntimeException('Invalid API key identifier', 400);
        }

        /** @var ApiKeyRepository $repo */
        $repo = $this->repos->getRepository(ApiKey::class);

        /* ── 4) Ensure the key belongs to this company ─────────────────── */
        $key = $repo->findOneBy(
            ['id' => $keyId, 'company_id' => $company->getId()],
            loadRelations: false
        );
        if (!$key) {
            throw new RuntimeException('API key not found', 404);
        }

        /* ── 5) Delete (repository cascades relations) ─────────────────── */
        if ($repo->delete($key) === 0) {
            throw new RuntimeException('Failed to delete API key', 500);
        }

        /* ── 6) Done ───────────────────────────────────────────────────── */
        return new JsonResponse(null, 204);
    }
}