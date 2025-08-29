<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Domain;
use App\Entity\IpPool;
use App\Entity\SmtpCredential;
use App\Service\CompanyResolver;
use App\Service\SmtpCredentialProvisioner;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Query\QueryBuilder;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class SmtpCredentialController
{
    public function __construct(
        private RepositoryFactory $repos,
        private CompanyResolver $companyResolver,
        private QueryBuilder $qb,
        private SmtpCredentialProvisioner $provisioner,
    ) {}

    /* ---------------------------- helpers ---------------------------- */

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

    private function now(): \DateTimeImmutable {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * For username preview only.
     * Priority: explicit "domain" in body → first company domain → "example.com".
     */
    private function domainHintForPreview(Company $co, array $body = []): string {
        $s = trim((string)($body['domain'] ?? ''));
        if ($s !== '') return $s;

        /** @var \App\Repository\DomainRepository $dRepo */
        $dRepo = $this->repos->getRepository(Domain::class);
        $domains = $dRepo->findBy(['company_id' => $co->getId()]);
        return ($domains[0]??null)?->getDomain() ?: 'example.com';
    }

    private function shape(SmtpCredential $c, ?string $domainHint = null): array {
        $prefix   = (string)($c->getUsername_prefix() ?? 'smtpuser');
        $username = $domainHint ? sprintf('%s@%s', $prefix, $domainHint) : $prefix;

        // Prefer relation; fall back to raw FK getter if present.
        $poolId = $c->getIpPool()?->getId();
        if ($poolId === null) {
            foreach (['getIppool_id','getIp_pool_id','getIpPoolId'] as $getter) {
                if (method_exists($c, $getter)) { $poolId = (int)$c->{$getter}(); break; }
            }
        }

        return [
            'id'               => $c->getId(),
            'username_prefix'  => $c->getUsername_prefix(),
            'scopes'           => $c->getScopes(),
            'limits'           => [
                'max_msgs_min' => $c->getMax_msgs_min(),
                'max_rcpt_msg' => $c->getMax_rcpt_msg(),
            ],
            'ip_pool'          => $poolId,
            'created_at'       => $c->getCreated_at()?->format(\DateTimeInterface::ATOM),
            'username_render'  => $username,
        ];
    }

    /* ----------------------------- CRUD ----------------------------- */

    #[Route(methods: 'GET', path: '/companies/{hash}/smtp-credentials')]
    public function list(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);

        /** @var \App\Repository\SmtpCredentialRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);

        $q       = $r->getQueryParams();
        $search  = trim((string)($q['search'] ?? ''));
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        $rows = $repo->findBy(['company_id' => $co->getId()]);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, fn(SmtpCredential $c) =>
            str_contains(mb_strtolower((string)$c->getUsername_prefix()), $needle)
            ));
        }

        $total = count($rows);
        $slice = array_slice($rows, ($page-1)*$perPage, $perPage);
        $hint  = $this->domainHintForPreview($co);

        return new JsonResponse([
            'meta'  => ['page'=>$page,'perPage'=>$perPage,'total'=>$total,'totalPages'=>(int)ceil($total/$perPage)],
            'items' => array_map(fn(SmtpCredential $c) => $this->shape($c, $hint), $slice),
        ]);
    }

    /**
     * Body (all optional unless noted):
     *  - username_prefix?: string ("smtpuser")
     *  - scopes?: string[] | csv/whitespace
     *  - max_msgs_min?: int>=0 (default 0)
     *  - max_rcpt_msg?: int>=0 (default 100)
     *  - ip_pool_id?: int|null (must belong to company if provided)
     *  - domain?: string (for username preview only)
     * @throws \ReflectionException
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/smtp-credentials')]
    public function create(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);

        $body   = json_decode((string)$r->getBody(), true) ?: [];
        $prefix = (string)($body['username_prefix'] ?? 'smtpuser');

        /** @var \App\Repository\SmtpCredentialRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);

        // Provision (creates row + returns plaintext password once)
        $domainHint = $this->domainHintForPreview($co, $body);
        $result     = $this->provisioner->provisionForCompany($co, $domainHint, $prefix);

        $ipPool = null;
        if (array_key_exists('ip_pool_id', $body)) {
            $ipPool = $repo->findOneBy(['id' => $body['ip_pool_id']]);
        }

        $maxMsgsMin = isset($body['max_msgs_min']) && is_numeric($body['max_msgs_min']) ? max(0, (int)$body['max_msgs_min']) : 0;
        $maxRcptMsg = isset($body['max_rcpt_msg']) && is_numeric($body['max_rcpt_msg']) ? max(0, (int)$body['max_rcpt_msg']) : 0;
        $rawScopes  = $body['scopes'] ?? [];
        $list       = is_array($rawScopes) ? $rawScopes : (is_string($rawScopes) ? preg_split('~[,\s]+~', trim($rawScopes), -1, PREG_SPLIT_NO_EMPTY) : []);
        $clean      = array_values(array_unique(array_filter(array_map('strval', $list), fn($s) => $s !== '')));
        // Fetch the credential created by provisioner
        $cred = $repo->findOneBy([
            'company' => $co,
            'username_prefix' => $prefix,
        ]);

        if (!$cred) {
            throw new \RuntimeException('Provisioner did not return a valid credential');
        }

        // Update extra fields
        $cred->setScopes($clean);
        $cred->setMax_msgs_min($maxMsgsMin);
        $cred->setMax_rcpt_msg($maxRcptMsg);
//         $cred->setIpPool($ipPool);

        $repo->save($cred);



        return new JsonResponse([
            'credential' => $this->shape($cred, $domainHint),
            'password'   => $result['password'],
        ], 201);
    }

    #[Route(methods: 'GET', path: '/companies/{hash}/smtp-credentials/{id}')]
    public function get(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\SmtpCredentialRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Credential not found', 404);

        $hint = $this->domainHintForPreview($co);
        return new JsonResponse($this->shape($c, $hint));
    }

    #[Route(methods: 'PATCH', path: '/companies/{hash}/smtp-credentials/{id}')]
    public function update(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\SmtpCredentialRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Credential not found', 404);

        $body = json_decode((string)$r->getBody(), true) ?: [];

        // Inline editable fields: scopes
        if (isset($body['scopes'])) {
            $raw   = $body['scopes'];
            $list  = is_array($raw) ? $raw : (is_string($raw) ? preg_split('~[,\s]+~', trim($raw), -1, PREG_SPLIT_NO_EMPTY) : []);
            $clean = array_values(array_unique(array_filter(array_map('strval', $list), fn($s) => $s !== '')));
            $c->setScopes($clean);
        }

        // Inline limits
        if (array_key_exists('max_msgs_min', $body)) {
            $v = $body['max_msgs_min'];
            $c->setMax_msgs_min(is_numeric($v) ? max(0, (int)$v) : $c->getMax_msgs_min());
        }
        if (array_key_exists('max_rcpt_msg', $body)) {
            $v = $body['max_rcpt_msg'];
            $c->setMax_rcpt_msg(is_numeric($v) ? max(0, (int)$v) : $c->getMax_rcpt_msg());
        }

        // Inline ip_pool (validate ownership)
        if (array_key_exists('ip_pool_id', $body)) {
            $pool = null;
            if ($body['ip_pool_id'] !== null && $body['ip_pool_id'] !== '' && is_numeric($body['ip_pool_id'])) {
                /** @var \App\Repository\IpPoolRepository $ipRepo */
                $ipRepo = $this->repos->getRepository(IpPool::class);
                $candidate = $ipRepo->find((int)$body['ip_pool_id']);
                if (!$candidate || $candidate->getCompany()?->getId() !== $co->getId()) {
                    throw new RuntimeException('IP pool not found in company', 400);
                }
                $pool = $candidate;
            }
            if (method_exists($c, 'setIpPool')) $c->setIpPool($pool);
            foreach (['setIppool_id','setIp_pool_id','setIpPoolId'] as $setter) {
                if (method_exists($c, $setter)) { $c->{$setter}($pool?->getId()); break; }
            }
        }

        $repo->save($c);

        $hint = $this->domainHintForPreview($co, $body);
        return new JsonResponse($this->shape($c, $hint));
    }

    #[Route(methods: 'DELETE', path: '/companies/{hash}/smtp-credentials/{id}')]
    public function delete(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\SmtpCredentialRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Credential not found', 404);

        // Optional: prevent deleting last credential
        $others = $repo->findBy(['company_id' => $co->getId()]);
        if (count($others) <= 1) throw new RuntimeException('Cannot delete the last SMTP credential for the company', 400);

        if (method_exists($repo, 'delete')) $repo->delete($c);
        elseif (method_exists($repo, 'remove')) $repo->remove($c);
        else $this->qb->delete('smtpcredential')->where('id','=', $id)->execute();

        return new JsonResponse(null, 204);
    }
}
