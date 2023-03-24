<?php

namespace CommonGateway\XxllncZGWBundle\Command;

use CommonGateway\XxllncZGWBundle\Service\ZaakTypeService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * This class handles the command for the synchronization of a xxllnc casetype to a zgw ztc zaaktype.
 *
 * This Command executes the zaakTypeService->zaakTypeHandler.
 *
 * @author Barry Brands <barry@conduction.nl>
 *
 * @category Command
 */
class ZaakTypeCommand extends Command
{

    /**
     * @var static $defaultName The actual command
     */
    protected static $defaultName = 'xxllnc:zaakType:synchronize';

    /**
     * @var Uuid
     */
    private Uuid $uuid;

    /**
     * @var ZaakTypeService
     */
    private ZaakTypeService $zaakTypeService;


    /**
     * __construct
     */
    public function __construct(ZaakTypeService $zaakTypeService)
    {
        $this->zaakTypeService = $zaakTypeService;
        $this->uuid            = new Uuid();
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
            ->setDescription('This command triggers Xxllnc zaakTypeService')
            ->setHelp('This command triggers Xxllnc zaakTypeService')
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
        $style = new SymfonyStyle($input, $output);
        $this->zaakTypeService->setStyle($style);
        $zaakTypeId = $input->getArgument('id');

        if (isset($zaakTypeId) === true && $this->uuid->isValid($zaakTypeId)) {
            $style->info('ID is valid, trying to fetch and map casetype '.$zaakTypeId.' to a ZGW ZaakType');
            if ($this->zaakTypeService->getZaakType($zaakTypeId)) {
                return Command::FAILURE;
            }//end if

            return Command::SUCCESS;
        }//end if

        if ($this->zaakTypeService->zaakTypeHandler() === null) {
            return Command::FAILURE;
        }//end if

        return Command::SUCCESS;

    }//end execute()


}//end class
