<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\ListContact;
use App\Entity\ListGroup;
use App\Service\CompanyResolver;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;
use RuntimeException;
use Psr\Http\Message\UploadedFileInterface;

final class ListController
{
    public function __construct(
        private RepositoryFactory $repos,
        private CompanyResolver   $companyResolver,
        private QueryBuilder      $qb,
    ) {}

    /* ---------------------------------------------------------------------- *
     * Helpers
     * ---------------------------------------------------------------------- */

    private function authenticateUser(ServerRequestInterface $request): int
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }
        return $userId;
    }

    private function resolveCompany(string $hash, int $userId): Company
    {
        $company = $this->companyResolver->resolveCompanyForUser($hash, $userId);
        if (!$company) {
            throw new RuntimeException('Company not found or access denied', 404);
        }
        return $company;
    }

    private function shapeContact(Contact $c): array
    {
        return [
            'id'             => $c->getId(),
            'email'          => $c->getEmail(),
            'name'           => $c->getName(),
            'locale'         => $c->getLocale(),
            'timezone'       => $c->getTimezone(),
            'gdpr_consent_at'=> $c->getGdpr_consent_at()?->format(\DateTimeInterface::ATOM),
            'status'         => $c->getStatus(),
            'attributes'     => $c->getAttributes(),
            'consent_source' => $c->getConsent_source(),
            'created_at'     => $c->getCreated_at()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function shapeListGroup(ListGroup $g, bool $withCounts = false): array
    {
        $out = [
            'id'         => $g->getId(),
            'name'       => $g->getName(),
            'created_at' => $g->getCreated_at()?->format(\DateTimeInterface::ATOM),
            'hash'       => $g->getHash() ?? null,
        ];
        if ($withCounts) {
            $out['counts'] = [
                'contacts'  => is_array($g->getListContacts()) ? count($g->getListContacts()) : null,
                'campaigns' => is_array($g->getCampaigns()) ? count($g->getCampaigns()) : null,
            ];
        }
        return $out;
    }

    private function shapeListContact(ListContact $lc): array
    {
        return [
            'id'            => $lc->getId(),
            'subscribed_at' => $lc->getSubscribed_at()?->format(\DateTimeInterface::ATOM),
            'contact'       => $lc->getContact()?->getId(),
            'list_group'    => $lc->getListGroup()?->getId(),
        ];
    }

    private function ensureEmail(string $email): string
    {
        $email = trim(strtolower($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email', 400);
        }
        return $email;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /* ---------------------------------------------------------------------- *
     * Contacts — company scoped
     * ---------------------------------------------------------------------- */

    #[Route(methods: 'GET', path: '/companies/{hash}/contacts')]
    public function listContacts(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);

        /** @var \App\Repository\ContactRepository $repo */
        $repo = $this->repos->getRepository(Contact::class);
        $q    = $request->getQueryParams();

        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));
        $search  = trim((string)($q['search'] ?? '')); // matches email or name

        // Basic filtering via repo; if repo doesn’t support search, fallback to PHP filter
        $rows = $repo->findBy(['company_id' => $company->getId()]);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function (Contact $c) use ($needle) {
                return str_contains(mb_strtolower((string)$c->getEmail()), $needle)
                    || str_contains(mb_strtolower((string)$c->getName()), $needle);
            }));
        }

        $total = count($rows);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($rows, $offset, $perPage);

        return new JsonResponse([
            'meta'  => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => (int)ceil($total / $perPage)],
            'items' => array_map(fn(Contact $c) => $this->shapeContact($c), $slice),
        ]);
    }

    #[Route(methods: 'POST', path: '/companies/{hash}/contacts')]
    public function createContact(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);

        $body  = json_decode((string)$request->getBody(), true) ?: [];
        $email = $this->ensureEmail((string)($body['email'] ?? ''));

        // Parse optional single/multiple list ids coming from the client
        $listIds = $this->parseListIds($body);

        /** @var \App\Repository\ContactRepository $repo */
        $repo = $this->repos->getRepository(Contact::class);

        // Idempotent by (company_id, email)
        $existing = $repo->findOneBy(['company_id' => $company->getId(), 'email' => $email]);
        if ($existing) {
            // Optionally attach to lists when client provided them
            if ($listIds) {
                $this->ensureListMemberships($company, $existing, $listIds);
            }
            return new JsonResponse($this->shapeContact($existing), 200);
        }

        // Create new contact
        $c = (function () use ($company, $email, $body) {
            $x = new Contact();
            $x->setCompany($company);
            $x->setEmail($email);
            $x->setName((string)($body['name'] ?? null) ?: null);
            $x->setLocale((string)($body['locale'] ?? null) ?: null);
            $x->setTimezone((string)($body['timezone'] ?? null) ?: null);
            $x->setStatus((string)($body['status'] ?? null) ?: null);
            $x->setConsent_source((string)($body['consent_source'] ?? null) ?: null);

            $attrs = $body['attributes'] ?? null;
            $x->setAttributes(is_array($attrs) ? $attrs : null);

            if (!empty($body['gdpr_consent_at'])) {
                try { $x->setGdpr_consent_at(new \DateTimeImmutable((string)$body['gdpr_consent_at'])); } catch (\Throwable) {}
            }

            $x->setCreated_at($this->now());
            return $x;
        })();

        $repo->save($c);

        // Optionally attach to lists
        if ($listIds) {
            $this->ensureListMemberships($company, $c, $listIds);
        }

        return new JsonResponse($this->shapeContact($c), 201);
    }

    /**
     * Accepts list_id (int) or list_ids (int[]) and returns a normalized int[].
     */
    private function parseListIds(array $body): array
    {
        $ids = [];

        if (isset($body['list_id'])) {
            $ids[] = (int)$body['list_id'];
        }

        if (!empty($body['list_ids']) && is_array($body['list_ids'])) {
            foreach ($body['list_ids'] as $v) {
                $ids[] = (int)$v;
            }
        }

        // Positive & unique
        return array_values(array_unique(array_filter($ids, static fn($n) => $n > 0)));
    }

    /**
     * Ensure the (contact,list) relation exists for each list id.
     * - Verifies the list belongs to the given company
     * - Idempotent: skips if relation already exists
     * - No explicit DB transaction needed (each repo->save persists one row)
     */
    private function ensureListMemberships(
        Company $company,
        Contact $contact,
        array   $listIds
    ): void {
        if (!$listIds) return;

        /** @var \App\Repository\ListGroupRepository $lgRepo */
        $lgRepo = $this->repos->getRepository(ListGroup::class);
        /** @var \App\Repository\ListContactRepository $lcRepo */
        $lcRepo = $this->repos->getRepository(ListContact::class);

        foreach ($listIds as $listId) {
            $list = $lgRepo->find($listId);
            // ensure list exists and belongs to the same company
            if (!$list || $list->getCompany()?->getId() !== $company->getId()) {
                continue;
            }

            // idempotency: skip if relation already exists
            $exists = $lcRepo->findOneBy([
                'listGroup_id' => $listId,
                'contact_id'   => $contact->getId(),
            ]);
            if ($exists) {
                continue;
            }

            $lc = new ListContact();
            $lc->setListGroup($list);
            $lc->setContact($contact);
            $lc->setSubscribed_at($this->now());
            $lcRepo->save($lc);
        }
    }


    #[Route(methods: 'GET', path: '/companies/{hash}/contacts/{id}')]
    public function getContact(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $id      = (int)$request->getAttribute('id');

        /** @var \App\Repository\ContactRepository $repo */
        $repo = $this->repos->getRepository(Contact::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Contact not found', 404);
        }
        return new JsonResponse($this->shapeContact($c));
    }

    /**
     * @throws \DateMalformedStringException
     * @throws \JsonException
     */
    #[Route(methods: 'PATCH', path: '/companies/{hash}/contacts/{id}')]
    public function updateContact(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $id      = (int)$request->getAttribute('id');

        /** @var \App\Repository\ContactRepository $repo */
        $repo = $this->repos->getRepository(Contact::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Contact not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true) ?: [];

        if (array_key_exists('email', $body))  $c->setEmail($this->ensureEmail((string)$body['email']));
        if (array_key_exists('name', $body))   $c->setName((string)($body['name'] ?? null) ?: null);
        if (array_key_exists('locale', $body)) $c->setLocale((string)($body['locale'] ?? null) ?: null);
        if (array_key_exists('timezone', $body)) $c->setTimezone((string)($body['timezone'] ?? null) ?: null);
        if (array_key_exists('status', $body)) $c->setStatus((string)($body['status'] ?? null) ?: null);
        if (array_key_exists('consent_source', $body)) $c->setConsent_source((string)($body['consent_source'] ?? null) ?: null);
        if (array_key_exists('attributes', $body)) $c->setAttributes(is_array($body['attributes']) ? $body['attributes'] : null);
        if (array_key_exists('gdpr_consent_at', $body)) {
            $val = $body['gdpr_consent_at'];
            $c->setGdpr_consent_at($val ? new \DateTimeImmutable((string)$val) : null);
        }

        // Persist field changes
        $repo->save($c);

        // 🔹 Optional membership sync when client provided list ids
        $listIds = $this->parseListIds($body);
        if (!empty($listIds) || array_key_exists('list_ids', $body) || array_key_exists('list_id', $body)) {
            // If client included the key(s) at all, treat as authoritative:
            // - if it's empty array, it will clear all memberships
            $this->syncListMemberships($company, $c, $listIds);
        }

        return new JsonResponse($this->shapeContact($c));
    }


    #[Route(methods: 'DELETE', path: '/companies/{hash}/contacts/{id}')]
    public function deleteContact(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $id      = (int)$request->getAttribute('id');

        /** @var \App\Repository\ContactRepository $repo */
        $repo = $this->repos->getRepository(Contact::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Contact not found', 404);
        }

        // 1) remove all memberships for this contact (across all lists)
        $this->qb->delete('listcontact')->where('contact_id', '=', $id)->execute();

        // 2) then delete the contact
        if (method_exists($repo, 'delete')) $repo->delete($c);
        elseif (method_exists($repo, 'remove')) $repo->remove($c);
        else $this->qb->delete('contact')->where('id', '=', $id)->execute();

        return new JsonResponse(null, 204);
    }

    /* ---------------------------------------------------------------------- *
     * Lists (ListGroup) — company scoped
     * ---------------------------------------------------------------------- */

    #[Route(methods: 'GET', path: '/companies/{hash}/lists')]
    public function listGroups(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $q       = $request->getQueryParams();

        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));
        $search  = trim((string)($q['search'] ?? ''));
        $withCounts = (bool)($q['withCounts'] ?? false);

        // Ensure company exists and is accessible
        if (!$company || $company->getId() <= 0) {
            error_log('Company not found');
        }

        /** @var \App\Repository\ListGroupRepository $repo */
        $repo = $this->repos->getRepository(ListGroup::class);
        $rows = $repo->findBy(['company' => $company->getId()]);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, fn(ListGroup $g) =>
            str_contains(mb_strtolower((string)$g->getName()), $needle)
            ));
        }
        $total = count($rows);

        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new JsonResponse([
            'meta'  => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => (int)ceil($total / $perPage)],
            'items' => array_map(fn(ListGroup $g) => $this->shapeListGroup($g, $withCounts), $slice),
        ]);

    }

    /**
     * @throws RandomException
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/lists')]
    public function createGroup(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);

        $body = json_decode((string)$request->getBody(), true) ?: [];
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') throw new RuntimeException('List name is required', 400);

        /** @var \App\Repository\ListGroupRepository $repo */
        $repo = $this->repos->getRepository(ListGroup::class);

        // Optional: idempotency by name per company
        $existing = $repo->findOneBy(['company_id' => $company->getId(), 'name' => $name]);
        if ($existing) {
            return new JsonResponse($this->shapeListGroup($existing, true), 200);
        }

        $hash = bin2hex(random_bytes(32));

        $g = new ListGroup()
            ->setCompany($company)
            ->setName($name)
            ->setHash($hash)
            ->setCreated_at($this->now());

        $repo->save($g);
        return new JsonResponse($this->shapeListGroup($g, true), 201);
    }

    #[Route(methods: 'GET', path: '/companies/{hash}/lists/{id}')]
    public function getGroup(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $id      = (int)$request->getAttribute('id');

        /** @var \App\Repository\ListGroupRepository $repo */
        $repo = $this->repos->getRepository(ListGroup::class);
        $g = $repo->find($id);
        if (!$g || $g->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('List not found', 404);
        }
        return new JsonResponse($this->shapeListGroup($g, true));
    }

    #[Route(methods: 'PATCH', path: '/companies/{hash}/lists/{id}')]
    public function updateGroup(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $id      = (int)$request->getAttribute('id');

        /** @var \App\Repository\ListGroupRepository $repo */
        $repo = $this->repos->getRepository(ListGroup::class);
        $g = $repo->find($id);
        if (!$g || $g->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('List not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true) ?: [];
        if (array_key_exists('name', $body)) {
            $name = trim((string)$body['name']);
            if ($name === '') throw new RuntimeException('List name cannot be empty', 400);
            $g->setName($name);
        }

        $repo->save($g);
        return new JsonResponse($this->shapeListGroup($g, true));
    }

    #[Route(methods: 'DELETE', path: '/companies/{hash}/lists/{id}')]
    public function deleteGroup(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $id      = (int)$request->getAttribute('id');

        /** @var \App\Repository\ListGroupRepository $repo */
        $repo = $this->repos->getRepository(ListGroup::class);
        $g = $repo->find($id);
        if (!$g || $g->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('List not found', 404);
        }

        // 1) remove memberships first
        $this->qb->delete('listcontact')->where('listgroup_id', '=', $id)->execute();

        // 2) then delete the list group
        if (method_exists($repo, 'delete')) $repo->delete($g);
        elseif (method_exists($repo, 'remove')) $repo->remove($g);
        else $this->qb->delete('listgroup')->where('id', '=', $id)->execute();

        return new JsonResponse(null, 204);
    }

    /* ---------------------------------------------------------------------- *
     * Memberships (ListContact)
     * ---------------------------------------------------------------------- */

    #[Route(methods: 'GET', path: '/companies/{hash}/lists/{id}/contacts')]
    public function listMembers(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $listId  = (int)$request->getAttribute('id');
        $q       = $request->getQueryParams();

        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        /** @var \App\Repository\ListGroupRepository $lgRepo */
        $lgRepo = $this->repos->getRepository(ListGroup::class);
        $list = $lgRepo->find($listId);
        if (!$list || $list->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('List not found', 404);
        }

        /** @var \App\Repository\ListContactRepository $lcRepo */
        $lcRepo = $this->repos->getRepository(ListContact::class);
        $rows   = $lcRepo->findBy(['listGroup_id' => $listId]);

        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        // include contact summary for convenience
        $out = array_map(function (ListContact $lc) {
            $c = $lc->getContact();
            return [
                'id'            => $lc->getId(),
                'subscribed_at' => $lc->getSubscribed_at()?->format(\DateTimeInterface::ATOM),
                'contact'       => $c ? [
                    'id'     => $c->getId(),
                    'email'  => $c->getEmail(),
                    'name'   => $c->getName(),
                    'status' => $c->getStatus(),
                ] : null,
            ];
        }, $slice);

        return new JsonResponse([
            'meta'  => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => (int)ceil($total / $perPage)],
            'items' => $out,
        ]);
    }

    /**
     * Add ONE member.
     * Body:
     *   - contact_id (preferred) OR email (+ optional name/attrs)
     *   - subscribed_at (optional ISO date; default now)
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/lists/{id}/contacts')]
    public function addMember(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $listId  = (int)$request->getAttribute('id');

        /** @var \App\Repository\ListGroupRepository $lgRepo */
        $lgRepo = $this->repos->getRepository(ListGroup::class);
        $list = $lgRepo->find($listId);
        if (!$list || $list->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('List not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true) ?: [];
        $contactId = isset($body['contact_id']) ? (int)$body['contact_id'] : null;

        /** @var \App\Repository\ContactRepository $cRepo */
        $cRepo = $this->repos->getRepository(Contact::class);
        $contact = null;

        if ($contactId) {
            $contact = $cRepo->find($contactId);
            if (!$contact || $contact->getCompany()?->getId() !== $company->getId()) {
                throw new RuntimeException('Contact not found', 404);
            }
        } else {
            $email = $this->ensureEmail((string)($body['email'] ?? ''));
            $existing = $cRepo->findOneBy(['company_id' => $company->getId(), 'email' => $email]);
            if ($existing) {
                $contact = $existing;
            } else {
                $c = new Contact();
                $c->setCompany($company)
                    ->setEmail($email)
                    ->setName((string)($body['name'] ?? null) ?: null)
                    ->setAttributes(is_array($body['attributes'] ?? null) ? $body['attributes'] : null)
                    ->setCreated_at($this->now());
                $cRepo->save($c);
                $contact = $c;
            }
        }

        /** @var \App\Repository\ListContactRepository $lcRepo */
        $lcRepo = $this->repos->getRepository(ListContact::class);
        $existing = $lcRepo->findOneBy(['listGroup_id' => $listId, 'contact_id' => $contact->getId()]);
        if ($existing) {
            // idempotent
            return new JsonResponse([
                'membership' => $this->shapeListContact($existing),
                'created'    => false,
            ], 200);
        }

        $lc = (new ListContact())
            ->setListGroup($list)
            ->setContact($contact);

        if (!empty($body['subscribed_at'])) {
            try { $lc->setSubscribed_at(new \DateTimeImmutable((string)$body['subscribed_at'])); } catch (\Throwable) {}
        } else {
            $lc->setSubscribed_at($this->now());
        }

        $lcRepo->save($lc);

        return new JsonResponse([
            'membership' => $this->shapeListContact($lc),
            'created'    => true,
        ], 201);
    }

    /**
     * Bulk subscribe by emails
     * Body: { emails: string[], subscribed_at?: string, default_name?: string }
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/lists/{id}/contacts/bulk')]
    public function bulkAddMembers(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $listId  = (int)$request->getAttribute('id');

        /** @var \App\Repository\ListGroupRepository $lgRepo */
        $lgRepo = $this->repos->getRepository(ListGroup::class);
        $list = $lgRepo->find($listId);
        if (!$list || $list->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('List not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true) ?: [];
        $emails = isset($body['emails']) && is_array($body['emails']) ? $body['emails'] : [];
        if (!$emails) throw new RuntimeException('emails[] required', 400);

        $defaultName   = (string)($body['default_name'] ?? '');
        $subscribedIso = (string)($body['subscribed_at'] ?? '');
        $subscribedAt  = null;
        if ($subscribedIso !== '') {
            try { $subscribedAt = new \DateTimeImmutable($subscribedIso); } catch (\Throwable) {}
        }
        if (!$subscribedAt) $subscribedAt = $this->now();

        /** @var \App\Repository\ContactRepository $cRepo */
        $cRepo = $this->repos->getRepository(Contact::class);
        /** @var \App\Repository\ListContactRepository $lcRepo */
        $lcRepo = $this->repos->getRepository(ListContact::class);

        $added = 0; $skipped = 0; $results = [];

        foreach ($emails as $raw) {
            try {
                $email = $this->ensureEmail((string)$raw);
            } catch (\Throwable) {
                $skipped++; continue;
            }

            $contact = $cRepo->findOneBy(['company_id' => $company->getId(), 'email' => $email]);
            if (!$contact) {
                $contact = new Contact()
                    ->setCompany($company)
                    ->setEmail($email)
                    ->setName($defaultName ?: null)
                    ->setCreated_at($this->now());
                $cRepo->save($contact);
            }

            $existing = $lcRepo->findOneBy(['listGroup_id' => $listId, 'contact_id' => $contact->getId()]);
            if ($existing) {
                $skipped++;
                $results[] = ['email' => $email, 'status' => 'exists', 'membership_id' => $existing->getId()];
                continue;
            }

            $lc = (new ListContact())
                ->setListGroup($list)
                ->setContact($contact)
                ->setSubscribed_at($subscribedAt);
            $lcRepo->save($lc);

            $added++;
            $results[] = ['email' => $email, 'status' => 'added', 'membership_id' => $lc->getId()];
        }

        return new JsonResponse([
            'summary' => ['added' => $added, 'skipped' => $skipped, 'total' => count($emails)],
            'results' => $results,
        ], 207); // multi-status-ish
    }

    #[Route(methods: 'DELETE', path: '/companies/{hash}/lists/{id}/contacts/{contactId}')]
    public function removeMember(ServerRequestInterface $request): JsonResponse
    {
        $userId    = $this->authenticateUser($request);
        $company   = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $listId    = (int)$request->getAttribute('id');
        $contactId = (int)$request->getAttribute('contactId');

        /** @var \App\Repository\ListGroupRepository $lgRepo */
        $lgRepo = $this->repos->getRepository(ListGroup::class);
        $list = $lgRepo->find($listId);
        if (!$list || $list->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('List not found', 404);
        }

        /** @var \App\Repository\ContactRepository $cRepo */
        $cRepo = $this->repos->getRepository(Contact::class);
        $contact = $cRepo->find($contactId);
        if (!$contact || $contact->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Contact not found', 404);
        }

        /** @var \App\Repository\ListContactRepository $lcRepo */
        $lcRepo = $this->repos->getRepository(ListContact::class);
        $lc = $lcRepo->findOneBy(['listGroup_id' => $listId, 'contact_id' => $contactId]);
        if (!$lc) {
            throw new RuntimeException('Membership not found', 404);
        }

        if (method_exists($lcRepo, 'delete')) $lcRepo->delete($lc);
        elseif (method_exists($lcRepo, 'remove')) $lcRepo->remove($lc);
        else $this->qb->delete('listcontact')->where('id', '=', $lc->getId())->execute();

        return new JsonResponse(null, 204);
    }

    /**
     * Helper: POST /unsubscribe — by contact_id or email
     * Body: { contact_id?: int, email?: string }
     * Removes membership from this list. (Optional: also set contact status.)
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/lists/{id}/unsubscribe')]
    public function unsubscribe(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);
        $listId  = (int)$request->getAttribute('id');

        /** @var \App\Repository\ListGroupRepository $lgRepo */
        $lgRepo = $this->repos->getRepository(ListGroup::class);
        $list = $lgRepo->find($listId);
        if (!$list || $list->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('List not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true) ?: [];
        $contactId = isset($body['contact_id']) ? (int)$body['contact_id'] : null;
        $email     = isset($body['email']) ? $this->ensureEmail((string)$body['email']) : null;

        /** @var \App\Repository\ContactRepository $cRepo */
        $cRepo = $this->repos->getRepository(Contact::class);
        $contact = null;
        if ($contactId) {
            $contact = $cRepo->find($contactId);
        } elseif ($email) {
            $contact = $cRepo->findOneBy(['company_id' => $company->getId(), 'email' => $email]);
        }

        if (!$contact || $contact->getCompany()?->getId() !== $company->getId()) {
            throw new RuntimeException('Contact not found', 404);
        }

        /** @var \App\Repository\ListContactRepository $lcRepo */
        $lcRepo = $this->repos->getRepository(ListContact::class);
        $lc = $lcRepo->findOneBy(['listGroup_id' => $listId, 'contact_id' => $contact->getId()]);
        if (!$lc) {
            // idempotent; nothing to remove
            return new JsonResponse(['removed' => false], 200);
        }

        if (method_exists($lcRepo, 'delete')) $lcRepo->delete($lc);
        elseif (method_exists($lcRepo, 'remove')) $lcRepo->remove($lc);
        else $this->qb->delete('listcontact')->where('id', '=', $lc->getId())->execute();

        // Optional: mark contact status
        // $contact->setStatus('unsubscribed'); $cRepo->save($contact);

        return new JsonResponse(['removed' => true], 200);
    }

    #[Route(methods: 'GET', path: '/companies/contacts/{hash}/lookup')]
    public function getContactByEmail(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);

        // Read email ONLY from query param
        $q = $request->getQueryParams();
        $emailRaw = trim(strtolower((string)($q['email'] ?? '')));

        if ($emailRaw === '') {
            return new JsonResponse(['error' => 'Query parameter "email" is required.'], 400);
        }
        if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Invalid email.'], 400);
        }

        /** @var \App\Repository\ContactRepository $cRepo */
        $cRepo = $this->repos->getRepository(Contact::class);
        $contact = $cRepo->findOneBy([
            'company_id' => $company->getId(),
            'email'      => $emailRaw,
        ]);

        if (!$contact) {
            return new JsonResponse(['error' => 'Contact not found'], 404);
        }

        // 🔹 Fetch memberships
        /** @var \App\Repository\ListContactRepository $lcRepo */
        $lcRepo = $this->repos->getRepository(ListContact::class);
        $memberships = $lcRepo->findBy(['contact_id' => $contact->getId()]);

        $lists = [];
        foreach ($memberships as $lc) {
            $g = $lc->getListGroup();
            $lists[] = [
                'id'   => $g->getId(),
                'name' => $g->getName(),
                'subscribed_at' => $lc->getSubscribed_at()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse([
            'contact' => $this->shapeContact($contact),
            'lists'   => $lists,
        ], 200);
    }

    /**
     * Replace a contact's memberships with exactly $listIds.
     * - Verifies lists belong to $company
     * - Adds missing memberships
     * - Removes extra memberships
     */
    private function syncListMemberships(
        Company $company,
        Contact $contact,
        array   $listIds
    ): void {
        /** @var \App\Repository\ListContactRepository $lcRepo */
        $lcRepo = $this->repos->getRepository(ListContact::class);
        /** @var \App\Repository\ListGroupRepository $lgRepo */
        $lgRepo = $this->repos->getRepository(ListGroup::class);

        // Current memberships
        $current = $lcRepo->findBy(['contact_id' => $contact->getId()]);
        $currentIds = [];
        foreach ($current as $lc) {
            $g = $lc->getListGroup();
            if ($g && $g->getCompany()?->getId() === $company->getId()) {
                $currentIds[] = (int)$g->getId();
            }
        }

        // Normalize desired → only valid lists for this company
        $desired = [];
        foreach ($listIds as $lid) {
            $list = $lgRepo->find((int)$lid);
            if ($list && $list->getCompany()?->getId() === $company->getId()) {
                $desired[] = (int)$list->getId();
            }
        }
        $desired = array_values(array_unique($desired));

        $toAdd    = array_values(array_diff($desired, $currentIds));
        $toRemove = array_values(array_diff($currentIds, $desired));

        // Add missing
        foreach ($toAdd as $lid) {
            $list = $lgRepo->find($lid);
            if (!$list) continue;

            // idempotent guard
            $exists = $lcRepo->findOneBy(['listGroup_id' => $lid, 'contact_id' => $contact->getId()]);
            if ($exists) continue;

            $lc = (new ListContact())
                ->setListGroup($list)
                ->setContact($contact)
                ->setSubscribed_at($this->now());
            $lcRepo->save($lc);
        }

        // Remove extras
        foreach ($toRemove as $lid) {
            $lc = $lcRepo->findOneBy(['listGroup_id' => $lid, 'contact_id' => $contact->getId()]);
            if (!$lc) continue;

            if (method_exists($lcRepo, 'delete')) $lcRepo->delete($lc);
            elseif (method_exists($lcRepo, 'remove')) $lcRepo->remove($lc);
            else $this->qb->delete('listcontact')->where('id', '=', $lc->getId())->execute();
        }
    }

    /**
     * @throws \DateMalformedStringException
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/contacts-import')]
    public function importContactsCsv(ServerRequestInterface $request): JsonResponse
    {

        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);

        // ------ read multipart form (robust across PSR-7 impls + PHP superglobals)
        $rawFiles = $request->getUploadedFiles();
        $file = null; // UploadedFileInterface|array|null

        // A) PSR-7 first
        if ($rawFiles instanceof UploadedFileInterface) {
            $file = $rawFiles;
        } elseif (is_array($rawFiles)) {
            $candidate = $rawFiles['file'] ?? (count($rawFiles) ? reset($rawFiles) : null);
            $file = is_array($candidate) ? ($candidate[0] ?? null) : $candidate;
        }

        // B) Fallback to $_FILES
        if (!$file && !empty($_FILES)) {
            $firstKey = array_key_first($_FILES);
            $file = $_FILES['file'] ?? ($firstKey !== null ? $_FILES[$firstKey] : null);
        }

        if (!$file) {
            error_log('CSV IMPORT: no file found in request');
            throw new RuntimeException('CSV file is required (multipart/form-data, field "file")', 400);
        }

        // ------ resolve tmp path + read small files if needed
        $uploadedName = '(no-name)';
        $tmpPath = null;
        $csvRaw = '';

        if ($file instanceof UploadedFileInterface) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                error_log('CSV IMPORT: upload error code='.$file->getError());
                throw new RuntimeException('Upload failed', 400);
            }
            $uploadedName = $file->getClientFilename() ?: '(no-name)';
            $stream = $file->getStream();
            $tmpPath = $stream->getMetadata('uri') ?: null;

            if (!$tmpPath || !is_readable($tmpPath)) {
                if (method_exists($stream, 'isSeekable') && $stream->isSeekable()) $stream->rewind();
                $csvRaw = (string)$stream->getContents();
            }
        } elseif (is_array($file)) {
            $tmpPath = is_array($file['tmp_name'] ?? null) ? ($file['tmp_name'][0] ?? null) : ($file['tmp_name'] ?? null);
            $err     = is_array($file['error'] ?? null) ? ($file['error'][0] ?? UPLOAD_ERR_NO_FILE) : ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $uploadedName = is_array($file['name'] ?? null) ? ($file['name'][0] ?? '(no-name)') : ($file['name'] ?? '(no-name)');

            if (!$tmpPath || $err !== UPLOAD_ERR_OK) {
                error_log('CSV IMPORT: missing tmp or err!='.UPLOAD_ERR_OK);
                throw new RuntimeException('Upload failed', 400);
            }
            // stream via tmp path later
        }

        // Edge-case fallback
        if (!$tmpPath && $csvRaw === '' && !empty($_FILES['file']['tmp_name'])) {
            $tmp = $_FILES['file']['tmp_name'];
            $bytes = @file_get_contents($tmp);
            if ($bytes !== false) $csvRaw = (string)$bytes;
        }

        // ------ options (all optional)
        $body             = $request->getParsedBody() ?? [];
        $defaultStatus    = isset($body['default_status']) ? (string)$body['default_status'] : null;
        $defaultName      = isset($body['default_name']) ? (string)$body['default_name'] : null;
        $defaultLocale    = isset($body['default_locale']) ? (string)$body['default_locale'] : null;
        $defaultTimezone  = isset($body['default_timezone']) ? (string)$body['default_timezone'] : null;
        $dryRun           = filter_var($body['dryRun'] ?? false, FILTER_VALIDATE_BOOL);
        $subscribedAtIso  = isset($body['subscribed_at']) ? (string)$body['subscribed_at'] : '';
        $subscribedAt     = $subscribedAtIso ? new \DateTimeImmutable($subscribedAtIso) : $this->now();

        // Normalize list ids
        $listIds = [];
        if (isset($body['list_id'])) $listIds[] = (int)$body['list_id'];
        if (isset($body['list_ids'])) {
            if (is_string($body['list_ids'])) {
                $decoded = json_decode($body['list_ids'], true);
                if (is_array($decoded)) foreach ($decoded as $v) $listIds[] = (int)$v;
            } elseif (is_array($body['list_ids'])) {
                foreach ($body['list_ids'] as $v) $listIds[] = (int)$v;
            }
        }
        $listIds = array_values(array_unique(array_filter($listIds, fn($n) => $n > 0)));

        // ------ detect header + delimiter, get a streaming iterator for rows
        [$headers, $delimiter] = $this->detectCsvHeaderAndDelimiter($tmpPath, $csvRaw);

        if (!$headers) {
            error_log('CSV IMPORT: empty headers');
            throw new RuntimeException('CSV appears empty', 400);
        }
        $rowsIter = $this->streamCsvRows($tmpPath, $csvRaw, $delimiter);

        // Map header aliases once
        $map = [
            'email'            => ['email', 'e-mail', 'mail'],
            'name'             => ['name', 'full_name', 'fullname'],
            'locale'           => ['locale', 'language'],
            'timezone'         => ['timezone', 'tz', 'time_zone'],
            'status'           => ['status', 'state'],
            'consent_source'   => ['consent_source', 'source'],
            'gdpr_consent_at'  => ['gdpr_consent_at', 'consent_at', 'gdpr_at'],
            'attributes'       => ['attributes', 'attrs', 'meta', 'custom'],
        ];
        $hidx = $this->buildHeaderIndex($headers, $map);

        /** @var \App\Repository\ContactRepository $cRepo */
        $cRepo = $this->repos->getRepository(Contact::class);
        /** @var \App\Repository\ListGroupRepository $lgRepo */
        $lgRepo = $this->repos->getRepository(ListGroup::class);
        /** @var \App\Repository\ListContactRepository $lcRepo */
        $lcRepo = $this->repos->getRepository(ListContact::class);

        // Pre-validate lists
        $validListIds = [];
        foreach ($listIds as $lid) {
            $g = $lgRepo->find($lid);
            if ($g && $g->getCompany()?->getId() === $company->getId()) {
                $validListIds[] = $lid;
            }
        }

        $now = $this->now();
        $summary = [
            'processed' => 0,
            'created'   => 0,
            'updated'   => 0,
            'attached'  => 0,
            'skipped'   => 0,
            'errors'    => 0,
            'dryRun'    => $dryRun,
        ];
        $results = [];
        $seenEmails = [];

        // -------- batch processing
        $BATCH = 1000;
        $batch = [];
        $lineNo = 1; // header line = 1

        $flush = function(array $batch) use (
            $company, $cRepo, $lcRepo, $lgRepo, $validListIds, $subscribedAt, $now, $dryRun,
            &$summary, &$results
        ) {
            if (!$batch) return;

            try {
                // 1) existing contacts
                $emails = array_column($batch, 'email');
                $emails = array_values(array_unique($emails));
                $existingByEmail = $this->fetchContactsByEmailsRepo($cRepo, $company->getId(), $emails);

                // 2) upserts
                $upCreated = 0; $upUpdated = 0;
                foreach ($batch as $row) {
                    $email  = $row['email'];
                    $line   = $row['line'];
                    $f      = $row['fields'];
                    $existing = $existingByEmail[$email] ?? null;

                    if ($dryRun) {
                        $results[] = ['line'=>$line,'status'=>$existing ? 'would_update' : 'would_create','email'=>$email];
                        continue;
                    }

                    if ($existing) {
                        if ($f['name']      !== null && $f['name']      !== '') $existing->setName($f['name']);
                        if ($f['locale']    !== null && $f['locale']    !== '') $existing->setLocale($f['locale']);
                        if ($f['timezone']  !== null && $f['timezone']  !== '') $existing->setTimezone($f['timezone']);
                        if ($f['status']    !== null && $f['status']    !== '') $existing->setStatus($f['status']);
                        if ($f['consent']   !== null && $f['consent']   !== '') $existing->setConsent_source($f['consent']);
                        if ($f['gdpr_at']   instanceof \DateTimeImmutable)     $existing->setGdpr_consent_at($f['gdpr_at']);
                        if (is_array($f['attrs'])) {
                            $merged = array_merge($existing->getAttributes() ?? [], $f['attrs']);
                            $existing->setAttributes($merged);
                        }
                        $cRepo->save($existing);
                        $summary['updated']++; $upUpdated++;
                        $results[] = ['line'=>$line,'status'=>'updated','email'=>$email];
                    } else {
                        $c = new Contact()
                            ->setCompany($row['company'])
                            ->setEmail($email)
                            ->setName($f['name'] ?: null)
                            ->setLocale($f['locale'] ?: null)
                            ->setTimezone($f['timezone'] ?: null)
                            ->setStatus($f['status'] ?: null)
                            ->setConsent_source($f['consent'] ?: null)
                            ->setAttributes($f['attrs'] ?: null)
                            ->setCreated_at($row['now']);
                        if ($f['gdpr_at'] instanceof \DateTimeImmutable) $c->setGdpr_consent_at($f['gdpr_at']);

                        $cRepo->save($c);
                        $summary['created']++; $upCreated++;
                        $existingByEmail[$email] = $c;
                        $results[] = ['line'=>$line,'status'=>'created','email'=>$email,'contact_id'=>$c->getId()];
                    }
                }

                // 3) memberships
                if ($validListIds) {
                    $contactIds = [];
                    foreach ($batch as $row) {
                        $email = $row['email'];
                        if (isset($existingByEmail[$email])) $contactIds[] = $existingByEmail[$email]->getId();
                    }
                    $contactIds = array_values(array_unique(array_filter($contactIds)));

                    if ($contactIds) {
                        $existingPairs = $this->fetchExistingMembershipPairsRepo($lcRepo, $validListIds, $contactIds);
                        $addedPairs = 0;

                        foreach ($validListIds as $lid) {
                            $list = $lgRepo->find($lid);
                            if (!$list || $list->getCompany()?->getId() !== $company->getId()) continue;

                            foreach ($contactIds as $cid) {
                                $key = $lid . ':' . $cid;
                                if (isset($existingPairs[$key])) continue;

                                // resolve entity by id
                                $contactEntity = null;
                                foreach ($existingByEmail as $e) {
                                    if ($e->getId() === $cid) { $contactEntity = $e; break; }
                                }
                                if (!$contactEntity) continue;

                                $lc = (new ListContact())
                                    ->setListGroup($list)
                                    ->setContact($contactEntity)
                                    ->setSubscribed_at($subscribedAt);

                                $lcRepo->save($lc);
                                $summary['attached']++; $addedPairs++;
                            }
                        }
                    }
                }

            } catch (\Throwable $e) {
                error_log('CSV IMPORT: FLUSH error: '.$e->getMessage());
                foreach ($batch as $row) {
                    $summary['errors']++;
                    $results[] = [
                        'line' => $row['line'],
                        'status' => 'error',
                        'error' => 'batch: ' . $e->getMessage(),
                    ];
                }
            }
        };

        // Stream + batch
        $firstPreview = 0;
        foreach ($rowsIter as $row) {
            $lineNo++;
            $summary['processed']++;

            if ($firstPreview < 3) {
                error_log('CSV IMPORT: row#'.$lineNo.' sample='.json_encode($row));
                $firstPreview++;
            }

            try {
                $email = $this->extractCsvValue($row, $hidx['email'] ?? null);
                $email = $email !== null ? trim(strtolower($email)) : '';

                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $summary['skipped']++;
                    $results[] = ['line'=>$lineNo, 'status'=>'skipped', 'reason'=>'invalid_email'];
                    continue;
                }
                if (isset($seenEmails[$email])) {
                    $summary['skipped']++;
                    $results[] = ['line'=>$lineNo, 'status'=>'skipped', 'reason'=>'duplicate_in_file', 'email'=>$email];
                    continue;
                }
                $seenEmails[$email] = true;

                $name      = $this->extractCsvValue($row, $hidx['name'] ?? null) ?: $defaultName;
                $locale    = $this->extractCsvValue($row, $hidx['locale'] ?? null) ?: $defaultLocale;
                $timezone  = $this->extractCsvValue($row, $hidx['timezone'] ?? null) ?: $defaultTimezone;
                $status    = $this->extractCsvValue($row, $hidx['status'] ?? null) ?: $defaultStatus;
                $consent   = $this->extractCsvValue($row, $hidx['consent_source'] ?? null) ?: null;
                $gdprRaw   = $this->extractCsvValue($row, $hidx['gdpr_consent_at'] ?? null);
                $gdprAt    = null; if ($gdprRaw) { try { $gdprAt = new \DateTimeImmutable($gdprRaw); } catch (\Throwable) {} }

                // attributes
                $attrs = null;
                $attrsRaw = $this->extractCsvValue($row, $hidx['attributes'] ?? null);
                if ($attrsRaw) {
                    $attrsRaw = trim($attrsRaw);
                    if ($this->looksLikeJson($attrsRaw)) {
                        $tmp = json_decode($attrsRaw, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $attrs = $tmp;
                    } else {
                        $tmp = [];
                        foreach (preg_split('/[;,]\s*/', $attrsRaw) as $pair) {
                            if ($pair === '') continue;
                            [$k,$v] = array_pad(explode('=', $pair, 2), 2, null);
                            $k = trim((string)$k); $v = $v !== null ? trim((string)$v) : null;
                            if ($k !== '') $tmp[$k] = $v;
                        }
                        if ($tmp) $attrs = $tmp;
                    }
                }

                $batch[] = [
                    'line'    => $lineNo,
                    'email'   => $email,
                    'company' => $company,
                    'now'     => $now,
                    'fields'  => [
                        'name'=>$name,'locale'=>$locale,'timezone'=>$timezone,'status'=>$status,
                        'consent'=>$consent,'gdpr_at'=>$gdprAt,'attrs'=>$attrs,
                    ],
                ];

                if (count($batch) >= $BATCH) {
                    $flush($batch);
                    $batch = [];
                }
            } catch (\Throwable $e) {
                error_log('CSV IMPORT: row error @line '.$lineNo.' : '.$e->getMessage());
                $summary['errors']++;
                $results[] = ['line'=>$lineNo, 'status'=>'error', 'error'=>$e->getMessage()];
            }
        }
        // flush tail
        if ($batch) {
            $flush($batch);
        }

        $http = $dryRun ? 200 : 207;
        return new JsonResponse(['file' => $uploadedName, 'summary' => $summary, 'results' => $results], $http);
    }

    private function detectCsvHeaderAndDelimiter(?string $path, string $fallbackRaw): array
    {
        $firstLine = null;

        if ($path && is_readable($path)) {
            $fh = fopen($path, 'rb');
            if ($fh) { $firstLine = fgets($fh); fclose($fh); }
        }
        if ($firstLine === null) {
            $norm = str_replace(["\r\n","\r"], "\n", $fallbackRaw);
            $firstLine = strtok($norm, "\n");
        }
        if ($firstLine === false || $firstLine === null) {
            error_log('CSV IMPORT: detectHdr firstLine empty');
            throw new RuntimeException('CSV appears empty', 400);
        }

        // Strip UTF-8 BOM
        if (strncmp($firstLine, "\xEF\xBB\xBF", 3) === 0) {
            $firstLine = substr($firstLine, 3);
        }

        $delims = [',',';',"\t",'|'];
        $best = ','; $bestCount = -1;
        foreach ($delims as $d) {
            $c = substr_count($firstLine, $d);
            if ($c > $bestCount) { $bestCount = $c; $best = $d; }
        }

        $headers = str_getcsv($firstLine, $best, '"', '\\');
        $headers = array_map(static fn($h) => strtolower(trim((string)$h)), $headers);

        return [$headers, $best];
    }

    private function streamCsvRows(?string $path, string $fallbackRaw, string $delim): \Generator
    {
        if ($path && is_readable($path)) {

            $spl = new \SplFileObject($path, 'r');
            $spl->setFlags(
                \SplFileObject::READ_CSV
                | \SplFileObject::DROP_NEW_LINE
                | \SplFileObject::SKIP_EMPTY
            );
            $spl->setCsvControl($delim, '"', '\\');

            $first = true;
            foreach ($spl as $row) {
                if ($row === false || $row === [null]) { continue; }
                if ($first) { $first = false; continue; } // skip header

                // extra safety: if some driver still yields strings, parse it
                if (!is_array($row)) {
                    $row = str_getcsv((string)$row, $delim, '"', '\\');
                    if ($row === null) { continue; }
                }
                yield $row;
            }
            return;
        }

        // Fallback: small file in memory
        $raw = str_replace(["\r\n","\r"], "\n", $fallbackRaw);
        $lines = preg_split("/\n/", $raw) ?: [];
        while ($lines && trim((string)$lines[0])==='') array_shift($lines);
        while ($lines && trim((string)end($lines))==='') array_pop($lines);
        array_shift($lines); // drop header

        $i=0;
        foreach ($lines as $line) {
            if (trim($line)==='') continue;
            $i++; if ($i<=3) error_log('CSV IMPORT: mem row sample #'.$i.' len='.strlen($line));
            yield str_getcsv($line, $delim, '"', '\\');
        }
    }


    private function buildHeaderIndex(array $headers, array $aliasMap): array
    {
        $idx = [];
        $low = array_map('strtolower', $headers);

        foreach ($aliasMap as $canon => $aliases) {
            foreach ($aliases as $a) {
                $pos = array_search(strtolower($a), $low, true);
                if ($pos !== false) { $idx[$canon] = $pos; break; }
            }
        }
        if (!isset($idx['email'])) {
            if (isset($headers[0]) && str_contains($headers[0], 'email')) {
                $idx['email'] = 0;
            } else {
                error_log('CSV IMPORT: buildHeaderIndex MISSING email column');
                throw new RuntimeException('CSV must contain an "email" column', 400);
            }
        }
        return $idx;
    }

    private function extractCsvValue(array $row, ?int $pos): ?string
    {
        return $pos === null ? null : (array_key_exists($pos, $row) ? trim((string)$row[$pos]) : null);
    }

    private function looksLikeJson(string $s): bool
    {
        $s = ltrim($s);
        return ($s !== '' && ($s[0] === '{' || $s[0] === '['));
    }

    /**
     * Fallback: fetch contacts for given emails by looping with findOneBy.
     * Returns map: strtolower(email) => Contact entity
     */
    private function fetchContactsByEmailsRepo($cRepo, int $companyId, array $emails): array
    {
        $emails = array_values(array_unique(array_map('strtolower', array_filter($emails))));
        if (!$emails) return [];

        $out = [];
        foreach ($emails as $em) {
            try {
                $e = $cRepo->findOneBy(['company_id' => $companyId, 'email' => $em]);
                if ($e) $out[$em] = $e;
            } catch (\Throwable $err) {
                error_log('CSV IMPORT: repoFetch contact error for '.$em.' : '.$err->getMessage());
            }
        }
        return $out;
    }

    /**
     * Fallback: fetch membership pairs by looping with findOneBy.
     * Returns set keyed by "listId:contactId"
     */
    private function fetchExistingMembershipPairsRepo($lcRepo, array $listIds, array $contactIds): array
    {
        $set = [];
        foreach ($listIds as $lid) {
            foreach ($contactIds as $cid) {
                try {
                    $exists = $lcRepo->findOneBy(['listGroup_id' => (int)$lid, 'contact_id' => (int)$cid]);
                    if ($exists) {
                        $set[(int)$lid . ':' . (int)$cid] = true;
                    }
                } catch (\Throwable $err) {
                    error_log('CSV IMPORT: repoFetch pair error lid='.$lid.' cid='.$cid.' : '.$err->getMessage());
                }
            }
        }
        return $set;
    }

}
