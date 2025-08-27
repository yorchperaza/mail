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

final class PlanController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
    ) {}

    /* ---------------------------- helpers ---------------------------- */

    private function auth(ServerRequestInterface $r): int {
        // Keep consistent with your AutomationController
        $uid = (int)$r->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);
        return $uid;
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
        ];
    }

    /** @return array{0:array<int,Plan>,1:int} [rows,total] after in-memory filters */
    private function applyFilters(array $rows, array $q): array {
        $search   = trim((string)($q['search'] ?? ''));
        $minPrice = (string)($q['minPrice'] ?? '');
        $maxPrice = (string)($q['maxPrice'] ?? '');
        $hasFeat  = trim((string)($q['hasFeature'] ?? '')); // substring match inside features JSON

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function(Plan $p) use ($needle) {
                if (str_contains(mb_strtolower((string)($p->getName() ?? '')), $needle)) return true;
                // search numeric fields as strings too
                if (str_contains(mb_strtolower((string)($p->getMonthlyPrice() ?? '')), $needle)) return true;
                if (str_contains(mb_strtolower((string)($p->getIncludedMessages() ?? '')), $needle)) return true;
                if (str_contains(mb_strtolower((string)($p->getAveragePricePer1K() ?? '')), $needle)) return true;
                // search features JSON
                $featJson = json_encode($p->getFeatures() ?? [], JSON_UNESCAPED_UNICODE);
                return $featJson && str_contains(mb_strtolower($featJson), $needle);
            }));
        }

        if ($minPrice !== '' || $maxPrice !== '') {
            $min = $minPrice === '' ? null : (float)$minPrice;
            $max = $maxPrice === '' ? null : (float)$maxPrice;
            $rows = array_values(array_filter($rows, function(Plan $p) use ($min, $max) {
                $price = $p->getMonthlyPrice();
                // Treat null price as failing the range filter
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

    /**
     * Query params:
     *   search?     - free text (name, numeric fields, features JSON)
     *   minPrice?   - number (inclusive)
     *   maxPrice?   - number (inclusive)
     *   hasFeature? - substring to search within features JSON
     *   page?       - default 1
     *   perPage?    - default 25 (max 200)
     */
    #[Route(methods: 'GET', path: '/plans')]
    public function list(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);

        /** @var \App\Repository\PlanRepository $repo */
        $repo = $this->repos->getRepository(Plan::class);

        $q       = $r->getQueryParams();
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        // NOTE: your repository appears to be in-memory for demo; adjust if you support query-level filters.
        $rows = $repo->findBy([]); // all plans

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
     */
    #[Route(methods: 'POST', path: '/plans')]
    public function create(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);

        /** @var \App\Repository\PlanRepository $repo */
        $repo = $this->repos->getRepository(Plan::class);

        $body = json_decode((string)$r->getBody(), true) ?: [];

        $name = trim((string)($body['name'] ?? ''));
        error_log('name: ' . var_export($name, true));
        if ($name === '') throw new RuntimeException('Name is required', 400);

        $monthlyPrice     = $this->toDecimalOrNull($body['monthlyPrice'] ?? null);
        $includedMessages = $this->toIntOrNull($body['includedMessages'] ?? null);
        $avgPer1K         = $this->toDecimalOrNull($body['averagePricePer1K'] ?? null);
        $features         = array_key_exists('features', $body) ? $body['features'] : null;

        if ($features !== null && !is_array($features)) {
            throw new RuntimeException('features must be an array', 400);
        }

        $p = new Plan()
            ->setName($name)
            ->setMonthlyPrice($monthlyPrice)
            ->setIncludedMessages($includedMessages)
            ->setAveragePricePer1K($avgPer1K)
            ->setFeatures($features);

        $repo->save($p);

        return new JsonResponse($this->shape($p), 201);
    }

    /** ----- helpers for safe coercion ----- */
    private function toDecimalOrNull(mixed $v): ?float {
        if ($v === null) return null;
        if (is_string($v)) $v = trim($v);
        if ($v === '' || $v === 'null') return null;
        if (is_numeric($v)) return (float)$v;
        return null; // or throw if you prefer strict validation
    }
    private function toIntOrNull(mixed $v): ?int {
        if ($v === null) return null;
        if (is_string($v)) $v = trim($v);
        if ($v === '' || $v === 'null') return null;
        if (is_numeric($v)) return (int)$v;
        return null;
    }

    /* --------------------------------- get -------------------------------- */

    #[Route(methods: 'GET', path: '/plans/{id}')]
    public function get(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);
        $id = (int)$r->getAttribute('id');

        /** @var \App\Repository\PlanRepository $repo */
        $repo = $this->repos->getRepository(Plan::class);
        $p = $repo->find($id);
        if (!$p) throw new RuntimeException('Plan not found', 404);

        return new JsonResponse($this->shape($p));
    }

    /* ------------------------------- update ------------------------------- */

    /**
     * Body (all optional):
     *   name?, monthlyPrice?, includedMessages?, averagePricePer1K?, features?(array|null)
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

        $repo->save($p);

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

        if (method_exists($repo, 'delete')) $repo->delete($p);
        elseif (method_exists($repo, 'remove')) $repo->remove($p);
        else $this->qb->delete('plan')->where('id', '=', $id)->execute();

        return new JsonResponse(null, 204);
    }

    #[Route(methods: 'GET', path: '/plans/brief')]
    public function listBrief(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);

        /** @var \App\Repository\PlanRepository $repo */
        $repo = $this->repos->getRepository(Plan::class);

        // Load all plans (adjust to use DB-level filtering if your repo supports it)
        $plans = $repo->findBy([]);

        // Return only id + name
        $items = array_map(static fn(Plan $p) => [
            'id'   => $p->getId(),
            'name' => $p->getName(),
        ], $plans);

        return new JsonResponse($items);
    }

}
