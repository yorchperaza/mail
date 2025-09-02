<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Plan;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final class PlanController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
    ) {}

    /* ---------------------------- helpers ---------------------------- */

    private function auth(ServerRequestInterface $r): int {
        $uid = (int)$r->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);
        return $uid;
    }

    /** Central Stripe client (same pattern as your other controllers) */
    private function stripe(): StripeClient {
        $sk = $_ENV['STRIPE_SECRET_KEY'] ?? $_ENV['STRIPE_SK'] ?? '';
        if ($sk === '') throw new RuntimeException('Stripe secret key missing', 500);
        return new StripeClient($sk);
    }

    private function currency(): string {
        $c = strtolower((string)($_ENV['STRIPE_CURRENCY'] ?? 'usd'));
        return $c ?: 'usd';
    }

    /** Uniform JSON shape for a Plan row */
    private function shape(Plan $p): array {
        return [
            'id'                 => $p->getId(),
            'name'               => $p->getName(),
            'monthlyPrice'       => $p->getMonthlyPrice(),
            'includedMessages'   => $p->getIncludedMessages(),
            'averagePricePer1K'  => $p->getAveragePricePer1K(),
            'features'           => $p->getFeatures(),
            'stripePriceId'      => $p->getStripe_price_id(),
        ];
    }

    /** @return array{0:array<int,Plan>,1:int} [rows,total] after in-memory filters */
    private function applyFilters(array $rows, array $q): array {
        $search   = trim((string)($q['search'] ?? ''));
        $minPrice = (string)($q['minPrice'] ?? '');
        $maxPrice = (string)($q['maxPrice'] ?? '');
        $hasFeat  = trim((string)($q['hasFeature'] ?? ''));

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function(Plan $p) use ($needle) {
                if (str_contains(mb_strtolower((string)($p->getName() ?? '')), $needle)) return true;
                if (str_contains(mb_strtolower((string)($p->getMonthlyPrice() ?? '')), $needle)) return true;
                if (str_contains(mb_strtolower((string)($p->getIncludedMessages() ?? '')), $needle)) return true;
                if (str_contains(mb_strtolower((string)($p->getAveragePricePer1K() ?? '')), $needle)) return true;
                $featJson = json_encode($p->getFeatures() ?? [], JSON_UNESCAPED_UNICODE);
                return $featJson && str_contains(mb_strtolower($featJson), $needle);
            }));
        }

        if ($minPrice !== '' || $maxPrice !== '') {
            $min = $minPrice === '' ? null : (float)$minPrice;
            $max = $maxPrice === '' ? null : (float)$maxPrice;
            $rows = array_values(array_filter($rows, function(Plan $p) use ($min, $max) {
                $price = $p->getMonthlyPrice();
                if ($price === null) return false;
                $f = (float)$price;
                if ($min !== null && $f < $min) return false;
                if ($max !== null && $f > $max) return false;
                return true;
            }));
        }

        if ($hasFeat !== '') {
            $needle = mb_strtolower($hasFeat);
            $rows = array_values(array_filter($rows, function(Plan $p) use ($needle) {
                $feat = $p->getFeatures() ?? [];
                $blob = mb_strtolower(json_encode($feat, JSON_UNESCAPED_UNICODE) ?: '');
                return str_contains($blob, $needle);
            }));
        }

        return [$rows, count($rows)];
    }

    /* -------------------------------- list -------------------------------- */

    #[Route(methods: 'GET', path: '/plans')]
    public function list(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);
        /** @var \App\Repository\PlanRepository $repo */
        $repo = $this->repos->getRepository(Plan::class);

        $q       = $r->getQueryParams();
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        $rows = $repo->findBy([]);
        [$filtered, $total] = $this->applyFilters($rows, $q);
        $slice = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        return new JsonResponse([
            'meta'  => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => (int)ceil($total / $perPage),
            ],
            'items' => array_map(fn(Plan $p) => $this->shape($p), $slice),
        ]);
    }

    /* ------------------------------- create ------------------------------- */

    /**
     * Body (JSON):
     *   name (required)
     *   monthlyPrice? (number)
     *   includedMessages? (int)
     *   averagePricePer1K? (number)
     *   features? (array)
     *
     * When monthlyPrice > 0, a Stripe Product+Price are created
     * and stripe_price_id is stored on the plan.
     */
    #[Route(methods: 'POST', path: '/plans')]
    public function create(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);

        /** @var \App\Repository\PlanRepository $repo */
        $repo = $this->repos->getRepository(Plan::class);

        $body = json_decode((string)$r->getBody(), true) ?: [];

        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Name is required', 400);

        $monthlyPrice     = $this->toDecimalOrNull($body['monthlyPrice'] ?? null);
        $includedMessages = $this->toIntOrNull($body['includedMessages'] ?? null);
        $avgPer1K         = $this->toDecimalOrNull($body['averagePricePer1K'] ?? null);
        $features         = array_key_exists('features', $body) ? $body['features'] : null;

        if ($features !== null && !is_array($features)) {
            throw new RuntimeException('features must be an array', 400);
        }

        // 1) Create the Plan first to get an ID
        $p = new Plan();
        $p->setName($name)
            ->setMonthlyPrice($monthlyPrice)
            ->setIncludedMessages($includedMessages)
            ->setAveragePricePer1K($avgPer1K)
            ->setFeatures($features);
        $repo->save($p); // ID now available

        // 2) If paid, create Stripe Product + Price and store price id
        if ($monthlyPrice !== null && $monthlyPrice > 0) {
            try {
                $stripe   = $this->stripe();
                $currency = $this->currency();
                $metadata = [
                    'app_plan_id'          => (string)$p->getId(),
                    'included_messages'    => $includedMessages !== null ? (string)$includedMessages : '',
                    'average_price_per_1k' => $avgPer1K !== null ? (string)$avgPer1K : '',
                ];

                $product = $stripe->products->create([
                    'name'        => $name,
                    'active'      => true,
                    'metadata'    => array_filter($metadata, fn($v) => $v !== ''),
                    // Optional: 'description' => $features ? substr(json_encode($features), 0, 5000) : null,
                ]);

                $amount = (int)round($monthlyPrice * 100);
                if ($amount <= 0) throw new RuntimeException('Price must be > 0 for paid plans', 400);

                $price = $stripe->prices->create([
                    'product'      => $product->id,
                    'unit_amount'  => $amount,
                    'currency'     => $currency,
                    'recurring'    => ['interval' => 'month'],
                    'active'       => true,
                    'metadata'     => ['app_plan_id' => (string)$p->getId()],
                ]);

                $p->setStripe_price_id($price->id);
                $repo->save($p);
            } catch (ApiErrorException $e) {
                // Rollback Stripe coupling if something went wrong
                $p->setStripe_price_id(null);
                $repo->save($p);
                throw new RuntimeException('Stripe error while creating product/price: ' . $e->getMessage(), 500);
            }
        }

        return new JsonResponse($this->shape($p), 201);
    }

    /** ----- helpers for safe coercion ----- */
    private function toDecimalOrNull(mixed $v): ?float {
        if ($v === null) return null;
        if (is_string($v)) $v = trim($v);
        if ($v === '' || $v === 'null') return null;
        if (is_numeric($v)) return (float)$v;
        return null;
    }
    private function toIntOrNull(mixed $v): ?int {
        if ($v === null) return null;
        if (is_string($v)) $v = trim($v);
        if ($v === '' || $v === 'null') return null;
        if (is_numeric($v)) return (int)$v;
        return null;
    }

    /* --------------------------------- get -------------------------------- */

    #[Route(methods: 'GET', path: '/plans-id/{id}')]
    public function get(ServerRequestInterface $r): JsonResponse {
        /** @var \App\Repository\PlanRepository $repo */
        $repo = $this->repos->getRepository(Plan::class);
        $id = (int)$r->getAttribute('id');
        $p = $repo->find($id);
        if (!$p) throw new RuntimeException('Plan not found', 404);
        return new JsonResponse($this->shape($p));
    }

    /* ------------------------------- update ------------------------------- */

    /**
     * Body (all optional):
     *   name?, monthlyPrice?, includedMessages?, averagePricePer1K?, features?(array|null), stripe_price_id?(string|null)
     *
     * If monthlyPrice changes and the plan is paid, a NEW Stripe Price is created
     * (Stripe prices are immutable). Old price is deactivated. Product name is kept
     * in sync with plan name if a product exists.
     */
    #[Route(methods: 'PATCH', path: '/plans/{id}')]
    public function update(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);
        $id = (int)$r->getAttribute('id');

        /** @var \App\Repository\PlanRepository $repo */
        $repo = $this->repos->getRepository(Plan::class);
        $p = $repo->find($id);
        if (!$p) throw new RuntimeException('Plan not found', 404);

        $body = json_decode((string)$r->getBody(), true) ?: [];

        $oldName        = $p->getName();
        $oldMonthly     = $p->getMonthlyPrice();
        $oldStripePrice = $p->getStripe_price_id();

        if (array_key_exists('name', $body)) {
            $name = trim((string)$body['name']);
            if ($name === '') throw new RuntimeException('Name cannot be empty', 400);
            $p->setName($name);
        }
        if (array_key_exists('monthlyPrice', $body)) {
            $p->setMonthlyPrice(
                $body['monthlyPrice'] === null ? null : (float)$body['monthlyPrice']
            );
        }
        if (array_key_exists('includedMessages', $body)) {
            $p->setIncludedMessages(
                $body['includedMessages'] === null ? null : (int)$body['includedMessages']
            );
        }
        if (array_key_exists('averagePricePer1K', $body)) {
            $p->setAveragePricePer1K(
                $body['averagePricePer1K'] === null ? null : (float)$body['averagePricePer1K']
            );
        }
        if (array_key_exists('features', $body)) {
            $features = $body['features'];
            if ($features !== null && !is_array($features)) {
                throw new RuntimeException('features must be an array or null', 400);
            }
            $p->setFeatures($features);
        }

        if (array_key_exists('stripe_price_id', $body)) {
            $stripePriceId = $body['stripe_price_id'];
            if ($stripePriceId !== null && !is_string($stripePriceId)) {
                throw new RuntimeException('stripe_price_id must be a string or null', 400);
            }
            $p->setStripe_price_id($stripePriceId);
        }

        $repo->save($p);

        // --- Sync with Stripe if needed ---
        try {
            $stripe = $this->stripe();
            $currency = $this->currency();

            // If the name changed and we have a Stripe product behind this price, update the product's name
            if ($p->getName() !== $oldName && $p->getStripe_price_id()) {
                $currentPrice = $stripe->prices->retrieve($p->getStripe_price_id());
                if (is_string($currentPrice->product) && $currentPrice->product !== '') {
                    $stripe->products->update($currentPrice->product, ['name' => (string)$p->getName()]);
                }
            }

            // Handle monthly price changes
            $newMonthly = $p->getMonthlyPrice();

            // Case: switch to free/undefined -> deactivate existing price and clear ref
            if (($newMonthly === null || $newMonthly <= 0) && $oldStripePrice) {
                $stripe->prices->update($oldStripePrice, ['active' => false]);
                $p->setStripe_price_id(null);
                $repo->save($p);
            }

            // Case: (re)paid plan and amount changed -> ensure product exists, create a new price
            if ($newMonthly !== null && $newMonthly > 0 && $newMonthly !== $oldMonthly) {
                $productId = null;

                if ($oldStripePrice) {
                    $cur = $stripe->prices->retrieve($oldStripePrice);
                    $productId = is_string($cur->product) ? $cur->product : null;
                }

                if (!$productId) {
                    // Create a product if not present
                    $product = $stripe->products->create([
                        'name'     => (string)$p->getName(),
                        'active'   => true,
                        'metadata' => ['app_plan_id' => (string)$p->getId()],
                    ]);
                    $productId = $product->id;
                }

                $newAmount = (int)round($newMonthly * 100);
                if ($newAmount <= 0) throw new RuntimeException('Price must be > 0 for paid plans', 400);

                $newPrice = $stripe->prices->create([
                    'product'     => $productId,
                    'unit_amount' => $newAmount,
                    'currency'    => $currency,
                    'recurring'   => ['interval' => 'month'],
                    'active'      => true,
                    'metadata'    => ['app_plan_id' => (string)$p->getId()],
                ]);

                // Deactivate the old price (if any), point plan to the new one
                if ($oldStripePrice) {
                    $stripe->prices->update($oldStripePrice, ['active' => false]);
                }
                $p->setStripe_price_id($newPrice->id);
                $repo->save($p);
            }
        } catch (ApiErrorException $e) {
            // We keep the local change but surface the Stripe problem
            throw new RuntimeException('Stripe error while syncing plan: ' . $e->getMessage(), 500);
        }

        return new JsonResponse($this->shape($p));
    }

    /* ------------------------------- delete ------------------------------- */

    #[Route(methods: 'DELETE', path: '/plans/{id}')]
    public function delete(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);
        $id = (int)$r->getAttribute('id');

        /** @var \App\Repository\PlanRepository $repo */
        $repo = $this->repos->getRepository(Plan::class);
        $p = $repo->find($id);
        if (!$p) throw new RuntimeException('Plan not found', 404);

        // Try to archive on Stripe (best-effort)
        if ($p->getStripe_price_id()) {
            try {
                $stripe = $this->stripe();
                $price  = $stripe->prices->retrieve($p->getStripe_price_id());
                // Deactivate price
                $stripe->prices->update($price->id, ['active' => false]);
                // Archive product if we can resolve it
                if (is_string($price->product) && $price->product !== '') {
                    $stripe->products->update($price->product, ['active' => false]);
                }
            } catch (ApiErrorException $e) {
                // swallow; deletion should continue locally
            }
        }

        if (method_exists($repo, 'delete')) $repo->delete($p);
        elseif (method_exists($repo, 'remove')) $repo->remove($p);
        else $this->qb->delete('plan')->where('id', '=', $id)->execute();

        return new JsonResponse(null, 204);
    }

    #[Route(methods: 'GET', path: '/plans-brief')]
    public function listBrief(ServerRequestInterface $r): JsonResponse {
        /** @var \App\Repository\PlanRepository $repo */
        $repo = $this->repos->getRepository(Plan::class);
        $plans = $repo->findBy([]);
        $items = array_map(static fn(Plan $p) => [
            'id'   => $p->getId(),
            'name' => $p->getName(),
        ], $plans);

        return new JsonResponse($items);
    }
}
