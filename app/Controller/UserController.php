<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Media;
use App\Entity\Plan;
use App\Entity\User;
use DateTimeInterface;
use MonkeysLegion\Auth\AuthService;
use MonkeysLegion\Auth\PasswordHasher;
use MonkeysLegion\Files\Upload\UploadManager;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final class UserController
{
    private EntityRepository $users;

    public function __construct(
        private AuthService      $auth,
        private RepositoryFactory $repos,
        private PasswordHasher    $hasher,
        private UploadManager     $uploads,
    ) {}

    private function stripe(): StripeClient
    {
        return new StripeClient($_ENV['STRIPE_SECRET_KEY'] ?: '');
    }

    /**
     * Generate a URL-safe, DB-unique company hash.
     * Uses base64url over 24 random bytes (≈128 bits). Increase bytes if you want.
     * @throws RandomException
     */
    private function generateUniqueCompanyHash(object $companyRepo, int $maxAttempts = 10): string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            // URL-safe: +/ -> -_, no padding
            $hash = bin2hex(random_bytes(32));

            // Ensure not already taken
            $exists = $companyRepo->findOneBy(['hash' => $hash]);
            if (!$exists) {
                return $hash;
            }
        }
        throw new RuntimeException('Could not allocate a unique company hash', 500);
    }

    /**
     * @throws \DateMalformedStringException
     * @throws RandomException
     * @throws \JsonException
     * @throws \ReflectionException
     * @throws ApiErrorException
     */
    #[Route(methods: 'POST', path: '/auth/register')]
    public function register(ServerRequestInterface $request): JsonResponse
    {
        $data     = json_decode((string)$request->getBody(), true, JSON_THROW_ON_ERROR);
        $email    = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new RuntimeException('Email and password are required', 400);
        }

        $userRepo    = $this->repos->getRepository(User::class);
        $companyRepo = $this->repos->getRepository(Company::class);
        $planRepo    = $this->repos->getRepository(Plan::class);

        // 1) Prevent duplicate user
        /** @var ?User $existing */
        $existing = $userRepo->findOneBy(['email' => $email]);
        if ($existing) {
            throw new RuntimeException('User with this email already exists', 409);
        }

        // 2) Optional plan
        $planId = isset($data['plan_id']) ? (int)$data['plan_id'] : null;
        /** @var ?Plan $plan */
        $plan   = $planId ? $planRepo->findOneBy(['id' => $planId]) : null;

        // 3) Build entities
        $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $user = new User()
            ->setEmail($email)
            ->setFullName((string)($data['name'] ?? ''))
            ->setPasswordHash($this->hasher->hash($password))
            ->setStatus(true)
            ->setCreatedAt($now);

        $company = new Company()
            ->setName((string)($data['company'] ?? ''))
            ->setPlan($plan)
            ->setStatus(true)
            ->setCreatedAt($now)
            ->setHash($this->generateUniqueCompanyHash($companyRepo));

        // 4) Persist (retry once if hash collides)
        $userRepo->save($user);
        try {
            $companyRepo->save($company);
        } catch (\Throwable) {
            $company->setHash($this->generateUniqueCompanyHash($companyRepo));
            $companyRepo->save($company);
        }
        $companyRepo->attachRelation($company, 'users', $user->getId());

        // 5) Stripe: if paid plan, card is REQUIRED and becomes default
        $stripeCustomerId   = null;
        $stripeSubscription = null;

        $monthly = (float)($plan?->getMonthlyPrice() ?? 0.0);
        if ($plan && $monthly > 0) {
            $priceId = $plan->getStripe_price_id();
            if (!$priceId) {
                throw new RuntimeException('Plan is missing Stripe price id.', 500);
            }

            $stripe    = $this->stripe();
            $trialDays = (int)($_ENV['STRIPE_TRIAL_DAYS'] ?? 30);

            // Create customer first (idempotent-ish on email)
            $customer = $stripe->customers->create(
                [
                    'email'    => $email,
                    'name'     => (string)($data['company'] ?? ''),
                    'metadata' => [
                        'company_id' => (string)$company->getId(),
                        'user_id'    => (string)$user->getId(),
                        'plan_id'    => (string)$plan->getId(),
                    ],
                ],
                ['idempotency_key' => 'cust:create:email:' . md5($email)]
            );
            $stripeCustomerId = $customer->id;
            $company->setStripe_customer_id($customer->id);
            $companyRepo->save($company);

            // Did the client already collect a PM?
            $pmId = trim((string)($data['stripe_payment_method'] ?? ''));

            if ($pmId === '') {
                // No PM yet → create a SetupIntent tied to this customer and ask client to attach a card now
                $si = $stripe->setupIntents->create([
                    'customer'             => $customer->id,
                    'usage'                => 'off_session',
                    'payment_method_types' => ['card'],
                ]);

                $company->setSubscription_status('requires_payment_method');
                $companyRepo->save($company);

                return new JsonResponse([
                    'id'           => $user->getId(),
                    'email'        => $user->getEmail(),
                    'company_hash' => $company->getHash(),
                    'stripe'       => [
                        'customerId'     => $customer->id,
                        'clientSecret'   => $si->client_secret,
                        'publishableKey' => $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '',
                    ],
                    'message'    => 'This plan requires a card. Please add a payment method to continue.',
                    'next_step'  => 'attach_payment_method',
                ], 402);
            }

            // Attach PM & set as default on the customer
            try { $stripe->paymentMethods->attach($pmId, ['customer' => $customer->id]); } catch (\Throwable) {}
            $stripe->customers->update($customer->id, [
                'invoice_settings' => ['default_payment_method' => $pmId],
            ]);

            // Create subscription; store PM as the sub default too
            $subscription = $stripe->subscriptions->create(
                [
                    'customer'               => $customer->id,
                    'items'                  => [['price' => $priceId]],
                    'collection_method'      => 'charge_automatically',
                    'default_payment_method' => $pmId,
                    'trial_period_days'      => $trialDays,
                    // Make Stripe save future successful PMs and allow incomplete if initial payment can’t be taken
                    'payment_behavior'       => 'allow_incomplete',
                    'payment_settings'       => ['save_default_payment_method' => 'on_subscription'],
                    'metadata'               => [
                        'company_id' => (string)$company->getId(),
                        'user_id'    => (string)$user->getId(),
                        'plan_id'    => (string)$plan->getId(),
                    ],
                ],
                ['idempotency_key' => 'sub:create:company:' . $company->getId() . ':plan:' . $plan->getId()]
            );
            $stripeSubscription = $subscription->id;

            // Persist to Company
            $company->setStripe_subscription_id($subscription->id);
            $company->setSubscription_status($subscription->status);
            $company->setTrial_ends_at(
                new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->modify("+{$trialDays} days")
            );
            $companyRepo->save($company);

            // Finish (paid plan with default PM set)
            return new JsonResponse([
                'id'                 => $user->getId(),
                'email'              => $user->getEmail(),
                'company_hash'       => $company->getHash(),
                'stripeCustomerId'   => $stripeCustomerId,
                'stripeSubscription' => $stripeSubscription,
            ], 201);
        }

        // 6) Free plan (no card required)
        return new JsonResponse([
            'id'                 => $user->getId(),
            'email'              => $user->getEmail(),
            'company_hash'       => $company->getHash(),
            'stripeCustomerId'   => $stripeCustomerId,
            'stripeSubscription' => $stripeSubscription,
        ], 201);
    }

    /**
     * @throws \JsonException
     * @throws \DateMalformedStringException
     */
    #[Route('POST', '/auth/login')]
    public function login(ServerRequestInterface $request): JsonResponse
    {
        error_log('Login attempt');
        $data = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $email    = $data['email']    ?? '';
        $password = $data['password'] ?? '';

        $token = $this->auth->login($email, $password);

        // After successful login, update lastLoginAt
        $userRepo = $this->repos->getRepository(User::class);
        /** @var User|null $user */
        $user = $userRepo->findOneBy(['email' => $email]);
        if ($user) {
            $user->setLastLoginAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $userRepo->save($user);
        }

        return new JsonResponse(['token' => $token]);
    }


    /**
     * GET /auth/me
     *
     * @throws \JsonException
     * @throws \ReflectionException
     */
    #[Route(methods: 'GET', path: '/auth/me')]
    public function me(ServerRequestInterface $request): JsonResponse
    {
        // 1) Ensure we’re authenticated
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // 2) Load the User entity
        $userRepo = $this->repos->getRepository(User::class);
        /** @var User|null $user */
        $user = $userRepo->find($userId);
        if (! $user) {
            throw new RuntimeException('User not found', 404);
        }

        // 3) Use findByRelation() on the Company repo
        $companyRepo = $this->repos->getRepository(Company::class);
        $companies   = $companyRepo->findByRelation('users', $userId);

        if (!$user->getFullName()) {
            return new JsonResponse([
                'redirectTo' => '/dashboard/onboarding'
            ], 200);
        }

        // 4) Shape the response
        $companyPayload = array_map(fn(Company $c) => [
            'id'   => $c->getId(),
            'name' => $c->getName(),
        ], $companies);

        return new JsonResponse([
            'id'        => $user->getId(),
            'email'     => $user->getEmail(),
            'fullName'  => $user->getFullName() ?? '',
            'companies' => $companyPayload,
            'media'     => $user->getMedia() ? [
                'url'  => $user->getMedia()->getUrl(),
                'type' => $user->getMedia()->getType(),
            ] : null,
        ]);
    }

    /**
     * Update the authenticated user’s profile:
     *  - fullName via JSON { fullName: "…" }
     *  - avatar via multipart/form-data field "file"
     *
     * @param ServerRequestInterface $request
     * @return JsonResponse
     * @throws \JsonException
     * @throws \ReflectionException
     */
    #[Route(methods: 'POST', path: '/me/profile')]
    public function update(ServerRequestInterface $request): JsonResponse
    {
        // 1) Auth check
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // 2) Load user
        $userRepo = $this->repos->getRepository(User::class);
        /** @var User|null $user */
        $user = $userRepo->find($userId);
        if (! $user) {
            throw new RuntimeException('User not found', 404);
        }

        // 3) Update fullName if present
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $body = json_decode((string) $request->getBody(), true, JSON_THROW_ON_ERROR);
        } else {
            $body = $request->getParsedBody() ?: [];
        }

        if (isset($body['fullName'])) {
            $user->setFullName(trim($body['fullName']));
        }

        // 4) Handle avatar upload if present
        $files = $request->getUploadedFiles();
        if (isset($files['file'])) {
            try {
                $meta = $this->uploads->handle($request, 'file');
            } catch (\Throwable $e) {
                throw new RuntimeException('Upload failed: ' . $e->getMessage(), 400);
            }
            error_log('File upload metadata: ' . json_encode($meta, JSON_THROW_ON_ERROR));
            // persist Media
            $media = new Media();
            $media->setUrl($meta->url ?? $meta->path);
            $media->setType($meta->mimeType);
            $mediaRepo = $this->repos->getRepository(Media::class);
            $mediaRepo->save($media);

            // attach to user
            $user->setMedia($media);
        }
        // 5) Save user
        $userRepo->save($user);

        // 6) Return updated profile
        $payload = [
            'id'        => $user->getId(),
            'email'     => $user->getEmail(),
            'fullName'  => $user->getFullName(),
            'media'     => $user->getMedia() ? [
                'id'   => $user->getMedia()->getId(),
                'url'  => $user->getMedia()->getUrl(),
                'type' => $user->getMedia()->getType(),
            ] : null,
        ];

        return new JsonResponse($payload);
    }

    /**
     * POST /me/settings
     *
     * Updates any combination of:
     *   • fullName      (JSON: fullName)
     *   • password      (JSON: currentPassword + newPassword)
     *   • mfaEnabled    (JSON: mfaEnabled: bool)
     *   • mfaSecret     (JSON: mfaSecret: string)
     *   • avatar image  (multipart/form-data field "file")
     *
     * @throws \JsonException
     * @throws \ReflectionException
     */
    #[Route(methods: 'POST', path: '/me/settings')]
    public function saveSettings(ServerRequestInterface $request): JsonResponse
    {
        /* ----------------------------------------------------------------
         *  1) -- Authentication
         * --------------------------------------------------------------- */
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $userRepo = $this->repos->getRepository(User::class);
        /** @var User|null $user */
        $user = $userRepo->find($userId);
        if (! $user) {
            throw new RuntimeException('User not found', 404);
        }

        /* ----------------------------------------------------------------
         *  2) -- Parse body (works for JSON or multipart)
         * --------------------------------------------------------------- */
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $body = json_decode((string) $request->getBody(), true, JSON_THROW_ON_ERROR);
        } else {
            // multipart/form-data → fields live in $request->getParsedBody()
            $body = $request->getParsedBody() ?: [];
        }

        /* ----------------------------------------------------------------
         *  3) -- fullName
         * --------------------------------------------------------------- */
        if (isset($body['fullName'])) {
            $name = trim((string) $body['fullName']);
            if ($name === '') {
                throw new RuntimeException('fullName cannot be empty', 400);
            }
            $user->setFullName($name);
        }

        /* ----------------------------------------------------------------
         *  4) -- password change
         * --------------------------------------------------------------- */
        if (isset($body['currentPassword'], $body['newPassword'])) {
            $curr = (string) $body['currentPassword'];
            $next = (string) $body['newPassword'];

            if (! $this->hasher->verify($curr, $user->getPasswordHash())) {
                throw new RuntimeException('Current password is incorrect', 400);
            }
            if (strlen($next) < 8) {
                throw new RuntimeException('New password must be at least 8 chars', 400);
            }
            $user->setPasswordHash($this->hasher->hash($next));
        }

        /* ----------------------------------------------------------------
         *  5) -- MFA toggle / secret
         * --------------------------------------------------------------- */
        if (array_key_exists('mfaEnabled', $body)) {
            $enabled = filter_var($body['mfaEnabled'], FILTER_VALIDATE_BOOL);
            $user->setMfaEnabled($enabled);

            if (! $enabled) {
                // turning MFA off → wipe secret
                $user->setMfaSecret(null);
            } else {
                // turning MFA on → accept provided secret OR generate new
                $secret = $body['mfaSecret'] ?? bin2hex(random_bytes(10)); // placeholder generator
                $user->setMfaSecret((string) $secret);
            }
        }

        /* ----------------------------------------------------------------
         *  6) -- Avatar upload (optional)
         * --------------------------------------------------------------- */
        $files = $request->getUploadedFiles();
        if (isset($files['file'])) {
            try {
                $meta = $this->uploads->handle($request, 'file');
            } catch (\Throwable $e) {
                throw new RuntimeException('Upload failed: ' . $e->getMessage(), 400);
            }

            $media = new Media();
            $media->setUrl($meta->url ?? $meta->path);
            $media->setType($meta->mimeType);

            $mediaRepo = $this->repos->getRepository(Media::class);
            $mediaRepo->save($media);

            $user->setMedia($media);
        }

        /* ----------------------------------------------------------------
         *  7) -- Persist & respond
         * --------------------------------------------------------------- */
        $userRepo->save($user);

        return new JsonResponse([
            'id'        => $user->getId(),
            'email'     => $user->getEmail(),
            'fullName'  => $user->getFullName(),
            'mfaEnabled'=> (bool) $user->getMfaEnabled(),
            'media'     => $user->getMedia()
                ? ['url' => $user->getMedia()->getUrl(), 'type' => $user->getMedia()->getType()]
                : null,
        ]);
    }

    /**
     * GET /me/settings
     *
     * Returns the authenticated user’s settings:
     *   • id
     *   • email
     *   • fullName
     *   • mfaEnabled
     *   • mfaSecret
     *   • lastLoginAt
     *   • media (url + type) or null
     *
     * @throws \JsonException
     * @throws \ReflectionException
     */
    #[Route(methods: 'GET', path: '/me/settings')]
    public function getSettings(ServerRequestInterface $request): JsonResponse
    {
        // 1) Auth check
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // 2) Load User (including media)
        $userRepo = $this->repos->getRepository(User::class);
        /** @var User|null $user */
        $user = $userRepo->find($userId);
        if (! $user) {
            throw new RuntimeException('User not found', 404);
        }

        // 3) Build payload (exclude passwordHash)
        $payload = [
            'id'           => $user->getId(),
            'email'        => $user->getEmail(),
            'fullName'     => $user->getFullName(),
            'mfaEnabled'   => (bool) $user->getMfaEnabled(),
            'mfaSecret'    => $user->getMfaSecret(),  // string|null
            'lastLoginAt'  => $user->getLastLoginAt()
                ? $user->getLastLoginAt()->format(\DateTimeInterface::ATOM)
                : null,
            'media'        => $user->getMedia() ? [
                'url'  => $user->getMedia()->getUrl(),
                'type' => $user->getMedia()->getType(),
            ] : null,
        ];

        return new JsonResponse($payload);
    }
}