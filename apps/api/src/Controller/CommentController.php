<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administration\CommentManagementService;
use App\Http\ApiInput;
use App\Http\CommentView;
use App\Repository\CommentDirectory;
use App\Security\CurrentActor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class CommentController
{
    public function __construct(private CurrentActor $actor, private CommentDirectory $directory, private CommentManagementService $comments) {}

    #[Route('/api/posts/{postId}/comments', name: 'api_comments_list', requirements: ['postId' => '[0-9]+'], methods: ['GET'])]
    public function list(string $postId, Request $request): JsonResponse
    {
        return $this->listing($postId, $request, false);
    }

    #[Route('/api/admin/posts/{postId}/comments', name: 'api_admin_comments_list', requirements: ['postId' => '[0-9]+'], methods: ['GET'])]
    public function adminList(string $postId, Request $request): JsonResponse
    {
        return $this->listing($postId, $request, true);
    }

    private function listing(string $postId, Request $request, bool $administrative): JsonResponse
    {
        $page = ApiInput::pagination($request);
        return new JsonResponse($this->directory->list($this->actor->get()->userId, ApiInput::id($postId), $page['page'], $page['limit'], $administrative), headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/posts/{postId}/comments', name: 'api_comment_create', requirements: ['postId' => '[0-9]+'], methods: ['POST'])]
    public function create(string $postId, Request $request): JsonResponse
    {
        $this->noQuery($request);
        $row = CommentView::data($this->comments->create($this->actor->get(), ApiInput::id($postId), ApiInput::jsonObject($request, ['content'], 65536)));
        return new JsonResponse(
            ['comment' => $row], 201, 
            ['Cache-Control' => 'no-store', 
            'Location' => '/api/posts/'.$row['post_id'].'/comments/'.$row['id']]);
    }

    #[Route('/api/posts/{postId}/comments/{id}', name: 'api_comment_update', requirements: ['postId' => '[0-9]+', 'id' => '[0-9]+'], methods: ['PATCH'])]
    public function update(string $postId, string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return new JsonResponse(['comment' => CommentView::data($this->comments->update($this->actor->get(), ApiInput::id($postId), ApiInput::id($id), ApiInput::jsonObject($request, ['content'], 65536)))], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/posts/{postId}/comments/{id}', name: 'api_comment_delete', requirements: ['postId' => '[0-9]+', 'id' => '[0-9]+'], methods: ['DELETE'])]
    public function delete(string $postId, string $id, Request $request): Response
    {
        $this->noQuery($request);
        if ($request->getContent() !== '') { ApiInput::jsonObject($request, []); }
        $this->comments->delete($this->actor->get(), ApiInput::id($postId), ApiInput::id($id));
        return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/admin/posts/{postId}/comments/{id}/status', name: 'api_comment_moderate', requirements: ['postId' => '[0-9]+', 'id' => '[0-9]+'], methods: ['PATCH'])]
    public function moderate(string $postId, string $id, Request $request): Response
    {
        $this->noQuery($request);
        $this->comments->moderate($this->actor->get(), ApiInput::id($postId), ApiInput::id($id), ApiInput::jsonObject($request, ['status']));
        return new Response(
            status: 204, 
            headers: ['Cache-Control' => 'no-store']);
    }

    private function noQuery(Request $request): void
    {
        if ($request->query->count() !== 0) { throw new UnprocessableEntityHttpException(); }
    }
}
