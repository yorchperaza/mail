<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Segment;
use App\Service\CompanyResolver;
use App\Service\SegmentBuildOrchestrator;
use App\Service\SegmentBuildService;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class SegmentBuildController
{
    public function __construct(
        private RepositoryFactory        $repos,
        private CompanyResolver          $companyResolver,
        private SegmentBuildOrchestrator $orchestrator,
        private SegmentBuildService      $service,
    ) {}

    private function auth(ServerRequestInterface $r): int {
        $uid = (int)$r->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);
        return $uid;
    }
    private function company(string $hash, int $uid): Company {
        $c = $this->companyResolver->resolveCompanyForUser($hash, $uid);
        if (!$c) throw new RuntimeException('Company not found or access denied', 404);
        return $c;
    }
    private function segment(int $id, Company $c): Segment {
        /** @var \App\Repository\SegmentRepository $repo */
        $repo = $this->repos->getRepository(Segment::class);
        $s = $repo->find($id);
        if (!$s || (int)($s->getCompany()?->getId() ?? 0) !== (int)$c->getId()) {
            throw new RuntimeException('Segment not found', 404);
        }
        return $s;
    }

    /** POST /companies/{hash}/segments/{id}/builds — enqueue a build (202 Accepted)
     * @throws \DateMalformedStringException
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/segments/{id}/builds')]
    public function enqueue(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $seg = $this->segment((int)$r->getAttribute('id'), $co);

        $body = json_decode((string)$r->getBody(), true) ?: [];
        $materialize = (bool)($body['materialize'] ?? true);

        $entryId = $this->orchestrator->enqueueBuild(
            $co->getId(),
            $seg->getId(),
            $materialize
        );

        return new JsonResponse([
            'status'  => 'enqueued',
            'entryId' => $entryId,
            'segment' => ['id' => $seg->getId(), 'name' => $seg->getName()],
        ], 202);
    }

    /** GET /companies/{hash}/segments/{id}/builds — list past builds (paginated) */
    #[Route(methods: 'GET', path: '/companies/{hash}/segments/{id}/builds')]
    public function list(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $seg = $this->segment((int)$r->getAttribute('id'), $co);

        $q = $r->getQueryParams();
        $page = max(1, (int)($q['page'] ?? 1));
        $per  = max(1, min(200, (int)($q['perPage'] ?? 25)));

        $res = $this->service->listBuilds($seg, $page, $per);
        return new JsonResponse($res);
    }

    /** GET /companies/{hash}/segments/{id}/builds/status — last cached status (fast) */
    #[Route(methods: 'GET', path: '/companies/{hash}/segments/{id}/builds/status')]
    public function status(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $seg = $this->segment((int)$r->getAttribute('id'), $co);

        $cached = $this->orchestrator->lastStatus($co->getId(), $seg->getId());
        return new JsonResponse($cached ?: ['status' => 'unknown']);
    }

    /**
     * (Optional) POST /companies/{hash}/segments/{id}/builds/run-now
     * Synchronous build for small segments or admin tools.
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/segments/{id}/builds/run-now')]
    public function runNow(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $seg = $this->segment((int)$r->getAttribute('id'), $co);

        $body = json_decode((string)$r->getBody(), true) ?: [];
        $materialize = (bool)($body['materialize'] ?? true);

        $res = $this->orchestrator->runBuildJob([
            'company_id'  => $co->getId(),
            'segment_id'  => $seg->getId(),
            'materialize' => $materialize,
        ]);

        return new JsonResponse($res, ($res['status'] ?? '') === 'ok' ? 200 : 409);
    }
}
