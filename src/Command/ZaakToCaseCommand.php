<?php
/**
 * This class handles the command for the synchronization of a ZGW Zaak to xxllnc case.
 *
 * This Command executes the zgwToXxllncService->syncZaakToXxllnc.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Command
 */

namespace CommonGateway\XxllncZGWBundle\Command;

use App\Entity\Action;
use CommonGateway\XxllncZGWBundle\Service\ZGWToXxllncService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ZaakToCase extends Command
{

    /**
     * The actual command.
     *
     * @var static
     */
    protected static $defaultName = 'xxllnc:zaak:send';

    /**
     * ZGWToXxllncService.
     *
     * @var ZGWToXxllncService
     */
    private ZGWToXxllncService $zgwToXxllncService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;


    /**
     * Class constructor.
     *
     * @param ZaakService $zaakService The case service
     */
    public function __construct(ZGWToXxllncService $zgwToXxllncService, GatewayResourceService $resourceService)
    {
        $this->zgwToXxllncService = $zgwToXxllncService;
        $this->resourceService    = $resourceService;
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
            ->setDescription('This command triggers Xxllnc ZGWToXxllnc')
            ->setHelp('This command triggers Xxllnc ZGWToXxllnc')
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'ZGW Zaak id to map and send to xxllnc'
            );

    }//end configure()


    /**
     * Executes zgwToXxllncService->syncZaakToXxllnc if a id is given.
     *
     * @param InputInterface  Handles input from cli
     * @param OutputInterface Handles output from cli
     *
     * @return int 0 for failure, 1 for success
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $this->zgwToXxllncService->setStyle($style);
        $zaakId = $input->getArgument('id');

        $action = $this->resourceService->getAction('https://development.zaaksysteem.nl/action/xxllnc.ZGWZaakToXxllnc.action.json', 'xxllnc-zgw-bundle');
        if ($action instanceof Action === null) {
            $style->error('Action with reference https://development.zaaksysteem.nl/action/xxllnc.ZGWZaakToXxllnc.action.json not found');

            return Command::FAILURE;
        }

        $zaakObjectArray = $this->resourceService->getObject($zaakId)->toArray();
        if ($zaakObjectArray === null) {
            $style->error("Zaak object with identifier: $zaakId does not exist");

            return Command::FAILURE;
        }

        $this->zgwToXxllncService->data = $zaakObjectArray;
        if ($this->zgwToXxllncService->syncZaakToXxllnc($action->getConfiguration(), $zaakId) === true) {
            return Command::FAILURE;
        }

        isset($style) === true && $style->info("Succesfully synced and created a ZGW Zaak from xxllnc case: $zaakId.");

        return Command::SUCCESS;

    }//end execute()


}//end class
