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
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConnectBesluittypeToZaaktypeCommand extends Command
{

    /**
     * The actual command.
     *
     * @var static
     */
    protected static $defaultName = 'xxllnc:zaakType:connect:besluittype';

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
            ->setAliases(['xxllnc:besluitType:connect']);

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

        // ObjectType is a ZaakType.
        $objectTypeId = $input->getArgument('id');

        if (isset($objectTypeId) === true
            && Uuid::isValid($objectTypeId) === true
        ) {
            $style->info(
                "ID is valid, trying to connect besluittype to the casetype  with id: $objectTypeId"
            );
            if ($this->zaakTypeService->connectBesluittypeToZaaktypeHandler($objectTypeId) === true) {
                return Command::FAILURE;
            }//end if

            return Command::SUCCESS;
        }//end if

        if ($this->zaakTypeService->connectBesluittypeToZaaktypeHandler() === null) {
            return Command::FAILURE;
        }//end if

        return Command::SUCCESS;

    }//end execute()


}//end class
