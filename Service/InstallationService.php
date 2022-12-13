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
                $dashboardCard = new DashboardCard($entity);
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
        //@todo Hier de ZGW bundle requieren

        $sourceRepository = $this->entityManager->getRepository('App:Gateway');
        $actionRepository = $this->entityManager->getRepository('App:Action');
        $schemaRepository = $this->entityManager->getRepository('App:Entity');
        $xxllncZaakPostID = ($entity = $schemaRepository->findOneBy(['name' => 'XxllncZaakPost']) ? $entity->getId()->toString() : '');
        $xxllncZaakTypeID = ($entity = $schemaRepository->findOneBy(['name' => 'XxllncZaakType']) ? $entity->getId()->toString() : '');
        $zaakID = ($entity = $schemaRepository->findOneBy(['name' => 'Zaak']) ? $entity->getId()->toString() : '');
        $zaakTypeID = ($schema = $schemaRepository->findOneBy(['name' => 'ZaakType']) ? $schema->getId()->toString() : '');

        // Sources

        // Xxllnc v1 api
        $source = $sourceRepository->findOneBy(['name' => 'zaaksysteem']) ?? $source = new Gateway();
        $source->setName('zaaksysteem');
        $source->setAuth('apikey');
        $source->setLocation('https://development.zaaksysteem.nl/api/v1');
        $this->entityManager->persist($source);
        isset($this->io) && $this->io->writeln('Gateway: \'zaaksysteem\' created');


        // // Collections
        // $collectionRepository = $this->entityManager->getRepository('App:CollectionEntity');

        // $collection = $collectionRepository->findOneBy(['name' => 'ZaakRegistratieComponent']) ?? $collection = new CollectionEntity();
        // $collection->setAutoLoad(true);
        // $collection->setLoadTestData(false);
        // $collection->setLocationOAS('https://raw.githubusercontent.com/CommonGateway/ZaakRegistratieComponentAPI/main/OAS.yaml');
        // $collection->setName('ZaakRegistratieComponent');
        // $collection->setSourceType('GitHub');
        // $collection->setPrefix('zrc');
        // $this->entityManager->persist($collection);
        // isset($this->io) && $this->io->writeln('CollectionEntity: \'ZaakRegistratieComponent\' created');

        // $collection = $collectionRepository->findOneBy(['name' => 'ZaakTypeCatalogus']) ?? $collection = new CollectionEntity();
        // $collection->setAutoLoad(true);
        // $collection->setLoadTestData(false);
        // $collection->setLocationOAS('https://raw.githubusercontent.com/CommonGateway/ZaakTypeCatalogusAPI/main/OAS.yaml');
        // $collection->setName('ZaakTypeCatalogus');
        // $collection->setSourceType('GitHub');
        // $collection->setPrefix('ztc');
        // $collection->setSource($source);
        // $this->entityManager->persist($collection);
        // isset($this->io) && $this->io->writeln('CollectionEntity: \'ZaakTypeCatalogus\' created');

        // $collection = $collectionRepository->findOneBy(['name' => 'Klanten']) ?? $collection = new CollectionEntity();
        // $collection->setAutoLoad(true);
        // $collection->setLoadTestData(false);
        // $collection->setLocationOAS('https://raw.githubusercontent.com/CommonGateway/KlantenAPI/main/OAS.yaml');
        // $collection->setName('Klanten');
        // $collection->setSourceType('GitHub');
        // $collection->setPrefix('klanten');
        // $this->entityManager->persist($collection);
        // isset($this->io) && $this->io->writeln('CollectionEntity: \'Klanten\' created');

        // $collection = $collectionRepository->findOneBy(['name' => 'Contactmomenten']) ?? $collection = new CollectionEntity();
        // $collection->setAutoLoad(true);
        // $collection->setLoadTestData(false);
        // $collection->setLocationOAS('https://raw.githubusercontent.com/CommonGateway/ContactmomentenAPI/main/OAS.yaml');
        // $collection->setName('Contactmomenten');
        // $collection->setSourceType('GitHub');
        // $collection->setPrefix('cmc');
        // $this->entityManager->persist($collection);
        // isset($this->io) && $this->io->writeln('CollectionEntity: \'Contactmomenten\' created');

        // $collection = $collectionRepository->findOneBy(['name' => 'Besluiten']) ?? $collection = new CollectionEntity();
        // $collection->setAutoLoad(true);
        // $collection->setLoadTestData(false);
        // $collection->setLocationOAS('https://raw.githubusercontent.com/CommonGateway/BesluitenAPI/main/OAS.yaml');
        // $collection->setName('Besluiten');
        // $collection->setSourceType('GitHub');
        // $collection->setPrefix('brc');
        // $this->entityManager->persist($collection);
        // isset($this->io) && $this->io->writeln('CollectionEntity: \'Besluiten\' created');

        // $collection = $collectionRepository->findOneBy(['name' => 'Documenten']) ?? $collection = new CollectionEntity();
        // $collection->setAutoLoad(true);
        // $collection->setLoadTestData(false);
        // $collection->setLocationOAS('https://raw.githubusercontent.com/CommonGateway/DocumentenAPI/main/OAS.yaml');
        // $collection->setName('Documenten');
        // $collection->setSourceType('GitHub');
        // $collection->setPrefix('drc');
        // $this->entityManager->persist($collection);
        // isset($this->io) && $this->io->writeln('CollectionEntity: \'Documenten\' created');

        // $collection = $collectionRepository->findOneBy(['name' => 'Overige objecten']) ?? $collection = new CollectionEntity();
        // $collection->setAutoLoad(true);
        // $collection->setLoadTestData(false);
        // $collection->setLocationOAS('https://raw.githubusercontent.com/CommonGateway/XxllncOverigeObjecten/main/OAS.yaml');
        // $collection->setName('Overige objecten');
        // $collection->setSourceType('GitHub');
        // $collection->setSource($source);
        // $this->entityManager->persist($collection);
        // isset($this->io) && $this->io->writeln('CollectionEntity: \'Overige objecten\' created');


        // Actions

        // SyncZaakTypeAction
        $action = $actionRepository->findOneBy(['name' => 'SyncZaakTypeAction']) ?? $action = new Action();
        $action->setName('SyncZaakTypeAction');
        $action->setDescription('This is a synchronization action from the xxllnc v2 to the gateway zgw ztc zaaktypen.');
        $action->setListens(['commongateway.cronjob.trigger']);
        $action->setConditions(['==' => [1, 1]]);
        $action->setConfiguration([
            'entity'    => $xxllncZaakTypeID,
            'source'    => $source->getId()->toString(),
            'location'  => '/casetype',
            'apiSource' => [
                'location' => [
                    'objects' => 'result.instance.rows',
                    'idField' => 'reference'
                ],
                'queryMethod' => 'page',
                'syncFromList' => true,
                'sourceLeading' => true,
                'useDataFromCollection' => false,
                'mappingIn' => [],
                'mappingOut' => [],
                'translationsIn' => [],
                'translationsOut' => [],
                'skeletonIn' => []
            ]
        ]);
        $action->setClass('App\ActionHandler\SynchronizationCollectionHandler');
        $action->setIsEnabled(false);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'SyncZaakTypeAction\' created');

        // MapZaakTypeAction
        $action = $actionRepository->findOneBy(['name' => 'MapZaakTypeAction']) ?? $action = new Action();
        $action->setName('MapZaakTypeAction');
        $action->setDescription('This is a action to map xxllnc casetype to zgw casetype.');
        $action->setListens(['commongateway.object.create', 'commongateway.object.update']);
        $action->setConditions(['==' => [
            ['var' => 'entity'],
            $xxllncZaakTypeID
        ]]);
        $action->setConfiguration([
            'entities' => [
                'ZaakType' => $zaakTypeID,
                'RolType' => ($schema = $schemaRepository->findOneBy(['name' => 'RolType']) ? $schema->getId()->toString() : '')
            ]
        ]);
        $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\MapZaakTypeHandler');
        $action->setIsEnabled(false);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'MapZaakTypeAction\' created');

        // SyncZakenCollectionAction
        $action = $actionRepository->findOneBy(['name' => 'SyncZakenCollectionAction']) ?? $action = new Action();
        $action->setName('SyncZakenCollectionAction');
        $action->setDescription('This is a synchronization action from the xxllnc v2 to the gateway zrc zaken.');
        $action->setListens(['commongateway.cronjob.trigger']);
        $action->setConditions(['==' => [1, 1]]);
        $action->setConfiguration([
            'sourcePaginated' => true,
            'entity'    => ($schema = $schemaRepository->findOneBy(['name' => 'XxllncZaak']) ? $schema->getId()->toString() : ''),
            'source'    => $source->getId()->toString(),
            'location'  => '/case',
            'apiSource' => [
                'location' => [
                    'objects' => 'result.instance.rows',
                    'idField' => 'reference'
                ],
                'queryMethod' => 'page',
                'syncFromList' => true,
                'sourceLeading' => true,
                'useDataFromCollection' => false,
                'mappingIn' => [],
                'mappingOut' => [],
                'translationsIn' => [],
                'translationsOut' => [],
                'skeletonIn' => []
            ]
        ]);
        $action->setClass('App\ActionHandler\SynchronizationCollectionHandler');
        $action->setIsEnabled(false);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'SyncZakenCollectionAction\' created');

        // MapZaakAction
        $action = $actionRepository->findOneBy(['name' => 'MapZaakAction']) ?? $action = new Action();
        $action->setName('MapZaakAction');
        $action->setDescription('This is a action to map xxllnc case to zgw zaak. ');
        $action->setListens(['commongateway.object.create', 'commongateway.object.update']);
        $action->setConditions(['==' => [
            ['var' => 'entity'],
            $zaakID
        ]]);
        $action->setConfiguration([
            'source'    => $source->getId()->toString(),
            'entities' => [
                'Zaak'           => $zaakID,
                'ZaakType'       => $zaakTypeID,
                'XxllncZaakType' => $xxllncZaakTypeID
            ]
        ]);
        $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\MapZaakHandler');
        $action->setIsEnabled(false);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'MapZaakAction\' created');

        // ZgwToXxllncAction
        $action = $actionRepository->findOneBy(['name' => 'ZgwToXxllncAction']) ?? $action = new Action();
        $action->setName('ZgwToXxllncAction');
        $action->setDescription('This is a mapping action from gateway zrc zaken to xxllnc v1.  ');
        $action->setListens(['commongateway.object.create']);
        $action->setConditions(['==' => [
            ['var' => 'entity'],
            $zaakID
        ]]);
        $action->setConfiguration([
            'source'    => $source->getId()->toString(),
            'location'  => '/case/create',
            'entities' => [
                'XxllncZaakPost' => $xxllncZaakPostID
            ]

        ]);
        $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\ZgwToXxllncHandler');
        $action->setIsEnabled(false);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'ZgwToXxllncAction\' created');

        // SyncZgwToXxllncAction
        $action = $actionRepository->findOneBy(['name' => 'SyncZgwToXxllncAction']) ?? $action = new Action();
        $action->setName('SyncZgwToXxllncAction');
        $action->setDescription('This is a synchronization action from gateway zrc zaken to xxllnc v1.');
        $action->setListens(['commongateway.object.create']);
        $action->setConditions(['==' => [
            ['var' => 'entity'],
            $xxllncZaakPostID
        ]]);
        $action->setConfiguration([
            'entity'    => $xxllncZaakPostID,
            'source'    => $source->getId()->toString(),
            'location'  => '/case/create',
            'apiSource' => [
                'location' => [
                    'idField' => 'dossier.dossierId'
                ],
                'extend' => [],
                'mappingIn' => [],
                'mappingOut' => [],
                'translationsIn' => [],
                'translationsOut' => [],
                'skeletonIn' => [],
                'skeletonOut' => [],
                'unavailablePropertiesOut' => []
            ]
        ]);
        $action->setClass('App\ActionHandler\SynchronizationPushHandler');
        $action->setIsEnabled(false);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'SyncZgwToXxllncAction\' created');


        // Translations
        $translationRepository = $this->entityManager->getRepository('App:Translation');

        // SyncZgwToXxllncAction
        $trans = $translationRepository->findOneBy(['translateFrom' => 'Nee', 'translationTable' => 'caseTypeTable1']) ?? $trans = new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Nee');
        $trans->setTranslateTo(false);
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = $translationRepository->findOneBy(['translateFrom' => 'Ja', 'translationTable' => 'caseTypeTable1']) ?? $trans = new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Ja');
        $trans->setTranslateTo(true);
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = $translationRepository->findOneBy(['translateFrom' => 'internextern', 'translationTable' => 'caseTypeTable1']) ?? $trans = new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('internextern');
        $trans->setTranslateTo('intern');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = $translationRepository->findOneBy(['translateFrom' => 'Vernietigen (V)', 'translationTable' => 'caseTypeTable1']) ?? $trans = new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Vernietigen (V)');
        $trans->setTranslateTo('vernietigen');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = $translationRepository->findOneBy(['translateFrom' => 'Bewaren (B)', 'translationTable' => 'caseTypeTable1']) ?? $trans = new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Bewaren (B)');
        $trans->setTranslateTo('blijvend_bewaren');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $this->entityManager->flush();

        // Lets see if there is a generic search endpoint


    }
}