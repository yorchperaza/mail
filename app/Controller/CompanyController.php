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

    /**
     * GET /companies/{hash}/users
     * Returns users for the given company (only if the requester belongs to it).
     *
     * Query params:
     *   page      = 1-based page index (default 1)
     *   per_page  = items per page (default 25, max 100)
     *   q         = search by name/email (optional)
     *   sort      = full_name|email (default full_name)
     *   dir       = asc|desc (default asc)
     *
     * Response: { data: [...], page, per_page, total }
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/users')]
    public function listCompanyUsers(ServerRequestInterface $request): JsonResponse
    {
        $authUserId = (int) $request->getAttribute('user_id', 0);
        if ($authUserId <= 0) throw new RuntimeException('Unauthorized', 401);

        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || strlen($hash) !== 64) {
            throw new RuntimeException('Invalid company identifier', 400);
        }

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        /** @var Company|null $company */
        $company = $companyRepo->findOneBy(['hash' => $hash]);
        if (!$company) throw new RuntimeException('Company not found', 404);

        // membership check
        $belongs = array_filter($company->getUsers() ?? [], fn(User $u) => $u->getId() === $authUserId);
        if (empty($belongs)) throw new RuntimeException('Forbidden', 403);

        // ---- Query params
        parse_str((string)$request->getUri()->getQuery(), $qs);
        $page     = max(1, (int)($qs['page'] ?? 1));
        $perPage  = min(100, max(1, (int)($qs['per_page'] ?? 25)));
        $q        = trim((string)($qs['q'] ?? ''));
        $sort     = strtolower((string)($qs['sort'] ?? 'full_name'));
        $dir      = strtolower((string)($qs['dir']  ?? 'asc'));
        $dir      = $dir === 'desc' ? 'desc' : 'asc';

        $users = $company->getUsers() ?? [];

        // search
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $users = array_values(array_filter($users, function (User $u) use ($needle) {
                $name  = mb_strtolower($u->getFullName() ?? '');
                $email = mb_strtolower($u->getEmail());
                return str_contains($name, $needle) || str_contains($email, $needle);
            }));
        }

        // sort
        $keyFn = $sort === 'email'
            ? fn(User $u) => mb_strtolower($u->getEmail())
            : fn(User $u) => mb_strtolower($u->getFullName() ?? '');
        usort($users, function (User $a, User $b) use ($keyFn, $dir) {
            $ak = $keyFn($a); $bk = $keyFn($b);
            return $dir === 'desc' ? $bk <=> $ak : $ak <=> $bk;
        });

        // paginate
        $total  = count($users);
        $offset = ($page - 1) * $perPage;
        $slice  = array_slice($users, $offset, $perPage);

        // IMPORTANT: re-fetch each user with relations loaded (includes media)
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->repos->getRepository(User::class);

        $data = array_map(function (User $u) use ($userRepo): array {
            /** @var User|null $full */
            $full = $userRepo->find($u->getId(), true); // loads relations via repository
            $media = $full?->getMedia();

            return [
                'id'        => $full?->getId() ?? $u->getId(),
                'email'     => $full?->getEmail() ?? $u->getEmail(),
                'fullName'  => $full?->getFullName(),
                'createdAt' => $full?->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'media'     => $media ? [
                    'id'   => $media->getId(),
                    'url'  => $media->getUrl(),
                    'type' => $media->getType(),
                ] : null,
            ];
        }, $slice);

        return new JsonResponse([
            'data'     => $data,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
        ]);
    }

    /**
     * POST /companies/{hash}/users/invite
     *
     * Body:
     * {
     *   "email": "person@example.com",
     *   "roles": ["member","billing"] // optional; validated against allowed set
     * }
     *
     * Scenarios:
     * - If user exists: attach to company (idempotent). Optionally set roles on pivot if the column exists.
     * - If user does not exist: return an invite preview (no email is sent).
     *
     * Responses:
     * ① { "status": "already_member", "user": {...}, "company": {...} }
     * ② { "status": "added", "user": {...}, "company": {...} }
     * ③ {
     *      "status": "needs_invite",
     *      "preview": {
     *        "to": "person@example.com",
     *        "subject": "You're invited to <Company>",
     *        "body": "Hi ...",
     *        "acceptPath": "/invite/accept?company=<hash>&email=<encoded>",
     *        "roles": ["member"] // sanitized roles requested
     *      },
     *      "company": { "hash": "...", "name": "..." }
     *    }
     *
     * @throws \JsonException
     * @throws \ReflectionException
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/users/invite')]
    public function inviteUser(ServerRequestInterface $request): JsonResponse
    {
        $authUserId = (int) $request->getAttribute('user_id', 0);
        if ($authUserId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || strlen($hash) !== 64) {
            throw new RuntimeException('Invalid company identifier', 400);
        }

        // parse & validate input
        $body = json_decode((string) $request->getBody(), true, JSON_THROW_ON_ERROR);
        $email = strtolower(trim((string)($body['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email is required', 422);
        }

        $allowedRoles = ['owner','admin','member','billing','viewer'];
        $rolesReq = $body['roles'] ?? [];
        $roles = array_values(array_intersect(
            is_array($rolesReq) ? array_map('strval', $rolesReq) : [],
            $allowedRoles
        ));
        // default to ["member"] if no roles provided
        if (empty($roles)) {
            $roles = ['member'];
        }

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        /** @var Company|null $company */
        $company = $companyRepo->findOneBy(['hash' => $hash]);
        if (!$company) {
            throw new RuntimeException('Company not found', 404);
        }

        // Ensure inviter belongs to company
        $belongs = array_filter($company->getUsers() ?? [], fn(User $u) => $u->getId() === $authUserId);
        if (empty($belongs)) {
            throw new RuntimeException('Forbidden', 403);
        }

        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->repos->getRepository(User::class);

        // 1) Try to find existing user by email
        /** @var User|null $existing */
        $existing = $userRepo->findOneBy(['email' => $email]);

        if ($existing) {
            // Already a member?
            $already = array_filter($company->getUsers() ?? [], fn(User $u) => $u->getId() === $existing->getId());
            if (!empty($already)) {
                // Optionally update pivot roles here if you want "upsert" semantics
                $this->maybeUpdateCompanyUserRoles($company->getId(), $existing->getId(), $roles);

                return new JsonResponse([
                    'status'  => 'already_member',
                    'user'    => [
                        'id'       => $existing->getId(),
                        'email'    => $existing->getEmail(),
                        'fullName' => $existing->getFullName(),
                    ],
                    'company' => [
                        'hash' => $company->getHash(),
                        'name' => $company->getName(),
                    ],
                ]);
            }

            // Attach and set roles (if pivot has a roles column)
            $companyRepo->attachRelation($company, 'users', $existing->getId());
            $this->maybeUpdateCompanyUserRoles($company->getId(), $existing->getId(), $roles);

            return new JsonResponse([
                'status'  => 'added',
                'user'    => [
                    'id'       => $existing->getId(),
                    'email'    => $existing->getEmail(),
                    'fullName' => $existing->getFullName(),
                ],
                'company' => [
                    'hash' => $company->getHash(),
                    'name' => $company->getName(),
                ],
            ], 201);
        }

        // 2) No user found — return an invitation preview (no DB writes yet)
        // Load inviter for friendly preview text
        /** @var User|null $inviter */
        $inviter = $userRepo->find($authUserId);
        $inviterName = $inviter?->getFullName() ?: $inviter?->getEmail() ?: 'A teammate';

        $companyName = $company->getName() ?: 'your team';
        $acceptPath  = '/invite/accept?company=' . $company->getHash() . '&email=' . rawurlencode($email);

        $subject = sprintf("You're invited to %s", $companyName);
        $bodyText = sprintf(
            "Hi,\n\n%s invited you to join %s on MonkeysCloud.\n\n" .
            "Click the link below to create your account and accept the invite:\n%s\n\n" .
            "If you weren’t expecting this, you can safely ignore this email.",
            $inviterName,
            $companyName,
            $acceptPath
        );

        return new JsonResponse([
            'status'  => 'needs_invite',
            'preview' => [
                'to'         => $email,
                'subject'    => $subject,
                'body'       => $bodyText,
                'acceptPath' => $acceptPath, // front-end prefixes with its own origin
                'roles'      => $roles,
            ],
            'company' => [
                'hash' => $company->getHash(),
                'name' => $companyName,
            ],
        ], 200);
    }

    /**
     * Try to update roles on the company_user pivot if a 'roles' column exists.
     * Safe no-op if the column/table isn't present.
     */
    private function maybeUpdateCompanyUserRoles(int $companyId, int $userId, array $roles): void
    {
        try {
            $pdo = $this->qb->pdo();

            // Ensure the row exists; if not, skip (attachRelation should have created it).
            // Attempt to set a JSON string; adjust to CSV if your schema expects that.
            $sql = "UPDATE `company_user` SET `roles` = :roles WHERE `company_id` = :cid AND `user_id` = :uid";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':roles', json_encode(array_values($roles), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt->bindValue(':cid', $companyId, \PDO::PARAM_INT);
            $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
            $stmt->execute();
        } catch (\Throwable $e) {
            // Silently ignore if table/column not present or any other issue
        }
    }

    /**
     * GET /companies/search?q=...
     * Global company search (no user scoping).
     * Returns only id and name for lightweight dropdowns/autocomplete.
     *
     * Query:
     *   q      = search term (numeric -> exact ID; otherwise case-insensitive name contains)
     *   limit  = max results (default 20, max 100)
     */
    #[Route(methods: 'GET', path: '/search-companies')]
    public function search(ServerRequestInterface $request): JsonResponse
    {
        // You can still require auth if needed:
        $uid = (int)$request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }
        // If this should be admin-only, add your role/permission check here.

        // Parse query params
        parse_str((string)$request->getUri()->getQuery(), $qs);
        $qRaw  = isset($qs['q']) ? (string)$qs['q'] : '';
        $q     = trim($qRaw);
        $limit = max(1, min(100, (int)($qs['limit'] ?? 20)));

        $pdo = $this->qb->pdo();
        $out = [];

        // No search term: return first N alphabetically
        if ($q === '') {
            $stmt = $pdo->prepare("SELECT id, name FROM company ORDER BY name ASC LIMIT :lim");
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $out[] = ['id' => (int)$r['id'], 'name' => $r['name']];
            }
            return new JsonResponse($out);
        }

        // Numeric? → exact ID match
        if (ctype_digit($q)) {
            $stmt = $pdo->prepare("SELECT id, name FROM company WHERE id = :id LIMIT 1");
            $stmt->bindValue(':id', (int)$q, \PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $out[] = ['id' => (int)$row['id'], 'name' => $row['name']];
            }
            return new JsonResponse($out);
        }

        // Name contains (case-insensitive)
        // Uses LOWER(name) LIKE LOWER(:needle) for portability.
        $stmt = $pdo->prepare("
        SELECT id, name
        FROM company
        WHERE LOWER(name) LIKE LOWER(:needle)
        ORDER BY name ASC
        LIMIT :lim
    ");
        $needle = '%' . $q . '%';
        $stmt->bindValue(':needle', $needle, \PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int)$r['id'], 'name' => $r['name']];
        }

        return new JsonResponse($out);
    }

}
