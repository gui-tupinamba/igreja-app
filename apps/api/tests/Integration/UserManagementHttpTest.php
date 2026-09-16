<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\SessionService;
use App\Enum\AuthClientType;
use App\Security\JwtService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/** HTTP acceptance against committed, individually owned PostgreSQL fixtures. */
final class UserManagementHttpTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?Connection $db = null;
    private array $users = [];
    private array $createdEmails = [];
    private array $ministries = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Requires isolated PostgreSQL.');
        }
        $this->client = self::createClient();
        $this->db = DriverManager::getConnection(self::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams());
        self::assertStringEndsWith('_test', (string) $this->db->fetchOne('SELECT current_database()'));
    }

    protected function tearDown(): void
    {
        if ($this->db?->isTransactionActive()) {
            $this->db->rollBack();
        }
        // Capture a successfully inserted account even if its HTTP assertion failed.
        foreach ($this->createdEmails as $email) {
            $id = $this->db->fetchOne('SELECT id FROM users WHERE email_normalized = ?', [strtolower(trim($email))]);
            if ($id !== false) { $this->users[] = (int) $id; }
        }
        $this->users = array_values(array_unique($this->users));
        foreach ($this->users as $id) {
            $this->db->executeStatement("DELETE FROM audit_logs WHERE actor_id = ? OR (entity_type = 'users' AND entity_id = ?)", [$id, $id]);
            $this->db->executeStatement('DELETE FROM refresh_tokens WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)', [$id]);
            $this->db->delete('auth_sessions', ['user_id' => $id]);
            $this->db->delete('user_ministries', ['user_id' => $id]);
        }
        foreach ($this->users as $id) { $this->db->delete('users', ['id' => $id]); }
        foreach ($this->ministries as $id) { $this->db->delete('ministries', ['id' => $id]); }
        $this->db?->close();
        parent::tearDown();
    }

    public function testAdministratorCreatesAUsableAccountWithSafeProfileAndDefaults(): void
    {
        $admin = $this->account('ADMIN');
        $email = $this->newEmail();
        $password = '  test-password-2026  ';
        $this->request('POST', '/api/admin/users', $admin, [
            'name' => 'Pessoa de teste', 'email' => $email, 'password' => $password,
            'phone' => '+55 92 99999-0000', 'birth_date' => '1991-02-28',
        ]);
        self::assertResponseStatusCodeSame(201);
        $user = $this->body()['user'];
        $this->users[] = $user['id'];
        $this->assertProfileFields($user);
        self::assertResponseHeaderSame('Location', '/api/admin/users/'.$user['id']);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSame('MEMBER', $user['role']);
        self::assertSame('ACTIVE', $user['status']);
        self::assertSame('1991-02-28', $user['birth_date']);
        self::assertSame('+55 92 99999-0000', $user['phone']);
        $hash = $this->db->fetchOne('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        self::assertNotSame($password, $hash);
        self::assertTrue(password_verify($password, $hash));
        $login = $this->login($email, $password);
        $this->request('GET', '/api/profile', $login);
        self::assertResponseIsSuccessful();
        self::assertSame($user['id'], $this->body()['user']['id']);
        $this->assertAuditContainsNoValues($user['id'], [$email, $password, $hash, '+55 92 99999-0000', '1991-02-28', 'Pessoa de teste']);

        $this->request('POST', '/api/admin/users', $admin, [
            'name' => 'Perfil mínimo', 'email' => $this->newEmail(), 'password' => 'another-test-password',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->body()['user']['phone']);
        self::assertNull($this->body()['user']['birth_date']);
    }

    public function testNewOperationsRequireAuthenticationAndCurrentAdministrativeRole(): void
    {
        $admin = $this->account('ADMIN');
        $member = $this->account('MEMBER');
        $leader = $this->account('LEADER');
        foreach ([
            ['POST', '/api/admin/users'], ['PATCH', '/api/admin/users/'.$member['id']],
            ['POST', '/api/admin/users/'.$member['id'].'/password'], ['GET', '/api/profile'],
            ['PATCH', '/api/profile'], ['POST', '/api/profile/password'],
        ] as [$method, $path]) {
            $this->request($method, $path, null, []);
            self::assertResponseStatusCodeSame(401);
        }
        foreach ([$member, $leader] as $actor) {
            $this->request('POST', '/api/admin/users', $actor, ['name' => 'Denied', 'email' => $this->newEmail(), 'password' => 'test-password-denied']);
            self::assertResponseStatusCodeSame(403);
            $this->request('PATCH', '/api/admin/users/'.$member['id'], $actor, ['name' => 'Denied']);
            self::assertResponseStatusCodeSame(403);
            $this->request('POST', '/api/admin/users/'.$member['id'].'/password', $actor, ['new_password' => 'test-password-denied']);
            self::assertResponseStatusCodeSame(403);
        }
        $this->db->update('users', ['role' => 'MEMBER'], ['id' => $admin['id']]);
        $this->request('POST', '/api/admin/users', $admin, ['name' => 'Old JWT', 'email' => $this->newEmail(), 'password' => 'test-password-denied']);
        self::assertResponseStatusCodeSame(403);
        $this->db->update('users', ['status' => 'BLOCKED'], ['id' => $admin['id']]);
        $this->request('GET', '/api/profile', $admin);
        self::assertResponseStatusCodeSame(401);
    }

    public function testPastorCreatesAndEditsMembersButNeverCreatesOrChangesAnAdministrator(): void
    {
        $admin = $this->account('ADMIN');
        $pastor = $this->account('PASTOR');
        $this->request('POST', '/api/admin/users', $pastor, [
            'name' => 'Pastor-created member', 'email' => $this->newEmail(), 'password' => 'test-password-created',
        ]);
        self::assertResponseStatusCodeSame(201);
        $memberId = $this->body()['user']['id'];
        $this->users[] = $memberId;
        $this->request('PATCH', '/api/admin/users/'.$memberId, $pastor, ['name' => 'Updated by pastor']);
        self::assertResponseIsSuccessful();
        self::assertSame('Updated by pastor', $this->body()['user']['name']);
        $this->request('POST', '/api/admin/users', $pastor, [
            'name' => 'Denied administrator', 'email' => $this->newEmail(), 'password' => 'test-password-denied', 'role' => 'ADMIN',
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->request('PATCH', '/api/admin/users/'.$admin['id'], $pastor, ['name' => 'Fixture person']);
        self::assertResponseStatusCodeSame(403); // The current value is still a forbidden administrative operation.
        $this->request('POST', '/api/admin/users/'.$admin['id'].'/password', $pastor, ['new_password' => 'test-password-denied']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('ADMIN', $this->db->fetchOne('SELECT role FROM users WHERE id = ?', [$admin['id']]));
    }

    public function testProfileReturnsOnlyOwnActiveMembershipsAndCurrentLeadership(): void
    {
        $admin = $this->account('ADMIN');
        $leader = $this->account('LEADER');
        $other = $this->account('MEMBER');
        $this->db->update('users', ['phone' => '+55 92 98888-0001', 'birth_date' => '1985-05-12'], ['id' => $leader['id']]);
        $ledId = $this->membership($leader['id'], true);
        $memberId = $this->membership($leader['id'], false);
        $this->membership($leader['id'], false, 'INACTIVE');
        $this->membership($leader['id'], true, 'ACTIVE', 'INACTIVE');
        $this->membership($other['id'], false);
        $this->request('GET', '/api/profile', $leader);
        self::assertResponseIsSuccessful();
        $profile = $this->body();
        $this->assertProfileFields($profile['user']);
        self::assertSame($leader['id'], $profile['user']['id']);
        self::assertEqualsCanonicalizing([$ledId, $memberId], array_column($profile['ministries'], 'id'));
        self::assertSame([$ledId], array_column($profile['led_ministries'], 'id'));
        foreach ($profile['ministries'] as $item) { self::assertEqualsCanonicalizing(['id', 'name'], array_keys($item)); }
        self::assertStringNotContainsString($other['email'], $this->client->getResponse()->getContent());
        $this->request('GET', '/api/admin/users/'.$leader['id'], $admin);
        self::assertResponseIsSuccessful();
        self::assertSame('+55 92 98888-0001', $this->body()['user']['phone']);
        $this->assertProfileFields($this->body()['user']);
        $this->request('GET', '/api/admin/users/'.$other['id'], $leader);
        self::assertResponseStatusCodeSame(403);
        $this->db->update('users', ['role' => 'MEMBER'], ['id' => $leader['id']]);
        $this->request('GET', '/api/profile', $leader);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->body()['led_ministries']);
        self::assertEqualsCanonicalizing([$ledId, $memberId], array_column($this->body()['ministries'], 'id'));
    }

    public function testMemberEditsOwnProfileWithoutRevokingSessionOrChangingAnotherAccount(): void
    {
        $member = $this->account('MEMBER');
        $other = $this->account('MEMBER');
        $this->request('PATCH', '/api/profile', $member, ['name' => 'Nome atualizado', 'phone' => '+55 92 99999-0002', 'birth_date' => '2000-02-29']);
        self::assertResponseIsSuccessful();
        self::assertSame('Nome atualizado', $this->body()['user']['name']);
        self::assertSame($member['email'], $this->body()['user']['email']);
        self::assertSame('2000-02-29', $this->body()['user']['birth_date']);
        self::assertSame('Fixture person', $this->db->fetchOne('SELECT name FROM users WHERE id = ?', [$other['id']]));
        self::assertNull($this->db->fetchOne('SELECT revoked_at FROM auth_sessions WHERE id = ?', [$member['session']]));
        $this->request('PATCH', '/api/profile', $member, ['phone' => null, 'birth_date' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->body()['user']['phone']);
        self::assertNull($this->body()['user']['birth_date']);
        $this->request('GET', '/api/profile', $member);
        self::assertResponseIsSuccessful();
        self::assertSame('Nome atualizado', $this->body()['user']['name']);
    }

    public function testProfileAndAdministrativeEditRejectMassAssignmentAtomically(): void
    {
        $admin = $this->account('ADMIN');
        $member = $this->account('MEMBER');
        foreach (['id' => $admin['id'], 'user_id' => $admin['id'], 'email' => $admin['email'], 'role' => 'ADMIN', 'status' => 'BLOCKED', 'password' => 'test-password-injected', 'password_hash' => 'injected', 'ministries' => [1]] as $field => $value) {
            $this->request('PATCH', '/api/profile', $member, ['name' => 'Must not persist', $field => $value]);
            self::assertResponseStatusCodeSame(422);
        }
        foreach (['id' => $admin['id'], 'role' => 'ADMIN', 'status' => 'BLOCKED', 'password' => 'test-password-injected'] as $field => $value) {
            $this->request('PATCH', '/api/admin/users/'.$member['id'], $admin, ['name' => 'Must not persist', $field => $value]);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame('Fixture person', $this->db->fetchOne('SELECT name FROM users WHERE id = ?', [$member['id']]));
        self::assertSame('MEMBER', $this->db->fetchOne('SELECT role FROM users WHERE id = ?', [$member['id']]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM audit_logs WHERE entity_id = ?', [$member['id']]));
    }

    #[DataProvider('invalidRegistrationFields')]
    public function testRegistrationValidationReportsSafeFieldsWithoutWritingAnAccount(array $invalid): void
    {
        $admin = $this->account('ADMIN');
        $email = $this->newEmail();
        $body = array_replace(['name' => 'Safe fixture', 'email' => $email, 'password' => 'sensitive-test-password'], $invalid);
        $this->request('POST', '/api/admin/users', $admin, $body);
        self::assertResponseStatusCodeSame(422);
        self::assertNotEmpty($this->body()['error']['fields']);
        self::assertStringNotContainsString('sensitive-test-password', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('SQLSTATE', $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM users WHERE email_normalized = ?', [$email]));
    }

    public static function invalidRegistrationFields(): iterable
    {
        yield 'required name cannot be null' => [['name' => null]];
        yield 'phone is never an object' => [['phone' => ['number' => 'sensitive-input']]];
        yield 'password minimum is twelve bytes' => [['password' => 'elevenbytes']];
        yield 'birth date cannot roll into another month' => [['birth_date' => '2001-02-29']];
        yield 'birth date cannot be in the future' => [['birth_date' => '2999-01-01']];
        yield 'role cannot be null' => [['role' => null]];
    }

    public function testNormalizedDuplicateCreationAndEmailEditReturnConflictWithoutPartialChanges(): void
    {
        $admin = $this->account('ADMIN');
        $first = $this->account('MEMBER');
        $second = $this->account('MEMBER');
        $this->request('POST', '/api/admin/users', $admin, ['name' => 'Duplicate', 'email' => '  '.strtoupper($first['email']).'  ', 'password' => 'test-password-duplicate']);
        self::assertResponseStatusCodeSame(409);
        $this->request('PATCH', '/api/admin/users/'.$second['id'], $admin, ['name' => 'Must not persist', 'email' => strtoupper($first['email'])]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($second['email'], $this->db->fetchOne('SELECT email FROM users WHERE id = ?', [$second['id']]));
        self::assertSame('Fixture person', $this->db->fetchOne('SELECT name FROM users WHERE id = ?', [$second['id']]));
        $this->request('GET', '/api/profile', $second);
        self::assertResponseIsSuccessful();
    }

    public function testAdministrativeEmailChangeRevokesExistingCredentialsAndAuditsWithoutPersonalValues(): void
    {
        $admin = $this->account('ADMIN');
        $member = $this->account('MEMBER');
        $newEmail = $this->newEmail();
        $this->request('PATCH', '/api/admin/users/'.$member['id'], $admin, ['email' => $newEmail, 'phone' => '+55 92 98888-1234']);
        self::assertResponseIsSuccessful();
        self::assertSame($newEmail, $this->body()['user']['email']);
        $this->assertCredentialsRevoked($member);
        $fresh = $this->login($newEmail, $member['password']);
        $this->request('GET', '/api/profile', $fresh);
        self::assertResponseIsSuccessful();
        $this->assertAuditContainsNoValues($member['id'], [$member['email'], $newEmail, $member['password'], '+55 92 98888-1234']);
        try {
            self::getContainer()->get(SessionService::class)->login($member['email'], $member['password'], AuthClientType::MOBILE);
            self::fail('The former email must not authenticate.');
        } catch (UnauthorizedHttpException) {
            self::assertTrue(true);
        }
    }

    public function testOwnPasswordChangeRequiresCurrentPasswordAndRevokesEverySession(): void
    {
        $member = $this->account('MEMBER');
        $otherSession = $this->login($member['email'], $member['password']);
        $replacement = '  replacement-password-2026  ';
        $this->request('POST', '/api/profile/password', $member, ['current_password' => $member['password'], 'new_password' => $member['password']]);
        self::assertResponseStatusCodeSame(422);
        $this->request('POST', '/api/profile/password', $member, ['current_password' => 'incorrect-password', 'new_password' => $replacement]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->db->fetchOne('SELECT revoked_at FROM auth_sessions WHERE id = ?', [$member['session']]));
        $this->request('POST', '/api/profile/password', $member, ['current_password' => $member['password'], 'new_password' => $replacement]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        $this->assertCredentialsRevoked($member);
        $this->assertCredentialsRevoked($otherSession);
        $fresh = $this->login($member['email'], $replacement);
        $this->request('GET', '/api/profile', $fresh);
        self::assertResponseIsSuccessful();
        $this->assertAuditContainsNoValues($member['id'], [$member['email'], $member['password'], $replacement]);
    }

    public function testWrongCurrentPasswordAttemptsAreLimitedWithoutChangingCredentials(): void
    {
        $member = $this->account('MEMBER');
        $beforeHash = $this->db->fetchOne('SELECT password_hash FROM users WHERE id = ?', [$member['id']]);
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->request('POST', '/api/profile/password', $member, ['current_password' => 'wrong-test-password', 'new_password' => 'replacement-test-password']);
            self::assertResponseStatusCodeSame(403);
        }
        $this->request('POST', '/api/profile/password', $member, ['current_password' => $member['password'], 'new_password' => 'replacement-test-password']);
        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame($beforeHash, $this->db->fetchOne('SELECT password_hash FROM users WHERE id = ?', [$member['id']]));
        self::assertNull($this->db->fetchOne('SELECT revoked_at FROM auth_sessions WHERE id = ?', [$member['session']]));
    }

    public function testPastorPasswordResetRevokesAllMemberSessionsWithoutChangingAccessRole(): void
    {
        $pastor = $this->account('PASTOR');
        $member = $this->account('MEMBER');
        $otherSession = $this->login($member['email'], $member['password']);
        $this->request('POST', '/api/admin/users/'.$member['id'].'/password', $pastor, ['new_password' => 'reset-test-password-2026']);
        self::assertResponseStatusCodeSame(204);
        $this->assertCredentialsRevoked($member);
        $this->assertCredentialsRevoked($otherSession);
        $fresh = $this->login($member['email'], 'reset-test-password-2026');
        $this->request('GET', '/api/profile', $fresh);
        self::assertResponseIsSuccessful();
        self::assertSame('MEMBER', $this->body()['user']['role']);
        self::assertSame('ACTIVE', $this->body()['user']['status']);
        $this->assertAuditContainsNoValues($member['id'], [$member['email'], $member['password'], 'reset-test-password-2026']);
    }

    public function testFailedAuditRollsBackEmailChangeAndSessionRevocation(): void
    {
        $admin = $this->account('ADMIN');
        $member = $this->account('MEMBER');
        $this->db->executeStatement('ALTER TABLE audit_logs ADD CONSTRAINT test_reject_profile_audit CHECK (false) NOT VALID');
        try {
            $this->request('PATCH', '/api/admin/users/'.$member['id'], $admin, ['email' => $this->newEmail()]);
            self::assertResponseStatusCodeSame(500);
            self::assertSame($member['email'], $this->db->fetchOne('SELECT email FROM users WHERE id = ?', [$member['id']]));
            self::assertNull($this->db->fetchOne('SELECT revoked_at FROM auth_sessions WHERE id = ?', [$member['session']]));
            $this->request('GET', '/api/profile', $member);
            self::assertResponseIsSuccessful();
        } finally {
            $this->db->executeStatement('ALTER TABLE audit_logs DROP CONSTRAINT test_reject_profile_audit');
        }
    }

    public function testWebPreflightAllowsNewProfileAndAdministrativePatchRoutesOnlyForAllowedOrigin(): void
    {
        foreach (['/api/profile', '/api/admin/users/1'] as $path) {
            $this->client->request('OPTIONS', 'https://localhost'.$path, server: [
                'HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
            ]);
            self::assertResponseStatusCodeSame(204);
            self::assertContains('PATCH', array_map('trim', explode(',', $this->client->getResponse()->headers->get('Access-Control-Allow-Methods', ''))));
            self::assertResponseHeaderSame('Access-Control-Allow-Origin', 'http://localhost:5173');
        }
        $this->client->request('OPTIONS', 'https://localhost/api/profile', server: [
            'HTTP_ORIGIN' => 'https://untrusted.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->client->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }

    public function testExplicitInactiveCreationDoesNotPermitLoginAndPreservesRequestedRole(): void
    {
        $admin = $this->account('ADMIN');
        $email = $this->newEmail();
        $this->request('POST', '/api/admin/users', $admin, [
            'name' => 'Inactive fixture', 'email' => $email, 'password' => 'inactive-test-password', 'role' => 'LEADER', 'status' => 'INACTIVE',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('LEADER', $this->body()['user']['role']);
        self::assertSame('INACTIVE', $this->body()['user']['status']);
        $this->expectException(UnauthorizedHttpException::class);
        self::getContainer()->get(SessionService::class)->login($email, 'inactive-test-password', AuthClientType::MOBILE);
    }

    private function account(string $role): array
    {
        $email = $this->newEmail();
        $password = 'test-only-'.bin2hex(random_bytes(8));
        $id = (int) $this->db->fetchOne("INSERT INTO users (name,email,email_normalized,password_hash,role,status,created_at,updated_at) VALUES ('Fixture person',?,?,?,?,'ACTIVE',date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp())) RETURNING id", [$email, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), $role]);
        $this->users[] = $id;

        return $this->login($email, $password);
    }

    private function login(string $email, string $password): array
    {
        $session = self::getContainer()->get(SessionService::class)->login($email, $password, AuthClientType::MOBILE);
        $id = $session->user->getId();

        return ['id' => $id, 'email' => $email, 'password' => $password, 'session' => $session->sessionId, 'refresh' => $session->refreshToken,
            'token' => self::getContainer()->get(JwtService::class)->issue((string) $id, $session->sessionId)];
    }

    private function newEmail(): string
    {
        $email = 'user-profile-'.bin2hex(random_bytes(8)).'@example.test';
        $this->createdEmails[] = $email;

        return $email;
    }

    private function membership(int $userId, bool $leader, string $membershipStatus = 'ACTIVE', string $ministryStatus = 'ACTIVE'): int
    {
        $slug = 'profile-ministry-'.bin2hex(random_bytes(8));
        $id = (int) $this->db->fetchOne("INSERT INTO ministries (name,slug,status,created_at,updated_at) VALUES (?,?,?,date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp())) RETURNING id", ['Fixture ministry '.$slug, $slug, $ministryStatus]);
        $this->ministries[] = $id;
        $this->db->executeStatement("INSERT INTO user_ministries (user_id,ministry_id,is_leader,status,joined_at,created_at,updated_at) VALUES (?,?,?,?,date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp()))", [$userId, $id, $leader ? 'true' : 'false', $membershipStatus]);

        return $id;
    }

    private function request(string $method, string $path, ?array $actor, ?array $body = null): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($actor !== null) { $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$actor['token']; }
        $this->client->request($method, 'https://localhost'.$path, server: $headers, content: $body === null ? null : json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    private function body(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertProfileFields(array $user): void
    {
        self::assertEqualsCanonicalizing(['id', 'name', 'email', 'phone', 'birth_date', 'role', 'status', 'created_at', 'updated_at'], array_keys($user));
        self::assertIsInt($user['id']);
        self::assertNotFalse(strtotime($user['created_at']));
        self::assertNotFalse(strtotime($user['updated_at']));
    }

    private function assertCredentialsRevoked(array $account): void
    {
        $this->request('GET', '/api/profile', $account);
        self::assertResponseStatusCodeSame(401);
        self::assertNotNull($this->db->fetchOne('SELECT revoked_at FROM auth_sessions WHERE id = ?', [$account['session']]));
        try {
            self::getContainer()->get(SessionService::class)->refresh($account['refresh'], AuthClientType::MOBILE);
            self::fail('The former refresh credential must not create a new session token.');
        } catch (UnauthorizedHttpException) {
            self::assertTrue(true);
        }
    }

    private function assertAuditContainsNoValues(int $userId, array $privateValues): void
    {
        $rows = $this->db->fetchAllAssociative("SELECT action,metadata FROM audit_logs WHERE entity_type = 'users' AND entity_id = ?", [$userId]);
        self::assertNotEmpty($rows);
        $json = json_encode($rows, JSON_THROW_ON_ERROR);
        foreach ($privateValues as $value) { self::assertStringNotContainsString($value, $json); }
        foreach ($rows as $row) {
            self::assertIsArray(json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR));
        }
    }
}
