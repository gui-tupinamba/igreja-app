<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AuthorizedContentReader;
use App\Security\CurrentActor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ContentReadController
{
    public function __construct(private AuthorizedContentReader $reader, private CurrentActor $actor)
    {
    }

    #[Route('/api/ministries/{ministryId}/events/{id}', name: 'api_ministry_events_detail', requirements: ['ministryId' => '[0-9]+', 'id' => '[0-9]+'], methods: ['GET'])]
    public function ministryEvent(Request $request, string $ministryId, string $id): JsonResponse
    {
        $this->noQuery($request);
        $event = $this->reader->ministryEvent($this->actor->get()->userId, $this->identifier($ministryId), $this->identifier($id));

        return $this->response(['event' => $event ?? throw new NotFoundHttpException()]);
    }

    #[Route('/api/posts/{postId}/comments/{id}', name: 'api_post_comments_detail', requirements: ['postId' => '[0-9]+', 'id' => '[0-9]+'], methods: ['GET'])]
    public function postComment(Request $request, string $postId, string $id): JsonResponse
    {
        $this->noQuery($request);
        $comment = $this->reader->postComment($this->actor->get()->userId, $this->identifier($postId), $this->identifier($id));

        return $this->response(['comment' => $comment ?? throw new NotFoundHttpException()]);
    }

    private function identifier(string $value): int
    {
        if (preg_match('/\A[1-9][0-9]{0,9}\z/D', $value) !== 1 || (int) $value > 2147483647) {
            throw new NotFoundHttpException();
        }

        return (int) $value;
    }

    private function noQuery(Request $request): void
    {
        if ($request->query->all() !== []) {
            throw new UnprocessableEntityHttpException();
        }
    }

    /** @param array<string, mixed> $data */
    private function response(array $data): JsonResponse
    {
        return new JsonResponse($data, headers: ['Cache-Control' => 'no-store']);
    }
}
