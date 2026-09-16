<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Comment;
use App\Entity\Event;
use App\Entity\Ministry;
use App\Entity\MinistrySchedule;
use App\Entity\Post;
use App\Entity\User;
use App\Entity\UserMinistry;
use App\Enum\ContentVisibility;
use App\Enum\UserRole;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DomainSchemaTest extends KernelTestCase
{
    private ?EntityManagerInterface $entityManager = null;
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DATABASE_TESTS=1 and use a migrated PostgreSQL database ending in _test.');
        }

        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();

        self::assertInstanceOf(PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
        $database = (string) $this->connection->fetchOne('SELECT current_database()');
        if (!str_ends_with($database, '_test')) {
            self::fail('Refusing to write fixtures: the actual PostgreSQL database name must end in _test.');
        }

        // The runner applies migrations before PHPUnit. Tests never create, drop or truncate tables.
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->connection?->isTransactionActive()) {
                $this->connection->rollBack();
            }
        } finally {
            $this->entityManager?->clear();
            $this->entityManager = null;
            $this->connection = null;
            parent::tearDown();
        }
    }

    public function testAllEntitiesSurviveReloadWithTheirRelationshipsAndDates(): void
    {
        $ids = $this->persistGraph();
        $this->entityManager->clear();

        $user = $this->entityManager->find(User::class, $ids['user']);
        $ministry = $this->entityManager->find(Ministry::class, $ids['ministry']);
        $membership = $this->entityManager->find(UserMinistry::class, $ids['membership']);
        $post = $this->entityManager->find(Post::class, $ids['post']);
        $comment = $this->entityManager->find(Comment::class, $ids['comment']);
        $event = $this->entityManager->find(Event::class, $ids['event']);
        $schedule = $this->entityManager->find(MinistrySchedule::class, $ids['schedule']);

        self::assertInstanceOf(User::class, $user);
        self::assertInstanceOf(Ministry::class, $ministry);
        self::assertInstanceOf(UserMinistry::class, $membership);
        self::assertInstanceOf(Post::class, $post);
        self::assertInstanceOf(Comment::class, $comment);
        self::assertInstanceOf(Event::class, $event);
        self::assertInstanceOf(MinistrySchedule::class, $schedule);
        self::assertSame(UserRole::LEADER, $user->getRole());
        self::assertSame('2000-01-02', $user->getBirthDate()?->format('Y-m-d'));
        self::assertSame($user, $membership->getUser());
        self::assertSame($ministry, $membership->getMinistry());
        self::assertTrue($membership->isLeader());
        self::assertSame($user, $post->getAuthor());
        self::assertSame($ministry, $post->getMinistry());
        self::assertSame(ContentVisibility::MINISTRY_MEMBERS, $post->getVisibility());
        self::assertSame('2026-09-10T18:00:00+00:00', $post->getPublishedAt()?->format(DATE_ATOM));
        self::assertSame($post, $comment->getPost());
        self::assertSame($user, $comment->getUser());
        self::assertSame('Confirmado.', $comment->getContent());

        foreach ([$event, $schedule] as $activity) {
            self::assertSame($user, $activity->getCreatedBy());
            self::assertSame($ministry, $activity->getMinistry());
            self::assertSame(ContentVisibility::MINISTRY_MEMBERS, $activity->getVisibility());
            self::assertSame('2026-09-11T23:00:00+00:00', $activity->getStartsAt()->format(DATE_ATOM));
            self::assertSame('2026-09-12T01:00:00+00:00', $activity->getEndsAt()?->format(DATE_ATOM));
        }

        $storedEmail = $this->connection->fetchOne('SELECT email_normalized FROM users WHERE id = ?', [$ids['user']]);
        self::assertSame($user->getEmailNormalized(), $storedEmail);
    }

    public function testPostgresUsesInstantsForTimestampsAndCalendarDateForBirthday(): void
    {
        $columns = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT table_name, column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name IN ('users', 'ministries', 'user_ministries', 'posts', 'comments', 'events', 'ministry_schedules')
              AND (column_name LIKE '%\_at' ESCAPE '\' OR column_name = 'birth_date')
            SQL);

        self::assertCount(22, $columns);
        foreach ($columns as $column) {
            self::assertSame(
                $column['column_name'] === 'birth_date' ? 'date' : 'timestamp with time zone',
                $column['data_type'],
                $column['table_name'].'.'.$column['column_name'],
            );
        }
    }

    #[DataProvider('constraintViolations')]
    public function testPostgresRejectsInvalidDataEvenWhenPhpGuardsAreBypassed(string $sql, string $sqlState): void
    {
        $ids = $this->persistGraph();

        // Every statement targets only rows inserted inside this test's rollback transaction.
        $parameters = [];
        preg_match_all('/:(\w+)/', $sql, $matches);
        foreach (array_unique($matches[1]) as $name) {
            $parameters[$name] = $ids[$name];
        }

        try {
            $this->connection->executeStatement($sql, $parameters);
            self::fail('PostgreSQL accepted a domain constraint violation.');
        } catch (DriverException $exception) {
            // SQLSTATE is stable across PostgreSQL locales; error text is not.
            self::assertSame($sqlState, $exception->getSQLState());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function constraintViolations(): iterable
    {
        yield 'unique normalized email' => [
            'UPDATE users SET email = (SELECT email FROM users WHERE id = :user), email_normalized = (SELECT email_normalized FROM users WHERE id = :user) WHERE id = :other_user', '23505',
        ];
        yield 'unique ministry slug' => [
            'UPDATE ministries SET slug = (SELECT slug FROM ministries WHERE id = :ministry) WHERE id = :other_ministry', '23505',
        ];
        yield 'unique membership pair' => [
            'UPDATE user_ministries SET ministry_id = :ministry WHERE id = :other_membership', '23505',
        ];

        foreach ([
            ['users', 'user', 'role'],
            ['users', 'user', 'status'],
            ['ministries', 'ministry', 'status'],
            ['user_ministries', 'membership', 'status'],
            ['posts', 'post', 'status'],
            ['posts', 'post', 'visibility'],
            ['comments', 'comment', 'status'],
            ['events', 'event', 'status'],
            ['events', 'event', 'visibility'],
            ['ministry_schedules', 'schedule', 'status'],
            ['ministry_schedules', 'schedule', 'visibility'],
        ] as [$table, $id, $column]) {
            yield $table.'.'.$column.' enum' => ["UPDATE $table SET $column = 'UNKNOWN' WHERE id = :$id", '23514'];
        }

        foreach ([['posts', 'post'], ['events', 'event'], ['ministry_schedules', 'schedule']] as [$table, $id]) {
            yield $table.' private without ministry' => [
                "UPDATE $table SET ministry_id = NULL, visibility = 'MINISTRY_MEMBERS' WHERE id = :$id", '23514',
            ];
        }

        yield 'published post needs publication date' => [
            "UPDATE posts SET status = 'PUBLISHED', published_at = NULL WHERE id = :post", '23514',
        ];
        yield 'inactive participation cannot lead' => [
            "UPDATE user_ministries SET status = 'INACTIVE', is_leader = TRUE, left_at = joined_at WHERE id = :membership", '23514',
        ];
        yield 'departure cannot precede joining' => [
            "UPDATE user_ministries SET status = 'INACTIVE', is_leader = FALSE, left_at = joined_at - INTERVAL '1 second' WHERE id = :membership", '23514',
        ];

        foreach ([['events', 'event'], ['ministry_schedules', 'schedule']] as [$table, $id]) {
            yield $table.' invalid interval' => [
                "UPDATE $table SET ends_at = starts_at - INTERVAL '1 second' WHERE id = :$id", '23514',
            ];
        }

        foreach ([
            ['user_ministries', 'membership', 'user_id', 'users'],
            ['user_ministries', 'membership', 'ministry_id', 'ministries'],
            ['posts', 'post', 'author_id', 'users'],
            ['posts', 'post', 'ministry_id', 'ministries'],
            ['comments', 'comment', 'user_id', 'users'],
            ['comments', 'comment', 'post_id', 'posts'],
            ['events', 'event', 'created_by', 'users'],
            ['events', 'event', 'ministry_id', 'ministries'],
            ['ministry_schedules', 'schedule', 'created_by', 'users'],
            ['ministry_schedules', 'schedule', 'ministry_id', 'ministries'],
        ] as [$table, $id, $column, $parent]) {
            yield $table.'.'.$column.' foreign key' => [
                "UPDATE $table SET $column = (SELECT COALESCE(MIN(id), 0) - 1 FROM $parent) WHERE id = :$id", '23503',
            ];
        }

        foreach ([['users', 'user'], ['ministries', 'ministry'], ['posts', 'post']] as [$table, $id]) {
            yield 'history restricts deletion of '.$table => ["DELETE FROM $table WHERE id = :$id", '23503'];
        }
    }

    /** @return array<string, int> */
    private function persistGraph(): array
    {
        static $passwordHash = null;
        $passwordHash ??= password_hash('test-fixture-only', PASSWORD_BCRYPT, ['cost' => 4]);
        $suffix = bin2hex(random_bytes(8));
        $user = new User(' Maria da Silva ', ' Maria.'.$suffix.'@Example.test ', $passwordHash);
        $user->setRole(UserRole::LEADER);
        $user->setBirthDate(new \DateTimeImmutable('2000-01-02 00:00:00+14:00'));
        $otherUser = new User('Outro participante', 'outro.'.$suffix.'@example.test', $passwordHash);
        $ministry = new Ministry('Mídia', 'midia-'.$suffix);
        $otherMinistry = new Ministry('Louvor', 'louvor-'.$suffix);
        $membership = new UserMinistry($user, $ministry);
        $membership->promoteToLeader();
        $otherMembership = new UserMinistry($user, $otherMinistry);
        $post = new Post($user, 'Ensaio', 'Informações internas.', $ministry, ContentVisibility::MINISTRY_MEMBERS);
        $post->publish(new \DateTimeImmutable('2026-09-10T14:00:00-04:00'));
        $comment = new Comment($post, $user, 'Confirmado.');
        $startsAt = new \DateTimeImmutable('2026-09-11T19:00:00-04:00');
        $endsAt = new \DateTimeImmutable('2026-09-11T21:00:00-04:00');
        $event = new Event($user, 'Treinamento', $startsAt, $endsAt, $ministry, ContentVisibility::MINISTRY_MEMBERS);
        $schedule = new MinistrySchedule($user, 'Escala', $startsAt, $endsAt, $ministry, ContentVisibility::MINISTRY_MEMBERS);

        $entities = [
            'user' => $user,
            'other_user' => $otherUser,
            'ministry' => $ministry,
            'other_ministry' => $otherMinistry,
            'membership' => $membership,
            'other_membership' => $otherMembership,
            'post' => $post,
            'comment' => $comment,
            'event' => $event,
            'schedule' => $schedule,
        ];

        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $ids = [];
        foreach ($entities as $key => $entity) {
            self::assertNotNull($entity->getId());
            $ids[$key] = $entity->getId();
        }

        return $ids;
    }
}
