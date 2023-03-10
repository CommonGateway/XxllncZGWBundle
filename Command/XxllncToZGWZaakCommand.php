<?php

namespace CommonGateway\XxllncZGWBundle\Command;

use CommonGateway\XxllncZGWBundle\Service\XxllncToZGWZaakService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindOrganizationThroughRepositoriesService.
 */
class XxllncToZGWZaakCommand extends Command
{
    protected static $defaultName = 'xxllnc:xxllncToZGWZaak:execute';
    private XxllncToZGWZaakService  $xxllncToZGWZaakService;

    public function __construct(XxllncToZGWZaakService $xxllncToZGWZaakService)
    {
        $this->xxllncToZGWZaakService = $xxllncToZGWZaakService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers Xxllnc XxllncToZGWZaakService')
            ->setHelp('This command triggers Xxllnc XxllncToZGWZaakService');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->xxllncToZGWZaakService->setStyle($io);

        if (!$this->xxllncToZGWZaakService->xxllncToZGWZaakHandler()) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
