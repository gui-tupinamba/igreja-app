<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administration\UserManagementService;
use App\Auth\LoginThrottle;
use App\Http\ApiInput;
use App\Http\UserInput;
use App\Http\UserView;
use App\Repository\ProfileReader;
use App\Security\CurrentActor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ProfileController
{
    public function __construct(private CurrentActor $actor, private ProfileReader $reader, private UserManagementService $users, private LoginThrottle $throttle)
    {
    }

    #[Route('/api/profile', name: 'api_profile', methods: ['GET'])]
    public function detail(Request $request): JsonResponse
    {
        $this->noQuery($request);
        return new JsonResponse($this->reader->own($this->actor->get()->userId), headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/profile', name: 'api_profile_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $data = ApiInput::jsonObject($request, UserInput::SELF_FIELDS);
        $actor = $this->actor->get();
        $user = $this->users->updateProfile($actor, $actor->userId, $data, self: true);
        return new JsonResponse(['user' => UserView::profile($user)], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/profile/password', name: 'api_profile_password', methods: ['POST'])]
    public function password(Request $request): Response
    {
        $this->noQuery($request);
        $data = ApiInput::jsonObject($request, ['current_password', 'new_password']);
        $actor = $this->actor->get();
        $currentPassword = UserInput::currentPassword($data['current_password'] ?? null);
        $newPassword = UserInput::password($data['new_password'] ?? null, 'new_password');
        // A stable user key prevents brute force from being reset by changing profile data.
        $this->throttle->consume('password-change:'.$actor->userId, $request->getClientIp() ?? 'unknown');
        $this->users->changePassword($actor, $actor->userId, $newPassword, $currentPassword, self: true);
        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }

    private function noQuery(Request $request): void
    {
        if ($request->query->count() !== 0) {
            throw new UnprocessableEntityHttpException();
        }
    }
}
