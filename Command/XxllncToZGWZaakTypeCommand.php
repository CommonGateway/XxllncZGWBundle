<?php

namespace CommonGateway\XxllncZGWBundle\Command;

use CommonGateway\XxllncZGWBundle\Service\XxllncToZGWZaakTypeService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
            ->setHelp('This command triggers Xxllnc xxllncToZGWZaakTypeService')
            ->addArgument('id', InputArgument::OPTIONAL, 'Casetype id to fetch from xxllnc');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->xxllncToZGWZaakTypeService->setStyle($io);
        $id = $input->getArgument('id');

        if (isset($id) && Uuid::isValid($id)) {
            $io->info('ID is valid, trying to fetch and map casetype '.$id.' to a ZGW ZaakType');
            if (!$this->xxllncToZGWZaakTypeService->getZaakType($id)) {
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        if (!$this->xxllncToZGWZaakTypeService->xxllncToZGWZaakTypeHandler()) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
