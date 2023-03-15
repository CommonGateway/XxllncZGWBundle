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
 * This class handles the command for the synchronization of a xxllnc casetype to a zgw ztc zaaktype.
 *
 * This Command executes the xxllncToZGWZaakTypeService->xxllncToZGWZaakTypeHandler.
 *
 * @author Barry Brands <barry@conduction.nl>
 *
 * @category Command
 */
class XxllncToZGWZaakTypeCommand extends Command
{

    /**
     * @var static $defaultName The actual command
     */
    protected static $defaultName = 'xxllnc:xxllncToZGWZaakType:execute';
    /**
     * @var XxllncToZGWZaakTypeService
     */
    private XxllncToZGWZaakTypeService $xxllncToZGWZaakTypeService;

    /**
     * __construct
     */
    public function __construct(XxllncToZGWZaakTypeService $xxllncToZGWZaakTypeService)
    {
        $this->xxllncToZGWZaakTypeService = $xxllncToZGWZaakTypeService;
        parent::__construct();
    }//end __construct()


    /**
     * Configures this command
     * 
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers Xxllnc xxllncToZGWZaakTypeService')
            ->setHelp('This command triggers Xxllnc xxllncToZGWZaakTypeService')
            ->addArgument('id', InputArgument::OPTIONAL, 'Casetype id to fetch from xxllnc');
    }//end configure()

    /**
     * Executes this command
     * 
     * @param InputInterface  Handles input from cli
     * @param OutputInterface Handles output from cli
     * 
     * @return int 0 for failure, 1 for success
     */
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
    }// end execute()
}
