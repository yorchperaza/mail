<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Domain;
use App\Entity\Message;
use App\Entity\Plan;
use App\Entity\User;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final class CompanyController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb
    ) {}

    private function stripe(): StripeClient
    {
        return new StripeClient($_ENV['STRIPE_SECRET_KEY'] ?: '');
    }
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

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        $companies   = $companyRepo->findByRelation('users', $userId);

        // Include id, hash, name, and a relative URL to the dashboard detail page
        $out = array_map(
            fn (Company $c) => [
                'id'   => $c->getId(),
                'hash' => $c->getHash(),
                'name' => $c->getName(),
                'url'  => '/dashboard/company/' . $c->getHash(),
            ],
            $companies
        );

        return new JsonResponse($out);
    }

    /**
     * @param int[] $companyIds
     * @return array<int,int>  // [company_id => user_count]
     */
    public function countUsersByCompanyIds(array $companyIds): array
    {
        if ($companyIds === []) return [];

        // Join table per your User entity: name=company_user, user_id + company_id
        $rows = $this->qb
            ->select(['cu.company_id', 'COUNT(cu.user_id) AS cnt'])
            ->from('company_user', 'cu')
            ->whereIn('cu.company_id', $companyIds)
            ->groupBy('cu.company_id')
            ->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['company_id']] = (int)$r['cnt'];
        }
        return $out;
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
        /** @var \App\Repository\PlanRepository $planRepo */
        $planRepo    = $this->repos->getRepository(Plan::class);

        // 1) Companies for this user
        $companies = $companyRepo->findByRelation('users', $userId);
        if (!$companies) {
            return new JsonResponse([]); // nothing to show
        }

        // 2) Gather all IDs for batch counting (avoid N+1 where repos support grouping)
        $companyIds = array_map(fn(Company $c) => $c->getId(), $companies);

        // ---- Domain counts (fallback per-company)
        $domainCounts = [];
        foreach ($companies as $c) {
            $domainCounts[$c->getId()] = $domainRepo->count(['company_id' => $c->getId()]);
        }

        // ---- Messages count (optional but useful on a card)
        $messageCounts = [];
        foreach ($companies as $c) {
            $messageCounts[$c->getId()] = $messageRepo->count(['company_id' => $c->getId()]);
        }

        // ---- Users count (collaborators)
        $userCounts = $this->countUsersByCompanyIds($companyIds);

        // 3) Shape the response for card rendering
        //    Workaround: DO NOT call $c->getPlan(). Instead read plan_id directly,
        //    then load Plan via repository (loadRelations=false).
        $out = array_map(function (Company $c) use ($domainCounts, $messageCounts, $userCounts, $companyRepo, $planRepo) {

            $id = $c->getId();
            try {
                $row = (clone $companyRepo->qb)
                    ->select(['plan_id'])
                    ->from('company')
                    ->where('id', '=', $id)
                    ->fetch();
                $pid = $row ? (int)($row->plan_id ?? 0) : null;
            } catch (\Throwable $e) {
                // Keep going with null plan
                $pid = null;
            }

            $plan = null;
            if ($pid) {
                try {
                    /** @var Plan|null $p */
                    $p = $planRepo->find($pid, false); // loadRelations=false – we just need id/name
                    if ($p) {
                        $plan = [
                            'id'   => $p->getId(),
                            'name' => $p->getName(),
                        ];
                    }
                } catch (\Throwable $e) {
                    // plan remains null
                }
            }

            return [
                'hash'       => $c->getHash(),
                'name'       => $c->getName(),
                'status'     => (bool)$c->getStatus(),
                'statusText' => $c->getStatus() ? 'active' : 'inactive',
                'createdAt'  => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'plan'       => $plan, // ['id'=>..., 'name'=>...] or null
                'counts'     => [
                    'domains'  => $domainCounts[$id]  ?? 0,
                    'messages' => $messageCounts[$id] ?? 0,
                    'users'    => $userCounts[$id]    ?? 0,
                ],
            ];
        }, $companies);

        return new JsonResponse($out);
    }


    /**
     * Creates a new company and (if a paid plan is selected) creates a fresh Stripe subscription.
     *
     * Body JSON:
     *   name (required)
     *   plan_id? (number) — when present and the plan has monthlyPrice > 0, a subscription will be created
     *   stripe_payment_method? (string) — required WHEN plan is paid; PaymentMethod ID from Payment Element
     *   phone_number? (string)
     *   address? { street, city, zip, country }
     *
     * @throws \JsonException
     * @throws \ReflectionException
     * @throws \DateMalformedStringException
     * @throws ApiErrorException
     * @throws RandomException
     */
    #[Route(methods: 'POST', path: '/companies')]
    public function create(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) throw new RuntimeException('Unauthorized', 401);

        $body = json_decode((string)$request->getBody(), true, JSON_THROW_ON_ERROR);

        // 1) Validate name
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Company name is required', 400);

        // 2) Optional values
        $phone = isset($body['phone_number']) ? trim((string)$body['phone_number']) : null;
        $address = $body['address'] ?? null;
        if ($address !== null) {
            foreach (['street','city','zip','country'] as $key) {
                if (!array_key_exists($key, $address) || trim((string)$address[$key]) === '') {
                    throw new RuntimeException("Address must include non-empty “{$key}”", 400);
                }
                $address[$key] = trim((string)$address[$key]);
            }
        }

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        /** @var \App\Repository\PlanRepository $planRepo */
        $planRepo    = $this->repos->getRepository(Plan::class);

        // 3) Optional plan
        $planId = isset($body['plan_id']) ? (int)$body['plan_id'] : null;
        /** @var ?Plan $plan */
        $plan   = $planId ? $planRepo->findOneBy(['id' => $planId]) : null;

        // 4) Instantiate company (constructor created a random hash)
        $company = new Company();

        // 5) Ensure no hash collision
        while ($companyRepo->findOneBy(['hash' => $company->getHash()]) !== null) {
            $company->setHash(bin2hex(random_bytes(32)));
        }

        // 6) Fill and persist basic data
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $company
            ->setName($name)
            ->setStatus(true)
            ->setCreatedAt($now)
            ->setPlan($plan);

        if ($phone)   { $company->setPhone_number($phone); }
        if ($address) { $company->setAddress($address); }

        $companyRepo->save($company);
        $companyRepo->attachRelation($company, 'users', $userId);

        // 7) Stripe: if paid plan, require and register a default payment method
        $stripeCustomerId = null;
        $stripeSubId      = null;

        $monthly = (float)($plan?->getMonthlyPrice() ?? 0.0);
        $isPaid  = $plan && $monthly > 0;

        if ($isPaid) {
            $priceId = $plan?->getStripe_price_id();
            if (!$priceId) throw new RuntimeException('Plan is missing Stripe price id.', 500);

            $pmId      = trim((string)($body['stripe_payment_method'] ?? '')); // may be empty on first call
            $trialDays = (int)($_ENV['STRIPE_TRIAL_DAYS'] ?? 30);
            $stripe    = $this->stripe();

            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->repos->getRepository(\App\Entity\User::class);
            /** @var ?User $user */
            $user = $userRepo->findOneBy(['id' => $userId]);
            if (!$user) throw new RuntimeException('Creating user not found', 500);

            // Create Stripe Customer (idempotent on company id)
            $customer = $stripe->customers->create(
                [
                    'name'     => $company->getName() ?: ('Company #'.$company->getId()),
                    'email'    => $user->getEmail(),
                    'metadata' => [
                        'company_id' => (string)$company->getId(),
                        'user_id'    => (string)$user->getId(),
                        'plan_id'    => (string)$plan->getId(),
                    ],
                ],
                ['idempotency_key' => 'cust:create:company:'.$company->getId()]
            );
            $stripeCustomerId = $customer->id;
            $company->setStripe_customer_id($customer->id);
            $companyRepo->save($company);

            if ($pmId === '') {
                // No PM yet → return a customer-scoped SetupIntent so the UI can collect card now
                $si = $stripe->setupIntents->create([
                    'customer'             => $customer->id,
                    'usage'                => 'off_session',
                    'payment_method_types' => ['card'],
                ]);

                // mark local status for clarity
                $company->setSubscription_status('requires_payment_method');
                $companyRepo->save($company);

                return new JsonResponse([
                    'id'           => $company->getId(),
                    'hash'         => $company->getHash(),
                    'name'         => $company->getName(),
                    'stripe'       => [
                        'customerId'     => $customer->id,
                        'clientSecret'   => $si->client_secret,
                        'publishableKey' => $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '',
                    ],
                    'message'   => 'This plan requires a payment method. Please add a card to continue.',
                    'next_step' => 'attach_payment_method',
                ], 402);
            }

            // Attach PaymentMethod and set as DEFAULT on the customer
            try { $stripe->paymentMethods->attach($pmId, ['customer' => $customer->id]); } catch (\Throwable $e) { /* already attached */ }
            $stripe->customers->update($customer->id, [
                'invoice_settings' => ['default_payment_method' => $pmId],
            ]);

            // Create trialing subscription; also set default_payment_method at the sub level
            $subscription = $stripe->subscriptions->create(
                [
                    'customer'               => $customer->id,
                    'items'                  => [['price' => $priceId]],
                    'collection_method'      => 'charge_automatically',
                    'default_payment_method' => $pmId,
                    'trial_period_days'      => $trialDays,
                    // best practices: allow incomplete and let Stripe save future PMs
                    'payment_behavior'       => 'allow_incomplete',
                    'payment_settings'       => ['save_default_payment_method' => 'on_subscription'],
                    'metadata'               => [
                        'company_id' => (string)$company->getId(),
                        'plan_id'    => (string)$plan->getId(),
                    ],
                ],
                ['idempotency_key' => 'sub:create:company:'.$company->getId().':plan:'.$plan->getId()]
            );
            $stripeSubId = $subscription->id;

            // Persist Stripe state on Company
            $company->setStripe_subscription_id($subscription->id);
            $company->setSubscription_status($subscription->status);
            $company->setTrial_ends_at(
                new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->modify("+{$trialDays} days")
            );
            $companyRepo->save($company);
        }

        // 8) Response
        return new JsonResponse([
            'id'           => $company->getId(),
            'hash'         => $company->getHash(),
            'name'         => $company->getName(),
            'phone_number' => $company->getPhone_number(),
            'address'      => $company->getAddress(),
            'createdAt'    => $company->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'status'       => $company->getStatus(),
            'plan'         => $plan ? ['id' => $plan->getId(), 'name' => $plan->getName()] : null,
            'stripe'       => $isPaid ? [
                'customerId'     => $stripeCustomerId,
                'subscriptionId' => $stripeSubId,
            ] : null,
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
            'plan'         => $company->getPlan() ? [
                'id'   => $company->getPlan()?->getId(),
                'name' => $company->getPlan()?->getName(),
            ] : null,
            'status'       => (bool)$company->getStatus(),
            'users' => array_map(fn($u) => [
                'id'       => $u->getId(),
                'email'    => $u->getEmail(),
                'fullName' => $u->getFullName(),
            ], $company->getUsers()),
        ];

        return new JsonResponse($payload);
    }

    /**
     * PATCH /companies/{hash}
     * Partially updates basic company info.
     *
     * Accepts any subset of:
     *   - name (non-empty string)
     *   - phone_number (string|null)
     *   - address (object|null) with {street,city,zip,country} all non-empty when provided
     *   - status (bool)
     *
     * Returns the updated resource.
     *
     * @throws \JsonException
     * @throws \ReflectionException
     */
    #[Route(methods: 'PATCH', path: '/companies/{hash}')]
    public function update(ServerRequestInterface $request): JsonResponse
    {

        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            error_log('[CO][PATCH][ERR] Unauthorized');
            throw new RuntimeException('Unauthorized', 401);
        }

        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || strlen($hash) !== 64) {
            error_log('[CO][PATCH][ERR] invalid hash');
            throw new RuntimeException('Invalid company identifier', 400);
        }

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);

        /** @var Company|null $company */
        $company = $companyRepo->findOneBy(['hash' => $hash]);
        if (!$company) {
            error_log('[CO][PATCH][ERR] not found');
            throw new RuntimeException('Company not found', 404);
        }

        // Ensure requester belongs to the company (adjust to role-check if you have pivot roles)
        $belongs = array_filter($company->getUsers() ?? [], fn(User $u) => $u->getId() === $userId);
        if (empty($belongs)) {
            error_log('[CO][PATCH][ERR] forbidden (membership)');
            throw new RuntimeException('Forbidden', 403);
        }

        $raw = (string)$request->getBody();
        $len = strlen($raw);
        error_log("[CO][PATCH] bodyLen={$len}");
        $body = $raw === '' ? [] : (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?: []);

        // Track what actually changed
        $changed = [];

        // name
        if (array_key_exists('name', $body)) {
            $name = trim((string)$body['name']);
            if ($name === '') {
                throw new RuntimeException('Name cannot be empty', 422);
            }
            if ($company->getName() !== $name) {
                $company->setName($name);
                $changed[] = 'name';
            }
        }

        // phone_number (nullable)
        if (array_key_exists('phone_number', $body)) {
            $phone = $body['phone_number'];
            $phone = ($phone === null) ? null : trim((string)$phone);
            if ($company->getPhone_number() !== $phone) {
                $company->setPhone_number($phone);
                $changed[] = 'phone_number';
            }
        }

        // address (nullable OR full object with non-empty fields)
        if (array_key_exists('address', $body)) {
            $address = $body['address'];
            if ($address === null) {
                if ($company->getAddress() !== null) {
                    $company->setAddress(null);
                    $changed[] = 'address(null)';
                }
            } else {
                if (!is_array($address)) {
                    throw new RuntimeException('Address must be an object or null', 422);
                }
                foreach (['street','city','zip','country'] as $k) {
                    if (!array_key_exists($k, $address) || trim((string)$address[$k]) === '') {
                        throw new RuntimeException("Address must include non-empty “{$k}”", 422);
                    }
                    $address[$k] = trim((string)$address[$k]);
                }
                // Only mark changed if different
                if ($company->getAddress() !== $address) {
                    $company->setAddress($address);
                    $changed[] = 'address';
                }
            }
        }

        // status (bool)
        if (array_key_exists('status', $body)) {
            $status = (bool)$body['status'];
            if ((bool)$company->getStatus() !== $status) {
                $company->setStatus($status);
                $changed[] = 'status';
            }
        }

        if (empty($changed)) {
            error_log('[CO][PATCH] no changes');
            // 204 No Content is also fine; returning the current resource is convenient for clients
            return new JsonResponse([
                'id'           => $company->getId(),
                'hash'         => $company->getHash(),
                'name'         => $company->getName(),
                'phone_number' => $company->getPhone_number(),
                'address'      => $company->getAddress(),
                'status'       => (bool)$company->getStatus(),
            ], 200);
        }

        // Persist
        $companyRepo->save($company);

        // Response
        return new JsonResponse([
            'id'           => $company->getId(),
            'hash'         => $company->getHash(),
            'name'         => $company->getName(),
            'phone_number' => $company->getPhone_number(),
            'address'      => $company->getAddress(),
            'status'       => (bool)$company->getStatus(),
            // keep shape minimal; add plan, stripe, etc. if needed
        ], 200);
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

    /**
     * GET /companies/{hash}/plan
     * Returns the company's current plan, or null if none.
     *
     * Response (200):
     *   null
     *   — or —
     *   {
     *     "id": 123,
     *     "name": "Pro",
     *     "monthlyPrice": 99,
     *     "includedMessages": 250000,
     *     "averagePricePer1K": 0.4,
     *     "features": {...} // if your Plan exposes it
     *   }
     */
    #[Route(methods: 'GET', path: '/companies-plan/{hash}')]
    public function getCompanyPlan(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || strlen($hash) !== 64) {
            throw new RuntimeException('Invalid company identifier', 400);
        }

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        /** @var Company|null $company */
        $company = $companyRepo->findOneBy(['hash' => $hash]);
        if (!$company) {
            throw new RuntimeException('Company not found', 404);
        }

        // Ensure requester belongs to the company
        $belongs = array_filter($company->getUsers() ?? [], fn(User $u) => $u->getId() === $userId);
        if (empty($belongs)) {
            throw new RuntimeException('Forbidden', 403);
        }

        $plan = $company->getPlan();
        if (!$plan) {
            return new JsonResponse(null);
        }

        // Build a tolerant payload (works whether you have public props or getters)
        $payload = [
            'id'   => method_exists($plan, 'getId')   ? $plan->getId()   : ($plan->id   ?? null),
            'name' => method_exists($plan, 'getName') ? $plan->getName() : ($plan->name ?? null),
        ];

        if (method_exists($plan, 'getMonthlyPrice')) {
            $payload['monthlyPrice'] = $plan->getMonthlyPrice();
        }
        if (method_exists($plan, 'getIncludedMessages')) {
            $payload['includedMessages'] = $plan->getIncludedMessages();
        }
        if (method_exists($plan, 'getAveragePricePer1K')) {
            $payload['averagePricePer1K'] = $plan->getAveragePricePer1K();
        }
        if (method_exists($plan, 'getFeatures')) {
            $payload['features'] = $plan->getFeatures();
        }

        return new JsonResponse($payload);
    }

}
