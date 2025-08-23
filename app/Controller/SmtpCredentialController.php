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
        private QueryBuilder $qb,
        private SmtpCredentialProvisioner $provisioner,
        private CompanyResolver   $companyResolver,
    ) {}

    /* ---------------------------- Helpers ---------------------------- */

    private function authenticateUser(ServerRequestInterface $request): int
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }
        return $userId;
    }

    /**
     * @throws \ReflectionException
     */
    private function resolveCompany(string $hash, int $userId): Company
    {
        /** @var \App\Service\CompanyResolver $resolver */
        $company = $this->companyResolver->resolveCompanyForUser($hash, $userId);
        if (!$company) {
            throw new RuntimeException('Company not found or access denied', 404);
        }
        return $company;
    }

    private function shapeCred(SmtpCredential $c, ?string $domainForUsername = null): array
    {
        $prefix = (string)($c->getUsername_prefix() ?? 'smtpuser');
        $username = $domainForUsername ? sprintf('%s@%s', $prefix, $domainForUsername) : $prefix;

        return [
            'id'            => $c->getId(),
            'username_prefix'=> $c->getUsername_prefix(),
            'scopes'        => $c->getScopes(),
            'limits'        => [
                'max_msgs_min' => $c->getMax_msgs_min(),
                'max_rcpt_msg' => $c->getMax_rcpt_msg(),
            ],
            'ip_pool'       => $c->getIpPool()?->getId(),
            'created_at'    => $c->getCreated_at()?->format(\DateTimeInterface::ATOM),
            'username_render' => $username, // helpful for UIs
        ];
    }

    /* ---------------------- Company-level CRUD ---------------------- */

    #[Route(methods: 'GET', path: '/companies/{hash}/smtp-credentials')]
    public function listCompanyCreds(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);

        /** @var \App\Repository\SmtpCredentialRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);
        $rows = $repo->findBy(['company_id' => $company->getId()]);

        $out = array_map(fn (SmtpCredential $c) => $this->shapeCred($c), $rows);
        return new JsonResponse(['items' => $out, 'total' => count($out)]);
    }

    /**
     * Create or provision a credential.
     * Body (all optional):
     *   - username_prefix: string (default "smtpuser")
     *   - scopes: string[] (default ["submit"])
     *   - max_msgs_min: int (default 0)
     *   - max_rcpt_msg: int (default 100)
     *   - ip_pool_id: int|null
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/smtp-credentials')]
    public function createCompanyCred(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);

        $body = json_decode((string)$request->getBody(), true) ?: [];
        $prefix = (string)($body['username_prefix'] ?? 'smtpuser');

        /** @var \App\Repository\SmtpCredentialRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);

        // Reuse if exists for same prefix
        $existing = $repo->findOneBy([
            'company_id'      => $company->getId(),
            'username_prefix' => $prefix,
        ]);

        if ($existing) {
            // If you want to allow updating limits/scopes on POST when it already exists, do it here:
            $this->applyEditableFields($existing, $body);
            $repo->save($existing);
            return new JsonResponse([
                'credential' => $this->shapeCred($existing),
                'password'   => null, // not rotating on reuse
            ], 200);
        }

        // Else create via provisioner (which returns plaintext once)
        // We need any domain string only to render a username for UI; not required for creation.
        $domainHint = (string)($body['domain'] ?? 'example.com');
        $result     = $this->provisioner->provisionForCompany($company, $domainHint, $prefix);

        // Fetch the created row to return full meta
        $created = $repo->findOneBy([
            'company_id'      => $company->getId(),
            'username_prefix' => $prefix,
        ]);

        if (!$created) {
            throw new RuntimeException('Credential provisioning failed', 500);
        }

        // Apply additional fields (limits/scopes/ip_pool) if provided
        $this->applyEditableFields($created, $body);
        $repo->save($created);

        return new JsonResponse([
            'credential' => $this->shapeCred($created, $domainHint),
            'password'   => $result['password'], // show once
        ], 201);
    }

    #[Route(methods: 'GET', path: '/companies/{hash}/smtp-credentials/{id}')]
    public function getCompanyCred(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $id      = (int)$request->getAttribute('id', 0);

        /** @var \App\Repository\SmtpCredentialRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);
        $cred = $repo->find($id);
        if (!$cred || $cred->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Credential not found', 404);
        }

        return new JsonResponse($this->shapeCred($cred));
    }

    /**
     * PATCH allows updating:
     *   scopes: string[]
     *   max_msgs_min: int
     *   max_rcpt_msg: int
     *   ip_pool_id: int|null
     * (not password, not company, not created_at)
     */
    #[Route(methods: 'PATCH', path: '/companies/{hash}/smtp-credentials/{id}')]
    public function updateCompanyCred(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $id      = (int)$request->getAttribute('id', 0);

        /** @var \App\Repository\SmtpCredentialRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);
        $cred = $repo->find($id);
        if (!$cred || $cred->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Credential not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true) ?: [];
        $this->applyEditableFields($cred, $body);
        $repo->save($cred);

        return new JsonResponse($this->shapeCred($cred));
    }

    #[Route(methods: 'POST', path: '/companies/{hash}/smtp-credentials/{id}/rotate')]
    public function rotateCompanyCred(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $id      = (int)$request->getAttribute('id', 0);

        /** @var \App\Repository\SmtpCredentialRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);
        $cred = $repo->find($id);
        if (!$cred || $cred->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Credential not found', 404);
        }

        // Generate and set a new password hash (reuse your provisioner helpers)
        $ref = new \ReflectionClass($this->provisioner);
        $randomPassword = $ref->getMethod('randomPassword');
        $randomPassword->setAccessible(true);
        $newPassword = $randomPassword->invoke($this->provisioner, 16);

        $dovecotSha = $ref->getMethod('dovecotSha512Crypt');
        $dovecotSha->setAccessible(true);
        $hash = $dovecotSha->invoke($this->provisioner, $newPassword);

        $cred->setPassword_hash($hash);
        $repo->save($cred);

        return new JsonResponse([
            'credential' => $this->shapeCred($cred),
            'password'   => $newPassword, // show once
        ]);
    }

    #[Route(methods: 'DELETE', path: '/companies/{hash}/smtp-credentials/{id}')]
    public function deleteCompanyCred(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $id      = (int)$request->getAttribute('id', 0);

        /** @var \App\Repository\SmtpCredentialRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);
        $cred = $repo->find($id);
        if (!$cred || $cred->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Credential not found', 404);
        }

        // Optional: prevent deleting the last credential
        $others = $repo->findBy(['company_id' => $company->getId()]);
        if (count($others) <= 1) {
            throw new RuntimeException('Cannot delete the last SMTP credential for the company', 400);
        }

        if (method_exists($repo, 'delete')) $repo->delete($cred);
        elseif (method_exists($repo, 'remove')) $repo->remove($cred);
        else $this->qb->delete('smtpcredential')->where('id', '=', $id)->execute();

        return new JsonResponse(null, 204);
    }

    /* ---------------- Domain-scoped “effective” SMTP ---------------- */

    #[Route(methods: 'GET', path: '/companies/{hash}/domains/{id}/smtp')]
    public function getDomainSmtp(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $id      = (int)$request->getAttribute('id', 0);

        /** @var \App\Repository\DomainRepository $dRepo */
        $dRepo = $this->repos->getRepository(Domain::class);
        $domain = $dRepo->find($id);
        if (!$domain || $domain->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Domain not found', 404);
        }

        $prefix = (string)($request->getQueryParams()['username_prefix'] ?? 'smtpuser');

        /** @var \App\Repository\SmtpCredentialRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);
        $cred = $repo->findOneBy([
            'company_id'      => $company->getId(),
            'username_prefix' => $prefix,
        ]);

        if (!$cred) {
            // Option A: 404
            // throw new RuntimeException('SMTP credential not found for the company', 404);

            // Option B: auto-provision here (comment above line if you prefer 404)
            $this->provisioner->provisionForCompany($company, $domain->getDomain() ?? 'example.com', $prefix);
            $cred = $repo->findOneBy([
                'company_id'      => $company->getId(),
                'username_prefix' => $prefix,
            ]);
        }

        if (!$cred) {
            throw new RuntimeException('Provisioning failed', 500);
        }

        return new JsonResponse([
            'domain'   => ['id' => $domain->getId(), 'name' => $domain->getDomain()],
            'smtp'     => [
                'host'     => 'smtp.monkeysmail.com',
                'ip'       => '34.30.122.164',
                'ports'    => [587, 465],
                'tls'      => ['starttls' => true, 'implicit' => true],
                'username' => sprintf('%s@%s', $cred->getUsername_prefix() ?? 'smtpuser', $domain->getDomain()),
                'password' => null, // only returned on creation/rotation
                'ip_pool'  => $cred->getIpPool()?->getId(),
            ],
            'limits'   => [
                'max_msgs_min' => $cred->getMax_msgs_min(),
                'max_rcpt_msg' => $cred->getMax_rcpt_msg(),
            ],
            'scopes'   => $cred->getScopes(),
        ]);
    }

    /* ------------------------- internal ------------------------- */

    private function applyEditableFields(SmtpCredential $c, array $body): void
    {
        if (isset($body['scopes'])) {
            $sc = $body['scopes'];
            $c->setScopes(is_array($sc)
                ? array_values(array_filter(array_map('strval', $sc)))
                : (is_string($sc) ? array_values(array_filter(array_map('trim', preg_split('~[,\s]+~', $sc)))) : $c->getScopes()));
        }
        if (array_key_exists('max_msgs_min', $body)) {
            $c->setMax_msgs_min(is_numeric($body['max_msgs_min']) ? (int)$body['max_msgs_min'] : $c->getMax_msgs_min());
        }
        if (array_key_exists('max_rcpt_msg', $body)) {
            $c->setMax_rcpt_msg(is_numeric($body['max_rcpt_msg']) ? (int)$body['max_rcpt_msg'] : $c->getMax_rcpt_msg());
        }
        if (array_key_exists('ip_pool_id', $body)) {
            /** @var \App\Repository\IpPoolRepository $ipRepo */
            $ipRepo = $this->repos->getRepository(IpPool::class);
            $pool = $body['ip_pool_id'] ? $ipRepo->find((int)$body['ip_pool_id']) : null;
            $c->setIpPool($pool);
        }
    }
}
