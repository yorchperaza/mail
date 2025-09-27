<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Domain;
use App\Entity\DkimKey;
use App\Service\OpenDkimTableSync;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class DkimController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb
    ) {}

    /**
     * POST /dkim/sync
     * Syncs all DKIM keys from database to OpenDKIM tables
     * Admin only
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/dkim/sync')]
    public function sync(): JsonResponse
    {
        $svc = new OpenDkimTableSync($this->repos, $this->qb); // <- pass QB
        $out = $svc->syncTables();
        return new JsonResponse($out, $out['success'] ? 200 : 500);
    }

    /**
     * GET /dkim/status
     * Returns current OpenDKIM configuration status
     * Admin only
     */
    #[Route(methods: 'GET', path: '/dkim/status')]
    public function status(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        if (!$this->isAdmin($userId)) {
            throw new RuntimeException('Forbidden - Admin access required', 403);
        }

        $status = [
            'keytable' => [],
            'signingtable' => [],
            'trustedhosts' => [],
            'opendkim_running' => false,
            'stats' => []
        ];

        // Read current tables
        if (file_exists('/etc/opendkim/keytable')) {
            $lines = file('/etc/opendkim/keytable', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $status['keytable'] = $lines ?: [];
        }

        if (file_exists('/etc/opendkim/signingtable')) {
            $lines = file('/etc/opendkim/signingtable', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $status['signingtable'] = $lines ?: [];
        }

        if (file_exists('/etc/opendkim/trustedhosts')) {
            $lines = file('/etc/opendkim/trustedhosts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $status['trustedhosts'] = $lines ?: [];
        }

        // Check if OpenDKIM is running
        exec('pgrep opendkim', $output, $result);
        $status['opendkim_running'] = ($result === 0);

        // Get stats
        $status['stats'] = [
            'total_domains' => count($status['keytable']),
            'signing_rules' => count($status['signingtable']),
            'trusted_hosts' => count($status['trustedhosts'])
        ];

        return new JsonResponse($status);
    }

    /**
     * GET /companies/{hash}/dkim
     * Returns DKIM configuration for a specific company's domains
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/dkim')]
    public function getCompanyDkim(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int) $request->getAttribute('user_id', 0);
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

        // Check user belongs to company
        $belongs = array_filter(
            $company->getUsers() ?? [],
            fn($u) => $u->getId() === $userId
        );
        if (empty($belongs)) {
            throw new RuntimeException('Forbidden', 403);
        }

        // Get domains for this company with DKIM info
        $sql = "SELECT 
                    d.id,
                    d.domain,
                    d.is_active,
                    dk.selector,
                    dk.txt_value,
                    dk.active as dkim_active,
                    dk.created_at as dkim_created
                FROM domain d
                LEFT JOIN dkim_key dk ON dk.domain_id = d.id AND dk.active = 1
                WHERE d.company_id = :company_id
                ORDER BY d.domain";

        $stmt = $this->qb->pdo()->prepare($sql);
        $stmt->bindValue(':company_id', $company->getId(), \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $domains = [];
        foreach ($rows as $row) {
            $domains[] = [
                'id' => (int)$row['id'],
                'domain' => $row['domain'],
                'is_active' => (bool)$row['is_active'],
                'dkim' => $row['selector'] ? [
                    'selector' => $row['selector'],
                    'txt_record' => $row['selector'] . '._domainkey.' . $row['domain'],
                    'txt_value' => $row['txt_value'],
                    'is_active' => (bool)$row['dkim_active'],
                    'created_at' => $row['dkim_created']
                ] : null
            ];
        }

        return new JsonResponse([
            'company' => [
                'hash' => $company->getHash(),
                'name' => $company->getName()
            ],
            'domains' => $domains
        ]);
    }

    /**
     * POST /domains/{id}/dkim/validate
     * Validates DKIM DNS records for a domain
     */
    #[Route(methods: 'POST', path: '/domains/{id}/dkim/validate')]
    public function validateDomain(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $domainId = (int) $request->getAttribute('id');

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        /** @var Domain|null $domain */
        $domain = $domainRepo->find($domainId);

        if (!$domain) {
            throw new RuntimeException('Domain not found', 404);
        }

        // Check user has access via company
        $company = $domain->getCompany();
        if (!$company) {
            throw new RuntimeException('Domain has no company', 500);
        }

        $belongs = array_filter(
            $company->getUsers() ?? [],
            fn($u) => $u->getId() === $userId
        );
        if (empty($belongs)) {
            throw new RuntimeException('Forbidden', 403);
        }

        $validation = [
            'domain' => $domain->getDomain(),
            'checks' => []
        ];

        // Check DKIM key exists
        $dkimKeys = $domain->getDkimKeys();
        $activeKey = null;
        foreach ($dkimKeys as $key) {
            if ($key->getActive()) {
                $activeKey = $key;
                break;
            }
        }

        if (!$activeKey) {
            $validation['checks']['dkim_key'] = [
                'valid' => false,
                'message' => 'No active DKIM key found'
            ];
            return new JsonResponse($validation, 200);
        }

        // Check private key file
        $keyPath = $activeKey->getPrivate_key_ref();
        $validation['checks']['private_key'] = [
            'valid' => file_exists($keyPath),
            'message' => file_exists($keyPath) ? 'Private key exists' : 'Private key file missing'
        ];

        // Check DNS record
        $selector = $activeKey->getSelector();
        $dkimHost = "{$selector}._domainkey.{$domain->getDomain()}";
        $dnsRecords = @dns_get_record($dkimHost, DNS_TXT);

        $dnsDkimValid = false;
        $dnsDkimRecord = null;

        if ($dnsRecords) {
            foreach ($dnsRecords as $record) {
                if (isset($record['txt']) && str_contains($record['txt'], 'v=DKIM1')) {
                    $dnsDkimRecord = $record['txt'];

                    // Check if it matches our expected value
                    $expectedTxt = $activeKey->getTxt_value();
                    if ($expectedTxt) {
                        // Normalize for comparison
                        $normalizedDns = preg_replace('/\s+/', '', $dnsDkimRecord);
                        $normalizedExp = preg_replace('/\s+/', '', $expectedTxt);
                        $dnsDkimValid = ($normalizedDns === $normalizedExp);
                    } else {
                        $dnsDkimValid = true; // Record exists
                    }
                    break;
                }
            }
        }

        $validation['checks']['dns_dkim'] = [
            'valid' => $dnsDkimValid,
            'host' => $dkimHost,
            'record' => $dnsDkimRecord,
            'message' => $dnsDkimValid
                ? 'DKIM record found and valid'
                : ($dnsDkimRecord ? 'DKIM record mismatch' : 'DKIM record not found')
        ];

        // Check if in OpenDKIM tables
        $inKeytable = false;
        if (file_exists('/etc/opendkim/keytable')) {
            $keytable = file_get_contents('/etc/opendkim/keytable');
            $inKeytable = str_contains($keytable, $domain->getDomain());
        }

        $validation['checks']['opendkim_config'] = [
            'valid' => $inKeytable,
            'message' => $inKeytable ? 'Domain in OpenDKIM configuration' : 'Domain not in OpenDKIM configuration'
        ];

        // Overall status
        $validation['valid'] =
            $validation['checks']['private_key']['valid'] &&
            $validation['checks']['dns_dkim']['valid'] &&
            $validation['checks']['opendkim_config']['valid'];

        return new JsonResponse($validation);
    }

    /**
     * GET /dkim/domains
     * Lists all domains with DKIM status
     * Admin only
     */
    #[Route(methods: 'GET', path: '/dkim/domains')]
    public function listDomains(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        if (!$this->isAdmin($userId)) {
            throw new RuntimeException('Forbidden - Admin access required', 403);
        }

        // Parse query params for pagination
        parse_str((string)$request->getUri()->getQuery(), $qs);
        $page = max(1, (int)($qs['page'] ?? 1));
        $perPage = min(100, max(1, (int)($qs['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        // Get total count
        $countStmt = $this->qb->pdo()->query("
            SELECT COUNT(DISTINCT d.id) as total
            FROM domain d
            WHERE d.is_active = 1
        ");
        $total = (int)$countStmt->fetchColumn();

        // Get domains with DKIM info
        $sql = "SELECT 
                    d.id,
                    d.domain,
                    d.is_active,
                    c.name as company_name,
                    dk.selector,
                    dk.active as dkim_active,
                    dk.txt_value,
                    dk.private_key_ref
                FROM domain d
                LEFT JOIN company c ON d.company_id = c.id
                LEFT JOIN dkim_key dk ON dk.domain_id = d.id AND dk.active = 1
                WHERE d.is_active = 1
                ORDER BY d.domain
                LIMIT :limit OFFSET :offset";

        $stmt = $this->qb->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $domains = [];
        foreach ($rows as $row) {
            $domains[] = [
                'id' => (int)$row['id'],
                'domain' => $row['domain'],
                'company' => $row['company_name'],
                'is_active' => (bool)$row['is_active'],
                'dkim' => $row['selector'] ? [
                    'selector' => $row['selector'],
                    'is_active' => (bool)$row['dkim_active'],
                    'has_key' => !empty($row['private_key_ref']) && file_exists($row['private_key_ref']),
                    'has_txt' => !empty($row['txt_value'])
                ] : null
            ];
        }

        return new JsonResponse([
            'data' => $domains,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => ceil($total / $perPage)
        ]);
    }

    /**
     * Check if user is admin
     * Adjust this based on your actual admin system
     */
    private function isAdmin(int $userId): bool
    {
        // Example: check user role
        $stmt = $this->qb->pdo()->prepare("
            SELECT role 
            FROM user 
            WHERE id = :id 
            LIMIT 1
        ");
        $stmt->bindValue(':id', $userId, \PDO::PARAM_INT);
        $stmt->execute();
        $role = $stmt->fetchColumn();

        return $role === 'admin' || $role === 'super_admin';
    }
}