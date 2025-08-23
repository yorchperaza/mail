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
use RuntimeException;

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
        error_log('id: ' . $company->getId());

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

        $g = (new ListGroup())
            ->setCompany($company)
            ->setName($name)
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

}
