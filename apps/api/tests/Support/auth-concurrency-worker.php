<?php

declare(strict_types=1);

use App\Auth\InitialAdminCreator;
use App\Auth\SessionService;
use App\Enum\AuthClientType;
use App\Kernel;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

// Credentials are passed over a private pipe, never process arguments or output.
ini_set('display_errors', '0');
try {
    if (getenv('RUN_DATABASE_TESTS') !== '1' || getenv('APP_ENV') !== 'test') {
        throw new RuntimeException('Refusing a worker outside the test environment.');
    }
    require dirname(__DIR__).'/bootstrap.php';
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $kernel = new Kernel('test', false);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $connection = $container->get(EntityManagerInterface::class)->getConnection();
    if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform
        || !str_ends_with((string) $connection->fetchOne('SELECT current_database()'), '_test')) {
        throw new RuntimeException('Refusing a worker outside the isolated PostgreSQL database.');
    }
    $connection->executeQuery("SELECT set_config('application_name', ?, false)", [$input['label']]);
    $connection->executeStatement("SET statement_timeout = '15s'");
    echo "READY\n";
    flush();

    if ($input['action'] === 'refresh') {
        $container->get(SessionService::class)->refresh($input['token'], AuthClientType::from($input['client']));
    } elseif ($input['action'] === 'admin') {
        $container->get(InitialAdminCreator::class)->create('Concurrent test admin', $input['email'], $input['password']);
    } elseif ($input['action'] === 'access') {
        $container->get(App\Administration\UserAccessService::class)->changeAccess(
            new App\Security\AuthenticatedActor((int) $input['actor'], $input['session']),
            (int) $input['target'], App\Enum\UserRole::from($input['role']), null,
        );
    } elseif ($input['action'] === 'create_user') {
        $container->get(App\Administration\UserManagementService::class)->create(
            new App\Security\AuthenticatedActor((int) $input['actor'], $input['session']), $input['data'],
        );
    } elseif ($input['action'] === 'profile') {
        $container->get(App\Administration\UserManagementService::class)->updateProfile(
            new App\Security\AuthenticatedActor((int) $input['actor'], $input['session']), (int) $input['target'], $input['data'],
        );
    } elseif ($input['action'] === 'ministry_join' || $input['action'] === 'ministry_lead') {
        $service = $container->get(App\Administration\MinistryManagementService::class);
        $actor = new App\Security\AuthenticatedActor((int) $input['actor'], $input['session']);
        if ($input['action'] === 'ministry_join') {
            $service->addMember($actor, (int) $input['ministry'], (int) $input['target']);
        } else {
            $service->grantLeadership($actor, (int) $input['ministry'], (int) $input['target']);
        }
    } elseif ($input['action'] === 'post_publish') {
        $container->get(App\Administration\PostManagementService::class)->transition(
            new App\Security\AuthenticatedActor((int) $input['actor'], $input['session']), (int) $input['target'], App\Enum\PostStatus::PUBLISHED,
        );
    } elseif ($input['action'] === 'schedule_cancel') {
        $container->get(App\Administration\ScheduleManagementService::class)->transition(
            new App\Security\AuthenticatedActor((int) $input['actor'], $input['session']), (int) $input['target'], App\Enum\ScheduleStatus::CANCELLED,
        );
    } elseif ($input['action'] === 'event_cancel') {
        $container->get(App\Administration\EventManagementService::class)->transition(
            new App\Security\AuthenticatedActor((int) $input['actor'], $input['session']), (int) $input['target'], App\Enum\EventStatus::CANCELLED,
        );
    } elseif ($input['action'] === 'event_update') {
        $container->get(App\Administration\EventManagementService::class)->update(
            new App\Security\AuthenticatedActor((int) $input['actor'], $input['session']), (int) $input['target'], $input['data'],
        );
    } elseif ($input['action'] === 'comment_create') {
        $container->get(App\Administration\CommentManagementService::class)->create(
            new App\Security\AuthenticatedActor((int) $input['actor'], $input['session']), (int) $input['target'], ['content' => 'Concurrent comment'],
        );
    } elseif ($input['action'] === 'comment_moderate') {
        $container->get(App\Administration\CommentManagementService::class)->moderate(
            new App\Security\AuthenticatedActor((int) $input['actor'], $input['session']), (int) $input['post'], (int) $input['target'], ['status' => 'HIDDEN'],
        );
    } else {
        throw new RuntimeException('Unknown worker action.');
    }
    echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR)."\n";
} catch (UnauthorizedHttpException) {
    echo json_encode(['status' => 'unauthorized'], JSON_THROW_ON_ERROR)."\n";
} catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
    echo json_encode(['status' => 'conflict'], JSON_THROW_ON_ERROR)."\n";
} catch (Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
    echo json_encode(['status' => 'forbidden'], JSON_THROW_ON_ERROR)."\n";
} catch (DomainException) {
    echo json_encode(['status' => 'refused'], JSON_THROW_ON_ERROR)."\n";
} catch (Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
    echo json_encode(['status' => 'invalid'], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    // Do not expose an exception message, SQL, password, refresh token or DSN.
    echo json_encode(['status' => 'error', 'type' => $exception::class], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
