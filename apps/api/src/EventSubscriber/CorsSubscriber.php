<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Auth\AuthTransport;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class CorsSubscriber implements EventSubscriberInterface
{
    private const HEADERS = ['authorization', 'content-type', 'x-auth-client'];
    private const METHODS = ['GET', 'POST'];

    public function __construct(private AuthTransport $transport)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Preflight precedes routing and the firewall; no authentication work runs on OPTIONS.
        return [KernelEvents::REQUEST => ['onRequest', 250], KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        $origin = $request->headers->get('Origin');
        if ($origin !== null && !$this->transport->isAllowedOrigin($origin)) {
            throw new AccessDeniedHttpException();
        }
        if (!$request->isMethod('OPTIONS') || !$request->headers->has('Access-Control-Request-Method')) {
            return;
        }
        if ($origin === null || str_starts_with($request->getPathInfo(), '/api/auth/mobile/')) {
            throw new AccessDeniedHttpException();
        }
        $method = $request->headers->get('Access-Control-Request-Method');
        $path = $request->getPathInfo();
        $methods = match (true) {
            preg_match('#^/api/schedules/[1-9][0-9]*$#D', $path) === 1 => ['GET', 'PATCH', 'DELETE'],
            preg_match('#^/api/schedules/[1-9][0-9]*/(publish|unpublish|cancel)$#D', $path) === 1 => ['POST'],
            preg_match('#^/api/events/[1-9][0-9]*$#D', $path) === 1 => ['GET', 'PATCH', 'DELETE'],
            preg_match('#^/api/events/[1-9][0-9]*/(publish|unpublish|cancel)$#D', $path) === 1 => ['POST'],
            preg_match('#^/api/posts/[1-9][0-9]*$#D', $path) === 1 => ['GET', 'PATCH', 'DELETE'],
            preg_match('#^/api/posts/[1-9][0-9]*/comments/[1-9][0-9]*$#D', $path) === 1 => ['GET', 'PATCH', 'DELETE'],
            preg_match('#^/api/admin/posts/[1-9][0-9]*/comments/[1-9][0-9]*/status$#D', $path) === 1 => ['PATCH'],
            preg_match('#^/api/posts/[1-9][0-9]*/(publish|unpublish)$#D', $path) === 1 => ['POST'],
            preg_match('#^/api/ministries/[1-9][0-9]*$#D', $path) === 1 => ['GET', 'PATCH', 'DELETE'],
            preg_match('#^/api/ministries/[1-9][0-9]*/(members|leaders)/[1-9][0-9]*$#D', $path) === 1 => ['DELETE'],
            preg_match('#^/api/admin/users/[1-9][0-9]*/access$#D', $path) === 1 => ['PATCH'],
            preg_match('#^/api/admin/users/[1-9][0-9]*$#D', $path) === 1, $path === '/api/profile' => ['GET', 'PATCH'],
            preg_match('#^/api/admin/users/[1-9][0-9]*/password$#D', $path) === 1, $path === '/api/profile/password' => ['POST'],
            default => self::METHODS,
        };
        $headers = array_filter(array_map('trim', explode(',', strtolower($request->headers->get('Access-Control-Request-Headers', '')))));
        if (!in_array($method, $methods, true) || array_diff($headers, self::HEADERS) !== []) {
            throw new AccessDeniedHttpException();
        }

        $event->setResponse(new Response(status: Response::HTTP_NO_CONTENT, headers: [
            'Cache-Control' => 'no-store',
            'Access-Control-Allow-Methods' => implode(', ', $methods),
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Auth-Client',
            'Access-Control-Max-Age' => '600',
        ]));
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        $response = $event->getResponse();
        $response->setVary('Origin', false);
        $origin = $request->headers->get('Origin');
        if ($this->transport->isAllowedOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }
        if (str_starts_with($request->getPathInfo(), '/api/auth/')) {
            $response->headers->set('Cache-Control', 'no-store');
        }
    }
}
