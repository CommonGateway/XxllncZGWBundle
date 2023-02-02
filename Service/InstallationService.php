<?php

// src/Service/InstallationService.php

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Action;
use App\Entity\Cronjob;
use App\Entity\DashboardCard;
use App\Entity\Endpoint;
use App\Entity\ObjectEntity;
use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Translation;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

class InstallationService implements InstallerInterface
{
    private EntityManagerInterface $entityManager;
    private ContainerInterface $container;
    private SymfonyStyle $io;

    private ObjectRepository $sourceRepository;
    private ObjectRepository $actionRepository;
    private ObjectRepository $schemaRepository;
    private ObjectRepository $attributeRepository;
    private ObjectRepository $cronjobRepository;
    private ObjectRepository $translationRepository;

    public const ACTION_HANDLERS = [
        'CommonGateway\XxllncZGWBundle\ActionHandler\XxllncToZGWZaakHandler',
        'CommonGateway\XxllncZGWBundle\ActionHandler\XxllncToZGWZaakTypeHandler',
        'CommonGateway\XxllncZGWBundle\ActionHandler\ZGWToXxllncZaakHandler'
    ];

    public function __construct(EntityManagerInterface $entityManager, ContainerInterface $container)
    {
        $this->entityManager = $entityManager;
        $this->container = $container;

        $this->sourceRepository = $this->entityManager->getRepository('App:Gateway');
        $this->actionRepository = $this->entityManager->getRepository('App:Action');
        $this->schemaRepository = $this->entityManager->getRepository('App:Entity');
        $this->attributeRepository = $this->entityManager->getRepository('App:Attribute');
        $this->cronjobRepository = $this->entityManager->getRepository('App:Cronjob');
        $this->translationRepository = $this->entityManager->getRepository('App:Translation');
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

    public function addActionConfiguration($actionHandler): array
    {
        $defaultConfig = [];

        // What if there are no properties?
        if (!isset($actionHandler->getConfiguration()['properties'])) {
            return $defaultConfig;
        }

        foreach ($actionHandler->getConfiguration()['properties'] as $key => $value) {
            switch ($value['type']) {
                case 'string':
                case 'array':
                    $defaultConfig[$key] = $value['example'];
                    break;
                case 'object':
                    break;
                case 'uuid':
                    if (key_exists('$ref', $value)) {
                        if ($entity = $this->schemaRepository->findOneBy(['reference'=> $value['$ref']])) {
                            $defaultConfig[$key] = $entity->getId()->toString();
                        }
                    }
                    break;
                default:
                    return $defaultConfig;
            }
        }

        return $defaultConfig;
    }

    /**
     * This function creates actions for all the actionHandlers in OpenCatalogi.
     *
     * @return void
     */
    public function addActions(): void
    {
        isset($this->io) && $this->io->info('Looking for actions');

        foreach ($this::ACTION_HANDLERS as $handler) {
            $actionHandler = $this->container->get($handler);

            if ($this->actionRepository->findOneBy(['class' => get_class($actionHandler)])) {
                isset($this->io) && $this->io->info(['Action found for '.$handler]);
                continue;
            }

            if (!$schema = $actionHandler->getConfiguration()) {
                continue;
            }

            $defaultConfig = $this->addActionConfiguration($actionHandler);
            $action = new Action($actionHandler);
        }
    }

    private function updateAttributes(?Entity $xxllncZaak, ?Entity $xxllncZaakType, ?Entity $xxllncZaakPost)
    {
        if ($xxllncZaakInstance = $this->attributeRepository->findOneBy(['entity' => $xxllncZaak, 'name' => 'instance'])) {
            $xxllncZaakInstance->setObject(null);
            $xxllncZaakInstance->setMultiple(false);
            $xxllncZaakInstance->setType('array');
            $this->entityManager->persist($xxllncZaakInstance);
        }
        if ($xxllncZaakTypeInstance = $this->attributeRepository->findOneBy(['entity' => $xxllncZaakType, 'name' => 'instance'])) {
            $xxllncZaakTypeInstance->setObject(null);
            $xxllncZaakTypeInstance->setMultiple(false);
            $xxllncZaakTypeInstance->setType('array');
            $this->entityManager->persist($xxllncZaakTypeInstance);
        }
        if ($xxllncZaakPostFiles = $this->attributeRepository->findOneBy(['entity' => $xxllncZaakPost, 'name' => 'files'])) {
            $xxllncZaakPostFiles->setObject(null);
            $xxllncZaakPostFiles->setMultiple(false);
            $xxllncZaakPostFiles->setType('array');
            $this->entityManager->persist($xxllncZaakPostFiles);
        }
    }

    private function createTranslations()
    {
        $trans = $this->translationRepository->findOneBy(['translateFrom' => 'Nee', 'translationTable' => 'caseTypeTable1']) ?? new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Nee');
        $trans->setTranslateTo(false);
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = $this->translationRepository->findOneBy(['translateFrom' => 'Ja', 'translationTable' => 'caseTypeTable1']) ?? new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Ja');
        $trans->setTranslateTo(true);
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = $this->translationRepository->findOneBy(['translateFrom' => 'internextern', 'translationTable' => 'caseTypeTable1']) ?? new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('internextern');
        $trans->setTranslateTo('intern');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = $this->translationRepository->findOneBy(['translateFrom' => 'Vernietigen (V)', 'translationTable' => 'caseTypeTable1']) ?? new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Vernietigen (V)');
        $trans->setTranslateTo('vernietigen');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = $this->translationRepository->findOneBy(['translateFrom' => 'Bewaren (B)', 'translationTable' => 'caseTypeTable1']) ?? new Translation();
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Bewaren (B)');
        $trans->setTranslateTo('blijvend_bewaren');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');
    }

    private function createActions()
    {
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

        // Get schema ID's
        $xxllncZaakPost = $this->schemaRepository->findOneBy(['name' => 'XxllncZaakPost']);
        $xxllncZaakPostID = $xxllncZaakPost ? $xxllncZaakPost->getId()->toString() : '';
        $xxllncZaak = $this->schemaRepository->findOneBy(['name' => 'XxllncZaak']);
        $xxllncZaakID = $xxllncZaak ? $xxllncZaak->getId()->toString() : '';
        $xxllncZaakType = $this->schemaRepository->findOneBy(['name' => 'XxllncZaakType']);
        $xxllncZaakTypeID = $xxllncZaakType ? $xxllncZaakType->getId()->toString() : '';
        $zaak = $this->schemaRepository->findOneBy(['name' => 'Zaak']);
        $zaakID = $zaak ? $zaak->getId()->toString() : '';
        $zaakType = $this->schemaRepository->findOneBy(['name' => 'ZaakType']);
        $zaakTypeID = $zaakType ? $zaakType->getId()->toString() : '';
        $rolType = $this->schemaRepository->findOneBy(['name' => 'RolType']);
        $rolTypeID = $rolType ? $rolType->getId()->toString() : '';
        $zaakEigenschap = $this->schemaRepository->findOneBy(['name' => 'ZaakEigenschap']);
        $zaakEigenschapID = $zaakEigenschap ? $zaakEigenschap->getId()->toString() : '';

        // Cronjob
        $cronjob = $this->cronjobRepository->findOneBy(['name' => 'Xxllnc sync']) ?? new Cronjob();
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
        if ($source = $this->sourceRepository->findOneBy(['location' => 'https://development.zaaksysteem.nl/api/v1'])) {
            $newSource = false;
        } else {
            $newSource = true;
            $source = new Source();
        }
        $source->setName('Xxllnc zaaksysteem v1');
        $source->setAuth('apikey');
        $source->setAuthorizationHeader('API-KEY');
        $source->setLocation('https://development.zaaksysteem.nl/api/v1');
        $newSource && $source->setIsEnabled(false);
        $this->entityManager->persist($source);
        isset($this->io) && $this->io->writeln('Gateway: \'zaaksysteem\' created');

        // Create Catalogus
        $catalogusSchema = $this->entityManager->getRepository('App:Entity')->findOneBy(['name' => 'Catalogus']);
        if (!$catalogusSchema instanceof Entity) {
            isset($this->io) && $this->io->error('ZGW not correctly installed, no Catalogus schema found');
        }

        $catalogusObjecten = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['entity' => $catalogusSchema]);
        if (count($catalogusObjecten) < 1) {
            $catalogusObject = new ObjectEntity($catalogusSchema);
            $catalogusObject->hydrate([
                'contactpersoonBeheerNaam' => 'Conduction',
                'domein' => 'http://localhost'
            ]);
            $this->entityManager->persist($catalogusObject);
            isset($this->io) && $this->io->writeln('ObjectEntity: \'Catalogus\' created');
        } else {
            $catalogusObject = $catalogusObjecten[0];
            isset($this->io) && $this->io->writeln('ObjectEntity: \'Catalogus\' found');
        }

        // Actions
        // $this->createActionsOld(); // disabled cus old
        $this->createActions();

        // Translations
        $this->createTranslations();

        // Update attributes
        $this->updateAttributes($xxllncZaakPost, $xxllncZaakType, $xxllncZaakPost);

        $this->entityManager->flush();

        // Lets see if there is a generic search endpoint
    }

    // private function createActionsOld()
    // {
    //     // SyncZaakTypeAction
    //     $action = $this->actionRepository->findOneBy(['name' => 'SyncZaakTypeAction']) ?? new Action();
    //     $action->setName('SyncZaakTypeAction');
    //     $action->setDescription('This is a synchronization action from the xxllnc v2 to the gateway zgw ztc zaaktypen.');
    //     $action->setListens(['xxllnc.cronjob.trigger']);
    //     $action->setConditions(['==' => [1, 1]]);
    //     $action->setConfiguration([
    //         'entity'    => $xxllncZaakTypeID,
    //         'source'    => $source->getId()->toString(),
    //         'location'  => '/casetype',
    //         'apiSource' => [
    //             'sourcePaginated' => true,
    //             'location'        => [
    //                 'objects' => 'result.instance.rows',
    //                 'idField' => 'reference',
    //             ],
    //             'queryMethod'           => 'page',
    //             'syncFromList'          => true,
    //             'sourceLeading'         => true,
    //             'useDataFromCollection' => false,
    //             'mappingIn'             => [],
    //             'mappingOut'            => [],
    //             'translationsIn'        => [],
    //             'translationsOut'       => [],
    //             'skeletonIn'            => [],
    //         ],
    //     ]);
    //     $action->setAsync(false);
    //     $action->setClass('App\ActionHandler\SynchronizationCollectionHandler');
    //     $action->setIsEnabled(true);
    //     $this->entityManager->persist($action);
    //     isset($this->io) && $this->io->writeln('Action: \'SyncZaakTypeAction\' created');

    //     $syncOneZaakTypeAction = $this->actionRepository->findOneBy(['name' => 'SyncOneZaakTypeAction']) ?? new Action();
    //     $syncOneZaakTypeAction->setName('SyncOneZaakTypeAction');
    //     $syncOneZaakTypeAction->setDescription('This is a synchronization action from the xxllnc v2 to the gateway zgw ztc zaaktype.');
    //     $syncOneZaakTypeAction->setListens(['zgw.zaaktype.sync']);
    //     $syncOneZaakTypeAction->setConditions(['==' => [1, 1]]);
    //     $syncOneZaakTypeAction->setConfiguration([
    //         'entity'    => $xxllncZaakTypeID,
    //         'source'    => $source->getId()->toString(),
    //         'location'  => '/casetype',
    //         'apiSource' => [
    //             'sourcePaginated' => true,
    //             'location'        => [
    //                 'object'  => 'result',
    //                 'idField' => 'reference',
    //             ],
    //             'queryMethod'           => 'page',
    //             'syncFromList'          => true,
    //             'sourceLeading'         => true,
    //             'useDataFromCollection' => false,
    //             'mappingIn'             => [],
    //             'mappingOut'            => [],
    //             'translationsIn'        => [],
    //             'translationsOut'       => [],
    //             'skeletonIn'            => [],
    //         ],
    //     ]);
    //     $syncOneZaakTypeAction->setClass('App\ActionHandler\SynchronizationItemHandler');
    //     $syncOneZaakTypeAction->setIsEnabled(true);
    //     $this->entityManager->persist($syncOneZaakTypeAction);
    //     isset($this->io) && $this->io->writeln('Action: \'SyncOneZaakTypeAction\' created');

    //     // Create Catalogus
    //     $catalogusSchema = $this->entityManager->getRepository('App:Entity')->findOneBy(['name' => 'Catalogus']);
    //     if (!$catalogusSchema instanceof Entity) {
    //         throw new Exception('ZGW not correctly installed, no Catalogus schema found');
    //     }

    //     $catalogusObjecten = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['entity' => $catalogusSchema]);
    //     if (count($catalogusObjecten) < 1) {
    //         $catalogusObject = new ObjectEntity($catalogusSchema);
    //         $catalogusObject->hydrate([
    //             'contactpersoonBeheerNaam' => 'Conduction',
    //             'domein' => 'http://localhost'
    //         ]);
    //         $this->entityManager->persist($catalogusObject);
    //         isset($this->io) && $this->io->writeln('ObjectEntity: \'Catalogus\' created');
    //     } else {
    //         $catalogusObject = $catalogusObjecten[0];
    //         isset($this->io) && $this->io->writeln('ObjectEntity: \'Catalogus\' found');
    //     }

    //     // XxllncToZGWZaakTypeAction
    //     $action = $this->actionRepository->findOneBy(['name' => 'XxllncToZGWZaakTypeAction']) ?? new Action();
    //     $action->setName('XxllncToZGWZaakTypeAction');
    //     $action->setDescription('This is a action to map xxllnc casetype to zgw casetype.');
    //     $action->setListens(['commongateway.object.create', 'commongateway.object.update']);
    //     $action->setConditions(['==' => [
    //         ['var' => 'entity'],
    //         $xxllncZaakTypeID,
    //     ]]);
    //     $action->setConfiguration([
    //         'entities' => [
    //             'ZaakType' => $zaakTypeID,
    //             'RolType'  => $rolTypeID,
    //         ],
    //         'objects' => ['Catalogus' => $catalogusObject->getId()->toString()]
    //     ]);
    //     $action->setAsync(true);
    //     $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\XxllncToZGWZaakTypeHandler');
    //     $action->setIsEnabled(true);
    //     $this->entityManager->persist($action);
    //     isset($this->io) && $this->io->writeln('Action: \'XxllncToZGWZaakTypeAction\' created');

    //     // SyncZakenCollectionAction
    //     $action = $this->actionRepository->findOneBy(['name' => 'SyncZakenCollectionAction']) ?? new Action();
    //     $action->setName('SyncZakenCollectionAction');
    //     $action->setDescription('This is a synchronization action from the xxllnc v2 to the gateway zrc zaken.');
    //     $action->setListens(['xxllnc.cronjob.trigger']);
    //     $action->setConditions(['==' => [1, 1]]);
    //     $action->setConfiguration([
    //         'entity'    => $xxllncZaakID,
    //         'source'    => $source->getId()->toString(),
    //         'location'  => '/case',
    //         'apiSource' => [
    //             'sourcePaginated' => true,
    //             'location'        => [
    //                 'objects' => 'result.instance.rows',
    //                 'idField' => 'reference',
    //             ],
    //             'queryMethod'           => 'page',
    //             'syncFromList'          => true,
    //             'sourceLeading'         => true,
    //             'useDataFromCollection' => false,
    //             'mappingIn'             => [],
    //             'mappingOut'            => [],
    //             'translationsIn'        => [],
    //             'translationsOut'       => [],
    //             'skeletonIn'            => [],
    //         ],
    //     ]);
    //     $action->setAsync(false);
    //     $action->setClass('App\ActionHandler\SynchronizationCollectionHandler');
    //     $action->setIsEnabled(true);
    //     $this->entityManager->persist($action);
    //     isset($this->io) && $this->io->writeln('Action: \'SyncZakenCollectionAction\' created');

    //     // XxllncToZGWZaakAction
    //     $action = $this->actionRepository->findOneBy(['name' => 'XxllncToZGWZaakAction']) ?? new Action();
    //     $action->setName('XxllncToZGWZaakAction');
    //     $action->setDescription('This is a action to map xxllnc case to zgw zaak. ');
    //     $action->setListens(['commongateway.object.create', 'commongateway.object.update']);
    //     $action->setConditions(['==' => [
    //         ['var' => 'entity'],
    //         $xxllncZaakID,
    //     ]]);
    //     $action->setConfiguration([
    //         'source'    => $source->getId()->toString(),
    //         'entities'  => [
    //             'Zaak'           => $zaakID,
    //             'ZaakType'       => $zaakTypeID,
    //             'XxllncZaakType' => $xxllncZaakTypeID,
    //         ],
    //         'actions' => [
    //             'SyncOneZaakType' => $syncOneZaakTypeAction->getId()->toString(),
    //         ],
    //     ]);
    //     $action->setAsync(true);
    //     $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\XxllncToZGWZaakHandler');
    //     $action->setIsEnabled(true);
    //     $this->entityManager->persist($action);
    //     isset($this->io) && $this->io->writeln('Action: \'XxllncToZGWZaakAction\' created');

    //     // MapUpdateZaakAction
    //     $action = $this->actionRepository->findOneBy(['name' => 'MapUpdateZaakAction']) ?? new Action();
    //     $action->setName('MapUpdateZaakAction');
    //     $action->setDescription('Update xxllnc zaak with updated zgw zaak');
    //     $action->setListens(['zrc.zaakEigenschap.updated']);
    //     $action->setThrows(['xxllnc.case.updated']);
    //     $action->setConditions(['==' => [1, 1]]);
    //     $action->setConfiguration([
    //         'source'    => $source->getId()->toString(),
    //         'location'  => '/case/{id}/update',
    //         'entities'  => [
    //             'XxllncZaakPost' => $xxllncZaakPostID,
    //         ],
    //     ]);
    //     $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\MapUpdateZaakHandler');
    //     $action->setIsEnabled(true);
    //     $this->entityManager->persist($action);
    //     isset($this->io) && $this->io->writeln('Action: \'MapUpdateZaakAction\' created');

    //     // SyncUpdateZaakAction
    //     $action = $this->actionRepository->findOneBy(['name' => 'SyncUpdateZaakAction']) ?? new Action();
    //     $action->setName('SyncUpdateZaakAction');
    //     $action->setDescription('This is a synchronization update action from gateway zrc zaken to xxllnc v1.');
    //     $action->setListens(['xxllnc.case.updated']);
    //     $action->setConditions(['==' => [
    //         ['var' => 'entity'],
    //         $xxllncZaakPostID,
    //     ]]);
    //     $action->setConfiguration([
    //         'entity'    => $xxllncZaakPostID,
    //         'source'    => $source->getId()->toString(),
    //         'location'  => '{{ "/case/"~id~"/update" }}',
    //         'replaceTwigLocation' => 'objectEntityData',
    //         'apiSource' => [
    //             'location' => [
    //                 'idField' => 'dossier.dossierId',
    //             ],
    //             'extend'                   => [],
    //             'mappingIn'                => [],
    //             'mappingOut'               => [],
    //             'translationsIn'           => [],
    //             'translationsOut'          => [],
    //             'skeletonIn'               => [],
    //             'skeletonOut'              => [],
    //             'unavailablePropertiesOut' => ['_self', 'requestor', 'casetype_id', 'source', 'open', 'route', 'contact_details', 'confidentiality', 'number', 'zgwZaak'],
    //         ],
    //     ]);
    //     $action->setClass('App\ActionHandler\SynchronizationPushHandler');
    //     $action->setIsEnabled(true);
    //     $this->entityManager->persist($action);
    //     isset($this->io) && $this->io->writeln('Action: \'SyncZgwToXxllncAction\' created');

    //     // ZgwToXxllncAction
    //     $action = $this->actionRepository->findOneBy(['name' => 'ZgwToXxllncAction']) ?? new Action();
    //     $action->setName('ZgwToXxllncAction');
    //     $action->setDescription('This is a mapping action from gateway zrc zaken to xxllnc v1.  ');
    //     $action->setListens(['commongateway.object.create']);
    //     $action->setConditions(['==' => [
    //         ['var' => 'entity'],
    //         $zaakID,
    //     ]]);
    //     $action->setConfiguration([
    //         'source'    => $source->getId()->toString(),
    //         'location'  => '/case/create',
    //         'entities'  => [
    //             'XxllncZaakPost' => $xxllncZaakPostID,
    //         ],
    //     ]);
    //     $action->setClass('CommonGateway\XxllncZGWBundle\ActionHandler\ZgwToXxllncHandler');
    //     $action->setIsEnabled(true);
    //     $this->entityManager->persist($action);
    //     isset($this->io) && $this->io->writeln('Action: \'ZgwToXxllncAction\' created');

    //     // SyncZgwToXxllncAction
    //     $action = $this->actionRepository->findOneBy(['name' => 'SyncZgwToXxllncAction']) ?? new Action();
    //     $action->setName('SyncZgwToXxllncAction');
    //     $action->setDescription('This is a synchronization action from gateway zrc zaken to xxllnc v1.');
    //     $action->setListens(['commongateway.object.create']);
    //     $action->setConditions(['==' => [
    //         ['var' => 'entity'],
    //         $xxllncZaakPostID,
    //     ]]);
    //     $action->setConfiguration([
    //         'entity'    => $xxllncZaakPostID,
    //         'source'    => $source->getId()->toString(),
    //         'location'  => '/case/create',
    //         'apiSource' => [
    //             'location' => [
    //                 'idField' => 'dossier.dossierId',
    //             ],
    //             'extend'                   => [],
    //             'mappingIn'                => [],
    //             'mappingOut'               => [],
    //             'translationsIn'           => [],
    //             'translationsOut'          => [],
    //             'skeletonIn'               => [],
    //             'skeletonOut'              => [],
    //             'unavailablePropertiesOut' => ['_self', 'requestor._self', 'zgwZaak'],
    //         ],
    //     ]);
    //     $action->setClass('App\ActionHandler\SynchronizationPushHandler');
    //     $action->setIsEnabled(true);
    //     $this->entityManager->persist($action);
    //     isset($this->io) && $this->io->writeln('Action: \'SyncZgwToXxllncAction\' created');
    // }
}
