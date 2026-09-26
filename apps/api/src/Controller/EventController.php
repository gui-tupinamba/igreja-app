<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administration\EventImageService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
    public function __construct(
        private CurrentActor $actor,
        private EventDirectory $directory,
        private EventManagementService $events,
        private EventImageService $images,
    ) {
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
        return $this->json(
            ['event' => $this->directory->detail($this->actor->get()->userId, 
            ApiInput::id($id), true) ?? throw new NotFoundHttpException()]);
    }

    #[Route('/api/events', name: 'api_event_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $event = EventView::data($this->events->create($this->actor->get(), ApiInput::jsonObject($request, EventInput::FIELDS, 524288)));
        return new JsonResponse(
            ['event' => $event], 201, 
            ['Cache-Control' => 'no-store', 'Location' => '/api/admin/events/'.$event['id']]);
    }

    #[Route('/api/events/{id}', name: 'api_event_update', requirements: ['id' => '[0-9]+'], methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(
            ['event' => EventView::data($this->events->update($this->actor->get(), 
            ApiInput::id($id), 
            ApiInput::jsonObject($request, EventInput::FIELDS, 524288)))]);
    }

    #[Route('/api/events/{id}/publish', name: 'api_event_publish', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function publish(string $id, Request $request): JsonResponse
    {
        $this->emptyRequest($request);
        return $this->json(
            ['event' => EventView::data($this->events->transition($this->actor->get(), 
            ApiInput::id($id), 
            EventStatus::PUBLISHED))]);
    }

    #[Route(
    '/api/events/{id}/submit-review',
    name: 'api_event_submit_review',
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
        'event' =>
            EventView::data(
                $this->events->transition(
                    $this->actor->get(),
                    ApiInput::id($id),
                    EventStatus::PENDING_REVIEW,
                ),
            ),
    ]);
}

    #[Route('/api/events/{id}/unpublish', name: 'api_event_unpublish', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function unpublish(string $id, Request $request): JsonResponse
    {
        $this->emptyRequest($request);
        return $this->json(
            ['event' => EventView::data($this->events->transition($this->actor->get(), 
            ApiInput::id($id), 
            EventStatus::DRAFT))]);
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
        return $this->json(
            ['event' => EventView::data($this->events->transition($this->actor->get(), 
            ApiInput::id($id), 
            EventStatus::CANCELLED))]);
    }

    #[Route(
        '/api/events/{id}/images',
        name: 'api_event_image_upload',
        requirements: ['id' => '[0-9]+'],
        methods: ['POST']
    )]
    public function uploadImage(
        string $id,
        Request $request,
    ): JsonResponse {
        $this->noQuery($request);

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            throw new UnprocessableEntityHttpException(
                'Envie uma imagem no campo "file".'
            );
        }

        $image = $this->images->upload(
            $this->actor->get(),
            ApiInput::id($id),
            $file,
        );

        return new JsonResponse(
            ['image' => $image],
            Response::HTTP_CREATED,
            [
                'Cache-Control' => 'no-store',
            ],
        );
    }

    #[Route(
        '/api/events/{eventId}/images/{imageId}',
        name: 'api_event_image',
        requirements: [
            'eventId' => '[0-9]+',
            'imageId' => '[0-9]+',
        ],
        defaults: [
            'variant' => 'full',
        ],
        methods: ['GET']
    )]
    #[Route(
        '/api/events/{eventId}/images/{imageId}/{variant}',
        name: 'api_event_image_variant',
        requirements: [
            'eventId' => '[0-9]+',
            'imageId' => '[0-9]+',
            'variant' => 'full|detail|feed',
        ],
        methods: ['GET']
    )]
    public function image(
        string $eventId,
        string $imageId,
        string $variant,
        Request $request,
    ): BinaryFileResponse {
        $this->noQuery($request);

        $image = $this->images->getForRead(
            $this->actor->get(),
            ApiInput::id($eventId),
            ApiInput::id($imageId),
            $variant,
        );

        $response = new BinaryFileResponse(
            $image['path']
        );

        $response->headers->set(
            'Content-Type',
            $image['mime_type'],
        );

        $response->headers->set(
            'Cache-Control',
            'private, no-cache, must-revalidate',
        );

        $response->setAutoEtag();
        $response->setAutoLastModified();

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $image['original_name'],
        );

        $response->isNotModified(
            $request
        );

        return $response;
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
