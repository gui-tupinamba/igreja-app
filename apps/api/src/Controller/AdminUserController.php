<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administration\UserAccessService;
use App\Administration\UserManagementService;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Http\ApiInput;
use App\Http\UserInput;
use App\Http\UserView;
use App\Repository\AdminUserReader;
use App\Security\CurrentActor;
use App\Security\Voter\UserVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[AsController]
final readonly class AdminUserController
{
    public function __construct(private CurrentActor $actor, private AdminUserReader $reader, private UserAccessService $access, private AuthorizationCheckerInterface $authorization, private UserManagementService $users)
    {
    }

    #[Route('/api/admin/users', name: 'api_admin_user_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->requireUserManagement();
        $this->noQuery($request);
        $user = $this->users->create($this->actor->get(), ApiInput::jsonObject($request, UserInput::CREATE_FIELDS));
        return new JsonResponse(['user' => UserView::profile($user)], Response::HTTP_CREATED, [
            'Cache-Control' => 'no-store', 'Location' => '/api/admin/users/'.$user->getId(),
        ]);
    }

    #[Route('/api/admin/users/{id}', name: 'api_admin_user_update', requirements: ['id' => '[0-9]+'], methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->requireUserManagement();
        $this->noQuery($request);
        $user = $this->users->updateProfile($this->actor->get(), ApiInput::id($id), ApiInput::jsonObject($request, UserInput::PROFILE_FIELDS));
        return new JsonResponse(['user' => UserView::profile($user)], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/admin/users/{id}/password', name: 'api_admin_user_password', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function password(string $id, Request $request): Response
    {
        $this->requireUserManagement();
        $this->noQuery($request);
        $data = ApiInput::jsonObject($request, ['new_password']);
        $this->users->changePassword($this->actor->get(), ApiInput::id($id), UserInput::password($data['new_password'] ?? null, 'new_password'));
        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/admin/users', name: 'api_admin_users', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->requireUserManagement();
        $pagination = ApiInput::pagination($request, ['role', 'status']);
        $query = $request->query->all();
        $role = array_key_exists('role', $query) ? $this->role($query['role']) : null;
        $status = array_key_exists('status', $query) ? $this->status($query['status']) : null;

        return new JsonResponse($this->reader->list($this->actor->get()->userId, $pagination['page'], $pagination['limit'], $role, $status), headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/admin/users/{id}', name: 'api_admin_user_detail', requirements: ['id' => '[0-9]+'], methods: ['GET'])]
    public function detail(string $id): JsonResponse
    {
        $this->requireUserManagement();
        return new JsonResponse(['user' => $this->reader->detail($this->actor->get()->userId, ApiInput::id($id))], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/admin/users/{id}/access', name: 'api_admin_user_access', requirements: ['id' => '[0-9]+'], methods: ['PATCH'])]
    public function changeAccess(string $id, Request $request): JsonResponse
    {
        $this->requireUserManagement();
        $data = ApiInput::jsonObject($request, ['role', 'status']);
        if ($data === []) {
            throw new UnprocessableEntityHttpException();
        }
        $role = array_key_exists('role', $data) ? $this->role($data['role']) : null;
        $status = array_key_exists('status', $data) ? $this->status($data['status']) : null;
        $user = $this->access->changeAccess($this->actor->get(), ApiInput::id($id), $role, $status);

        return new JsonResponse(['user' => [
            'id' => $user->getId(), 'name' => $user->getName(), 'email' => $user->getEmail(),
            'role' => $user->getRole()->value, 'status' => $user->getStatus()->value,
        ]], headers: ['Cache-Control' => 'no-store']);
    }

    private function role(mixed $value): UserRole
    {
        return is_string($value) ? (UserRole::tryFrom($value) ?? throw new UnprocessableEntityHttpException()) : throw new UnprocessableEntityHttpException();
    }

    private function requireUserManagement(): void
    {
        if (!$this->authorization->isGranted(UserVoter::USER_LIST, User::class)) {
            throw new AccessDeniedHttpException();
        }
    }

    private function status(mixed $value): UserStatus
    {
        return is_string($value) ? (UserStatus::tryFrom($value) ?? throw new UnprocessableEntityHttpException()) : throw new UnprocessableEntityHttpException();
    }

    private function noQuery(Request $request): void
    {
        if ($request->query->count() !== 0) {
            throw new UnprocessableEntityHttpException();
        }
    }
}
