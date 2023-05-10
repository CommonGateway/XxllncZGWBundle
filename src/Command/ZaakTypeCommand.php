<?php
/**
 * This class handles the command for the synchronization of a xxllnc casetype to a zgw ztc zaaktype (or besluittype).
 *
 * This Command executes the zaakTypeService->getZaakType.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Command
 */

namespace CommonGateway\XxllncZGWBundle\Command;

use CommonGateway\XxllncZGWBundle\Service\ZaakTypeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Ramsey\Uuid\Uuid;


class ZaakTypeCommand extends Command
{

    /**
     * The actual command.
     *
     * @var static $defaultName
     */
    protected static $defaultName = 'xxllnc:zaakType:synchronize';

    /**
     * The case type service.
     *
     * @var ZaakTypeService
     */
    private ZaakTypeService $zaakTypeService;


    /**
     * Class constructor.
     *
     * @param ZaakTypeService $zaakTypeService The case type service
     */
    public function __construct(ZaakTypeService $zaakTypeService)
    {
        $this->zaakTypeService = $zaakTypeService;
        parent::__construct();

    }//end __construct()


    /**
     * Configures this command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers Xxllnc zaakTypeService')
            ->setHelp('This command triggers Xxllnc zaakTypeService')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'Casetype id to fetch from xxllnc'
            )
            // We also sync besluitType through this command. 
            ->setAliases(['xxllnc:besluitType:synchronize']);

    }//end configure()


    /**
     * Executes this command.
     *
     * @param InputInterface  $input  Handles input from cli
     * @param OutputInterface $output Handles output from cli
     *
     * @return int 0 for failure, 1 for success
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $this->zaakTypeService->setStyle($style);
        
        // ObjectType could be a BesluitType or ZaakType.
        $objectTypeId = $input->getArgument('id');

        if (isset($objectTypeId) === true
            && Uuid::isValid($objectTypeId) === true
        ) {
            $style->info(
                "ID is valid, trying to fetch and
                map casetype $objectTypeId to a ZGW ZaakType (or BesluitType)"
            );
            if ($this->zaakTypeService->getZaakType($objectTypeId) === true) {
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
