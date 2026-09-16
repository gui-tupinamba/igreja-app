<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\User;
use App\Auth\Exception\AdminCreationException;
use App\Domain\Guard;
use App\Enum\UserRole;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final readonly class InitialAdminCreator
{
    public function __construct(private EntityManagerInterface $entityManager, private PasswordHasherFactoryInterface $hashers)
    {
    }

    public function create(string $name, string $email, #[\SensitiveParameter] string $password): User
    {
        $name = self::validateName($name);
        $email = self::validateEmail($email);
        self::validatePassword($password);
        $user = new User($name, $email, $this->hashers->getPasswordHasher(User::class)->hash($password));
        $user->setRole(UserRole::ADMIN);
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            // Serializes concurrent bootstrap commands even when the users table is empty.
            $connection->executeQuery('SELECT pg_advisory_xact_lock(841920041)');
            if ($connection->fetchOne("SELECT 1 FROM users WHERE role = 'ADMIN' LIMIT 1") !== false) {
                throw new AdminCreationException('admin_exists');
            }
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($exception instanceof UniqueConstraintViolationException) {
                throw new AdminCreationException('email_taken');
            }
            throw $exception;
        }

        return $user;
    }

    public static function validateName(mixed $name): string
    {
        try {
            if (!is_string($name)) {
                throw new InvalidArgumentException();
            }

            return Guard::text($name, 120);
        } catch (InvalidArgumentException) {
            throw new AdminCreationException('name');
        }
    }

    public static function validateEmail(mixed $email): string
    {
        try {
            if (!is_string($email)) {
                throw new InvalidArgumentException();
            }
            $email = Guard::text($email, 180);
            if (preg_match('/[^\x00-\x7f]/', $email) === 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException();
            }

            return $email;
        } catch (InvalidArgumentException) {
            throw new AdminCreationException('email');
        }
    }

    public static function validatePassword(#[\SensitiveParameter] mixed $password): string
    {
        if (!is_string($password) || trim($password) === '' || str_contains($password, "\0")) {
            throw new AdminCreationException('password_invalid');
        }
        if (strlen($password) < 12) {
            throw new AdminCreationException('password_short');
        }
        if (strlen($password) > 72) {
            throw new AdminCreationException('password_long');
        }

        return $password;
    }
}
