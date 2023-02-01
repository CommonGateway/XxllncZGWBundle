<?php

namespace CommonGateway\XxllncZGWBundle\Command;

use CommonGateway\XxllncZGWBundle\Service\ZGWToXxllncZaakService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindOrganizationThroughRepositoriesService.
 */
class XxllncToZGWZaakTypeCommand extends Command
{
    protected static $defaultName = 'xxllnc:zgwToXxllncZaakService:execute';
    private ZGWToXxllncZaakService  $zgwToXxllncZaakService;

    public function __construct(ZGWToXxllncZaakService $zgwToXxllncZaakService)
    {
        $this->zgwToXxllncZaakService = $zgwToXxllncZaakService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers Xxllnc zgwToXxllncZaakService')
            ->setHelp('This command triggers Xxllnc zgwToXxllncZaakService');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->zgwToXxllncZaakService->setStyle($io);

        if (!$this->zgwToXxllncZaakService->zgwToXxllncZaakHandler()) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
