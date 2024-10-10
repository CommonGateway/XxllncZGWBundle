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

use App\Entity\Action;
use CommonGateway\XxllncZGWBundle\Service\ZaakService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

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
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SessionInterface
     */
    private SessionInterface $session;


    /**
     * Class constructor.
     *
     * @param ZaakService            $zaakService   The case service
     * @param EntityManagerInterface $entityManager
     * @param SessionInterface       $session
     */
    public function __construct(
        ZaakService $zaakService,
        EntityManagerInterface $entityManager,
        SessionInterface $session
    ) {
        $this->zaakService   = $zaakService;
        $this->entityManager = $entityManager;
        $this->session       = $session;
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
     * Executes zaakService->zaakHandler or zaakService->getZaak if a id is given.
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

        $action = $this->entityManager->getRepository(Action::class)->findOneBy(['reference' => 'https://development.zaaksysteem.nl/action/xxllnc.Zaak.action.json']);
        if ($action instanceof Action === null) {
            $style->error('Action with reference https://development.zaaksysteem.nl/action/xxllnc.Zaak.action.json not found');

            return Command::FAILURE;
        }

        $this->session->remove('currentActionUserId');
        if ($action->getUserId() !== null && Uuid::isValid($action->getUserId()) === true) {
            $user = $this->entityManager->getRepository('App:User')->find($action->getUserId());
            if ($user instanceof User === true) {
                $this->session->set('currentActionUserId', $action->getUserId());
            }
        }

        if (isset($zaakId) === true
            && Uuid::isValid($zaakId) === true
        ) {
            if ($this->zaakService->zaakHandler(['caseId' => $zaakId], $action->getConfiguration()) === true) {
                return Command::FAILURE;
            }

            isset($style) === true && $style->info("Succesfully synced and created a ZGW Zaak from xxllnc case: $zaakId.");

            return Command::SUCCESS;
        }//end if

        if ($this->zaakService->zaakHandler([], $action->getConfiguration()) === null) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class
