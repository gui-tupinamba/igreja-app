<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ApiInput;
use App\Notification\NotificationService;
use App\Security\CurrentActor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class NotificationController
{
    public function __construct(private CurrentActor $actor, private NotificationService $notifications) {}

    #[Route('/api/notifications', methods: ['GET'])]
    public function list(Request $request): JsonResponse { $p=ApiInput::pagination($request); return $this->json($this->notifications->list($this->actor->get()->userId,$p['page'],$p['limit'])); }

    #[Route('/api/notifications/{id}', requirements: ['id'=>'[0-9]+'], methods: ['GET'])]
    public function detail(string $id,Request $request): JsonResponse { $this->noQuery($request); return $this->json(['notification'=>$this->notifications->detail($this->actor->get()->userId,ApiInput::id($id))]); }

    #[Route('/api/notifications/{id}/read', requirements: ['id'=>'[0-9]+'], methods: ['POST'])]
    public function read(string $id,Request $request): JsonResponse { $this->empty($request); return $this->json(['notification'=>$this->notifications->read($this->actor->get()->userId,ApiInput::id($id))]); }

    #[Route('/api/notifications/read-all', methods: ['POST'], priority: 10)]
    public function readAll(Request $request): JsonResponse { $this->empty($request); return $this->json(['updated'=>$this->notifications->readAll($this->actor->get()->userId)]); }

    #[Route('/api/notifications/preferences', methods: ['GET'])]
    public function preferences(Request $request): JsonResponse { $this->noQuery($request); return $this->json(['preferences'=>$this->notifications->preferences($this->actor->get()->userId)]); }

    #[Route('/api/notifications/preferences', methods: ['PATCH'])]
    public function updatePreferences(Request $request): JsonResponse { $this->noQuery($request); return $this->json(['preferences'=>$this->notifications->updatePreferences($this->actor->get()->userId,ApiInput::jsonObject($request,['push_enabled','church_push_enabled','ministry_push_enabled']))]); }

    #[Route('/api/notifications/devices', methods: ['POST'])]
    public function device(Request $request): JsonResponse { $this->noQuery($request); return new JsonResponse(['device'=>$this->notifications->registerDevice($this->actor->get(),ApiInput::jsonObject($request,['expo_push_token','platform']))],201,['Cache-Control'=>'no-store']); }

    #[Route('/api/notifications/devices/unregister', methods: ['POST'])]
    public function unregister(Request $request): Response { $this->noQuery($request); $this->notifications->unregisterDevice($this->actor->get(),ApiInput::jsonObject($request,['expo_push_token'])); return new Response(status:204,headers:['Cache-Control'=>'no-store']); }

    #[Route('/api/admin/notifications', methods: ['POST'])]
    public function create(Request $request): JsonResponse { $this->noQuery($request); $n=$this->notifications->create($this->actor->get(),ApiInput::jsonObject($request,['scope','ministry_id','target_user_id','title','body','route','idempotency_key'])); return new JsonResponse(['notification'=>$n],201,['Cache-Control'=>'no-store','Location'=>'/api/notifications/'.$n['id']]); }

    private function noQuery(Request $r): void { if($r->query->count())throw new UnprocessableEntityHttpException(); }
    private function empty(Request $r): void { $this->noQuery($r); if($r->getContent()!=='')ApiInput::jsonObject($r,[]); }
    private function json(array $data): JsonResponse { return new JsonResponse($data,headers:['Cache-Control'=>'no-store']); }
}
