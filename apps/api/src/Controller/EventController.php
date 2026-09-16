<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administration\EventManagementService;
use App\Enum\EventStatus;
use App\Http\ApiInput;
use App\Http\EventInput;
use App\Http\EventView;
use App\Repository\EventDirectory;
use App\Security\CurrentActor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class EventController
{
    public function __construct(private CurrentActor $actor, private EventDirectory $directory, private EventManagementService $events)
    {
    }

    #[Route('/api/events', name: 'api_events_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = EventInput::query($request);
        return $this->json($this->directory->list($this->actor->get()->userId, $page['page'], $page['limit'], $page['filters']));
    }

    #[Route('/api/events/{id}', name: 'api_events_detail', requirements: ['id' => '[0-9]+'], methods: ['GET'])]
    public function detail(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(['event' => $this->directory->detail($this->actor->get()->userId, ApiInput::id($id)) ?? throw new NotFoundHttpException()]);
    }

    #[Route('/api/admin/events', name: 'api_admin_events', methods: ['GET'])]
    public function adminList(Request $request): JsonResponse
    {
        $page = EventInput::query($request, true);
        return $this->json($this->directory->list($this->actor->get()->userId, $page['page'], $page['limit'], $page['filters'], true));
    }

    #[Route('/api/admin/events/{id}', name: 'api_admin_event', requirements: ['id' => '[0-9]+'], methods: ['GET'])]
    public function adminDetail(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(['event' => $this->directory->detail($this->actor->get()->userId, ApiInput::id($id), true) ?? throw new NotFoundHttpException()]);
    }

    #[Route('/api/events', name: 'api_event_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $event = EventView::data($this->events->create($this->actor->get(), ApiInput::jsonObject($request, EventInput::FIELDS, 524288)));
        return new JsonResponse(['event' => $event], 201, ['Cache-Control' => 'no-store', 'Location' => '/api/admin/events/'.$event['id']]);
    }

    #[Route('/api/events/{id}', name: 'api_event_update', requirements: ['id' => '[0-9]+'], methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(['event' => EventView::data($this->events->update($this->actor->get(), ApiInput::id($id), ApiInput::jsonObject($request, EventInput::FIELDS, 524288)))]);
    }

    #[Route('/api/events/{id}/publish', name: 'api_event_publish', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function publish(string $id, Request $request): JsonResponse
    {
        $this->emptyRequest($request);
        return $this->json(['event' => EventView::data($this->events->transition($this->actor->get(), ApiInput::id($id), EventStatus::PUBLISHED))]);
    }

    #[Route('/api/events/{id}/unpublish', name: 'api_event_unpublish', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function unpublish(string $id, Request $request): JsonResponse
    {
        $this->emptyRequest($request);
        return $this->json(['event' => EventView::data($this->events->transition($this->actor->get(), ApiInput::id($id), EventStatus::DRAFT))]);
    }

    #[Route('/api/events/{id}', name: 'api_event_archive', requirements: ['id' => '[0-9]+'], methods: ['DELETE'])]
    public function archive(string $id, Request $request): Response
    {
        $this->emptyRequest($request);
        $this->events->transition($this->actor->get(), ApiInput::id($id), EventStatus::ARCHIVED);
        return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/events/{id}/cancel', name: 'api_event_cancel', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function cancel(string $id, Request $request): JsonResponse
    {
        $this->emptyRequest($request);
        return $this->json(['event' => EventView::data($this->events->transition($this->actor->get(), ApiInput::id($id), EventStatus::CANCELLED))]);
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
