<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Domain;
use App\Entity\Message;
use App\Entity\User;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class CompanyController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb
    ) {}

    /**
     * Lists all companies associated with the authenticated user.
     *
     * @param ServerRequestInterface $request
     * @return JsonResponse
     * @throws \JsonException
     * @throws \ReflectionException
     */
    #[Route(methods: 'GET', path: '/companies')]
    public function list(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $companyRepo = $this->repos->getRepository(Company::class);
        $companies   = $companyRepo->findByRelation('users', $userId);

        // --- include hash --------------------------------------------------------
        $out = array_map(
            fn (Company $c) => [
                'hash' => $c->getHash(),
                'name' => $c->getName(),
            ],
            $companies
        );

        return new JsonResponse($out);
    }

    /**
     * Lists all companies associated with the authenticated user,
     * including status and aggregates for card views.
     *
     * @throws \JsonException
     * @throws \ReflectionException
     */
    #[Route(methods: 'GET', path: '/companies/list-full')]
    public function listFull(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo  = $this->repos->getRepository(Domain::class);
        /** @var \App\Repository\MessageRepository $messageRepo */
        $messageRepo = $this->repos->getRepository(Message::class);
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo    = $this->repos->getRepository(User::class);

        // 1) Companies for this user
        $companies = $companyRepo->findByRelation('users', $userId);
        if (!$companies) {
            return new JsonResponse([]); // nothing to show
        }

        // 2) Gather all IDs for batch counting (avoid N+1 where repos support grouping)
        $companyIds = array_map(fn(Company $c) => $c->getId(), $companies);

        // ---- Domain counts
        // If your repository offers a grouped count helper, prefer it:
        // $domainCounts = $domainRepo->countGroupedByCompanyIds($companyIds);
        // Fallback: simple per-company count (works everywhere; optimize later if needed).
        $domainCounts = [];
        foreach ($companies as $c) {
            $domainCounts[$c->getId()] = $domainRepo->count(['company_id' => $c->getId()]);
        }

        // ---- Messages count (optional but useful on a card)
        $messageCounts = [];
        foreach ($companies as $c) {
            $messageCounts[$c->getId()] = $messageRepo->count(['company_id' => $c->getId()]);
        }

        // ---- Users count (collaborators) – count via relation if you don’t hydrate collections by default
        $userCounts = [];
        foreach ($companies as $c) {
            // If you have a join table company_user, implement a repo count using that table.
            // As a safe fallback, ask the repo to count by relation:
            // $userCounts[$c->getId()] = $companyRepo->countByRelation('users', $c->getId());
            // If that's not available, you can do a cheap find and count:
            $userCounts[$c->getId()] = count($c->getUsers() ?? []);
        }

        // 3) Shape the response for card rendering
        $out = array_map(function (Company $c) use ($domainCounts, $messageCounts, $userCounts) {
            $id = $c->getId();
            return [
                'hash'           => $c->getHash(),
                'name'           => $c->getName(),
                'status'         => (bool)$c->getStatus(),                                  // boolean
                'statusText'     => $c->getStatus() ? 'active' : 'inactive',               // handy for badges
                'createdAt'      => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'plan'           => $c->getPlan() ? [
                    'id'   => $c->getPlan()->id ?? null,                  // adjust if getters exist
                    'name' => method_exists($c->getPlan(), 'getName')
                        ? $c->getPlan()->getName()
                        : null,
                ] : null,
                'counts'         => [
                    'domains'  => $domainCounts[$id]   ?? 0,
                    'messages' => $messageCounts[$id]  ?? 0,
                    'users'    => $userCounts[$id]     ?? 0,
                ],
            ];
        }, $companies);

        return new JsonResponse($out);
    }

    /**
     * Creates a new company and associates it with the authenticated user.
     *
     * @param ServerRequestInterface $request
     * @return JsonResponse
     * @throws \JsonException
     * @throws \ReflectionException
     * @throws \DateMalformedStringException
     */
    #[Route(methods: 'POST', path: '/companies')]
    public function create(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $body = json_decode((string)$request->getBody(), true, JSON_THROW_ON_ERROR);

        // 1) Validate name
        $name = trim($body['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Company name is required', 400);
        }

        // 2) Optional phone
        $phone = isset($body['phone_number'])
            ? trim((string)$body['phone_number'])
            : null;

        // 3) Optional structured address
        $address = $body['address'] ?? null;
        if ($address !== null) {
            foreach (['street','city','zip','country'] as $key) {
                if (! array_key_exists($key, $address) || trim((string)$address[$key]) === '') {
                    throw new RuntimeException("Address must include non-empty “{$key}”", 400);
                }
                $address[$key] = trim((string)$address[$key]);
            }
        }

        $companyRepo = $this->repos->getRepository(Company::class);

        // 4) Instantiate (constructor already set a random hash)
        $company = new Company();

        // 5) Ensure no hash collision
        while ($companyRepo->findOneBy(['hash' => $company->getHash()]) !== null) {
            $company->setHash(bin2hex(random_bytes(32)));
        }

        // 6) Fill in the rest of the data
        $company
            ->setName($name)
            ->setStatus(true)
            ->setCreatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        if ($phone) {
            $company->setPhone_number($phone);
        }
        if ($address) {
            $company->setAddress($address);
        }

        // 7) Persist and attach the creating user
        $companyRepo->save($company);
        $companyRepo->attachRelation($company, 'users', $userId);

        // 8) Return full payload including the hash
        return new JsonResponse([
            'id'           => $company->getId(),
            'hash'         => $company->getHash(),
            'name'         => $company->getName(),
            'phone_number' => $company->getPhone_number(),
            'address'      => $company->getAddress(),
            'createdAt'    => $company->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'status'       => $company->getStatus(),
        ], 201);
    }

    /**
     * GET /companies/{hash}
     *
     * @throws \JsonException
     * @throws \ReflectionException
     */
    #[Route(methods: 'GET', path: '/companies/{hash}')]
    public function getCompany(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || strlen($hash) !== 64) {
            throw new RuntimeException('Invalid company identifier', 400);
        }

        $companyRepo = $this->repos->getRepository(Company::class);
        // find by hash
        /** @var Company|null $company */
        $company = $companyRepo->findOneBy(['hash' => $hash]);

        if (! $company) {
            throw new RuntimeException('Company not found', 404);
        }

        // ensure the user belongs to it
        $belongs = array_filter(
            $company->getUsers(),
            fn($u) => $u->getId() === $userId
        );
        if (empty($belongs)) {
            throw new RuntimeException('Forbidden', 403);
        }

        // shape the response
        $payload = [
            'hash'         => $company->getHash(),
            'name'         => $company->getName(),
            'address'      => $company->getAddress(),
            'phone_number' => $company->getPhone_number(),
            'users' => array_map(fn($u) => [
                'id'       => $u->getId(),
                'email'    => $u->getEmail(),
                'fullName' => $u->getFullName(),
            ], $company->getUsers()),
        ];

        return new JsonResponse($payload);
    }

    /**
     * GET /companies/{hash}/name
     * Fetches the name of a company by its hash.
     * @throws \JsonException
     * @throws \ReflectionException
     * @throws \Throwable
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/name')]
    public function getCompanyName(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $hash = $request->getAttribute('hash');
        error_log($hash);
        if (!is_string($hash) || strlen($hash) !== 64) {
            throw new RuntimeException('Invalid company identifier', 400);
        }

        $companyRepo = $this->repos->getRepository(Company::class);
        // find by hash
        /** @var Company|null $company */
        $company = $companyRepo->findOneBy(['hash' => $hash]);

        if (! $company) {
            throw new RuntimeException('Company not found', 404);
        }

        // ensure the user belongs to it
        $belongs = array_filter(
            $company->getUsers(),
            fn($u) => $u->getId() === $userId
        );
        if (empty($belongs)) {
            throw new RuntimeException('Forbidden', 403);
        }

        return new JsonResponse(['name' => $company->getName()]);
    }

}
