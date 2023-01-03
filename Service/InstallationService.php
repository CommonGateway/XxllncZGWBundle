<?php

// src/Service/InstallationService.php

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Action;
use App\Entity\CollectionEntity;
use App\Entity\Cronjob;
use App\Entity\DashboardCard;
use App\Entity\Endpoint;
use App\Entity\Gateway;
use App\Entity\Translation;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class InstallationService implements InstallerInterface
{
    private EntityManagerInterface $entityManager;
    private SymfonyStyle $io;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $io
     *
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
            (isset($this->io) ? $this->io->writeln('Looking for a dashboard card for: '.$object) : '');
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
            (isset($this->io) ? $this->io->writeln('Looking for a endpoint for: '.$object) : '');
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

        $sourceRepository = $this->entityManager->getRepository('App:Gateway');
        $actionRepository = $this->entityManager->getRepository('App:Action');
        $schemaRepository = $this->entityManager->getRepository('App:Entity');
        $attributeRepository = $this->entityManager->getRepository('App:Attribute');
        $cronjobRepository = $this->entityManager->getRepository('App:Cronjob');

        // Get schema ID's
        $xxllncZaakPost = $schemaRepository->findOneBy(['name' => 'XxllncZaakPost']);
        $xxllncZaakPostID = $xxllncZaakPost ? $xxllncZaakPost->getId()->toString() : '';
        $xxllncZaak = $schemaRepository->findOneBy(['name' => 'XxllncZaak']);
        $xxllncZaakID = $xxllncZaak ? $xxllncZaak->getId()->toString() : '';
        $xxllncZaakType = $schemaRepository->findOneBy(['name' => 'XxllncZaakType']);
        $xxllncZaakTypeID = $xxllncZaakType ? $xxllncZaakType->getId()->toString() : '';
        $zaak = $schemaRepository->findOneBy(['name' => 'Zaak']);
        $zaakID = $zaak ? $zaak->getId()->toString() : '';
        $zaakType = $schemaRepository->findOneBy(['name' => 'ZaakType']);
        $zaakTypeID = $zaakType ? $zaakType->getId()->toString() : '';
        $rolType = $schemaRepository->findOneBy(['name' => 'RolType']);
        $rolTypeID = $rolType ? $rolType->getId()->toString() : '';

        // Cronjob
        $cronjob = $cronjobRepository->findOneBy(['name' => 'Xxllnc sync']) ?? new Cronjob();
        $cronjob->setName('Xxllnc sync');
        $cronjob->setDescription('A cronjob that sets off the synchronizations for the various sources');
        $cronjob->setCrontab('*/1 * * * *');
        $cronjob->setThrows(['xxllnc.cronjob.trigger']);
        $cronjob->setData([]);
        $cronjob->setIsEnabled(true);
        $this->entityManager->persist($cronjob);
        isset($this->io) && $this->io->writeln('Cronjob: \'Xxllnc sync\' created');

        // Sources
        // Xxllnc v1 api
        if ($source = $sourceRepository->findOneBy(['name' => 'zaaksysteem'])) {
            $newSource = false;
        } else {
            $newSource = true;
            $source = new Gateway();
        }
        $source->setName('zaaksysteem');
        $source->setAuth('apikey');
        $source->setLocation('https://development.zaaksysteem.nl/api/v1');
        $newSource && $source->setIsEnabled(false);
        $this->entityManager->persist($source);
        isset($this->io) && $this->io->writeln('Gateway: \'zaaksysteem\' created');

        // // Collections BACKUP

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

        // Actions
        // SyncZaakTypeAction
        $action = $actionRepository->findOneBy(['name' => 'SyncZaakTypeAction']) ?? new Action();
        $action->setName('SyncZaakTypeAction');
        $action->setDescription('This is a synchronization action from the xxllnc v2 to the gateway zgw ztc zaaktypen.');
        $action->setListens(['xxllnc.cronjob.trigger']);
        $action->setConditions(['==' => [1, 1]]);
        $action->setConfiguration([
            'entity'    => $xxllncZaakTypeID,
            'source'    => $source->getId()->toString(),
            'location'  => '/casetype',
            'apiSource' => [
                'sourcePaginated' => true,
                'location'        => [
                    'objects' => 'result.instance.rows',
                    'idField' => 'reference',
                ],
                'queryMethod'           => 'page',
                'syncFromList'          => true,
                'sourceLeading'         => true,
                'useDataFromCollection' => false,
                'mappingIn'             => [],
                'mappingOut'            => [],
                'translationsIn'        => [],
                'translationsOut'       => [],
                'skeletonIn'            => [],
            ],
        ]);
        $action->setAsync(false);
        $action->setClass('App\ActionHandler\SynchronizationCollectionHandler');
        $action->setIsEnabled(true);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'SyncZaakTypeAction\' created');

        $syncOneZaakTypeAction = $actionRepository->findOneBy(['name' => 'SyncOneZaakTypeAction']) ?? new Action();
        $syncOneZaakTypeAction->setName('SyncOneZaakTypeAction');
        $syncOneZaakTypeAction->setDescription('This is a synchronization action from the xxllnc v2 to the gateway zgw ztc zaaktype.');
        $syncOneZaakTypeAction->setListens(['zgw.zaaktype.sync']);
        $syncOneZaakTypeAction->setConditions(['==' => [1, 1]]);
        $syncOneZaakTypeAction->setConfiguration([
            'entity'    => $xxllncZaakTypeID,
            'source'    => $source->getId()->toString(),
            'location'  => '/casetype',
            'apiSource' => [
                'sourcePaginated' => true,
                'location'        => [
                    'object'  => 'result',
                    'idField' => 'reference',
                ],
                'queryMethod'           => 'page',
                'syncFromList'          => true,
                'sourceLeading'         => true,
                'useDataFromCollection' => false,
                'mappingIn'             => [],
                'mappingOut'            => [],
                'translationsIn'        => [],
                'translationsOut'       => [],
                'skeletonIn'            => [],
            ],
        ]);
        $syncOneZaakTypeAction->setClass('App\ActionHandler\SynchronizationItemHandler');
        $syncOneZaakTypeAction->setIsEnabled(true);
        $this->entityManager->persist($syncOneZaakTypeAction);
        isset($this->io) && $this->io->writeln('Action: \'SyncOneZaakTypeAction\' created');

        // MapZaakTypeAction
        $action = $actionRepository->findOneBy(['name' => 'MapZaakTypeAction']) ?? new Action();
        $action->setName('MapZaakTypeAction');
        $action->setDescription('This is a action to map xxllnc casetype to zgw casetype.');
        $action->setListens(['commongateway.object.create', 'commongateway.object.update']);
        $action->setConditions(['==' => [
            ['var' => 'entity'],
            $xxllncZaakTypeID,
        ]]);
        $action->setConfiguration([
            'entities' => [
                'ZaakType' => $zaakTypeID,
                'RolType'  => $rolTypeID,
            ],
        ]);
        $action->setAsync(true);
        $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\MapZaakTypeHandler');
        $action->setIsEnabled(true);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'MapZaakTypeAction\' created');

        // SyncZakenCollectionAction
        $action = $actionRepository->findOneBy(['name' => 'SyncZakenCollectionAction']) ?? new Action();
        $action->setName('SyncZakenCollectionAction');
        $action->setDescription('This is a synchronization action from the xxllnc v2 to the gateway zrc zaken.');
        $action->setListens(['xxllnc.cronjob.trigger']);
        $action->setConditions(['==' => [1, 1]]);
        $action->setConfiguration([
            'entity'    => $xxllncZaakID,
            'source'    => $source->getId()->toString(),
            'location'  => '/case',
            'apiSource' => [
                'sourcePaginated' => true,
                'location'        => [
                    'objects' => 'result.instance.rows',
                    'idField' => 'reference',
                ],
                'queryMethod'           => 'page',
                'syncFromList'          => true,
                'sourceLeading'         => true,
                'useDataFromCollection' => false,
                'mappingIn'             => [],
                'mappingOut'            => [],
                'translationsIn'        => [],
                'translationsOut'       => [],
                'skeletonIn'            => [],
            ],
        ]);
        $action->setAsync(false);
        $action->setClass('App\ActionHandler\SynchronizationCollectionHandler');
        $action->setIsEnabled(true);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'SyncZakenCollectionAction\' created');

        // MapZaakAction
        $action = $actionRepository->findOneBy(['name' => 'MapZaakAction']) ?? new Action();
        $action->setName('MapZaakAction');
        $action->setDescription('This is a action to map xxllnc case to zgw zaak. ');
        $action->setListens(['commongateway.object.create', 'commongateway.object.update']);
        $action->setConditions(['==' => [
            ['var' => 'entity'],
            $xxllncZaakID,
        ]]);
        $action->setConfiguration([
            'source'    => $source->getId()->toString(),
            'entities'  => [
                'Zaak'           => $zaakID,
                'ZaakType'       => $zaakTypeID,
                'XxllncZaakType' => $xxllncZaakTypeID,
            ],
            'actions' => [
                'SyncOneZaakType' => $syncOneZaakTypeAction->getId()->toString(),
            ],
        ]);
        $action->setAsync(true);
        $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\MapZaakHandler');
        $action->setIsEnabled(true);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'MapZaakAction\' created');

        // ZgwToXxllncAction
        $action = $actionRepository->findOneBy(['name' => 'ZgwToXxllncAction']) ?? new Action();
        $action->setName('ZgwToXxllncAction');
        $action->setDescription('This is a mapping action from gateway zrc zaken to xxllnc v1.  ');
        $action->setListens(['commongateway.object.create']);
        $action->setConditions(['==' => [
            ['var' => 'entity'],
            $zaakID,
        ]]);
        $action->setConfiguration([
            'source'    => $source->getId()->toString(),
            'location'  => '/case/create',
            'entities'  => [
                'XxllncZaakPost' => $xxllncZaakPostID,
            ],
        ]);
        $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\ZgwToXxllncHandler');
        $action->setIsEnabled(true);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'ZgwToXxllncAction\' created');

        // SyncZgwToXxllncAction
        $action = $actionRepository->findOneBy(['name' => 'SyncZgwToXxllncAction']) ?? new Action();
        $action->setName('SyncZgwToXxllncAction');
        $action->setDescription('This is a synchronization action from gateway zrc zaken to xxllnc v1.');
        $action->setListens(['commongateway.object.create']);
        $action->setConditions(['==' => [
            ['var' => 'entity'],
            $xxllncZaakPostID,
        ]]);
        $action->setConfiguration([
            'entity'    => $xxllncZaakPostID,
            'source'    => $source->getId()->toString(),
            'location'  => '/case/create',
            'apiSource' => [
                'location' => [
                    'idField' => 'dossier.dossierId',
                ],
                'extend'                   => [],
                'mappingIn'                => [],
                'mappingOut'               => [],
                'translationsIn'           => [],
                'translationsOut'          => [],
                'skeletonIn'               => [],
                'skeletonOut'              => [],
                'unavailablePropertiesOut' => ['_self', 'requestor._self'],
            ],
        ]);
        $action->setClass('App\ActionHandler\SynchronizationPushHandler');
        $action->setIsEnabled(true);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'SyncZgwToXxllncAction\' created');

        // Translations
        $translationRepository = $this->entityManager->getRepository('App:Translation');

        // SyncZgwToXxllncAction
        $trans = $translationRepository->findOneBy(['translateFrom' => 'Nee', 'translationTable' => 'caseTypeTable1']) ?? new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Nee');
        $trans->setTranslateTo(false);
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = $translationRepository->findOneBy(['translateFrom' => 'Ja', 'translationTable' => 'caseTypeTable1']) ?? new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Ja');
        $trans->setTranslateTo(true);
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = $translationRepository->findOneBy(['translateFrom' => 'internextern', 'translationTable' => 'caseTypeTable1']) ?? new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('internextern');
        $trans->setTranslateTo('intern');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = $translationRepository->findOneBy(['translateFrom' => 'Vernietigen (V)', 'translationTable' => 'caseTypeTable1']) ?? new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Vernietigen (V)');
        $trans->setTranslateTo('vernietigen');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = $translationRepository->findOneBy(['translateFrom' => 'Bewaren (B)', 'translationTable' => 'caseTypeTable1']) ?? new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Bewaren (B)');
        $trans->setTranslateTo('blijvend_bewaren');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        if ($xxllncZaakInstance = $attributeRepository->findOneBy(['entity' => $xxllncZaak, 'name' => 'instance'])) {
            $xxllncZaakInstance->setObject(null);
            $xxllncZaakInstance->setMultiple(false);
            $xxllncZaakInstance->setType('array');
            $this->entityManager->persist($xxllncZaakInstance);
        }
        if ($xxllncZaakTypeInstance = $attributeRepository->findOneBy(['entity' => $xxllncZaakType, 'name' => 'instance'])) {
            $xxllncZaakTypeInstance->setObject(null);
            $xxllncZaakTypeInstance->setMultiple(false);
            $xxllncZaakTypeInstance->setType('array');
            $this->entityManager->persist($xxllncZaakTypeInstance);
        }
        if ($xxllncZaakPostFiles = $attributeRepository->findOneBy(['entity' => $xxllncZaakPost, 'name' => 'files'])) {
            $xxllncZaakPostFiles->setObject(null);
            $xxllncZaakPostFiles->setMultiple(false);
            $xxllncZaakPostFiles->setType('array');
            $this->entityManager->persist($xxllncZaakPostFiles);
        }

        $this->entityManager->flush();

        // Lets see if there is a generic search endpoint
    }
}
