<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Http\InputValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class JsonExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Handle before Symfony's exception logger can record sensitive exception messages.
        return [KernelEvents::EXCEPTION => ['onException', 64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode()
            : ($exception instanceof AccessDeniedException ? 403 : 500);
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
        [$code, $message] = match ($status) {
            400 => ['BAD_REQUEST', 'Requisição inválida.'],
            401 => ['UNAUTHORIZED', 'Autenticação necessária.'],
            403 => ['FORBIDDEN', 'Você não possui permissão para acessar este recurso.'],
            404 => ['NOT_FOUND', 'Recurso não encontrado.'],
            405 => ['METHOD_NOT_ALLOWED', 'Método não permitido.'],
            409 => ['CONFLICT', 'A operação conflita com o estado atual do recurso.'],
            422 => ['UNPROCESSABLE_ENTITY', 'Os dados enviados são inválidos.'],
            429 => ['TOO_MANY_REQUESTS', 'Muitas requisições. Tente novamente em instantes.'],
            503 => ['SERVICE_UNAVAILABLE', 'Serviço temporariamente indisponível.'],
            default => ['INTERNAL_SERVER_ERROR', 'Não foi possível concluir a solicitação.'],
        };

        if ($status >= 500) {
            $this->logger->error('API request failed.', [
                'status' => $status,
                'exception_type' => $exception::class,
            ]);
        }

        $error = ['code' => $code, 'message' => $message];
        if ($exception instanceof InputValidationException) {
            $error['fields'] = $exception->fields;
        }
        $event->setResponse(new JsonResponse([
            'error' => $error,
        ], $status, ['Cache-Control' => 'no-store', ...$headers]));
    }
}
