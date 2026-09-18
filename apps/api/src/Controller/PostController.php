<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use App\Administration\PostImageService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Administration\PostManagementService;
use App\Enum\PostStatus;
use App\Http\ApiInput;
use App\Http\PostInput;
use App\Http\PostView;
use App\Repository\PostDirectory;
use App\Security\CurrentActor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class PostController
{
    public function __construct(
        private CurrentActor $actor, 
        private PostDirectory $directory, 
        private PostManagementService $posts,
        private PostImageService $images,)
    {
    }

    #[Route('/api/posts', name: 'api_posts_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = PostInput::query($request);
        return $this->json($this->directory->list($this->actor->get()->userId, $page['page'], $page['limit'], $page['filters']));
    }

    #[Route('/api/posts/{id}', name: 'api_posts_detail', requirements: ['id' => '[0-9]+'], methods: ['GET'])]
    public function detail(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(['post' => $this->directory->detail($this->actor->get()->userId, ApiInput::id($id)) ?? throw new NotFoundHttpException()]);
    }

    #[Route('/api/admin/posts', name: 'api_admin_posts', methods: ['GET'])]
    public function adminList(Request $request): JsonResponse
    {
        $page = PostInput::query($request, true);
        return $this->json($this->directory->list($this->actor->get()->userId, $page['page'], $page['limit'], $page['filters'], true));
    }

    #[Route('/api/admin/posts/{id}', name: 'api_admin_post', requirements: ['id' => '[0-9]+'], methods: ['GET'])]
    public function adminDetail(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(['post' => $this->directory->detail($this->actor->get()->userId, ApiInput::id($id), true) ?? throw new NotFoundHttpException()]);
    }

    #[Route('/api/posts', name: 'api_post_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $post = PostView::data($this->posts->create($this->actor->get(), ApiInput::jsonObject($request, PostInput::FIELDS, 524288)));
        return new JsonResponse(['post' => $post], 201, ['Cache-Control' => 'no-store', 'Location' => '/api/admin/posts/'.$post['id']]);
    }

    #[Route('/api/posts/{id}', name: 'api_post_update', requirements: ['id' => '[0-9]+'], methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->json(['post' => PostView::data($this->posts->update($this->actor->get(), ApiInput::id($id), ApiInput::jsonObject($request, PostInput::FIELDS, 524288)))]);
    }

    #[Route('/api/posts/{id}/publish', name: 'api_post_publish', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function publish(string $id, Request $request): JsonResponse
    {
        $this->emptyRequest($request);
        return $this->json(['post' => PostView::data($this->posts->transition($this->actor->get(), ApiInput::id($id), PostStatus::PUBLISHED))]);
    }

    #[Route('/api/posts/{id}/unpublish', name: 'api_post_unpublish', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function unpublish(string $id, Request $request): JsonResponse
    {
        $this->emptyRequest($request);
        return $this->json(['post' => PostView::data($this->posts->transition($this->actor->get(), ApiInput::id($id), PostStatus::DRAFT))]);
    }

    #[Route('/api/posts/{id}', name: 'api_post_archive', requirements: ['id' => '[0-9]+'], methods: ['DELETE'])]
    public function archive(string $id, Request $request): Response
    {
        $this->emptyRequest($request);
        $this->posts->transition($this->actor->get(), ApiInput::id($id), PostStatus::ARCHIVED);
        return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
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

    #[Route(
        '/api/posts/{id}/images',
        name: 'api_post_image_upload',
        requirements: ['id' => '[0-9]+'],
        methods: ['POST']
    )]
    public function uploadImage(string $id, Request $request): JsonResponse
    {
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
            201,
            ['Cache-Control' => 'no-store'],
        );
    }

#[Route(
    '/api/posts/{postId}/images/{imageId}',
    name: 'api_post_image',
    requirements: [
        'postId' => '[0-9]+',
        'imageId' => '[0-9]+',
    ],
    defaults: [
        'variant' => 'full',
    ],
    methods: ['GET']
)]
#[Route(
    '/api/posts/{postId}/images/{imageId}/{variant}',
    name: 'api_post_image_variant',
    requirements: [
        'postId' => '[0-9]+',
        'imageId' => '[0-9]+',
        'variant' => 'full|detail|feed',
    ],
    methods: ['GET']
)]
public function image(
    string $postId,
    string $imageId,
    string $variant,
    Request $request,
): BinaryFileResponse {
        $this->noQuery($request);

        $image = $this->images->getForRead(
            $this->actor->get(),
            ApiInput::id($postId),
            ApiInput::id($imageId),
            $variant,
        );

        $response = new BinaryFileResponse($image['path']);

        $response->headers->set(
            'Content-Type',
            $image['mime_type']
        );

        $response->headers->set(
            'Cache-Control',
            'private, no-cache, must-revalidate'
        );

        $response->setAutoEtag();
        $response->setAutoLastModified();

        
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $image['original_name']
        );
            
        $response->isNotModified($request);
        
        return $response;
    }
}
