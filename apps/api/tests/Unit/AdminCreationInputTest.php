<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\Exception\AdminCreationException;
use App\Auth\InitialAdminCreator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminCreationInputTest extends TestCase
{
    public function testNameTrimsEdgesAndCountsUnicodeCharacters(): void
    {
        self::assertSame('João da Silva', InitialAdminCreator::validateName(" \tJoão da Silva \n"));
        self::assertSame(str_repeat('ç', 120), InitialAdminCreator::validateName(str_repeat('ç', 120)));
    }

    public function testEmailTrimsEdgesAndPreservesCaseForTheEntityToNormalize(): void
    {
        self::assertSame('Maria.Silva@Example.test', InitialAdminCreator::validateEmail(' Maria.Silva@Example.test '));
        $email = str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 46).'.test';
        self::assertSame(180, strlen($email));
        self::assertSame($email, InitialAdminCreator::validateEmail($email));
    }

    #[DataProvider('validPasswords')]
    public function testPasswordAcceptsByteBoundariesAndPreservesTheExactInput(string $password): void
    {
        self::assertSame($password, InitialAdminCreator::validatePassword($password));
    }

    public static function validPasswords(): iterable
    {
        yield '12 ASCII bytes' => [str_repeat('a', 12)];
        yield '72 ASCII bytes' => [str_repeat('a', 72)];
        yield '6 Unicode characters are 12 bytes' => [str_repeat('ç', 6)];
        yield '36 Unicode characters are 72 bytes' => [str_repeat('ç', 36)];
        yield 'mixed characters at upper boundary' => [str_repeat('a', 70).'ç'];
        yield 'edge spaces are part of the password' => [' 1234567890 '];
    }

    #[DataProvider('invalidInput')]
    public function testValidationIdentifiesTheFieldWithoutIncludingItsInput(string $validator, mixed $input, string $reason, string $messageFragment): void
    {
        try {
            InitialAdminCreator::$validator($input);
            self::fail('Invalid administrator input must be rejected.');
        } catch (AdminCreationException $exception) {
            self::assertSame($reason, $exception->reason);
            self::assertStringContainsString($messageFragment, $exception->getMessage());
            self::assertSame((new AdminCreationException($reason))->getMessage(), $exception->getMessage());
            if (is_string($input) && strlen(trim($input)) >= 5) {
                self::assertStringNotContainsString($input, $exception->getMessage());
            }
        }
    }

    public static function invalidInput(): iterable
    {
        yield 'missing name' => ['validateName', null, 'name', 'nome'];
        yield 'empty name' => ['validateName', " \t\n", 'name', 'nome'];
        yield 'long Unicode name' => ['validateName', str_repeat('ç', 121), 'name', '120'];
        yield 'malformed UTF-8 name' => ['validateName', "invalid-name-\xFF", 'name', 'válidos'];
        yield 'missing email' => ['validateEmail', null, 'email', 'email'];
        yield 'invalid email' => ['validateEmail', 'private-input-without-an-address', 'email', 'email válido'];
        yield 'accented email' => ['validateEmail', 'josé@example.test', 'email', 'sem acentos'];
        yield 'newline in email' => ['validateEmail', "private\n@example.test", 'email', 'email válido'];
        yield 'email exceeds application limit' => ['validateEmail', str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 47).'.test', 'email', '180'];
        yield 'missing password' => ['validatePassword', null, 'password_invalid', 'senha'];
        yield 'whitespace password' => ['validatePassword', str_repeat(' ', 20), 'password_invalid', 'apenas espaços'];
        yield 'NUL in password' => ['validatePassword', "private-password\0suffix", 'password_invalid', 'nulos'];
        yield '11 ASCII bytes' => ['validatePassword', str_repeat('a', 11), 'password_short', '12 bytes'];
        yield '5 Unicode characters are 10 bytes' => ['validatePassword', str_repeat('ç', 5), 'password_short', '12 bytes'];
        yield '73 ASCII bytes' => ['validatePassword', str_repeat('a', 73), 'password_long', '72 bytes'];
        yield '37 Unicode characters are 74 bytes' => ['validatePassword', str_repeat('ç', 37), 'password_long', '72 bytes'];
    }

    public function testUnknownErrorReasonCannotBecomeAConsoleMessage(): void
    {
        $unknownReason = 'private-value-that-must-not-be-displayed';
        try {
            new AdminCreationException($unknownReason);
            self::fail('Only predefined error reasons may be displayed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringNotContainsString($unknownReason, $exception->getMessage());
        }
    }
}
