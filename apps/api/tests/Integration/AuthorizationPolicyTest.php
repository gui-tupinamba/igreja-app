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
use App\Security\Authorization\AccessPolicy;
use App\Security\Voter\CommentVoter;
use App\Security\Voter\EventVoter;
use App\Security\Voter\MinistryVoter;
use App\Security\Voter\PostVoter;
use App\Security\Voter\ScheduleVoter;
use App\Security\Voter\UserVoter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class AuthorizationPolicyTest extends KernelTestCase
{
    private ?Connection $connection = null;
    private ?EntityManagerInterface $em = null;
    private AccessPolicy $policy;
    /** @var array<string, User> */
    private array $users = [];
    /** @var array<int, Ministry> */
    private array $ministries = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Requires isolated PostgreSQL ending in _test.');
        }
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        self::assertInstanceOf(PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
        if (!str_ends_with((string) $this->connection->fetchOne('SELECT current_database()'), '_test')) {
            self::fail('Refusing authorization fixtures outside an isolated _test database.');
        }
        $this->connection->beginTransaction();
        $this->policy = new AccessPolicy($this->connection);
        $suffix = bin2hex(random_bytes(8));
        $hash = password_hash('isolated-policy-fixture', PASSWORD_BCRYPT, ['cost' => 4]);
        foreach (['admin' => UserRole::ADMIN, 'pastor' => UserRole::PASTOR, 'leader' => UserRole::LEADER, 'member' => UserRole::MEMBER, 'outsider' => UserRole::LEADER] as $name => $role) {
            $user = new User($name, "$name.$suffix@example.test", $hash);
            $user->setRole($role);
            $this->em->persist($user);
            $this->users[$name] = $user;
        }
        for ($i = 1; $i <= 3; ++$i) {
            $ministry = new Ministry("Ministry $i", "ministry-$i-$suffix");
            $this->em->persist($ministry);
            $this->ministries[$i] = $ministry;
        }
        foreach ([['member', 1, false], ['leader', 1, true], ['leader', 2, false], ['outsider', 3, true]] as [$user, $ministry, $leader]) {
            $membership = new UserMinistry($this->users[$user], $this->ministries[$ministry]);
            if ($leader) {
                $membership->promoteToLeader();
            }
            $this->em->persist($membership);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->connection?->isTransactionActive()) {
                $this->connection->rollBack();
            }
        } finally {
            $this->em?->clear();
            $this->em = null;
            $this->connection = null;
            parent::tearDown();
        }
    }

    public function testPublicAndPrivateReadMatrixIsIdenticalAcrossContentTypes(): void
    {
        foreach (['posts', 'events', 'ministry_schedules'] as $table) {
            $public = $this->content($table, 3, ContentVisibility::PUBLIC);
            $private = $this->content($table, 1, ContentVisibility::MINISTRY_MEMBERS);
            $foreign = $this->content($table, 3, ContentVisibility::MINISTRY_MEMBERS);
            $general = $this->content($table, null, ContentVisibility::PUBLIC);
            foreach (array_keys($this->users) as $name) {
                self::assertTrue($this->read($table, $name, $public->getId()));
                self::assertTrue($this->read($table, $name, $general->getId()));
                self::assertSame($name !== 'outsider', $this->read($table, $name, $private->getId()));
                self::assertSame(in_array($name, ['admin', 'pastor', 'outsider'], true), $this->read($table, $name, $foreign->getId()));
            }
            self::assertFalse($this->read($table, 'member', -1));
        }
        self::assertFalse($this->policy->canReadPost(-1, $this->content('posts', null, ContentVisibility::PUBLIC)->getId()));
    }

    public function testContentStatesAndInactiveMinistryStayOutsideRegularReadEvenForAdmins(): void
    {
        foreach (['posts', 'events', 'ministry_schedules'] as $table) {
            $content = $this->content($table, 1, ContentVisibility::PUBLIC);
            foreach (['DRAFT', 'ARCHIVED'] as $state) {
                $this->connection->executeStatement("UPDATE $table SET status = ? WHERE id = ?", [$state, $content->getId()]);
                foreach (['admin', 'member', 'leader'] as $name) {
                    self::assertFalse($this->read($table, $name, $content->getId()));
                }
            }
            $this->connection->executeStatement("UPDATE $table SET status = 'PUBLISHED' WHERE id = ?", [$content->getId()]);
            if ($table === 'posts') {
                $this->connection->executeStatement("UPDATE posts SET published_at = CURRENT_TIMESTAMP + INTERVAL '1 day' WHERE id = ?", [$content->getId()]);
                self::assertFalse($this->read($table, 'admin', $content->getId()));
                $this->connection->executeStatement("UPDATE posts SET published_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = ?", [$content->getId()]);
            } else {
                $this->connection->executeStatement("UPDATE $table SET status = 'CANCELLED' WHERE id = ?", [$content->getId()]);
                self::assertTrue($this->read($table, 'member', $content->getId()));
            }
            $this->connection->executeStatement("UPDATE ministries SET status = 'INACTIVE' WHERE id = ?", [$this->ministries[1]->getId()]);
            self::assertFalse($this->read($table, 'admin', $content->getId()));
            self::assertFalse($this->read($table, 'member', $content->getId()));
            self::assertTrue($this->policy->canManageContent($this->id('admin'), $this->ministries[1]->getId()));
            self::assertFalse($this->policy->canManageContent($this->id('leader'), $this->ministries[1]->getId()));
            $this->connection->executeStatement("UPDATE ministries SET status = 'ACTIVE' WHERE id = ?", [$this->ministries[1]->getId()]);
        }
    }

    public function testLeadershipIsSpecificAndCannotComeFromMemberRoleOrAuthTokenRoles(): void
    {
        foreach (['admin', 'pastor'] as $name) {
            self::assertTrue($this->policy->canManageContent($this->id($name), null));
            foreach ($this->ministries as $ministry) {
                self::assertTrue($this->policy->canManageContent($this->id($name), $ministry->getId()));
            }
        }
        self::assertTrue($this->policy->canManageContent($this->id('leader'), $this->ministries[1]->getId()));
        foreach ([null, $this->ministries[2]->getId(), $this->ministries[3]->getId(), -1] as $ministryId) {
            self::assertFalse($this->policy->canManageContent($this->id('leader'), $ministryId));
        }
        self::assertFalse($this->policy->canManageContent($this->id('admin'), -1));
        $this->connection->executeStatement('UPDATE user_ministries SET is_leader = TRUE WHERE user_id = ?', [$this->id('member')]);
        self::assertFalse($this->policy->canManageContent($this->id('member'), $this->ministries[1]->getId()));
        $post = $this->content('posts', 1, ContentVisibility::PUBLIC);
        $token = new UsernamePasswordToken($this->users['leader'], 'api', ['ROLE_ADMIN']);
        $voter = new PostVoter($this->policy);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $post, [PostVoter::POST_MANAGE]));
        $this->connection->executeStatement("UPDATE users SET role = 'MEMBER' WHERE id = ?", [$this->id('leader')]);
        self::assertSame(UserRole::LEADER, $this->users['leader']->getRole(), 'Managed actor intentionally remains stale.');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $post, [PostVoter::POST_MANAGE]));
    }

    public function testMembershipRevocationImmediatelyRemovesPrivateAccessAndLeadershipRevocationOnlyRemovesManagement(): void
    {
        $post = $this->content('posts', 1, ContentVisibility::MINISTRY_MEMBERS);
        self::assertTrue($this->policy->canReadPost($this->id('leader'), $post->getId()));
        $this->connection->executeStatement('UPDATE user_ministries SET is_leader = FALSE WHERE user_id = ?', [$this->id('leader')]);
        self::assertTrue($this->policy->canReadPost($this->id('leader'), $post->getId()));
        self::assertFalse($this->policy->canManagePost($this->id('leader'), $post->getId()));
        $this->connection->executeStatement("UPDATE user_ministries SET status = 'INACTIVE', left_at = GREATEST(joined_at, CURRENT_TIMESTAMP) WHERE user_id = ?", [$this->id('leader')]);
        self::assertFalse($this->policy->canReadPost($this->id('leader'), $post->getId()));
        $this->connection->executeStatement("UPDATE user_ministries SET status = 'ACTIVE', left_at = NULL WHERE user_id = ?", [$this->id('leader')]);
        self::assertTrue($this->policy->canReadPost($this->id('leader'), $post->getId()));
        self::assertFalse($this->policy->canManagePost($this->id('leader'), $post->getId()));
    }

    public function testInactiveOrBlockedActorsCannotReadOrAdminister(): void
    {
        $post = $this->content('posts', null, ContentVisibility::PUBLIC);
        foreach (['pastor', 'leader', 'member'] as $name) {
            foreach (['INACTIVE', 'BLOCKED'] as $status) {
                $this->connection->executeStatement('UPDATE users SET status = ? WHERE id = ?', [$status, $this->id($name)]);
                self::assertFalse($this->policy->canReadPost($this->id($name), $post->getId()));
                self::assertFalse($this->policy->canManageUsers($this->id($name)));
                self::assertFalse($this->policy->canManageContent($this->id($name), $this->ministries[1]->getId()));
                self::assertFalse($this->policy->canReadMinistry($this->id($name), $this->ministries[1]->getId()));
            }
        }
    }

    public function testPastorCannotManageAdminOrProposeAdminAndSettingsAreExclusive(): void
    {
        self::assertTrue($this->policy->canManageUsers($this->id('admin'), $this->id('admin'), UserRole::ADMIN));
        self::assertFalse($this->policy->canManageUsers($this->id('pastor'), $this->id('admin')));
        self::assertFalse($this->policy->canManageUsers($this->id('pastor'), null, UserRole::ADMIN));
        self::assertFalse($this->policy->canManageUsers($this->id('pastor'), $this->id('member'), UserRole::ADMIN));
        self::assertTrue($this->policy->canManageUsers($this->id('pastor'), $this->id('member'), UserRole::PASTOR));
        self::assertFalse($this->policy->canManageUsers($this->id('admin'), -1));
        foreach (array_keys($this->users) as $name) {
            self::assertSame($name === 'admin', $this->policy->canManageCriticalSettings($this->id($name)));
            self::assertSame(in_array($name, ['admin', 'pastor'], true), $this->policy->canManageMinistries($this->id($name)));
        }
        self::assertFalse($this->policy->canManageUsers(-1));
    }

    public function testCommentsInheritCurrentPostAccessAndEnforceOwnVisibleEditingAndGlobalModeration(): void
    {
        $post = $this->content('posts', 1, ContentVisibility::MINISTRY_MEMBERS);
        $comment = new Comment($post, $this->users['member'], 'Resposta');
        $this->em->persist($comment);
        $this->em->flush();
        self::assertTrue($this->policy->canCommentOnPost($this->id('member'), $post->getId()));
        self::assertFalse($this->policy->canCommentOnPost($this->id('outsider'), $post->getId()));
        self::assertTrue($this->policy->canEditComment($this->id('member'), $comment->getId()));
        self::assertFalse($this->policy->canEditComment($this->id('admin'), $comment->getId()));
        self::assertFalse($this->policy->canReadComment($this->id('outsider'), $comment->getId()));
        self::assertFalse($this->policy->canModerateComment($this->id('leader'), $comment->getId()));
        self::assertTrue($this->policy->canModerateComment($this->id('pastor'), $comment->getId()));
        $this->connection->executeStatement('UPDATE posts SET comments_enabled = FALSE WHERE id = ?', [$post->getId()]);
        self::assertFalse($this->policy->canCommentOnPost($this->id('member'), $post->getId()));
        self::assertTrue($this->policy->canEditComment($this->id('member'), $comment->getId()));
        $this->connection->executeStatement("UPDATE user_ministries SET status = 'INACTIVE', left_at = GREATEST(joined_at, CURRENT_TIMESTAMP) WHERE user_id = ?", [$this->id('member')]);
        self::assertFalse($this->policy->canEditComment($this->id('member'), $comment->getId()));
        self::assertFalse($this->policy->canReadComment($this->id('member'), $comment->getId()));
        $this->connection->executeStatement("UPDATE comments SET status = 'HIDDEN' WHERE id = ?", [$comment->getId()]);
        self::assertFalse($this->policy->canReadComment($this->id('admin'), $comment->getId()));
        self::assertTrue($this->policy->canModerateComment($this->id('admin'), $comment->getId()));
        $this->connection->executeStatement("UPDATE posts SET status = 'ARCHIVED' WHERE id = ?", [$post->getId()]);
        self::assertTrue($this->policy->canModerateComment($this->id('admin'), $comment->getId()));
        $this->connection->executeStatement("UPDATE comments SET status = 'DELETED' WHERE id = ?", [$comment->getId()]);
        self::assertFalse($this->policy->canModerateComment($this->id('admin'), $comment->getId()));
    }

    public function testAllVotersUseFreshResourceScopeAndDoNotTrustUnsavedOrUnrelatedSubjects(): void
    {
        $leader = new UsernamePasswordToken($this->users['leader'], 'api', ['ROLE_USER', 'ROLE_LEADER']);
        $pastor = new UsernamePasswordToken($this->users['pastor'], 'api', ['ROLE_USER', 'ROLE_PASTOR']);
        $member = new UsernamePasswordToken($this->users['member'], 'api', ['ROLE_USER']);
        foreach ([['posts', PostVoter::class, 'POST'], ['events', EventVoter::class, 'EVENT'], ['ministry_schedules', ScheduleVoter::class, 'SCHEDULE']] as [$table, $class, $prefix]) {
            $content = $this->content($table, 1, ContentVisibility::MINISTRY_MEMBERS);
            $voter = new $class($this->policy);
            self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($leader, $content, [$prefix.'_MANAGE']));
            self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($member, $content, [$prefix.'_VIEW']));
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($member, $content, [$prefix.'_CREATE']));
            $this->connection->executeStatement("UPDATE $table SET ministry_id = ? WHERE id = ?", [$this->ministries[3]->getId(), $content->getId()]);
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($leader, $content, [$prefix.'_MANAGE']), 'The object still points to ministry 1; DB moved it to ministry 3.');
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($member, $content, [$prefix.'_VIEW']));
            self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($leader, $this->users['admin'], [$prefix.'_MANAGE']));
        }
        $userVoter = new UserVoter($this->policy);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $userVoter->vote($pastor, User::class, [UserVoter::USER_CREATE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $userVoter->vote($pastor, $this->users['admin'], [UserVoter::USER_MANAGE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $userVoter->vote($member, User::class, [UserVoter::USER_LIST]));
        $ministryVoter = new MinistryVoter($this->policy);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $ministryVoter->vote($leader, $this->ministries[1], [MinistryVoter::MINISTRY_MANAGE_CONTENT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $ministryVoter->vote($leader, $this->ministries[1], [MinistryVoter::MINISTRY_MANAGE]));
        $post = $this->content('posts', 1, ContentVisibility::PUBLIC);
        $commentVoter = new CommentVoter($this->policy);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $commentVoter->vote($member, $post, [CommentVoter::COMMENT_CREATE]));
        $comment = new Comment($post, $this->users['member'], 'Ainda não persistido');
        self::assertSame(VoterInterface::ACCESS_DENIED, $commentVoter->vote($member, $comment, [CommentVoter::COMMENT_EDIT]));
        $unsavedUser = new User('Sem ID', 'unsaved@example.test', $this->users['member']->getPasswordHash());
        $unknownActor = new UsernamePasswordToken($unsavedUser, 'api', ['ROLE_ADMIN']);
        self::assertSame(VoterInterface::ACCESS_DENIED, $userVoter->vote($unknownActor, User::class, [UserVoter::USER_CREATE]));
    }

    private function id(string $name): int
    {
        return $this->users[$name]->getId();
    }

    private function content(string $table, ?int $ministry, ContentVisibility $visibility): Post|Event|MinistrySchedule
    {
        $context = $ministry === null ? null : $this->ministries[$ministry];
        $starts = new \DateTimeImmutable('+1 day');
        $content = match ($table) {
            'posts' => new Post($this->users['admin'], 'Publicação', 'Texto autorizado.', $context, $visibility),
            'events' => new Event($this->users['admin'], 'Evento', $starts, null, $context, $visibility),
            'ministry_schedules' => new MinistrySchedule($this->users['admin'], 'Agenda', $starts, null, $context, $visibility),
        };
        if ($content instanceof Post) {
            $content->publish(new \DateTimeImmutable('-1 day'));
        } else {
            $content->publish();
        }
        $this->em->persist($content);
        $this->em->flush();

        return $content;
    }

    private function read(string $table, string $name, int $id): bool
    {
        return match ($table) {
            'posts' => $this->policy->canReadPost($this->id($name), $id),
            'events' => $this->policy->canReadEvent($this->id($name), $id),
            'ministry_schedules' => $this->policy->canReadSchedule($this->id($name), $id),
        };
    }
}
