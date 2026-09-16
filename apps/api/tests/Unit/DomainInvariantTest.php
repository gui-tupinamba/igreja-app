<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Comment;
use App\Entity\Event;
use App\Entity\Ministry;
use App\Entity\MinistrySchedule;
use App\Entity\Post;
use App\Entity\User;
use App\Entity\UserMinistry;
use App\Enum\ContentVisibility;
use App\Enum\MembershipStatus;
use App\Enum\MinistryStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainInvariantTest extends TestCase
{
    public function testIdentityNormalizesEmailAndPreservesBirthdayWithoutTimezoneShift(): void
    {
        $user = new User('  Maria da Silva  ', ' Maria.Silva@Example.test ', self::passwordHash());
        $user->setBirthDate(new \DateTimeImmutable('2000-01-02T00:00:00+14:00'));

        self::assertSame('Maria da Silva', $user->getName());
        self::assertSame('maria.silva@example.test', $user->getEmailNormalized());
        self::assertSame(UserRole::MEMBER, $user->getRole());
        self::assertSame(UserStatus::ACTIVE, $user->getStatus());
        self::assertSame('2000-01-02', $user->getBirthDate()?->format('Y-m-d'));
        self::assertSame('UTC', $user->getCreatedAt()->getTimezone()->getName());
        self::assertTrue(password_verify('test-fixture-only', $user->getPasswordHash()));

        $user->setEmail(' Novo.Email@Example.test ');
        self::assertSame('novo.email@example.test', $user->getEmailNormalized());
    }

    #[DataProvider('invalidIdentities')]
    public function testInvalidIdentityInputIsRejected(string $field, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        match ($field) {
            'name' => new User($value, 'member@example.test', self::passwordHash()),
            'email' => new User('Maria', $value, self::passwordHash()),
            'password' => new User('Maria', 'member@example.test', $value),
            'slug' => new Ministry('Louvor', $value),
        };
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidIdentities(): iterable
    {
        yield 'blank name' => ['name', " \t\n "];
        yield 'name length counts Unicode characters' => ['name', str_repeat('ç', 121)];
        yield 'malformed UTF-8' => ['name', "invalid\xFF"];
        yield 'invalid email' => ['email', 'not-an-email'];
        yield 'email with newline' => ['email', "member\n@example.test"];
        yield 'plaintext password is not a hash' => ['password', 'do-not-store-plaintext'];
        yield 'unknown hash format' => ['password', '$unknown$hash'];
        yield 'uppercase slug' => ['slug', 'Louvor'];
        yield 'slug with path separator' => ['slug', '../louvor'];
        yield 'slug with consecutive separators' => ['slug', 'equipe--louvor'];
    }

    public function testInvalidEmailChangeLeavesThePreviousIdentityIntact(): void
    {
        $user = self::user();
        $email = $user->getEmail();
        $normalized = $user->getEmailNormalized();
        $updatedAt = $user->getUpdatedAt();

        try {
            $user->setEmail('invalid');
            self::fail('Invalid email must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame($email, $user->getEmail());
            self::assertSame($normalized, $user->getEmailNormalized());
            self::assertSame($updatedAt, $user->getUpdatedAt());
        }
    }

    public function testGlobalRoleAndParticipationDoNotAutomaticallyGrantMinistryLeadership(): void
    {
        $user = self::user();
        $user->setRole(UserRole::LEADER);
        $media = new UserMinistry($user, new Ministry('Mídia', 'midia'));
        $worship = new UserMinistry($user, new Ministry('Louvor', 'louvor'));

        self::assertFalse($media->isLeader());
        self::assertFalse($worship->isLeader());
        $media->promoteToLeader();
        self::assertTrue($media->isLeader());
        self::assertFalse($worship->isLeader());
        self::assertSame(MembershipStatus::ACTIVE, $worship->getStatus());
    }

    public function testReactivationDoesNotRestoreRevokedLeadership(): void
    {
        $user = self::user();
        $user->setRole(UserRole::LEADER);
        $membership = new UserMinistry($user, new Ministry('Louvor', 'louvor'));
        $membership->promoteToLeader();
        $joinedAt = $membership->getJoinedAt();
        $membership->deactivate();

        self::assertSame(MembershipStatus::INACTIVE, $membership->getStatus());
        self::assertFalse($membership->isLeader());
        self::assertNotNull($membership->getLeftAt());
        self::assertGreaterThanOrEqual($joinedAt, $membership->getLeftAt());
        $leftAt = $membership->getLeftAt();
        $membership->deactivate();
        self::assertSame($leftAt, $membership->getLeftAt());

        $membership->reactivate();
        self::assertSame(MembershipStatus::ACTIVE, $membership->getStatus());
        self::assertNull($membership->getLeftAt());
        self::assertFalse($membership->isLeader());
        self::assertGreaterThanOrEqual($leftAt, $membership->getJoinedAt());
    }

    #[DataProvider('ineligibleLeadership')]
    public function testLeadershipRequiresEligibleRoleAndActiveParticipation(string $reason): void
    {
        $user = self::user();
        $user->setRole(UserRole::LEADER);
        $ministry = new Ministry('Louvor', 'louvor');
        $membership = new UserMinistry($user, $ministry);

        match ($reason) {
            'member' => $user->setRole(UserRole::MEMBER),
            'inactive user' => $user->setStatus(UserStatus::INACTIVE),
            'blocked user' => $user->setStatus(UserStatus::BLOCKED),
            'inactive ministry' => $ministry->setStatus(MinistryStatus::INACTIVE),
            'inactive membership' => $membership->deactivate(),
        };

        try {
            $membership->promoteToLeader();
            self::fail('Ineligible participation must not acquire leadership.');
        } catch (\DomainException) {
            self::assertFalse($membership->isLeader());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function ineligibleLeadership(): iterable
    {
        foreach (['member', 'inactive user', 'blocked user', 'inactive ministry', 'inactive membership'] as $reason) {
            yield $reason => [$reason];
        }
    }

    #[DataProvider('contentTypes')]
    public function testPrivateContentCannotBeCreatedWithoutMinistry(string $class): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::content($class, null, ContentVisibility::MINISTRY_MEMBERS);
    }

    #[DataProvider('contentTypes')]
    public function testInvalidAudienceChangeKeepsExistingPrivateAudience(string $class): void
    {
        $ministry = new Ministry('Louvor', 'louvor');
        $content = self::content($class, $ministry, ContentVisibility::MINISTRY_MEMBERS);

        try {
            $content->changeAudience(null, ContentVisibility::MINISTRY_MEMBERS);
            self::fail('Private content requires a ministry.');
        } catch (\InvalidArgumentException) {
            self::assertSame($ministry, $content->getMinistry());
            self::assertSame(ContentVisibility::MINISTRY_MEMBERS, $content->getVisibility());
        }

        $content->changeAudience(null, ContentVisibility::PUBLIC);
        self::assertNull($content->getMinistry());
        self::assertSame(ContentVisibility::PUBLIC, $content->getVisibility());
        self::assertSame('DRAFT', $content->getStatus()->value);
    }

    /** @return iterable<string, array{class-string<Post|Event|MinistrySchedule>}> */
    public static function contentTypes(): iterable
    {
        yield 'post' => [Post::class];
        yield 'event' => [Event::class];
        yield 'schedule' => [MinistrySchedule::class];
    }

    #[DataProvider('activityTypes')]
    public function testActivitiesPreserveInstantsInUtcAndRejectReversedIntervals(string $class): void
    {
        $activity = new $class(
            self::user(),
            'Encontro',
            new \DateTimeImmutable('2026-09-11T19:00:00-04:00'),
            new \DateTimeImmutable('2026-09-12T00:00:00+00:00'),
        );

        self::assertSame('2026-09-11T23:00:00+00:00', $activity->getStartsAt()->format(DATE_ATOM));
        self::assertSame('2026-09-12T00:00:00+00:00', $activity->getEndsAt()?->format(DATE_ATOM));
        $startsAt = $activity->getStartsAt();
        $endsAt = $activity->getEndsAt();

        try {
            $activity->reschedule(new \DateTimeImmutable('2026-10-01T12:00:00Z'), new \DateTimeImmutable('2026-10-01T11:59:59Z'));
            self::fail('An activity cannot end before its start.');
        } catch (\InvalidArgumentException) {
            self::assertSame($startsAt, $activity->getStartsAt());
            self::assertSame($endsAt, $activity->getEndsAt());
        }

        $activity->reschedule($startsAt, $startsAt);
        self::assertEquals($activity->getStartsAt(), $activity->getEndsAt());
    }

    /** @return iterable<string, array{class-string<Event|MinistrySchedule>}> */
    public static function activityTypes(): iterable
    {
        yield 'event' => [Event::class];
        yield 'schedule' => [MinistrySchedule::class];
    }

    public function testPublishingRecordsAnInstantAndArchivingPreservesIt(): void
    {
        $post = new Post(self::user(), 'Comunicado', 'Informações para a igreja.');
        self::assertSame('DRAFT', $post->getStatus()->value);
        self::assertNull($post->getPublishedAt());

        $post->publish(new \DateTimeImmutable('2026-09-10T14:00:00-04:00'));
        self::assertSame('PUBLISHED', $post->getStatus()->value);
        self::assertSame('2026-09-10T18:00:00+00:00', $post->getPublishedAt()?->format(DATE_ATOM));
        $publishedAt = $post->getPublishedAt();
        $post->archive();
        self::assertSame('ARCHIVED', $post->getStatus()->value);
        self::assertSame($publishedAt, $post->getPublishedAt());
    }

    public function testCommentRejectsEmptyEditWithoutLosingItsContent(): void
    {
        $user = self::user();
        $comment = new Comment(new Post($user, 'Comunicado', 'Informações.'), $user, ' Confirmado. ');

        try {
            $comment->editContent(" \n\t ");
            self::fail('A comment cannot be empty.');
        } catch (\InvalidArgumentException) {
            self::assertSame('Confirmado.', $comment->getContent());
        }
    }

    /** @param class-string<Post|Event|MinistrySchedule> $class */
    private static function content(string $class, ?Ministry $ministry, ContentVisibility $visibility): Post|Event|MinistrySchedule
    {
        if ($class === Post::class) {
            return new Post(self::user(), 'Comunicado', 'Informações.', $ministry, $visibility);
        }

        return new $class(self::user(), 'Encontro', new \DateTimeImmutable('2026-09-11T19:00:00Z'), null, $ministry, $visibility);
    }

    private static function user(): User
    {
        return new User('Maria', 'maria@example.test', self::passwordHash());
    }

    private static function passwordHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash('test-fixture-only', PASSWORD_BCRYPT, ['cost' => 4]);
    }
}
