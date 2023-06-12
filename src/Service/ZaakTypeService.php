<?php
/**
 * This class handles the synchronizations of xxllnc casetypes to zgw ztc zaaktypen.
 *
 * By fetching, mapping and creating synchronizations.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Service
 */

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;

class ZaakTypeService
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $style;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var ObjectRepository|null
     */
    private ?ObjectRepository $objectRepo;

    /**
     * @var ObjectRepository|null
     */
    private ?ObjectRepository $schemaRepo;

    /**
     * @var ObjectEntity|null
     */
    private ?ObjectEntity $catalogusObject;

    /**
     * @var array
     */
    private array $skeletonIn;


    /**
     * __construct.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        SynchronizationService $synchronizationService,
        CallService            $callService,
        CacheService           $cacheService,
        GatewayResourceService $resourceService,
        MappingService         $mappingService
    ) {
        $this->entityManager          = $entityManager;
        $this->synchronizationService = $synchronizationService;
        $this->callService            = $callService;
        $this->cacheService           = $cacheService;
        $this->resourceService        = $resourceService;
        $this->mappingService         = $mappingService;

        $this->objectRepo = $this->entityManager->getRepository('App:ObjectEntity');
        $this->schemaRepo = $this->entityManager->getRepository('App:Entity');

        // @todo add this to a mapping.
        $this->skeletonIn = [
            'handelingInitiator'   => 'indienen',
            'beginGeldigheid'      => '1970-01-01',
            'versieDatum'          => '1970-01-01',
            'doel'                 => 'Overzicht hebben van de bezoekers die aanwezig zijn',
            'versiedatum'          => '1970-01-01',
            'handelingBehandelaar' => 'Hoofd beveiliging',
            'aanleiding'           => 'Er is een afspraak gemaakt met een (niet) natuurlijk persoon',
        ];

    }//end __construct()


    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $style
     *
     * @return self
     *
     * @todo change to monolog
     */
    public function setStyle(SymfonyStyle $style): self
    {
        $this->style = $style;

        return $this;

    }//end setStyle()


    /**
     * Fetches a xxllnc casetype and maps it to a zgw zaaktype.
     *
     * @param string $caseTypeId This is the xxllnc casetype id.
     *
     * @return object|null $zaakTypeObject Fetched and mapped ZGW ZaakType.
     */
    public function getZaakType(string $caseTypeID)
    {
        $xxllncAPI = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'common-gateway/xxllnc-zgw-bundle');

        try {
            isset($this->style) === true && $this->style->info("Fetching casetype: $caseTypeID");
            $response = $this->callService->call($xxllncAPI, "/casetype/$caseTypeID", 'GET', [], false, false);
            $caseType = $this->callService->decodeResponse($xxllncAPI, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to fetch casetype: $caseTypeID, message:  {$e->getMessage()}");

            return null;
        }

        // If this is a besluittype disguised as a casetype, create a ZGW BesluitType.
        if ($this->isBesluitType($caseType) === true) {
            isset($this->style) === true && $this->style->info('CaseType seen as a BesluitType, creating a BesluitType.');

            return $this->caseTypeToBesluitType($caseType);
        }

        // Else create a normal ZGW ZaakType.
        $caseType        = $caseType['result'];
        $caseType['url'] = $xxllncAPI->getLocation().'/casetype/'.$caseType['reference'];
        return $this->syncCaseType($caseType);

    }//end getZaakType()


    /**
     * Makes sure this action has all the gateway objects it needs.
     *
     * @return bool false if some object couldn't be fetched.
     */
    private function getCatalogusObject(): ?bool
    {
        // Get Catalogus object.
        $catalogusSchema = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.catalogus.schema.json', 'common-gateway/xxllnc-zgw-bundle');

        if ($catalogusSchema === null) {
            isset($this->style) === true && $this->style->error('Could not find schema: https://vng.opencatalogi.nl/schemas/ztc.catalogus.schema.json.');

            return false;
        }

        if (isset($this->catalogusObject) === false
            && ($this->catalogusObject = $this->objectRepo->findOneBy(['entity' => $catalogusSchema])) === null
        ) {
            $this->catalogusObject = new ObjectEntity($catalogusSchema);
            $this->catalogusObject->hydrate(
                [
                    "id"                       => "d3de83d2-aa64-4d34-a9d1-ea07c5c6b045",
                    "domein"                   => "http://localhost",
                    "contactpersoonBeheerNaam" => "Conduction",
                ]
            );
            $this->entityManager->persist($this->catalogusObject);
        }//end if

        return true;

    }//end getCatalogusObject()


    /**
     * Sets default values.
     *
     * @param array $zaakTypeArray
     *
     * @return array $zaakTypeArray
     */
    private function setDefaultValues($zaakTypeArray)
    {
        foreach ($this->skeletonIn as $key => $data) {
            if (isset($zaakTypeArray[$key]) === false) {
                $zaakTypeArray[$key] = $data;
            }//end if
        }//end foreach

        return $zaakTypeArray;

    }//end setDefaultValues()


    /**
     * Synchronises a casetype to zgw zaaktype based on the data retrieved from the Xxllnc api.
     *
     * @param array $caseType The caseType to synchronize.
     * @param bool  $flush    Wether or not the casetype should be flushed already (not functional at this time)
     *
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\SyntaxError
     *
     * @return ObjectEntity The resulting zaaktype object.
     */
    public function syncCaseType(array $caseType, bool $flush = true): ObjectEntity
    {
        $this->getCatalogusObject();

        $zaakTypeSchema  = $this->resourceService->getSchema(
            'https://vng.opencatalogi.nl/schemas/ztc.zaakType.schema.json',
            'common-gateway/xxllnc-zgw-bundle'
        );
        $xxllncAPI       = $this->resourceService->getSource(
            'https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json',
            'common-gateway/xxllnc-zgw-bundle'
        );
        $caseTypeMapping = $this->resourceService->getMapping(
            'https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseTypeToZGWZaakType.mapping.json',
            'common-gateway/xxllnc-zgw-bundle'
        );

        $zaakTypeArray              = $this->mappingService->mapping($caseTypeMapping, $caseType);
        $zaakTypeArray['catalogus'] = $this->catalogusObject;
        $zaakTypeArray              = $this->setDefaultValues($zaakTypeArray);

        $hydrationService = new HydrationService($this->synchronizationService, $this->entityManager);

        $zaakType = $hydrationService->searchAndReplaceSynchronizations(
            $zaakTypeArray,
            $xxllncAPI,
            $zaakTypeSchema,
            $flush
        );

        return $zaakType;

    }//end syncCaseType()


    /**
     * Creates or updates a informatieObjecttype.
     *
     * @param Source $xxllncAPI The xxllnc api source
     * @param array  $phases    The phases of the besluittype
     *
     * @return ObjectEntity|null
     */
    public function createInformatieObjecttype(Source $xxllncAPI, array $phases): ?ObjectEntity
    {
        $informatieobjectSchema  = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.informatieObjectType.schema.json', 'common-gateway/xxllnc-zgw-bundle');
        $informatieobjectMapping = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncInformatieObjectTypeToZGWInformatieObjectType.mapping.json', 'common-gateway/xxllnc-zgw-bundle');

        $fields = null;
        foreach ($phases as $phase) {
            if (key_exists('fields', $phase) === false) {
                continue;
            }

            if ($phase['fields'] !== []) {
                $fields = $phase['fields'];
            }
        }

        foreach ($fields as $field) {
            if (key_exists('original_label', $field)
                && $field['original_label'] === 'ZGW Besluit informatieobjecttypen'
            ) {
                // Get or create sync and map object.
                $synchronization = $this->synchronizationService->findSyncBySource($xxllncAPI, $informatieobjectSchema, $field['catalogue_id']);
                $synchronization->setMapping($informatieobjectMapping);
                $synchronization = $this->synchronizationService->synchronize($synchronization, $field);

                return $synchronization->getObject();
            }
        }

        return null;

    }//end createInformatieObjecttype()


    /**
     * Updates a besluittype to the catalogus.
     *
     * @param array  $besluittypeArray The besluittype array
     * @param string $besluittypeId    The besluittype id
     *
     * @return void
     */
    public function setBesluittypeToCatalogus(array $besluittypeArray, string $besluittypeId): void
    {
        // Update catalogus with new besluittype.
        isset($this->style) === true && $this->style->info("Updating catalogus: {$this->catalogusObject->getId()->toString()} with besluittype: $besluittypeId");

        $linkedBesluittypen = [];
        if (($besluittypen = $this->catalogusObject->getValue('besluittypen')) !== false) {
            foreach ($besluittypen as $besluittype) {
                $linkedBesluittypen[] = $besluittype->getId()->toString();
            }
        }

        // Merge the besluittype from the catalogus with the current besluittype.
        $mergedBesluittypen = array_merge($linkedBesluittypen, [$besluittypeId]);
        // Remove duplicate values from array.
        $mergedBesluittypen = array_unique($mergedBesluittypen);

        // Set the besluittype to the catalogus.
        $this->catalogusObject->setValue('besluittypen', $mergedBesluittypen);
        $this->entityManager->persist($this->catalogusObject);
        $this->entityManager->flush();
        $this->entityManager->flush();
        // The besluittype are only visable with the second flush.
    }//end setBesluittypeToCatalogus()


    /**
     * Creates or updates a casetype to besluittype.
     *
     * @param array $caseType CaseType from the Xxllnc API
     * @param bool  $flush    Do we need to flush here
     *
     * @return void|null
     */
    public function caseTypeToBesluitType(array $caseType, bool $flush = true): void
    {
        $besluittypeSchema  = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.besluitType.schema.json', 'common-gateway/xxllnc-zgw-bundle');
        $xxllncAPI          = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'common-gateway/xxllnc-zgw-bundle');
        $besluittypeMapping = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncBesluitTypeToZGWBesluitType.mapping.json', 'common-gateway/xxllnc-zgw-bundle');
        $this->getCatalogusObject();

        $caseType = $caseType['result'];

        isset($this->style) === true && $this->style->info("Sync and mapping besluittype with sourceId: {$caseType['reference']}");

        // Get or create sync and map object.
        $synchronization = $this->synchronizationService->findSyncBySource($xxllncAPI, $besluittypeSchema, $caseType['reference']);
        $synchronization->setMapping($besluittypeMapping);
        $synchronization = $this->synchronizationService->synchronize($synchronization, $caseType);

        // Get besluittype object from sync.
        $besluittypeObject = $synchronization->getObject();

        // Create informatieobjecttypen array and set it to the besluittype object.
        $informatieobjecttypen[] = $this->createInformatieObjecttype($xxllncAPI, $caseType['instance']['phases'])->getId()->toString();
        $besluittypeObject->setValue('informatieobjecttypen', $informatieobjecttypen);

        // Get catalogus and set it to the besluittype object.
        $catalogus = $this->catalogusObject->getId()->toString();
        $besluittypeObject->setValue('catalogus', $catalogus);

        // Set the besluittype to the catalogus.
        $this->setBesluittypeToCatalogus($besluittypeObject->toArray(), $besluittypeObject->getId()->toString());

        $this->entityManager->persist($besluittypeObject);
        $this->entityManager->flush();

        if (isset($this->style) === true) {
            $this->style->success("Created/updated zaaktype: {$besluittypeObject->getId()->toString()}");
        }

    }//end caseTypeToBesluitType()


    /**
     * Checks if we have to flush or not.
     *
     * @param $persistCount How many objects are persisted.
     *
     * @return int $persistCount How many objects are persisted, resets each 20 for optimization.
     */
    private function flush(int $persistCount): int
    {
        $persistCount = ($persistCount + 1);

        // Flush every 20.
        if ($persistCount == 20) {
            $this->entityManager->flush();
            $persistCount = 0;
        }

        return $persistCount;

    }//end flush()


    /**
     * Checks if the casetype is a besluittype disguised as a casetype.
     *
     * @param array $caseType A xxllnc casetype (potential besluittype).
     *
     * @return bool true if casetype is a besluittype.
     */
    private function isBesluitType(array $caseType): bool
    {
        if (key_exists('result', $caseType) === false) {
            return false;
        }

        // Check if the title of the casetype is one of the titles from the array (a besluittype).
        if (in_array($caseType['result']['instance']['title'], ['Besluit ', 'Besluit Toegekend', 'Besluit Afgewezen']) === true) {
            return true;
        }

        return false;

    }//end isBesluitType()


    /**
     * Creates or updates a ZGW ZaakType from a xxllnc casetype with the use of the CoreBundle.
     *
     * @param ?array $data          Data from the handler where the xxllnc casetype is in.
     * @param ?array $configuration Configuration from the Action where the ZaakType entity id is stored in.
     *
     * @return void|null
     *
     * @todo make function smaller and more readable.
     */
    public function zaakTypeHandler(?array $data = [], ?array $configuration = [])
    {
        isset($this->style) === true && $this->style->success('zaakType triggered');

        $xxllncAPI = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'common-gateway/xxllnc-zgw-bundle');

        // Fetch the xxllnc casetypes.
        isset($this->style) === true && $this->style->info('Fetching xxllnc casetypes');

        try {
            $xxllncCaseTypes = $this->callService->getAllResults($xxllncAPI, '/casetype', [], 'result.instance.rows');
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to fetch: {$e->getMessage()}");

            return null;
        }

        $caseTypeCount = count($xxllncCaseTypes);
        isset($this->style) === true && $this->style->success("Fetched $caseTypeCount casetypes");

        $createdZaakTypeCount    = 0;
        $createdBesluitTypeCount = 0;
        $persistCount            = 0;
        foreach ($xxllncCaseTypes as $caseType) {
            $caseType['url'] = $xxllncAPI->getLocation().'/casetype/'.$caseType['reference'];
            if ($this->isBesluitType($caseType) === true && $this->caseTypeToBesluitType($caseType, false)) {
                $createdBesluitTypeCount = ($createdBesluitTypeCount + 1);
                $persistCount            = $this->flush($persistCount);
                continue;
            }

            if ($this->syncCaseType($caseType, false) !== null) {
                $createdZaakTypeCount = ($createdZaakTypeCount + 1);
                $persistCount         = $this->flush($persistCount);
                continue;
            }
        }//end foreach

        isset($this->style) === true && $this->style->success("Created $createdBesluitTypeCount besluittypen from the $caseTypeCount fetched casetypes");
        isset($this->style) === true && $this->style->success("Created $createdZaakTypeCount zaaktypen from the $caseTypeCount fetched casetypes");

    }//end zaakTypeHandler()


    /**
     * Connects besluittype to the zaaktype.
     *
     * @param ObjectEntity $zaaktype The zgw zaaktype
     *
     * @return void
     */
    private function connectBesluittypeToZaaktype(ObjectEntity $caseType): void
    {
        $besluittypeSchema  = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.besluitType.schema.json', 'common-gateway/xxllnc-zgw-bundle');
        $besluittypeObjects = $this->objectRepo->findBy(['entity' => $besluittypeSchema]);

        if (isset($this->style) === true) {
            $this->style->info("Get the besluittypen we want to add to the casetype with id: {$caseType->getId()->toString()}");
        }

        // Get the ids of the besluittypen we want to add to the given zaaktype.
        $besluittypeIds = [];
        foreach ($besluittypeObjects as $besluittypeObject) {
            if (in_array($besluittypeObject->getValue('omschrijving'), ['Besluit ', 'Besluit Toegekend', 'Besluit Afgewezen']) === true) {
                $besluittypeIds[] = $besluittypeObject->getId()->toString();
            }
        }

        if (isset($this->style) === true) {
            $this->style->info("Get the besluittypen from the casetype with id: {$caseType->getId()->toString()}");
        }

        // Get the ids of the besluittypen from the given zaaktype.
        $linkedBesluittypeIds = [];
        if (($besluittypen = $caseType->getValue('besluittypen')) !== false) {
            foreach ($besluittypen as $item) {
                $linkedBesluittypeIds[] = $item->getId()->toString();
            }
        }

        // Merge the besluittype from the zaaktypes with the besluittypen we want to add.
        $mergedBesluittypen = array_merge($linkedBesluittypeIds, $besluittypeIds);
        // Remove duplicate values from array.
        $mergedBesluittypen = array_unique($mergedBesluittypen);

        if (isset($this->style) === true) {
            $this->style->info("Set the besluittypen to the casetype with id: {$caseType->getId()->toString()}");
        }

        $caseType->setValue('besluittypen', $mergedBesluittypen);
        $this->entityManager->persist($caseType);
        $this->entityManager->flush();
        $this->entityManager->flush();
        // The besluittype are only visable with the second flush.
    }//end connectBesluittypeToZaaktype()


    /**
     * Creates or updates a ZGW ZaakType from a xxllnc casetype with the use of the CoreBundle.
     *
     * @param ?array $data          Data from the handler where the xxllnc casetype is in.
     * @param ?array $configuration Configuration from the Action where the ZaakType entity id is stored in.
     *
     * @return void|null
     *
     * @todo make function smaller and more readable.
     */
    public function connectBesluittypeToZaaktypeHandler(?array $data = [], ?array $configuration = [], ?string $zaaktypeId = null)
    {
        $zaaktypeSchema = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.zaakType.schema.json', 'common-gateway/xxllnc-zgw-bundle');

        if ($zaaktypeId !== null) {
            if (isset($this->style) === true) {
                $this->style->info("Get zaaktype with id: $zaaktypeId");
            }

            // Get the zaaktype with the given id.
            $zaaktypeObject = $this->objectRepo->find($zaaktypeId);

            if ($zaaktypeObject instanceof ObjectEntity === false) {
                return null;
            }

            // Connects besluittypen to the zaaktype.
            $this->connectBesluittypeToZaaktype($zaaktypeObject);
        }//end if

        if ($zaaktypeId === null) {
            if (isset($this->style) === true) {
                $this->style->info('Get all zaaktype');
            }

            // Get all zaaktype objects.
            $zaaktypeObjects = $this->objectRepo->findBy(['entity' => $zaaktypeSchema]);
            foreach ($zaaktypeObjects as $zaaktypeObject) {
                if ($zaaktypeObject instanceof ObjectEntity === false) {
                    continue;
                }

                // Connects besluittypen to the zaaktype.
                $this->connectBesluittypeToZaaktype($zaaktypeObject);
            }
        }//end if

    }//end connectBesluittypeToZaaktypeHandler()


}//end class
