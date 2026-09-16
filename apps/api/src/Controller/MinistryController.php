<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administration\MinistryManagementService;
use App\Http\ApiInput;
use App\Http\MinistryInput;
use App\Http\MinistryView;
use App\Repository\MinistryDirectory;
use App\Security\CurrentActor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class MinistryController
{
    public function __construct(private CurrentActor $actor, private MinistryDirectory $directory, private MinistryManagementService $ministries)
    {
    }

    #[Route('/api/ministries', name: 'api_ministries', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = ApiInput::pagination($request);
        return $this->json($this->directory->list($this->actor->get()->userId, $page['page'], $page['limit']));
    }

    #[Route('/api/admin/ministries', name: 'api_admin_ministries', methods: ['GET'])]
    public function adminList(Request $request): JsonResponse
    {
        $page = ApiInput::pagination($request, ['status']);
        $query = $request->query->all();
        $status = array_key_exists('status', $query) ? MinistryInput::status($query['status']) : null;
        return $this->json($this->directory->list($this->actor->get()->userId, $page['page'], $page['limit'], true, $status));
    }

    #[Route('/api/ministries/{id}', name: 'api_ministry', requirements: ['id' => '[0-9]+'], methods: ['GET'])]
    public function detail(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(['ministry' => $this->directory->detail($this->actor->get()->userId, ApiInput::id($id))]);
    }

    #[Route('/api/admin/ministries/{id}', name: 'api_admin_ministry', requirements: ['id' => '[0-9]+'], methods: ['GET'])]
    public function adminDetail(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(['ministry' => $this->directory->detail($this->actor->get()->userId, ApiInput::id($id), true)]);
    }

    #[Route('/api/ministries', name: 'api_ministry_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $row = $this->ministries->create($this->actor->get(), ApiInput::jsonObject($request, MinistryInput::FIELDS, 65536));
        return new JsonResponse(['ministry' => MinistryView::ministry($row)], 201,
            ['Cache-Control' => 'no-store', 'Location' => '/api/ministries/'.$row['id']]);
    }

    #[Route('/api/ministries/{id}', name: 'api_ministry_update', requirements: ['id' => '[0-9]+'], methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(['ministry' => MinistryView::ministry($this->ministries->update($this->actor->get(), ApiInput::id($id), ApiInput::jsonObject($request, MinistryInput::FIELDS, 65536)))]);
    }

    #[Route('/api/ministries/{id}', name: 'api_ministry_deactivate', requirements: ['id' => '[0-9]+'], methods: ['DELETE'])]
    public function deactivate(string $id, Request $request): Response
    {
        $this->emptyRequest($request);
        $this->ministries->update($this->actor->get(), ApiInput::id($id), ['status' => 'INACTIVE']);
        return $this->noContent();
    }

    #[Route('/api/ministries/{id}/members', name: 'api_ministry_members', requirements: ['id' => '[0-9]+'], methods: ['GET'])]
    public function members(string $id, Request $request): JsonResponse
    {
        $page = ApiInput::pagination($request, ['status']);
        $query = $request->query->all();
        $status = array_key_exists('status', $query) ? MinistryInput::status($query['status']) : 'ACTIVE';
        return $this->json($this->directory->members($this->actor->get()->userId, ApiInput::id($id), $page['page'], $page['limit'], $status));
    }

    #[Route('/api/ministries/{id}/leaders', name: 'api_ministry_leaders', requirements: ['id' => '[0-9]+'], methods: ['GET'])]
    public function leaders(string $id, Request $request): JsonResponse
    {
        $page = ApiInput::pagination($request);
        return $this->json($this->directory->members($this->actor->get()->userId, ApiInput::id($id), $page['page'], $page['limit'], leadersOnly: true));
    }

    #[Route('/api/ministries/{id}/members', name: 'api_ministry_member_add', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function addMember(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        $data = MinistryInput::member(ApiInput::jsonObject($request, ['user_id']));
        return $this->json(['membership' => MinistryView::membership($this->ministries->addMember($this->actor->get(), ApiInput::id($id), $data['user_id']))]);
    }

    #[Route('/api/ministries/{id}/leaders', name: 'api_ministry_leader_add', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function addLeader(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        $data = MinistryInput::member(ApiInput::jsonObject($request, ['user_id', 'promote_to_leader']), true);
        return $this->json(['membership' => MinistryView::membership($this->ministries->grantLeadership($this->actor->get(), ApiInput::id($id), $data['user_id'], $data['promote_to_leader']))]);
    }

    #[Route('/api/ministries/{id}/members/{userId}', name: 'api_ministry_member_remove', requirements: ['id' => '[0-9]+', 'userId' => '[0-9]+'], methods: ['DELETE'])]
    public function removeMember(string $id, string $userId, Request $request): Response
    {
        $this->emptyRequest($request);
        $this->ministries->removeMember($this->actor->get(), ApiInput::id($id), ApiInput::id($userId));
        return $this->noContent();
    }

    #[Route('/api/ministries/{id}/leaders/{userId}', name: 'api_ministry_leader_remove', requirements: ['id' => '[0-9]+', 'userId' => '[0-9]+'], methods: ['DELETE'])]
    public function removeLeader(string $id, string $userId, Request $request): Response
    {
        $this->emptyRequest($request);
        $this->ministries->removeLeadership($this->actor->get(), ApiInput::id($id), ApiInput::id($userId));
        return $this->noContent();
    }

    private function noQuery(Request $request): void
    {
        if ($request->query->count() !== 0) { throw new UnprocessableEntityHttpException(); }
    }

    private function emptyRequest(Request $request): void
    {
        $this->noQuery($request);
        if ($request->getContent() !== '') { ApiInput::jsonObject($request, []); }
    }

    private function noContent(): Response
    {
        return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
    }

    private function json(array $body): JsonResponse
    {
        return new JsonResponse($body, headers: ['Cache-Control' => 'no-store']);
    }
}
