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
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $gatewayResourceService;

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
        CallService $callService,
        CacheService $cacheService,
        GatewayResourceService $gatewayResourceService
    ) {
        $this->entityManager          = $entityManager;
        $this->synchronizationService = $synchronizationService;
        $this->callService            = $callService;
        $this->cacheService           = $cacheService;
        $this->gatewayResourceService = $gatewayResourceService;

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
        $xxllncAPI = $this->gatewayResourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'common-gateway/xxllnc-zgw-bundle');

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
        return $this->caseTypeToZaakType($caseType);

    }//end getZaakType()


    /**
     * Maps a simple informatieobjecttype.
     *
     * @param array $field xxllnc field.
     *
     * @return array InformatieObjectType.
     */
    private function mapInformatieObjectType(array $field): array
    {
        return [
            'omschrijving'                => ($field['original_label'] ?? $field['label'] ?? $field['magic_string']),
            'vertrouwelijkheidaanduiding' => 'openbaar',
        ];

    }//end mapInformatieObjectType()


    /**
     * @TODO make function smaller and readable.
     *
     * Maps the statusTypen and rolTypen from xxllnc to zgw.
     *
     * @param array $caseType      This is the xxllcn casetype array.
     * @param array $zaakTypeArray This is the ZGW ZaakType array.
     *
     * @return array $zaakTypeArray This is the ZGW ZaakType array with the added statustypen.
     */
    private function mapStatusAndRolTypen(array $caseType, array $zaakTypeArray): array
    {
        $rolTypeSchema        = $this->gatewayResourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.rolType.schema.json', 'common-gateway/xxllnc-zgw-bundle');
        $statusTypeSchema     = $this->gatewayResourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.statusType.schema.json', 'common-gateway/xxllnc-zgw-bundle');
        $infoObjectTypeSchema = $this->gatewayResourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.informatieObjectType.schema.json', 'common-gateway/xxllnc-zgw-bundle');
        $eigenschapSchema     = $this->gatewayResourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.eigenschap.schema.json', 'common-gateway/xxllnc-zgw-bundle');

        $zaakTypeArray['roltypen'] = [];
        $preventDupedRolTypen      = [];

        // Manually map phases to statustypen.
        if (isset($caseType['instance']['phases'])) {
            $zaakTypeArray['statustypen']           = [];
            $zaakTypeArray['informatieobjecttypen'] = [];
            $zaakTypeArray['eigenschappen']         = [];

            // Phases are ZTC StatusTypen.
            foreach ($caseType['instance']['phases'] as $phase) {
                // Find or create new roltype object.
                $statusTypeObject = ($this->objectRepo->findOneBy(['externalId' => $phase['id'], 'entity' => $statusTypeSchema]) ?? new ObjectEntity($statusTypeSchema));
                $statusTypeObject->setExternalId($phase['id']);
                $newStatusTypeArray = [
                    'omschrijving'         => $phase['name'] ?? null,
                    'omschrijvingGeneriek' => ($phase['fields'][0]['label'] ?? 'geen omschrijving'),
                    'statustekst'          => ($phase['fields'][0]['help'] ?? 'geen statustekst'),
                    'volgnummer'           => $phase['seq'] ?? null,
                ];

                // Use external id so we can find this object when resyncing.
                $statusTypeObject->hydrate($newStatusTypeArray);
                $this->entityManager->persist($statusTypeObject);
                $zaakTypeArray['statustypen'][] = $statusTypeObject;

                // Fields can be mapped to ZTC Eigencshappen or ZTC InformatieObjectTypen.
                if (isset($phase['fields'])) {
                    foreach ($phase['fields'] as $field) {
                        // If type file map informatieobjecttype.
                        if ($field['type'] === 'file') {
                            $subObject = ($this->objectRepo->findOneBy(['externalId' => $field['id'], 'entity' => $infoObjectTypeSchema]) ?? new ObjectEntity($infoObjectTypeSchema));
                            $subObject->setExternalId($field['id']);
                            $subObjectArray = $this->mapInformatieObjectType($field);
                            $subObjectType  = 'informatieobjecttypen';
                        }
                        // else its a eigenschap.
                        else if (isset($field['magic_string']) === true) {
                            $subObject = ($this->objectRepo->findOneBy(['externalId' => $field['id'], 'entity' => $eigenschapSchema]) ?? new ObjectEntity($eigenschapSchema));
                            $subObject->setExternalId($field['id']);
                            $subObjectArray = [
                                'naam'      => $field['magic_string'],
                                'definitie' => $field['magic_string'],
                            ];
                            $subObjectType  = 'eigenschappen';
                        } else {
                            continue;
                        }

                        // Use external id so we can find this object when resyncing.
                        $subObject->hydrate($subObjectArray);
                        $this->entityManager->persist($subObject);
                        $zaakTypeArray[$subObjectType][] = $subObject;
                    }//end foreach
                }//end if

                // Map role to roltype.
                if (isset($phase['route']['role']['reference']) && isset($phase['route']['role']['instance']['name'])
                    && in_array(strtolower($phase['route']['role']['instance']['name']), $preventDupedRolTypen) === false
                ) {
                    $rolTypeArray                                                                          = [
                        'omschrijving'         => isset($phase['route']['role']['instance']['description']) ? $phase['route']['role']['instance']['description'] : null,
                        'omschrijvingGeneriek' => isset($phase['route']['role']['instance']['name']) ? strtolower($phase['route']['role']['instance']['name']) : null,
                    ];
                    isset($phase['route']['role']['instance']['name']) === true && $preventDupedRolTypen[] = strtolower($phase['route']['role']['instance']['name']);

                    // Find or create new roltype object.
                    $rolTypeObject = ($this->objectRepo->findOneBy(['externalId' => $phase['route']['role']['reference'], 'entity' => $rolTypeSchema]) ?? new ObjectEntity($rolTypeSchema));
                    $rolTypeObject->setExternalId($phase['route']['role']['reference']);
                    // use external id so we can find this object when sending case to xxllnc
                    $rolTypeObject->hydrate($rolTypeArray);
                    $this->entityManager->persist($rolTypeObject);
                    $zaakTypeArray['roltypen'][] = $rolTypeObject;
                }//end if
            }//end foreach
        }//end if

        return $zaakTypeArray;

    }//end mapStatusAndRolTypen()


    /**
     * Maps the resultaatTypen from xxllnc to zgw.
     *
     * @param array $caseType      This is the xxllnc casetype.
     * @param array $zaakTypeArray This is the ZGW ZaakType array.
     *
     * @return array $zaakTypeArray This is the ZGW ZaakType array with the added resultaattypen.
     */
    private function mapResultaatTypen(array $caseType, array $zaakTypeArray): array
    {
        // Manually map results to resultaattypen.
        if (isset($caseType['instance']['results']) === true) {
            $zaakTypeArray['resultaattypen'] = [];
            foreach ($caseType['instance']['results'] as $result) {
                $resultaatTypeArray                                                             = [];
                $result['type'] && $resultaatTypeArray['omschrijving']                          = $result['type'];
                $result['label'] && $resultaatTypeArray['toelichting']                          = $result['label'];
                $resultaatTypeArray['selectielijstklasse']                                      = ($result['selection_list'] ?? 'http://localhost');
                $result['type_of_archiving'] && $resultaatTypeArray['archiefnominatie']         = $result['type_of_archiving'];
                $result['period_of_preservation'] && $resultaatTypeArray['archiefactietermijn'] = $result['period_of_preservation'];

                $zaakTypeArray['resultaattypen'][] = $resultaatTypeArray;
            }//end foreach
        }//end if

        return $zaakTypeArray;

    }//end mapResultaatTypen()


    /**
     * Makes sure this action has all the gateway objects it needs.
     *
     * @return bool false if some object couldn't be fetched.
     */
    private function getCatalogusObject(): ?bool
    {
        // Get Catalogus object.
        $catalogusSchema = $this->gatewayResourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.catalogus.schema.json', 'common-gateway/xxllnc-zgw-bundle');

        if ($catalogusSchema === null) {
            isset($this->style) === true && $this->style->error('Could not find schema: https://vng.opencatalogi.nl/schemas/ztc.catalogus.schema.json.');

            return false;
        }

        if (isset($this->catalogusObject) === false
            && ($this->catalogusObject = $this->objectRepo->findOneBy(['entity' => $catalogusSchema])) === null
        ) {
            isset($this->style) === true && $this->style->error('Could not find catalogus object');

            return false;
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
     * Creates or updates a casetype to zaaktype.
     *
     * @param array $caseType CaseType from the Xxllnc API
     * @param bool  $flush    Do we need to flush here
     *
     * @var Synchronization
     *
     * @return void|null
     *
     * @todo make function smaller and more readable.
     */
    public function caseTypeToZaakType(array $caseType, bool $flush = true)
    {
        $this->getCatalogusObject();
        $zaakTypeSchema  = $this->gatewayResourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.zaakType.schema.json', 'common-gateway/xxllnc-zgw-bundle');
        $xxllncAPI       = $this->gatewayResourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'common-gateway/xxllnc-zgw-bundle');
        $caseTypeMapping = $this->gatewayResourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseTypeToZGWZaakType.mapping.json', 'common-gateway/xxllnc-zgw-bundle');

        isset($caseType['result']) === true && $caseType = $caseType['result'];

        // Check for id.
        if (isset($caseType['reference']) === false) {
            isset($this->style) === true && $this->style->error('CaseType has no id (reference)');

            return null;
        }//end if

        // Get or create sync and map object.
        $synchronization = $this->synchronizationService->findSyncBySource($xxllncAPI, $zaakTypeSchema, $caseType['reference']);
        $synchronization->setMapping($caseTypeMapping);
        isset($this->style) === true && $this->style->info("Mapping casetype with sourceId: {$caseType['reference']}");
        $synchronization = $this->synchronizationService->synchronize($synchronization, $caseType);
        $zaakTypeObject  = $synchronization->getObject();
        $zaakTypeArray   = $zaakTypeObject->toArray();
        $zaakTypeArray   = $this->setDefaultValues($zaakTypeArray);

        // Manually set array properties (cant map with twig).
        $zaakTypeArray['verantwoordingsrelatie'] = [$caseType['instance']['properties']['supervisor_relation']] ?? null;
        $zaakTypeArray['trefwoorden']            = $caseType['instance']['subject_types'] ?? null;

        // Manually map subobjects.
        $zaakTypeArray              = $this->mapStatusAndRolTypen($caseType, $zaakTypeArray);
        $zaakTypeArray              = $this->mapResultaatTypen($caseType, $zaakTypeArray);
        $zaakTypeArray['catalogus'] = $this->catalogusObject->getId()->toString();

        // Hydrate and persist.
        $zaakTypeObject->hydrate($zaakTypeArray);
        $this->entityManager->persist($zaakTypeObject);
        $zaakTypeID = $zaakTypeObject->getId()->toString();

        // Update catalogus with new zaaktype.
        isset($this->style) === true && $this->style->info("Updating catalogus: {$zaakTypeArray['catalogus']} with zaaktype: $zaakTypeID");
        $linkedZaakTypen = ($this->catalogusObject->getValue('zaaktypen')->toArray() ?? []);
        $this->catalogusObject->setValue('zaaktypen', array_merge($linkedZaakTypen, [$zaakTypeID]));
        $this->entityManager->persist($this->catalogusObject);

        // Flush here if we are only mapping one zaaktype and not loopin through more in a parent function.
        if ($flush === true) {
            $this->entityManager->flush();
            $this->entityManager->flush();
            $this->cacheService->cacheObject($zaakTypeObject);
        }

        isset($this->style) === true && $this->style->success("Created/updated zaaktype: $zaakTypeID");

        return $synchronization->getObject();

    }//end caseTypeToZaakType()


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
        $informatieobjectSchema  = $this->gatewayResourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.informatieObjectType.schema.json', 'common-gateway/xxllnc-zgw-bundle');
        $informatieobjectMapping = $this->gatewayResourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncInformatieObjectTypeToZGWInformatieObjectType.mapping.json', 'common-gateway/xxllnc-zgw-bundle');

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
        $besluittypeSchema  = $this->gatewayResourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.besluitType.schema.json', 'common-gateway/xxllnc-zgw-bundle');
        $xxllncAPI          = $this->gatewayResourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'common-gateway/xxllnc-zgw-bundle');
        $besluittypeMapping = $this->gatewayResourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncBesluitTypeToZGWBesluitType.mapping.json', 'common-gateway/xxllnc-zgw-bundle');
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

        $xxllncAPI = $this->gatewayResourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'common-gateway/xxllnc-zgw-bundle');

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
            if ($this->isBesluitType($caseType) === true && $this->caseTypeToBesluitType($caseType, false)) {
                $createdBesluitTypeCount = ($createdBesluitTypeCount + 1);
                $persistCount            = $this->flush($persistCount);
                continue;
            }

            if ($this->caseTypeToZaakType($caseType, false)) {
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
        $besluittypeSchema  = $this->gatewayResourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.besluitType.schema.json', 'common-gateway/xxllnc-zgw-bundle');
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
        $zaaktypeSchema = $this->gatewayResourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.zaakType.schema.json', 'common-gateway/xxllnc-zgw-bundle');

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
