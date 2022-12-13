<?php

// src/Service/InstallationService.php
namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\DashboardCard;
use App\Entity\Endpoint;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Entity\Gateway;
use App\Entity\Action;
use App\Entity\CollectionEntity;
use App\Entity\Translation;

class InstallationService implements InstallerInterface
{
    private EntityManagerInterface $entityManager;
    private SymfonyStyle $io;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Set symfony style in order to output to the console
     *
     * @param SymfonyStyle $io
     * @return self
     */
    public function setStyle(SymfonyStyle $io): self
    {
        $this->io = $io;

        return $this;
    }

    public function install()
    {
        $this->checkDataConsistency();
    }

    public function update()
    {
        $this->checkDataConsistency();
    }

    public function uninstall()
    {
        // Do some cleanup
    }

    public function checkDataConsistency()
    {

        // Lets create some genneric dashboard cards
        $objectsThatShouldHaveCards = [];

        foreach ($objectsThatShouldHaveCards as $object) {
            (isset($this->io) ? $this->io->writeln('Looking for a dashboard card for: ' . $object) : '');
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $object]);
            if (
                !$dashboardCard = $this->entityManager->getRepository('App:DashboardCard')->findOneBy(['entityId' => $entity->getId()])
            ) {
                $dashboardCard = new DashboardCard();
                $dashboardCard->setType('schema');
                $dashboardCard->setEntity('App:Entity');
                $dashboardCard->setObject('App:Entity');
                $dashboardCard->setName($entity->getName());
                $dashboardCard->setDescription($entity->getDescription());
                $dashboardCard->setEntityId($entity->getId());
                $dashboardCard->setOrdering(1);
                $this->entityManager->persist($dashboardCard);
                (isset($this->io) ? $this->io->writeln('Dashboard card created') : '');
                continue;
            }
            (isset($this->io) ? $this->io->writeln('Dashboard card found') : '');
        }

        // Let create some endpoints
        $objectsThatShouldHaveEndpoints = [];

        foreach ($objectsThatShouldHaveEndpoints as $object) {
            (isset($this->io) ? $this->io->writeln('Looking for a endpoint for: ' . $object) : '');
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $object]);

            if (
                count($entity->getEndpoints()) == 0
            ) {
                $endpoint = new Endpoint($entity);
                $this->entityManager->persist($endpoint);
                (isset($this->io) ? $this->io->writeln('Endpoint created') : '');
                continue;
            }
            (isset($this->io) ? $this->io->writeln('Endpoint found') : '');
        }

        // $collection = new CollectionEntity();
        // $collection->setAutoLoad(true);
        // $collection->setLoadTestData(false);
        // $collection->setLocationOAS('https://raw.githubusercontent.com/CommonGateway/ZaakRegistratieComponentAPI/main/OAS.yaml');
        // $collection->setName('ZaakRegistratieComponent');
        // $collection->setSourceType('GitHub');
        // $collection->setPrefix('zrc');

        // // Xxllnc v1 api
        // $source = new Gateway();
        // $source->setName('zaaksysteem');
        // $source->setAuth('apikeyw');
        // $source->setLocation('https://development.zaaksysteem.nl/api/v1');
        // $this->entityManager->persist($source);

        // // actionAction
        // $action = new Action();
        // $action->setName('actionAction');
        // $action->setDescription('This is a synchronization action from the xxllnc v2 to the gateway zgw ztc zaaktypen.');
        // $action->setListens(['commongateway.cronjob.trigger']);
        // $action->setConditions(['==' => [1, 1]]);
        // $action->setClass('App\ActionHandler\SynchronizationCollectionHandler');
        // $action->setPriority(0);
        // $action->setAsync(false);
        // $action->setIsEnabled(true);
        // $this->entityManager->persist($action);
        // // MapZaakTypeAction
        // $action = new Action();
        // $action->setName('MapZaakTypeAction');
        // $action->setDescription('This is a action to map xxllnc casetype to zgw casetype.');
        // $action->setListens(['commongateway.object.create', 'commongateway.object.update']);
        // $action->setConditions(['==' => [1, 1]]);
        // $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\MapZaakTypeHandler');
        // $action->setPriority(0);
        // $action->setAsync(false);
        // $action->setIsEnabled(true);
        // $this->entityManager->persist($action);
        // // SyncZakenCollectionAction
        // $action = new Action();
        // $action->setName('SyncZakenCollectionAction');
        // $action->setDescription('This is a synchronization action from the xxllnc v2 to the gateway zrc zaken.');
        // $action->setListens(['commongateway.cronjob.trigger']);
        // $action->setConditions(['==' => [1, 1]]);
        // $action->setClass('App\ActionHandler\SynchronizationCollectionHandler');
        // $action->setPriority(0);
        // $action->setAsync(false);
        // $action->setIsEnabled(true);
        // $this->entityManager->persist($action);
        // // MapZaakAction
        // $action = new Action();
        // $action->setName('MapZaakAction');
        // $action->setDescription('This is a action to map xxllnc case to zgw zaak. ');
        // $action->setListens(['commongateway.object.create', 'commongateway.object.update']);
        // $action->setConditions(['==' => [1, 1]]);
        // $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\MapZaakHandler');
        // $action->setPriority(0);
        // $action->setAsync(false);
        // $action->setIsEnabled(true);
        // $this->entityManager->persist($action);
        // // ZgwToXxllncAction
        // $action = new Action();
        // $action->setName('ZgwToXxllncAction');
        // $action->setDescription('This is a mapping action from gateway zrc zaken to xxllnc v1.  ');
        // $action->setListens(['commongateway.object.create']);
        // $action->setConditions(['==' => [1, 1]]);
        // $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\ZgwToXxllncHandler');
        // $action->setPriority(0);
        // $action->setAsync(false);
        // $action->setIsEnabled(true);
        // $this->entityManager->persist($action);
        // // SyncZgwToXxllncAction
        // $action = new Action();
        // $action->setName('SyncZgwToXxllncAction');
        // $action->setDescription('This is a synchronization action from gateway zrc zaken to xxllnc v1.');
        // $action->setListens(['commongateway.object.create']);
        // $action->setConditions(['==' => [1, 1]]);
        // $action->setClass('App\ActionHandler\SynchronizationPushHandler');
        // $action->setPriority(0);
        // $action->setAsync(false);
        // $action->setIsEnabled(true);
        // $this->entityManager->persist($action);
        // // SyncZgwToXxllncAction
        // $trans = new Translation();
        // $trans->setTranslationTable('caseTypeTable1');
        // $trans->setTranslateFrom('Nee');
        // $trans->setTranslateTo(false);
        // $trans->setLanguage('nl');
        // $this->entityManager->persist($trans);
        // $trans = new Translation();
        // $trans->setTranslationTable('caseTypeTable1');
        // $trans->setTranslateFrom('Ja');
        // $trans->setTranslateTo(true);
        // $trans->setLanguage('nl');
        // $this->entityManager->persist($trans);
        // $trans = new Translation();
        // $trans->setTranslationTable('caseTypeTable1');
        // $trans->setTranslateFrom('internextern');
        // $trans->setTranslateTo('intern');
        // $trans->setLanguage('nl');
        // $this->entityManager->persist($trans);
        // $trans = new Translation();
        // $trans->setTranslationTable('caseTypeTable1');
        // $trans->setTranslateFrom('Vernietigen (V)');
        // $trans->setTranslateTo('vernietigen');
        // $trans->setLanguage('nl');
        // $this->entityManager->persist($trans);
        // $trans = new Translation();
        // $trans->setTranslationTable('caseTypeTable1');
        // $trans->setTranslateFrom('Bewaren (B)');
        // $trans->setTranslateTo('blijvend_bewaren');
        // $trans->setLanguage('nl');
        // $this->entityManager->persist($trans);

        // $this->entityManager->flush();

        // Lets see if there is a generic search endpoint


    }
}
