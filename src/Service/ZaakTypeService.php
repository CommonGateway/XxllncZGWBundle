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

use App\Entity\Entity as Schema;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
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
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

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
     * @var ObjectRepository|null
     */
    private ?ObjectRepository $sourceRepo;

    /**
     * @var ObjectRepository|null
     */
    private ?ObjectRepository $mappingRepo;

    /**
     * @var Source|null
     */
    private ?Source $xxllncAPI;

    /**
     * @var Schema|null
     */
    private ?Schema $zaakTypeSchema;

    /**
     * @var Schema|null
     */
    private ?Schema $rolTypeSchema;

    /**
     * @var Mapping|null
     */
    private ?Mapping $caseTypeMapping;

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
        CallService $callService
    ) {
        $this->entityManager          = $entityManager;
        $this->synchronizationService = $synchronizationService;
        $this->callService            = $callService;

        $this->objectRepo  = $this->entityManager->getRepository('App:ObjectEntity');
        $this->schemaRepo  = $this->entityManager->getRepository('App:Entity');
        $this->sourceRepo  = $this->entityManager->getRepository('App:Gateway');
        $this->mappingRepo = $this->entityManager->getRepository('App:Mapping');

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
        $this->getXxllncAPI();

        try {
            isset($this->style) === true && $this->style->info("Fetching casetype: $caseTypeID");
            $response = $this->callService->call($this->xxllncAPI, "/casetype/$caseTypeID", 'GET', [], false, false);
            $caseType = $this->callService->decodeResponse($this->xxllncAPI, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to fetch casetype: $caseTypeID, message:  {$e->getMessage()}");

            return null;
        }

        return $this->caseTypeToZaakType($caseType);

    }//end getZaakType()


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
        $zaakTypeArray['roltypen'] = [];
        $preventDupedRolTypen      = [];

        // Manually map phases to statustypen.
        if (isset($caseType['instance']['phases'])) {
            $zaakTypeArray['statustypen']   = [];
            $zaakTypeArray['eigenschappen'] = [];

            foreach ($caseType['instance']['phases'] as $phase) {
                // Mapping maken voor status.
                $statusTypeArray                                                               = [];
                isset($phase['name']) && $statusTypeArray['omschrijving']                      = $phase['name'];
                isset($phase['fields'][0]['label']) ? $statusTypeArray['omschrijvingGeneriek'] = $phase['fields'][0]['label'] : 'geen omschrijving';
                isset($phase['fields'][0]['help']) ? $statusTypeArray['statustekst']           = $phase['fields'][0]['help'] : 'geen statustekst';
                isset($phase['seq']) && $statusTypeArray['volgnummer']                         = $phase['seq'];

                if (isset($phase['fields'])) {
                    foreach ($phase['fields'] as $field) {
                        isset($field['magic_string']) && $zaakTypeArray['eigenschappen'][] = [
                            'naam'      => $field['magic_string'],
                            'definitie' => $field['magic_string'],
                        ];
                    }
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
                    $rolTypeObject = ($this->objectRepo->findOneBy(['externalId' => $phase['route']['role']['reference']]) ?? new ObjectEntity($this->rolTypeSchema));
                    $rolTypeObject->setExternalId($phase['route']['role']['reference']);
                    // use external id so we can find this object when sending case to xxllnc
                    $rolTypeObject->hydrate($rolTypeArray);
                    $this->entityManager->persist($rolTypeObject);
                    $zaakTypeArray['roltypen'][] = $rolTypeObject;
                }//end if

                $zaakTypeArray['statustypen'][] = $statusTypeArray;
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
     * Makes sure this action has the xxllnc api source.
     *
     * @return bool|null false if some object couldn't be fetched.
     */
    private function getXxllncAPI()
    {
        // Get xxllnc source
        if (isset($this->xxllncAPI) === false && ($this->xxllncAPI = $this->sourceRepo->findOneBy(['reference' => 'https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json'])) === null) {
            isset($this->style) === true && $this->style->error('Could not find Source: Xxllnc API');

            return false;
        }//end if

    }//end getXxllncAPI()


    /**
     * Makes sure this action has the ZaakTypeSchema.
     *
     * @return bool|null false if some object couldn't be fetched.
     */
    private function getZaakTypeSchema()
    {
        // Get ZaakType schema
        if (isset($this->zaakTypeSchema) === false && ($this->zaakTypeSchema = $this->schemaRepo->findOneBy(['name' => 'ZaakType'])) === null) {
            isset($this->style) === true && $this->style->error('Could not find Schema: ZaakType');

            return false;
        }//end if

    }//end getZaakTypeSchema()


    /**
     * Makes sure this action has all the gateway objects it needs.
     *
     * @return bool false if some object couldn't be fetched.
     */
    private function hasRequiredGatewayObjects(): bool
    {
        $this->getXxllncAPI();
        $this->getZaakTypeSchema();

        // Get ZaakType schema.
        if (isset($this->rolTypeSchema) === false && ($this->rolTypeSchema = $this->schemaRepo->findOneBy(['name' => 'RolType'])) === null) {
            isset($this->style) === true && $this->style->error('Could not find Schema: RolType');

            return false;
        }//end if

        // Get Catalogus object.
        $catalogusSchema = $this->schemaRepo->findOneBy(['reference' => 'https://vng.opencatalogi.nl/schemas/ztc.catalogus.schema.json']);
        if ($catalogusSchema === null || (isset($this->catalogusObject) === false && ($this->catalogusObject = $this->objectRepo->findOneBy(['entity' => $catalogusSchema])) === null)) {
            isset($this->style) === true && $this->style->error('Could not find schema: https://vng.opencatalogi.nl/schemas/ztc.catalogus.schema.json or a catalogus object');

            return false;
        }//end if

        if (isset($this->caseTypeMapping) === false && ($this->caseTypeMapping = $this->mappingRepo->findOneBy(['reference' => 'https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseTypeToZGWZaakType.mapping.json'])) === null) {
            isset($this->style) === true && $this->style->error('No mapping found for https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseTypeToZGWZaakType.mapping.json');

            return false;
        }//end if

        return true;

    }//end hasRequiredGatewayObjects()


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
        $this->hasRequiredGatewayObjects();
        isset($caseType['result']) === true && $caseType = $caseType['result'];

        // Check for id.
        if (isset($caseType['reference']) === false) {
            isset($this->style) === true && $this->style->error('CaseType has no id (reference)');

            return null;
        }//end if

        // Get or create sync and map object.
        $synchronization = $this->synchronizationService->findSyncBySource($this->xxllncAPI, $this->zaakTypeSchema, $caseType['reference']);
        $synchronization->setMapping($this->caseTypeMapping);
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
        $flush && $this->entityManager->flush();

        isset($this->style) === true && $this->style->success("Created/updated zaaktype: $zaakTypeID");

        return $synchronization->getObject();

    }//end caseTypeToZaakType()


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

        // Get schemas, sources and other gateway objects.
        if ($this->hasRequiredGatewayObjects() === false) {
            return null;
        }//end if

        // Fetch the xxllnc casetypes.
        isset($this->style) === true && $this->style->info('Fetching xxllnc casetypes');

        try {
            $xxllncCaseTypes = $this->callService->getAllResults($this->xxllncAPI, '/casetype', [], 'result.instance.rows');
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to fetch: {$e->getMessage()}");

            return null;
        }

        $caseTypeCount = count($xxllncCaseTypes);
        isset($this->style) === true && $this->style->success("Fetched $caseTypeCount casetypes");

        $createdZaakTypeCount = 0;
        $flushCount           = 0;
        foreach ($xxllncCaseTypes as $caseType) {
            if ($this->caseTypeToZaakType($caseType, false)) {
                $createdZaakTypeCount = ($createdZaakTypeCount + 1);
                $flushCount           = ($flushCount + 1);
            }//end if

            // Flush every 20.
            if ($flushCount == 20) {
                $this->entityManager->flush();
                $flushCount = 0;
            }//end if
        }//end foreach

        isset($this->style) === true && $this->style->success("Created $createdZaakTypeCount zaaktypen from the $caseTypeCount fetched casetypes");

    }//end zaakTypeHandler()


}//end class
