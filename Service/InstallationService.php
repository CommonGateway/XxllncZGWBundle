<?php

// src/Service/InstallationService.php

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Action;
use App\Entity\Cronjob;
use App\Entity\DashboardCard;
use App\Entity\Endpoint;
use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Entity\Translation;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * This class handles the installation of the XxllncZGWBundle.
 *
 * By creating symfony objects that function as configuration for the gateway.
 *
 * @author Barry Brands <barry@conduction.nl>
 *
 * @category Service
 * 
 * @todo update to new installation method (check zgwbundle as example)
 */
class InstallationService implements InstallerInterface
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

    /**
     * @var SymfonyStyle
     */
    private ObjectRepository $sourceRepository;

    /**
     * @var SymfonyStyle
     */
    private ObjectRepository $schemaRepository;

    /**
     * @var SymfonyStyle
     */
    private ObjectRepository $attributeRepository;

    /**
     * @var SymfonyStyle
     */
    private ObjectRepository $cronjobRepository;

    /**
     * @var SymfonyStyle
     */
    private ObjectRepository $translationRepository;

    /**
     * @var const
     */
    public const OBJECTS_THAT_SHOULD_HAVE_CARDS = ['https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json',];

    public const ACTION_HANDLERS = [
        ['name' => 'XxllncToZGWZaak', 'actionHandler' => 'CommonGateway\XxllncZGWBundle\ActionHandler\XxllncToZGWZaakHandler', 'listens' => ['xxllnc.cronjob.trigger']],
        ['name' => 'XxllncToZGWZaakType', 'actionHandler' => 'CommonGateway\XxllncZGWBundle\ActionHandler\XxllncToZGWZaakTypeHandler', 'listens' => ['xxllnc.cronjob.trigger']],
        ['name' => 'ZGWZaakToXxllnc', 'actionHandler' => 'CommonGateway\XxllncZGWBundle\ActionHandler\ZGWToXxllncZaakHandler', 'listens' => ['zgw.zaak.saved']],
    ];

    /**
     * __construct
     */
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
     * @TODO change to monolog
     *
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

    /**
     * This function creates default configuration for the action.
     *
     * @param $actionHandler The actionHandler for witch the default configuration is set
     *
     * @return array
     */
    public function addActionConfiguration($actionHandler): array
    {
        $defaultConfig = [];
        foreach ($actionHandler->getConfiguration()['properties'] as $key => $value) {
            switch ($value['type']) {
                case 'string':
                case 'array':
                    if (isset($value['example'])) {
                        $defaultConfig[$key] = $value['example'];
                    }
                    break;
                case 'object':
                    break;
                case 'uuid':
                    if (isset($value['$ref'])) {
                        try {
                            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $value['$ref']]);
                        } catch (Exception $exception) {
                            throw new Exception("No entity found with reference {$value['$ref']}");
                        }
                        $defaultConfig[$key] = $entity->getId()->toString();
                    }
                    break;
                default:
                    // throw error
            }
        }

        return $defaultConfig;
    }

    /**
     * Decides wether or not an array is associative.
     *
     * @param array $array The array to check
     *
     * @return bool Wether or not the array is associative
     */
    private function isAssociative(array $array)
    {
        if ([] === $array) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * @param array $defaultConfig
     * @param array $overrides
     *
     * @throws Exception
     *
     * @return array
     */
    public function overrideConfig(array $defaultConfig, array $overrides): array
    {
        foreach ($overrides as $key => $override) {
            if (is_array($override) && $this->isAssociative($override)) {
                $defaultConfig[$key] = $this->overrideConfig(isset($defaultConfig[$key]) ? $defaultConfig[$key] : [], $override);
            } elseif ($key == 'entity') {
                $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $override]);
                if (!$entity) {
                    throw new Exception("No entity found with reference {$override}");
                }
                $defaultConfig[$key] = $entity->getId()->toString();
            } elseif ($key == 'source') {
                $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['name' => $override]);
                if (!$source) {
                    throw new Exception("No source found with name {$override}");
                }
                $defaultConfig[$key] = $source->getId()->toString();
            } else {
                $defaultConfig[$key] = $override;
            }
        }

        return $defaultConfig;
    }

    public function replaceRefById(array $conditions): array
    {
        if ($conditions['=='][0]['var'] == 'entity') {
            try {
                $conditions['=='][1] = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $conditions['=='][1]]);
            } catch (Exception $exception) {
                throw new Exception("No entity found with reference {$conditions['=='][1]}");
            }
        }

        return $conditions;
    }

    /**
     * This function creates actions for all the actionHandlers in Kiss.
     *
     * @throws Exception
     *
     * @return void
     */
    public function addActions(): void
    {
        $actionHandlers = $this::ACTION_HANDLERS;
        isset($this->io) && $this->io->writeln(['', '<info>Looking for actions</info>']);

        foreach ($actionHandlers as $handler) {
            $actionHandler = $this->container->get($handler['actionHandler']);

            if (array_key_exists('name', $handler)) {
                if ($this->entityManager->getRepository('App:Action')->findOneBy(['name'=> $handler['name']])) {
                    (isset($this->io) ? $this->io->writeln(['Action found with name '.$handler['name']]) : '');
                    continue;
                }
            } elseif ($this->entityManager->getRepository('App:Action')->findOneBy(['class'=> get_class($actionHandler)])) {
                (isset($this->io) ? $this->io->writeln(['Action found for '.$handler['actionHandler']]) : '');
                continue;
            }

            if (!$actionHandler->getConfiguration()) {
                continue;
            }

            $defaultConfig = $this->addActionConfiguration($actionHandler);
            isset($handler['config']) && $defaultConfig = $this->overrideConfig($defaultConfig, $handler['config']);

            $action = new Action($actionHandler);
            array_key_exists('name', $handler) ? $action->setName($handler['name']) : '';
            $action->setListens($handler['listens'] ?? ['xxllnc.default.listens']);
            $action->setConfiguration($defaultConfig);
            $action->setConditions($handler['conditions'] ?? ['==' => [1, 1]]);

            $this->entityManager->persist($action);
            (isset($this->io) ? $this->io->writeln(['Created Action '.$action->getName().' with Handler: '.$handler['actionHandler']]) : '');
        }
    }

    private function updateAttributes(?Entity $xxllncZaakPost)
    {
        if ($xxllncZaakPostFiles = $this->attributeRepository->findOneBy(['entity' => $xxllncZaakPost, 'name' => 'files'])) {
            $xxllncZaakPostFiles->setObject(null);
            $xxllncZaakPostFiles->setMultiple(false);
            $xxllncZaakPostFiles->setType('array');
            $this->entityManager->persist($xxllncZaakPostFiles);
        }
    }

    /**
     * Creates needed translations for xxllnc to zgw
     * 
     * @todo Should be integrated in mapping
     *
     * @return void
     */
    private function createTranslations(): void
    {
        isset($this->io) && $this->io->writeln(['', '<info>Looking for translations</info>']);
        $trans = ($this->translationRepository->findOneBy(['translateFrom' => 'Nee', 'translationTable' => 'caseTypeTable1']) ?? new Translation());
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Nee');
        $trans->setTranslateTo(false);
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = ($this->translationRepository->findOneBy(['translateFrom' => 'Ja', 'translationTable' => 'caseTypeTable1']) ?? new Translation());
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Ja');
        $trans->setTranslateTo(true);
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = ($this->translationRepository->findOneBy(['translateFrom' => 'internextern', 'translationTable' => 'caseTypeTable1']) ?? new Translation());
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('internextern');
        $trans->setTranslateTo('intern');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = ($this->translationRepository->findOneBy(['translateFrom' => 'Vernietigen (V)', 'translationTable' => 'caseTypeTable1']) ?? new Translation());
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Vernietigen (V)');
        $trans->setTranslateTo('vernietigen');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');

        $trans = ($this->translationRepository->findOneBy(['translateFrom' => 'Bewaren (B)', 'translationTable' => 'caseTypeTable1']) ?? new Translation());
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Bewaren (B)');
        $trans->setTranslateTo('blijvend_bewaren');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->io) && $this->io->writeln('Translation created');
    }


    /**
     * Creates a standard ZGW Catalogus
     *
     * @return void
     */
    private function createCatalogus(): void
    {
        isset($this->io) && $this->io->writeln(['', '<info>Creating catalogus</info>']);
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
                'domein'                   => 'http://localhost',
            ]);
            $this->entityManager->persist($catalogusObject);
            isset($this->io) && $this->io->writeln('ObjectEntity: \'Catalogus\' created');
        } else {
            $catalogusObject = $catalogusObjecten[0];
            isset($this->io) && $this->io->writeln('ObjectEntity: \'Catalogus\' found');
        }
    }

    /**
     * Creates dashboard cards for the given schemas.
     *
     * @return void
     */
    public function createDashboardCards(): void
    {
        // Lets create some generic dashboard cards
        foreach ($this::OBJECTS_THAT_SHOULD_HAVE_CARDS as $object) {
            isset($this->io) && $this->io->writeln('Looking for a dashboard card for: '.$object);
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $object]);
            if (
                isset($entity) && !$dashboardCard = $this->entityManager->getRepository('App:DashboardCard')->findOneBy(['entityId' => $entity->getId()])
            ) {
                $dashboardCard = new DashboardCard($entity);
                $this->entityManager->persist($dashboardCard);
                (isset($this->io) ? $this->io->writeln('Dashboard card created') : '');
                continue;
            }
            (isset($this->io) ? $this->io->writeln('Dashboard card found') : '');
        }
    }

    /**
     * Creates cronjobs for xxllnc.
     *
     * @return void
     */
    public function createCronjobs(): void
    {
        isset($this->io) && $this->io->writeln(['', '<info>Looking for cronjobs</info>']);
        // We only need 1 cronjob so lets set that
        $cronjob = $this->cronjobRepository->findOneBy(['name' => 'Xxllnc sync']) ?? new Cronjob();
        $cronjob->setName('Xxllnc sync');
        $cronjob->setDescription('A cronjob that sets off the synchronizations for the various sources');
        $cronjob->setCrontab('*/1 * * * *');
        $cronjob->setThrows(['xxllnc.cronjob.trigger']);
        $cronjob->setData([]);
        $cronjob->setIsEnabled(true);
        $this->entityManager->persist($cronjob);
        isset($this->io) && $this->io->writeln('Cronjob: \'Xxllnc sync\' created');

        (isset($this->io) ? $this->io->writeln(['', 'Created/updated a cronjob for '.$cronjob->getName()]) : '');
    }

    /**
     * Creates the xxllnc api source.
     *
     * @return void
     */
    private function createSource(): void
    {
        isset($this->io) && $this->io->writeln(['', '<info>Creating xxllnc source</info>']);
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
    }

    /**
     * Updates ZGW Zaak endpoint with a throw event.
     *
     * @return void
     */
    private function updateZGWZaakEndpoint(): void
    {
        isset($this->io) && $this->io->writeln(['', '<info>Updating zgw zaak endpoint</info>']);
        $endpoint = $this->entityManager->getRepository('App:Endpoint')->findOneBy(['name' => 'Zaak']);
        $endpoint->setThrows(['zgw.zaak.saved']);
        $this->entityManager->persist($endpoint);
    }


    /**
     * Checks if ZGWBundle is installed
     * 
     * If not we cant install this XxllncZGWBundle.
     *
     * @return bool true if installed, false if not
     */
    private function isZGWBundleInstalled()
    {
        $ZGWZaak = $this->schemaRepository->findOneBy(['reference' => 'https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json']);
        if (!$ZGWZaak) {
            isset($this->io) && $this->io->error('ZGWBundle not installed, please make sure that bundle is installed before this one');

            return false;
        }
        isset($this->io) && $this->io->info('ZGWBundle is installed, continueing..');

        return true;
    }

    /**
     * Checks if we need to create/update objects
     *
     * @return void
     */
    public function checkDataConsistency(): void
    {
        // Return if zgw is not installed
        if ($this->isZGWBundleInstalled() == false) {
            return;
        }

        // Lets create some generic dashboard cards
        $this->createDashboardCards();

        // create cronjobs
        $this->createCronjobs();

        // Get schema ID's
        $xxllncZaakPost = $this->schemaRepository->findOneBy(['name' => 'XxllncZaakPost']);

        // Sources
        $this->createSource();

        // A default zgw catalogus
        $this->createCatalogus();

        // Actions
        $this->addActions();
        // $this->createActionsOld(); // disabled cus old

        // Translations
        $this->createTranslations();

        // Update attributes
        $this->updateAttributes($xxllncZaakPost);

        // Update zgw zaak endpoint to throw event that gets the zaak to xxllnc
        $this->updateZGWZaakEndpoint($xxllncZaakPost);

        $this->entityManager->flush();
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
