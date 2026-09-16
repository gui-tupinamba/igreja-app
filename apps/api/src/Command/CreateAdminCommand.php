<?php

declare(strict_types=1);

namespace App\Command;

use App\Auth\InitialAdminCreator;
use App\Auth\Exception\AdminCreationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:admin:create', description: 'Cria o primeiro ADMIN por entrada interativa segura.')]
final class CreateAdminCommand extends Command
{
    public function __construct(private readonly InitialAdminCreator $creator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('Use um terminal interativo. Senhas não são aceitas como argumento ou variável de ambiente.');

            return Command::FAILURE;
        }
        try {
            $nameQuestion = new Question('Nome do administrador');
            $nameQuestion->setValidator(InitialAdminCreator::validateName(...))->setMaxAttempts(3);
            $name = $io->askQuestion($nameQuestion);
            $emailQuestion = new Question('Email do administrador');
            $emailQuestion->setValidator(InitialAdminCreator::validateEmail(...))->setMaxAttempts(3);
            $email = $io->askQuestion($emailQuestion);
            $question = new Question('Senha (mínimo 12 caracteres sem acentos; máximo 72 bytes)');
            // With trimming disabled Symfony keeps the Enter terminator; remove
            // only that terminator, preserving every space in the password.
            $removeEnter = static fn (mixed $value): mixed => is_string($value)
                ? preg_replace('/\r?\n\z/', '', $value)
                : $value;
            $question->setHidden(true)->setHiddenFallback(false)->setTrimmable(false);
            $question->setNormalizer($removeEnter);
            $question->setValidator(InitialAdminCreator::validatePassword(...))->setMaxAttempts(3);
            $password = $io->askQuestion($question);
            $confirmation = new Question('Confirme a senha');
            $confirmation->setHidden(true)->setHiddenFallback(false)->setTrimmable(false);
            $confirmation->setNormalizer($removeEnter);
            $repeat = $io->askQuestion($confirmation);
            if (!is_string($name) || !is_string($email) || !is_string($password) || !is_string($repeat) || !hash_equals($password, $repeat)) {
                $io->error('Dados incompletos ou confirmação de senha diferente.');

                return Command::FAILURE;
            }
            $this->creator->create($name, $email, $password);
            unset($password, $repeat);
            $io->success('Primeiro administrador criado. Nenhuma credencial foi exibida.');

            return Command::SUCCESS;
        } catch (AdminCreationException $exception) {
            $io->error($exception->getMessage());
        } catch (\DomainException | \InvalidArgumentException $exception) {
            $io->error('Criação recusada. Verifique os dados, a política de senha e se já existe um administrador.');
        } catch (\Throwable $exception) {
            $io->error('Não foi possível criar o administrador. Verifique o terminal e a disponibilidade do banco.');
        }

        return Command::FAILURE;
    }
}
