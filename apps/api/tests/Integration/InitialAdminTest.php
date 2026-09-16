<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\InitialAdminCreator;
use App\Auth\Exception\AdminCreationException;
use App\Command\CreateAdminCommand;
use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final class InitialAdminTest extends KernelTestCase
{
    private ?Connection $connection = null;
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Requires isolated PostgreSQL integration tests.');
        }
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertStringEndsWith('_test', (string) $this->connection->fetchOne('SELECT current_database()'));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM users WHERE role='ADMIN'"));
    }

    protected function tearDown(): void
    {
        foreach ($this->ids as $id) {
            $this->connection?->delete('users', ['id' => $id]);
        }
        $this->ids = [];
        $this->connection = null;
        parent::tearDown();
    }

    public function testFirstAdminUsesHasherAndSecondCreationIsRefusedEvenWhenInactive(): void
    {
        $creator = self::getContainer()->get(InitialAdminCreator::class);
        $password = 'test-only-'.bin2hex(random_bytes(10));
        $user = $creator->create('Administrador de teste', 'bootstrap-'.bin2hex(random_bytes(8)).'@example.test', $password);
        $this->ids[] = $user->getId();
        self::assertSame(UserRole::ADMIN, $user->getRole());
        $hasher = self::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(User::class);
        self::assertTrue($hasher->verify($user->getPasswordHash(), $password));
        self::assertNotSame($password, $user->getPasswordHash());
        $this->connection->update('users', ['status' => 'INACTIVE'], ['id' => $user->getId()]);
        $this->expectException(\DomainException::class);
        $creator->create('Segundo', 'second-'.bin2hex(random_bytes(8)).'@example.test', $password);
    }

    #[DataProvider('invalidPasswords')]
    public function testPasswordPolicyRejectsBeforeWriting(string $password): void
    {
        $this->expectException(AdminCreationException::class);
        self::getContainer()->get(InitialAdminCreator::class)->create('Teste', 'bootstrap@example.test', $password);
    }

    public static function invalidPasswords(): iterable
    {
        yield ['short'];
        yield [str_repeat('a', 73)];
        yield [str_repeat(' ', 20)];
        yield ["long-enough\0password"];
    }

    public function testCommandRefusesNonInteractiveAndHasNoPasswordArgument(): void
    {
        $command = self::getContainer()->get(CreateAdminCommand::class);
        self::assertFalse($command->getDefinition()->hasOption('password'));
        self::assertFalse($command->getDefinition()->hasArgument('password'));
        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => false]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM users WHERE role='ADMIN'"));
    }

    public function testInteractiveCommandCreatesAdminWithoutDisplayingPassword(): void
    {
        $email = 'command-'.bin2hex(random_bytes(8)).'@example.test';
        $password = 'test-only-'.bin2hex(random_bytes(10));
        $tester = new CommandTester(self::getContainer()->get(CreateAdminCommand::class));
        $tester->setInputs(['Administrador de teste', $email, $password, $password]);
        try {
            $status = $tester->execute([], ['interactive' => true]);
            self::assertSame(Command::SUCCESS, $status);
            self::assertStringNotContainsString($password, $tester->getDisplay());
            self::assertSame('ADMIN', $this->connection->fetchOne('SELECT role FROM users WHERE email_normalized = ?', [$email]));
        } finally {
            $id = $this->connection->fetchOne('SELECT id FROM users WHERE email_normalized = ?', [$email]);
            if ($id !== false) {
                $this->ids[] = (int) $id;
            }
        }
    }

    public function testInvalidFieldsAreExplainedAndRetriedWithoutTrimmingOrDisplayingPassword(): void
    {
        $email = 'retry-'.bin2hex(random_bytes(8)).'@example.test';
        $password = '  '.bin2hex(random_bytes(10)).'  ';
        $tooShort = 'curta#123';
        $tester = new CommandTester(self::getContainer()->get(CreateAdminCommand::class));
        $tester->setInputs(['   ', 'Administrador', 'email-invalido', $email, $tooShort, $password, $password]);
        try {
            self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => true]));
            $output = $tester->getDisplay();
            self::assertStringContainsString('Informe um nome', $output);
            self::assertStringContainsString('Informe um email válido', $output);
            self::assertStringContainsString('pelo menos 12 bytes', $output);
            self::assertStringNotContainsString($password, $output);
            self::assertStringNotContainsString($tooShort, $output);
            $hash = $this->connection->fetchOne('SELECT password_hash FROM users WHERE email_normalized = ?', [$email]);
            $hasher = self::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(User::class);
            self::assertTrue($hasher->verify($hash, $password));
            self::assertFalse($hasher->verify($hash, trim($password)));
        } finally {
            $id = $this->connection->fetchOne('SELECT id FROM users WHERE email_normalized = ?', [$email]);
            if ($id !== false) {
                $this->ids[] = (int) $id;
            }
        }
    }

    public function testThreeInvalidPasswordsExplainTheReasonAndCreateNoAccount(): void
    {
        $tester = new CommandTester(self::getContainer()->get(CreateAdminCommand::class));
        $tester->setInputs(['Administrador', 'rejected@example.test', 'curta#123', 'curta#123', 'curta#123']);
        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => true]));
        self::assertStringContainsString('pelo menos 12 bytes', $tester->getDisplay());
        self::assertStringNotContainsString('curta#123', $tester->getDisplay());
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM users WHERE email_normalized = 'rejected@example.test'"));
    }
}
