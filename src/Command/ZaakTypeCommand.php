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

use App\Entity\Action;
use CommonGateway\XxllncZGWBundle\Service\ZaakTypeService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class ZaakTypeCommand extends Command
{

    /**
     * The actual command.
     *
     * @var static
     */
    protected static $defaultName = 'xxllnc:zaakType:synchronize';

    /**
     * The case type service.
     *
     * @var ZaakTypeService
     */
    private ZaakTypeService $zaakTypeService;

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
     * @param ZaakTypeService        $zaakTypeService The case type service
     * @param EntityManagerInterface $entityManager
     * @param SessionInterface       $session
     */
    public function __construct(
        ZaakTypeService $zaakTypeService,
        EntityManagerInterface $entityManager,
        SessionInterface $session
    ) {
        $this->zaakTypeService = $zaakTypeService;
        $this->entityManager   = $entityManager;
        $this->session         = $session;
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

        $actionRef = 'https://development.zaaksysteem.nl/action/xxllnc.ZaakType.action.json';
        $action    = $this->entityManager->getRepository('App:Action')->findOneBy(['reference' => $actionRef]);
        if ($action instanceof Action === false) {
            $style->error("Action with reference $actionRef not found");

            return Command::FAILURE;
        }

        $this->session->remove('currentActionUserId');
        if ($action->getUserId() !== null && Uuid::isValid($action->getUserId()) === true) {
            $user = $this->entityManager->getRepository('App:User')->find($action->getUserId());
            if ($user instanceof User === true) {
                $this->session->set('currentActionUserId', $action->getUserId());
            }
        }

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
