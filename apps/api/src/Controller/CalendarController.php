<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ScheduleInput;
use App\Repository\CalendarDirectory;
use App\Security\CurrentActor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class CalendarController
{
    public function __construct(private CurrentActor $actor, private CalendarDirectory $directory) {}

    #[Route('/api/calendar', name: 'api_calendar', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $query = ScheduleInput::query($request);
        return new JsonResponse($this->directory->list($this->actor->get()->userId, $query['page'], $query['limit'], $query['filters']), headers: ['Cache-Control'=>'no-store']);
    }
}
