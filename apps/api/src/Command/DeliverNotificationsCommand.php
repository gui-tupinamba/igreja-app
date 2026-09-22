<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\PushDeliveryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:notifications:deliver',description:'Entrega notificações pendentes pelo Expo Push.')]
final class DeliverNotificationsCommand extends Command
{
    public function __construct(private readonly PushDeliveryService $delivery){parent::__construct();}
    protected function configure():void{$this->addOption('limit',null,InputOption::VALUE_REQUIRED,'Máximo por execução','100');}
    protected function execute(InputInterface $input,OutputInterface $output):int{$limit=filter_var($input->getOption('limit'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);if($limit===false){$output->writeln('<error>limit deve estar entre 1 e 100.</error>');return Command::INVALID;} $r=$this->delivery->deliver($limit);$output->writeln(json_encode($r,JSON_THROW_ON_ERROR));return Command::SUCCESS;}
}
