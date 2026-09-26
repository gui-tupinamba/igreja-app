<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administration\ScheduleManagementService;
use App\Enum\ScheduleStatus;
use App\Http\ApiInput;
use App\Http\ScheduleInput;
use App\Http\ScheduleView;
use App\Repository\ScheduleDirectory;
use App\Security\CurrentActor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ScheduleController
{
    public function __construct(private CurrentActor $actor, private ScheduleDirectory $directory, private ScheduleManagementService $schedules)
    {
    }

    #[Route('/api/schedules', name: 'api_schedules_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = ScheduleInput::query($request);
        return $this->json($this->directory->list($this->actor->get()->userId, $page['page'], $page['limit'], $page['filters']));
    }

    #[Route('/api/schedules/{id}', name: 'api_schedules_detail', requirements: ['id' => '[0-9]+'], methods: ['GET'])]
    public function detail(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(['schedule' => $this->directory->detail($this->actor->get()->userId, ApiInput::id($id)) ?? throw new NotFoundHttpException()]);
    }

    #[Route('/api/admin/schedules', name: 'api_admin_schedules', methods: ['GET'])]
    public function adminList(Request $request): JsonResponse
    {
        $page = ScheduleInput::query($request, true);
        return $this->json($this->directory->list($this->actor->get()->userId, $page['page'], $page['limit'], $page['filters'], true));
    }

    #[Route('/api/admin/schedules/{id}', name: 'api_admin_schedule', requirements: ['id' => '[0-9]+'], methods: ['GET'])]
    public function adminDetail(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(['schedule' => $this->directory->detail($this->actor->get()->userId, ApiInput::id($id), true) ?? throw new NotFoundHttpException()]);
    }

    #[Route('/api/schedules', name: 'api_schedule_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $schedule = ScheduleView::data($this->schedules->create($this->actor->get(), ApiInput::jsonObject($request, ScheduleInput::FIELDS, 524288)));
        return new JsonResponse(['schedule' => $schedule], 201, ['Cache-Control' => 'no-store', 'Location' => '/api/admin/schedules/'.$schedule['id']]);
    }

    #[Route('/api/schedules/{id}', name: 'api_schedule_update', requirements: ['id' => '[0-9]+'], methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(['schedule' => ScheduleView::data($this->schedules->update($this->actor->get(), ApiInput::id($id), ApiInput::jsonObject($request, ScheduleInput::FIELDS, 524288)))]);
    }

    #[Route('/api/schedules/{id}/publish', name: 'api_schedule_publish', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function publish(string $id, Request $request): JsonResponse
    {
        $this->emptyRequest($request);
        return $this->json(['schedule' => ScheduleView::data($this->schedules->transition($this->actor->get(), ApiInput::id($id), ScheduleStatus::PUBLISHED))]);
    }

    #[Route(
    '/api/schedules/{id}/submit-review',
    name: 'api_schedule_submit_review',
    requirements: [
        'id' => '[0-9]+',
    ],
    methods: ['POST']
)]
public function submitReview(
    string $id,
    Request $request,
): JsonResponse {
    $this->emptyRequest(
        $request,
    );

    return $this->json([
        'schedule' =>
            ScheduleView::data(
                $this->schedules->transition(
                    $this->actor->get(),
                    ApiInput::id($id),
                    ScheduleStatus::PENDING_REVIEW,
                ),
            ),
    ]);
}

    #[Route('/api/schedules/{id}/unpublish', name: 'api_schedule_unpublish', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function unpublish(string $id, Request $request): JsonResponse
    {
        $this->emptyRequest($request);
        return $this->json(['schedule' => ScheduleView::data($this->schedules->transition($this->actor->get(), ApiInput::id($id), ScheduleStatus::DRAFT))]);
    }

    #[Route('/api/schedules/{id}', name: 'api_schedule_archive', requirements: ['id' => '[0-9]+'], methods: ['DELETE'])]
    public function archive(string $id, Request $request): Response
    {
        $this->emptyRequest($request);
        $this->schedules->transition($this->actor->get(), ApiInput::id($id), ScheduleStatus::ARCHIVED);
        return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/schedules/{id}/cancel', name: 'api_schedule_cancel', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function cancel(string $id, Request $request): JsonResponse
    {
        $this->emptyRequest($request);
        return $this->json(['schedule' => ScheduleView::data($this->schedules->transition($this->actor->get(), ApiInput::id($id), ScheduleStatus::CANCELLED))]);
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

    private function json(array $data): JsonResponse
    {
        return new JsonResponse($data, headers: ['Cache-Control' => 'no-store']);
    }
}
