<?php
/**
 * This class handles the command for the synchronization of a xxllnc case to a zgw zrc zaak.
 *
 * This Command executes the zaakService->ZaakHandler.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Command
 */

namespace CommonGateway\XxllncZGWBundle\Command;

use CommonGateway\XxllncZGWBundle\Service\ZaakService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ZaakCommand extends Command
{

    /**
     * The actual command.
     *
     * @var static
     */
    protected static $defaultName = 'xxllnc:zaak:synchronize';

    /**
     * The case service.
     *
     * @var ZaakService
     */
    private ZaakService $zaakService;


    /**
     * Class constructor.
     *
     * @param ZaakService $zaakService The case service
     */
    public function __construct(ZaakService $zaakService)
    {
        $this->zaakService = $zaakService;
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
            ->setDescription('This command triggers Xxllnc ZaakService')
            ->setHelp('This command triggers Xxllnc ZaakService')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'Casetype id to fetch from xxllnc'
            );

    }//end configure()


    /**
     * Executes this command.
     *
     * @param InputInterface  Handles input from cli
     * @param OutputInterface Handles output from cli
     *
     * @return int 0 for failure, 1 for success
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $this->zaakService->setStyle($style);
        $zaakId = $input->getArgument('id');

        if (isset($zaakId) === true
            && Uuid::isValid($zaakId) === true
        ) {
            $style->info(
                "ID is valid, trying to fetch and
                map casetype $zaakId to a ZGW Zaak"
            );
            if ($this->zaakService->getZaak($zaakId) === true) {
                return Command::FAILURE;
            }//end if

            return Command::SUCCESS;
        }//end if

        if ($this->zaakService->zaakHandler() === null) {
            return Command::FAILURE;
        }//end if

        return Command::SUCCESS;

    }//end execute()


    // end execute()
}//end class
