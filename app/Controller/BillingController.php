<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Plan;
use App\Entity\User;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Subscription;

final class BillingController
{
    public function __construct(private RepositoryFactory $repos) {}

    private function stripe(): StripeClient
    {
        return new StripeClient($_ENV['STRIPE_SECRET_KEY'] ?: '');
    }

    private function auth(ServerRequestInterface $r): int
    {
        $uid = (int)$r->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);
        return $uid;
    }

    /* ======================================================================
     * SHARED ENDPOINTS
     * ====================================================================== */

    #[Route(methods: 'POST', path: '/billing/create-setup-intent')]
    public function createSetupIntent(ServerRequestInterface $r): JsonResponse
    {
        $stripe = $this->stripe();
        $si = $stripe->setupIntents->create([
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
        ]);
        return new JsonResponse([
            'clientSecret'   => $si->client_secret,
            'publishableKey' => $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '',
        ]);
    }

    #[Route(methods: 'POST', path: '/billing/portal')]
    public function createPortalSession(ServerRequestInterface $r): JsonResponse
    {
        $userId = $this->auth($r);

        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->repos->getRepository(User::class);
        /** @var ?User $u */
        $u = $userRepo->find($userId);
        if (!$u) throw new RuntimeException('User not found', 404);

        /** @var ?Company $company */
        $company = method_exists($u, 'getCompany') ? $u->getCompany() : null;
        if (!$company) throw new RuntimeException('Company not found', 404);

        $customerId = $this->getCompanyStripeCustomerId($company);
        if (!$customerId) throw new RuntimeException('No Stripe customer.', 400);

        // Robust origin
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? ($scheme . '://' . $host));
        $returnUrl = $origin . '/billing';

        $stripe = $this->stripe();
        $session = $stripe->billingPortal->sessions->create([
            'customer'   => $customerId,
            'return_url' => $returnUrl,
        ]);

        return new JsonResponse(['url' => $session->url]);
    }

    #[Route(methods: 'POST', path: '/stripe/webhook')]
    public function webhook(ServerRequestInterface $r): JsonResponse
    {
        $payload = (string)$r->getBody();
        $sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $secret  = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig, $secret);
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid signature', 400);
        }

        switch ($event->type) {
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted': {
                /** @var Subscription $sub */
                $sub = $event->data->object;
                $this->syncSubscription($sub);
                break;
            }
            default:
                break;
        }

        return new JsonResponse(['received' => true]);
    }

    /* ======================================================================
     * COMPANY-FOCUSED BILLING ENDPOINTS
     * ====================================================================== */

    /**
     * GET the current subscription + default PM/Source details for a company.
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/billing/subscription')]
    public function getCompanySubscription(ServerRequestInterface $r): JsonResponse
    {
        $userId  = $this->auth($r);
        $company = $this->resolveCompanyForUser((string)$r->getAttribute('hash'), $userId);

        $stripe = $this->stripe();
        $customerId = $this->getCompanyStripeCustomerId($company);
        $subPayload = null;
        $pmPayload  = null;
        $sourcePayload = null;

        if ($customerId) {
            // Active (or most recent) subscription
            $sub = $this->getActiveSubscriptionForCustomer($stripe, $customerId);

            if ($sub) {
                $subPayload = [
                    'id'                   => $sub->id,
                    'status'               => $sub->status,
                    'cancel_at_period_end' => (bool)$sub->cancel_at_period_end,
                    'current_period_start' => $sub->current_period_start
                        ? (new \DateTimeImmutable('@'.$sub->current_period_start))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)
                        : null,
                    'current_period_end'   => $sub->current_period_end
                        ? (new \DateTimeImmutable('@'.$sub->current_period_end))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)
                        : null,
                    'collection_method'    => $sub->collection_method,
                ];
            }

            // Customer defaults (expand both PM and legacy Source)
            $cust = $stripe->customers->retrieve($customerId, [
                'expand' => ['invoice_settings.default_payment_method', 'default_source'],
            ]);
            $pm = $cust->invoice_settings?->default_payment_method;
            if ($pm && $pm->object === 'payment_method' && $pm->card) {
                $pmPayload = [
                    'id'        => $pm->id,
                    'brand'     => $pm->card->brand,
                    'last4'     => $pm->card->last4,
                    'exp_month' => $pm->card->exp_month,
                    'exp_year'  => $pm->card->exp_year,
                ];
            } elseif (is_string($pm)) {
                $pmPayload = ['id' => $pm];
            }

            $src = $cust->default_source ?? null;
            if ($src && is_object($src) && isset($src->object) && $src->object === 'card') {
                $sourcePayload = [
                    'id'        => $src->id,
                    'brand'     => $src->brand,
                    'last4'     => $src->last4,
                    'exp_month' => $src->exp_month,
                    'exp_year'  => $src->exp_year,
                ];
            } elseif (is_string($src)) {
                $sourcePayload = ['id' => $src];
            }
        }

        $plan = $company->getPlan();
        return new JsonResponse([
            'company' => [
                'stripe_customer_id'     => $company->getStripe_customer_id(),
                'stripe_subscription_id' => $company->getStripe_subscription_id(),
                'subscription_status'    => $company->getSubscription_status(),
                'trial_ends_at'          => $company->getTrial_ends_at()
                    ? $company->getTrial_ends_at()->format(\DateTimeInterface::ATOM)
                    : null,
                'plan' => $plan ? [
                    'id'   => $plan->getId(),
                    'name' => $plan->getName(),
                ] : null,
            ],
            'subscription'               => $subPayload,
            'default_payment_method'     => $pmPayload,
            'default_source'             => $sourcePayload,
        ]);
    }

    /**
     * Create a customer-scoped SetupIntent so the user can enter a new payment method.
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/billing/setup-intent')]
    public function createCompanySetupIntent(ServerRequestInterface $r): JsonResponse
    {
        $userId  = $this->auth($r);
        $company = $this->resolveCompanyForUser((string)$r->getAttribute('hash'), $userId);

        $stripe     = $this->stripe();
        $customerId = $this->requireStripeCustomer($stripe, $company);

        $si = $stripe->setupIntents->create([
            'customer'             => $customerId,
            'usage'                => 'off_session',
            'payment_method_types' => ['card'],
        ]);

        return new JsonResponse([
            'clientSecret'   => $si->client_secret,
            'publishableKey' => $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '',
        ]);
    }

    /**
     * Attach a new payment method to the company’s customer and set as default.
     * Body: { "payment_method": "pm_xxx" }
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/billing/update-payment-method')]
    public function updateDefaultPaymentMethod(ServerRequestInterface $r): JsonResponse
    {
        $userId  = $this->auth($r);
        $company = $this->resolveCompanyForUser((string)$r->getAttribute('hash'), $userId);

        $data = json_decode((string)$r->getBody(), true) ?: [];
        $pmId = trim((string)($data['payment_method'] ?? ''));
        if ($pmId === '') {
            throw new RuntimeException('payment_method is required', 400);
        }

        $stripe     = $this->stripe();
        $customerId = $this->requireStripeCustomer($stripe, $company);

        // Attach PM to customer (if not already attached)
        try {
            $stripe->paymentMethods->attach($pmId, ['customer' => $customerId]);
        } catch (\Throwable $e) {
            // Ignore "already attached" errors
        }

        // Set as default
        $stripe->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $pmId],
        ]);

        // Also update the active subscription’s default PM (optional)
        $sub = $this->getActiveSubscriptionForCustomer($stripe, $customerId);
        if ($sub) {
            $stripe->subscriptions->update($sub->id, [
                'default_payment_method' => $pmId,
            ]);
        }

        return new JsonResponse([
            'customerId'           => $customerId,
            'defaultPaymentMethod' => $pmId,
            'subscriptionUpdated'  => (bool)$sub,
        ]);
    }

    /**
     * Cancel subscription (default: at period end).
     * Body (optional): { "cancel_now": false, "prorate": true }
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/billing/cancel-subscription')]
    public function cancelSubscription(ServerRequestInterface $r): JsonResponse
    {
        $userId  = $this->auth($r);
        $company = $this->resolveCompanyForUser((string)$r->getAttribute('hash'), $userId);

        $stripe     = $this->stripe();
        $customerId = $this->getCompanyStripeCustomerId($company);
        if (!$customerId) {
            throw new RuntimeException('No subscription found for this company.', 400);
        }

        $data       = json_decode((string)$r->getBody(), true) ?: [];
        $cancelNow  = (bool)($data['cancel_now'] ?? false);
        $prorate    = (bool)($data['prorate'] ?? true);

        $sub = $this->getActiveSubscriptionForCustomer($stripe, $customerId);
        if (!$sub) {
            throw new RuntimeException('No active subscription to cancel.', 400);
        }

        $updated = $cancelNow
            ? $stripe->subscriptions->cancel($sub->id, ['prorate' => $prorate])
            : $stripe->subscriptions->update($sub->id, ['cancel_at_period_end' => true]);

        // Sync local company state
        $this->syncSubscription($updated);

        return new JsonResponse([
            'id'                   => $updated->id,
            'status'               => $updated->status,
            'cancel_at_period_end' => (bool)$updated->cancel_at_period_end,
            'current_period_end'   => $updated->current_period_end
                ? (new \DateTimeImmutable('@'.$updated->current_period_end))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)
                : null,
        ]);
    }

    /**
     * Change package/plan.
     *
     * If there is no default PaymentMethod or legacy default source on the customer,
     * we DO NOT call Stripe Subscriptions (to avoid the “no payment source” error).
     * Instead, we return HTTP 402 with a SetupIntent so the UI can collect a card,
     * then call update-payment-method and retry this request.
     *
     * Body: {
     *   "plan_id": number,
     *   "proration_behavior": "create_prorations" | "none",
     *   "payment_method": "pm_xxx",   // optional; overrides if present
     *   "trial_days": 0               // optional when creating new sub
     * }
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/billing/change-plan')]
    public function changePlan(ServerRequestInterface $r): JsonResponse
    {
        $userId  = $this->auth($r);
        $company = $this->resolveCompanyForUser((string)$r->getAttribute('hash'), $userId);

        $body   = json_decode((string)$r->getBody(), true, JSON_THROW_ON_ERROR);
        $planId = (int)($body['plan_id'] ?? 0);
        if ($planId <= 0) throw new RuntimeException('plan_id is required', 400);

        /** @var \App\Repository\PlanRepository $planRepo */
        $planRepo = $this->repos->getRepository(Plan::class);
        /** @var ?Plan $plan */
        $plan = $planRepo->findOneBy(['id' => $planId]);
        if (!$plan) throw new RuntimeException('Plan not found', 404);

        $priceId = method_exists($plan, 'getStripe_price_id') ? $plan->getStripe_price_id() : null;
        $monthly = (float)($plan->getMonthlyPrice() ?? 0.0);

        $stripe     = $this->stripe();
        $customerId = $this->requireStripeCustomer($stripe, $company);

        $requestedProration = $body['proration_behavior'] ?? 'create_prorations';
        if (!in_array($requestedProration, ['create_prorations', 'none'], true)) {
            $requestedProration = 'create_prorations';
        }

        $currentSub = $this->getActiveSubscriptionForCustomer($stripe, $customerId);

        // FREE plan ⇒ cancel any active sub and just store plan locally
        if ($monthly <= 0 || !$priceId) {
            if ($currentSub && $currentSub->status !== 'canceled') {
                $updated = $stripe->subscriptions->cancel($currentSub->id, ['prorate' => false]);
                $this->syncSubscription($updated);
            }
            $company->setPlan($plan);
            $this->repos->getRepository(Company::class)->save($company);

            return new JsonResponse([
                'plan_id'   => $plan->getId(),
                'plan_name' => $plan->getName(),
                'status'    => 'free',
            ]);
        }

        // ------------------- Figure out how we’ll charge -------------------
        // 1) Did the caller send a new PM? If yes, attach + use it.
        $pmFromBodyId = isset($body['payment_method']) ? trim((string)$body['payment_method']) : null;
        if ($pmFromBodyId === '') $pmFromBodyId = null;

        $effectivePmId = null;
        if ($pmFromBodyId) {
            try { $stripe->paymentMethods->attach($pmFromBodyId, ['customer' => $customerId]); } catch (\Throwable) {}
            $stripe->customers->update($customerId, [
                'invoice_settings' => ['default_payment_method' => $pmFromBodyId],
            ]);
            $effectivePmId = $pmFromBodyId;
        } else {
            // 2) Reuse existing defaults, in priority order
            $cust = $stripe->customers->retrieve($customerId, [
                'expand' => ['invoice_settings.default_payment_method', 'default_source'],
            ]);
            $effectivePmId = $this->pmId($cust->invoice_settings?->default_payment_method ?? null);

            // Promote subscription PM -> customer PM if present
            if (!$effectivePmId && $currentSub) {
                $subPmId = $this->pmId($currentSub->default_payment_method ?? null);
                if ($subPmId) {
                    $stripe->customers->update($customerId, [
                        'invoice_settings' => ['default_payment_method' => $subPmId],
                    ]);
                    $effectivePmId = $subPmId;
                }
            }
        }

        // 3) Do we at least have a legacy default_source (old cards)?
        $hasLegacySource = $this->hasLegacyCardSource($stripe, $customerId);

        // If we have no PM and no legacy source, DO NOT touch subscriptions.
        if (!$effectivePmId && !$hasLegacySource) {
            $payload = $this->beginPaymentCollectionFlow($stripe, $customerId, [
                'reason'   => 'missing_payment_method',
                'message'  => 'This customer has no attached payment source or default payment method. Please add a payment method to continue.',
                'plan_id'  => $plan->getId(),
                'plan_name'=> $plan->getName(),
            ]);
            // 402 Payment Required — gives the UI everything needed to collect a card
            return new JsonResponse($payload, 402);
        }

        // If the caller asked for proration but we can’t guarantee a charge now, keep NONE.
        $prorationBehavior = (!$effectivePmId && $hasLegacySource ? $requestedProration : $requestedProration);
        // NOTE: with a legacy source, Stripe can still charge; we keep requested behavior.

        // Let Stripe mark the sub INCOMPLETE if it can’t charge; auto-save next PM.
        $commonPaymentFlags = [
            'payment_behavior' => 'allow_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
        ];

        // ------------------- Create or Update the subscription -------------
        if ($currentSub) {
            // Update existing subscription (assumes single item)
            $item = $currentSub->items->data[0] ?? null;
            if (!$item) throw new RuntimeException('Subscription has no items to update', 500);

            $params = array_merge($commonPaymentFlags, [
                'cancel_at_period_end'   => false,
                'proration_behavior'     => $prorationBehavior,
                'items'                  => [[ 'id' => $item->id, 'price' => $priceId ]],
            ]);
            if ($effectivePmId) {
                $params['default_payment_method'] = $effectivePmId;
            }
            // If we only have legacy source, omit default_payment_method and Stripe will use the customer default_source.

            $updated = $stripe->subscriptions->update($currentSub->id, $params);

            $this->syncSubscription($updated);
            $subId  = $updated->id;
            $status = $updated->status;
        } else {
            // Create a new subscription
            $trialDays = max(0, (int)($body['trial_days'] ?? 0));
            $params = array_merge($commonPaymentFlags, [
                'customer'               => $customerId,
                'items'                  => [['price' => $priceId]],
                'collection_method'      => 'charge_automatically',
                'metadata'               => [
                    'company_id' => (string)$company->getId(),
                    'plan_id'    => (string)$plan->getId(),
                ],
            ]);
            if ($trialDays > 0) {
                $params['trial_period_days'] = $trialDays;
            }
            if ($effectivePmId) {
                $params['default_payment_method'] = $effectivePmId;
            }
            // else: rely on customer.default_source if any; otherwise it will be incomplete until card is added

            $created = $stripe->subscriptions->create($params);

            $this->syncSubscription($created);
            $subId  = $created->id;
            $status = $created->status;
        }

        // Persist chosen plan + subscription fields on Company
        $company->setPlan($plan);
        $company->setStripe_subscription_id($subId);
        $company->setSubscription_status($status);

        $this->repos->getRepository(Company::class)->save($company);

        return new JsonResponse([
            'plan_id'                => $plan->getId(),
            'plan_name'              => $plan->getName(),
            'stripe_subscription_id' => $subId,
            'status'                 => $status,
        ]);
    }

    /** True if the customer has a legacy default card source (Sources API). */
    private function hasLegacyCardSource(StripeClient $stripe, string $customerId): bool
    {
        try {
            $cust = $stripe->customers->retrieve($customerId, ['expand' => ['default_source']]);
            $src  = $cust->default_source ?? null;
            if (is_object($src) && ($src->object ?? null) === 'card') return true;
            if (is_string($src)) {
                try {
                    $s = $stripe->sources->retrieve($src);
                    return ($s && ($s->object ?? null) === 'card');
                } catch (\Throwable) {}
            }
        } catch (\Throwable) {}
        return false;
    }

    /* ======================================================================
     * HELPERS
     * ====================================================================== */

    /** Start the “attach a card” flow and return a 402-style payload. */
    private function beginPaymentCollectionFlow(StripeClient $stripe, string $customerId, array $extra = []): array
    {
        $si = $stripe->setupIntents->create([
            'customer'             => $customerId,
            'usage'                => 'off_session',
            'payment_method_types' => ['card'],
        ]);

        return array_merge([
            'action'         => 'attach_payment_method',
            'message'        => 'A default payment method is required to start billing.',
            'stripe'         => [
                'clientSecret'   => $si->client_secret,
                'publishableKey' => $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '',
            ],
            'how_to_proceed' => [
                '1' => 'Render Stripe PaymentElement with the provided clientSecret.',
                '2' => 'Confirm it (stripe.confirmSetup) to obtain a PaymentMethod id.',
                '3' => 'POST that id to /companies/{hash}/billing/update-payment-method.',
                '4' => 'Retry this change-plan request.',
            ],
        ], $extra);
    }

    /** Sync subset of subscription state back to Company (snake_case + DateTimeImmutable). */
    private function syncSubscription(Subscription $sub): void
    {
        $stripeCustomerId = $sub->customer;

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);

        /** @var ?Company $company */
        $company = $companyRepo->findOneBy(['stripe_customer_id' => $stripeCustomerId]);
        if (!$company) return;

        $company->setStripe_subscription_id($sub->id);
        $company->setSubscription_status($sub->status);

        $t = $sub->trial_end
            ? (new \DateTimeImmutable("@{$sub->trial_end}"))->setTimezone(new \DateTimeZone('UTC'))
            : null;
        $company->setTrial_ends_at($t);

        // Optional: toggle access status based on Stripe status
        $company->setStatus(in_array($sub->status, ['trialing', 'active', 'past_due'], true));

        $companyRepo->save($company);
    }

    private function getCompanyStripeCustomerId(Company $c): ?string
    {
        if (method_exists($c, 'getStripe_customer_id')) return $c->getStripe_customer_id();
        if (property_exists($c, 'stripe_customer_id'))  return $c->stripe_customer_id ?? null;
        return null;
    }

    /**
     * Ensure a Stripe Customer exists for the company; first try to re-use by email
     * to avoid orphan cards on a different customer.
     */
    private function requireStripeCustomer(StripeClient $stripe, Company $company): string
    {
        $existing = $this->getCompanyStripeCustomerId($company);
        if ($existing) return $existing;

        // Try to infer a contact email (billing contact or first user).
        $email = null;
        if (method_exists($company, 'getBillingContactEmail') && $company->getBillingContactEmail()) {
            $email = $company->getBillingContactEmail();
        } else {
            $users = method_exists($company, 'getUsers') ? $company->getUsers() : [];
            if (!empty($users) && method_exists($users[0], 'getEmail')) {
                $email = $users[0]->getEmail();
            }
        }

        // Reuse by email if possible
        if ($email) {
            try {
                $search = $stripe->customers->search([
                    'query' => "email:'" . addslashes($email) . "'",
                    'limit' => 1,
                ]);
                if (!empty($search->data)) {
                    $cust = $search->data[0];
                    $company->setStripe_customer_id($cust->id);
                    $this->repos->getRepository(Company::class)->save($company);
                    return $cust->id;
                }
            } catch (\Throwable) {}
        }

        // Create a new customer
        $cust = $stripe->customers->create([
            'email'    => $email,
            'name'     => $company->getName(),
            'metadata' => [
                'company_id' => (string)$company->getId(),
            ],
        ]);

        $company->setStripe_customer_id($cust->id);
        $this->repos->getRepository(Company::class)->save($company);

        return $cust->id;
    }

    /** Return the most recent non-canceled subscription for the customer, if any. */
    private function getActiveSubscriptionForCustomer(StripeClient $stripe, string $customerId): ?Subscription
    {
        $list = $stripe->subscriptions->all([
            'customer' => $customerId,
            'limit'    => 1,
        ]);
        return $list->data[0] ?? null;
    }

    /** Resolve the company by hash and ensure the user belongs to it. */
    private function resolveCompanyForUser(string $hash, int $userId): Company
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('Invalid company identifier', 400);
        }

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        /** @var ?Company $company */
        $company = $companyRepo->findOneBy(['hash' => $hash]);
        if (!$company) throw new RuntimeException('Company not found', 404);

        $belongs = array_filter($company->getUsers(), static fn($u) => $u->getId() === $userId);
        if (empty($belongs)) throw new RuntimeException('Forbidden', 403);

        return $company;
    }

    /** Return a PaymentMethod ID from either a string or expanded object. */
    private function pmId(null|array|object|string $pm): ?string
    {
        if (is_string($pm)) return $pm;
        if (is_object($pm) && isset($pm->id) && is_string($pm->id)) return $pm->id;
        if (is_array($pm) && isset($pm['id']) && is_string($pm['id'])) return $pm['id'];
        return null;
    }

    /**
     * Open Stripe Billing Portal for a specific company (by hash).
     * Creates a Stripe customer if missing so Portal always works.
     *
     * POST /companies/{hash}/billing/portal
     * @throws ApiErrorException
     */
    #[Route(methods: 'POST', path: '/companies-billing-portal/{hash}')]
    public function createCompanyPortalSession(ServerRequestInterface $r): JsonResponse
    {
        $userId  = $this->auth($r);
        $company = $this->resolveCompanyForUser((string)$r->getAttribute('hash'), $userId);

        $stripe = $this->stripe();

        // Ensure a Stripe customer exists for this company
        $customerId = $this->requireStripeCustomer($stripe, $company);

        // Build a robust absolute return URL
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $proto  = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ($_SERVER['REQUEST_SCHEME'] ?? 'https');
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? ($proto . '://' . $host));
        $returnUrl = rtrim($origin, '/') . '/billing';

        // Optional: pick a specific portal configuration via env, or auto-pick the first active one
        $configId = $_ENV['STRIPE_PORTAL_CONFIGURATION_ID'] ?? null;
        if (!$configId) {
            try {
                $cfgs = $stripe->billingPortal->configurations->all(['active' => true, 'limit' => 1]);
                if (!empty($cfgs->data)) {
                    $configId = $cfgs->data[0]->id;
                }
            } catch (\Throwable $e) {
                // non-fatal; we can still try to create a session without explicit configuration
                $configId = null;
            }
        }

        try {
            $params = [
                'customer'   => $customerId,
                'return_url' => $returnUrl,
            ];
            if ($configId) {
                $params['configuration'] = $configId;
            }

            // Create the Billing Portal session
            $session = $stripe->billingPortal->sessions->create($params);

            return new JsonResponse(['url' => $session->url]);
        } catch (ApiErrorException $e) {
            // Map a few common issues to clearer messages
            $msg = $e->getMessage();
            $code = 500;

            if (stripos($msg, 'No such customer') !== false) {
                $code = 400;
                $msg = 'Stripe customer not found for this company.';
            } elseif (stripos($msg, 'Unrecognized request URL') !== false
                || stripos($msg, 'billing portal') !== false && stripos($msg, 'not enabled') !== false) {
                $code = 400;
                $msg = 'Stripe Billing Portal is not enabled on your Stripe account (or not available with this API key).';
            } elseif (stripos($msg, 'Invalid return_url') !== false) {
                $code = 400;
                $msg = 'Invalid return URL. It must be an absolute http(s) URL.';
            }

            // Include Stripe request id if available to help debugging
            $rid = $e->getHttpHeaders()['Request-Id'] ?? null;
            if ($rid) {
                $msg .= " (stripe_request_id={$rid})";
            }

            throw new RuntimeException($msg, $code);
        } catch (\JsonException $e) {
        }

        throw new RuntimeException('Could not create billing portal session.', 500);
    }
}
