<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Template;
use App\Service\CompanyResolver;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;
use RuntimeException;

final class TemplateController
{
    public function __construct(
        private RepositoryFactory $repos,
        private CompanyResolver   $companyResolver,
        private QueryBuilder      $qb,
    ) {}

    /* ---------------------------- helpers ---------------------------- */

    private function auth(ServerRequestInterface $r): int {
        $uid = (int)$r->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);
        return $uid;
    }

    /**
     * @throws \ReflectionException
     */
    private function company(string $hash, int $uid): Company {
        $c = $this->companyResolver->resolveCompanyForUser($hash, $uid);
        if (!$c) throw new RuntimeException('Company not found or access denied', 404);
        return $c;
    }

    private function now(): \DateTimeImmutable {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function shape(Template $t): array {
        return [
            'id'          => $t->getId(),
            'name'        => $t->getName(),
            'engine'      => $t->getEngine(),
            'version'     => $t->getVersion(),
            'html'        => $t->getHtml(),
            'text'        => $t->getText(),
            'created_at'  => $t->getCreated_at()?->format(\DateTimeInterface::ATOM),
            'hash'        => $t->getHash(),
            'usage'       => [
                'campaigns' => is_countable($t->getCampaigns()) ? count($t->getCampaigns()) : null,
            ],
        ];
    }

    /* ------------------------------ list ------------------------------ */

    #[Route(methods: 'GET', path: '/companies/{hash}/templates')]
    public function list(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);

        /** @var \App\Repository\TemplateRepository $repo */
        $repo = $this->repos->getRepository(Template::class);

        $q       = $r->getQueryParams();
        $search  = trim((string)($q['search'] ?? ''));
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        // With your repository layer this is likely already filtered by company_id when provided.
        // If not, filter in PHP as below.
        $rows = $repo->findBy(['company_id' => $co->getId()]);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function(Template $t) use ($needle) {
                $n = mb_strtolower((string)$t->getName());
                $e = mb_strtolower((string)$t->getEngine());
                return str_contains($n, $needle) || str_contains($e, $needle);
            }));
        }

        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new JsonResponse([
            'meta'  => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => (int)ceil($total / $perPage),
            ],
            'items' => array_map(fn(Template $t) => $this->shape($t), $slice),
        ]);
    }

    /* ------------------------------ create ----------------------------- */

    /**
     * Body:
     *   name (required), engine?, version?, html?, text?
     * @throws RandomException
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/templates')]
    public function create(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);

        $body = json_decode((string)$r->getBody(), true) ?: [];
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Name is required', 400);

        $engine  = isset($body['engine']) ? (string)$body['engine'] : null;
        $version = array_key_exists('version', $body) ? (int)$body['version'] : null;
        $html    = array_key_exists('html', $body) ? (string)$body['html'] : null;
        $text    = array_key_exists('text', $body) ? (string)$body['text'] : null;

        /** @var \App\Repository\TemplateRepository $repo */
        $repo = $this->repos->getRepository(Template::class);

        $t = (new Template())
            ->setCompany($co)
            ->setName($name)
            ->setEngine($engine)
            ->setVersion($version)
            ->setHtml($html)
            ->setText($text)
            ->setCreated_at($this->now())
            ->setHash(bin2hex(random_bytes(16))); // 32-char hex

        $repo->save($t);

        return new JsonResponse($this->shape($t), 201);
    }

    /* -------------------------------- get ------------------------------- */

    #[Route(methods: 'GET', path: '/companies/{hash}/templates/{id}')]
    public function get(ServerRequestInterface $r): JsonResponse {
        $id  = (int)$r->getAttribute('id');
        /** @var \App\Repository\TemplateRepository $repo */
        $repo = $this->repos->getRepository(Template::class);
        $t = $repo->find($id);
        if (!$t) {
            throw new RuntimeException('Template not found', 404);
        }

        return new JsonResponse($this->shape($t));
    }

    /* ------------------------------- update ----------------------------- */

    /**
     * Body (all optional):
     *   name?, engine?, version?, html?, text?
     */
    #[Route(methods: 'PATCH', path: '/companies/{hash}/templates/{id}')]
    public function update(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\TemplateRepository $repo */
        $repo = $this->repos->getRepository(Template::class);
        $t = $repo->find($id);
        if (!$t || $t->getCompany()?->getId() !== $co->getId()) {
            throw new RuntimeException('Template not found', 404);
        }

        $body = json_decode((string)$r->getBody(), true) ?: [];

        if (array_key_exists('name', $body)) {
            $name = trim((string)$body['name']);
            if ($name === '') throw new RuntimeException('Name cannot be empty', 400);
            $t->setName($name);
        }
        if (array_key_exists('engine', $body)) {
            $t->setEngine((string)$body['engine'] ?: null);
        }
        if (array_key_exists('version', $body)) {
            $t->setVersion(
                $body['version'] === null || $body['version'] === ''
                    ? null
                    : (int)$body['version']
            );
        }
        if (array_key_exists('html', $body)) {
            $t->setHtml((string)$body['html'] ?? null);
        }
        if (array_key_exists('text', $body)) {
            $t->setText((string)$body['text'] ?? null);
        }

        $repo->save($t);

        return new JsonResponse($this->shape($t));
    }

    /* ------------------------------- delete ----------------------------- */

    #[Route(methods: 'DELETE', path: '/companies/{hash}/templates/{id}')]
    public function delete(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\TemplateRepository $repo */
        $repo = $this->repos->getRepository(Template::class);
        $t = $repo->find($id);
        if (!$t || $t->getCompany()?->getId() !== $co->getId()) {
            throw new RuntimeException('Template not found', 404);
        }

        // If your ORM/repo supports delete/remove:
        if (method_exists($repo, 'delete')) {
            $repo->delete($t);
        } elseif (method_exists($repo, 'remove')) {
            $repo->remove($t);
        } else {
            // Fallback raw delete if needed
            $this->qb->delete('template')->where('id', '=', $id)->execute();
        }

        return new JsonResponse(null, 204);
    }
}
