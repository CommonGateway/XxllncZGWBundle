<?php

namespace CommonGateway\XxllncZGWBundle\Command;

use CommonGateway\XxllncZGWBundle\Service\XxllncToZGWZaakTypeService;
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
    protected static $defaultName = 'xxllnc:xxllncToZGWZaakType:execute';
    private XxllncToZGWZaakTypeService  $xxllncToZGWZaakTypeService;

    public function __construct(XxllncToZGWZaakTypeService $xxllncToZGWZaakTypeService)
    {
        $this->xxllncToZGWZaakTypeService = $xxllncToZGWZaakTypeService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers Xxllnc xxllncToZGWZaakTypeService')
            ->setHelp('This command triggers Xxllnc xxllncToZGWZaakTypeService');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->xxllncToZGWZaakTypeService->setStyle($io);

        // if (!$this->xxllncToZGWZaakTypeService->xxllncToZGWZaakTypeHandler()) {
        //     return Command::FAILURE;
        // }

        return Command::SUCCESS;
    }
}
