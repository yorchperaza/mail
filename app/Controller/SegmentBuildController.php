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
    ) {
        error_log('[SEG][CTRL] ctor');
    }

    private function auth(ServerRequestInterface $r): int {
        $uid = (int)$r->getAttribute('user_id', 0);
        error_log('[SEG][CTRL] auth uid='.$uid);
        if ($uid <= 0) {
            error_log('[SEG][CTRL][ERR] Unauthorized');
            throw new RuntimeException('Unauthorized', 401);
        }
        return $uid;
    }

    private function company(string $hash, int $uid): Company {
        error_log(sprintf('[SEG][CTRL] company resolve hash=%s uid=%d', $hash, $uid));
        $c = $this->companyResolver->resolveCompanyForUser($hash, $uid);
        if (!$c) {
            error_log('[SEG][CTRL][ERR] company not found or access denied');
            throw new RuntimeException('Company not found or access denied', 404);
        }
        /** @var Company $c */
        error_log('[SEG][CTRL] company ok id='.$c->getId());
        return $c;
    }

    private function segment(int $id, Company $c): Segment {
        error_log(sprintf('[SEG][CTRL] segment resolve id=%d company_id=%d', $id, $c->getId()));
        /** @var \App\Repository\SegmentRepository $repo */
        $repo = $this->repos->getRepository(Segment::class);
        $s = $repo->find($id);
        if (!$s || (int)($s->getCompany()?->getId() ?? 0) !== (int)$c->getId()) {
            error_log('[SEG][CTRL][ERR] segment not found or mismatch');
            throw new RuntimeException('Segment not found', 404);
        }
        /** @var Segment $s */
        error_log('[SEG][CTRL] segment ok id='.$s->getId());
        return $s;
    }

    /** POST /companies/{hash}/segments/{id}/builds — enqueue a build (202 Accepted) */
    #[Route(methods: 'POST', path: '/companies/{hash}/segments/{id}/builds')]
    public function enqueue(ServerRequestInterface $r): JsonResponse {
        error_log('[SEG][CTRL][ENQ] start');
        try {
            $uid = $this->auth($r);
            $hash = (string)$r->getAttribute('hash');
            $segId = (int)$r->getAttribute('id');

            $co  = $this->company($hash, $uid);
            $seg = $this->segment($segId, $co);

            $body = json_decode((string)$r->getBody(), true) ?: [];
            $materialize = (bool)($body['materialize'] ?? true);

            // Add to queue
            $entryId = $this->orchestrator->enqueueBuild(
                $co->getId(),
                $seg->getId(),
                $materialize
            );

            // IMMEDIATELY process the queue (don't wait for worker)
            $this->orchestrator->runOnce(1, 100);

            return new JsonResponse([
                'status'  => 'processing',
                'entryId' => $entryId,
                'segment' => ['id' => $seg->getId(), 'name' => $seg->getName()],
            ], 200); // Changed from 202 to 200
        } catch (\Throwable $e) {
            error_log('[SEG][CTRL][ENQ][ERR] '.$e->getMessage());
            throw $e;
        }
    }

    /** GET /companies/{hash}/segments/{id}/builds — list past builds (paginated) */
    #[Route(methods: 'GET', path: '/companies/{hash}/segments/{id}/builds')]
    public function list(ServerRequestInterface $r): JsonResponse {
        error_log('[SEG][CTRL][LIST] start');
        try {
            $uid  = $this->auth($r);
            $hash = (string)$r->getAttribute('hash');
            $sid  = (int)$r->getAttribute('id');
            error_log(sprintf('[SEG][CTRL][LIST] params hash=%s segId=%d', $hash, $sid));

            $co  = $this->company($hash, $uid);
            $seg = $this->segment($sid, $co);

            $q = $r->getQueryParams();
            $page = max(1, (int)($q['page'] ?? 1));
            $per  = max(1, min(200, (int)($q['perPage'] ?? 25)));
            error_log(sprintf('[SEG][CTRL][LIST] page=%d per=%d', $page, $per));

            $res = $this->service->listBuilds($seg, $page, $per);
            $total = isset($res['total']) ? (int)$res['total'] : -1;
            error_log(sprintf('[SEG][CTRL][LIST] ok total=%d', $total));

            return new JsonResponse($res);
        } catch (\Throwable $e) {
            error_log('[SEG][CTRL][LIST][ERR] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            throw $e;
        }
    }

    /** GET /companies/{hash}/segments/{id}/builds/status — last cached status (fast) */
    #[Route(methods: 'GET', path: '/companies/{hash}/segments/{id}/builds/status')]
    public function status(ServerRequestInterface $r): JsonResponse {
        error_log('[SEG][CTRL][STATUS] start');
        try {
            $uid  = $this->auth($r);
            $hash = (string)$r->getAttribute('hash');
            $sid  = (int)$r->getAttribute('id');
            error_log(sprintf('[SEG][CTRL][STATUS] params hash=%s segId=%d', $hash, $sid));

            $co  = $this->company($hash, $uid);
            $seg = $this->segment($sid, $co);

            $cached = $this->orchestrator->lastStatus($co->getId(), $seg->getId());
            $have  = is_array($cached) ? '1' : '0';
            $status = is_array($cached) && isset($cached['status']) ? (string)$cached['status'] : 'unknown';
            error_log(sprintf('[SEG][CTRL][STATUS] hit=%s status=%s', $have, $status));

            return new JsonResponse($cached ?: ['status' => 'unknown']);
        } catch (\Throwable $e) {
            error_log('[SEG][CTRL][STATUS][ERR] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            throw $e;
        }
    }

    /**
     * (Optional) POST /companies/{hash}/segments/{id}/builds/run-now
     * Synchronous build for small segments or admin tools.
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/segments/{id}/builds/run-now')]
    public function runNow(ServerRequestInterface $r): JsonResponse {
        error_log('[SEG][CTRL][RUN] start');
        try {
            $uid  = $this->auth($r);
            $hash = (string)$r->getAttribute('hash');
            $sid  = (int)$r->getAttribute('id');
            error_log(sprintf('[SEG][CTRL][RUN] params hash=%s segId=%d', $hash, $sid));

            $co  = $this->company($hash, $uid);
            $seg = $this->segment($sid, $co);

            $rawBody     = (string)$r->getBody();
            $bodyLen     = strlen($rawBody);
            $body        = json_decode($rawBody, true) ?: [];
            $materialize = (bool)($body['materialize'] ?? true);
            error_log(sprintf('[SEG][CTRL][RUN] bodyLen=%d materialize=%s', $bodyLen, $materialize ? '1' : '0'));

            // *** IMPORTANT: use the heartbeat runner so status key is updated during the run ***
            $res = $this->orchestrator->runBuildJobWithHeartbeat([
                'company_id'  => $co->getId(),
                'segment_id'  => $seg->getId(),
                'materialize' => $materialize,
            ]);

            $ok = (string)($res['status'] ?? '') === 'ok';
            error_log(sprintf('[SEG][CTRL][RUN] done ok=%s', $ok ? '1' : '0'));

            // Make the response shape friendly for both "enqueue" UI and "sync run" UI:
            // - keep HTTP 200 because we actually performed the job
            // - include a 'mode' so the frontend can branch
            // - include 'entryId' as null (so the same UI code doesn't choke)
            return new JsonResponse([
                'mode'     => 'sync',
                'status'   => $ok ? 'ok' : 'error',
                'entryId'  => null,
                'result'   => $res,
                'company'  => (int)$co->getId(),
                'segment'  => (int)$seg->getId(),
                'at'       => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            ], 200);
        } catch (\Throwable $e) {
            error_log('[SEG][CTRL][RUN][ERR] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            // Surface structured error
            $code = ($e instanceof \RuntimeException && $e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            return new JsonResponse([
                'mode'   => 'sync',
                'status' => 'error',
                'error'  => $e->getMessage(),
            ], $code);
        }
    }

}
