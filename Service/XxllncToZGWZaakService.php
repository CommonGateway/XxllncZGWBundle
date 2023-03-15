<?php

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;


/**
 * This class handles the synchronizations of xxllnc cases to zgw zrc zaken.
 *
 * By fetching, mapping and creating synchronizations.
 *
 * @author Barry Brands <barry@conduction.nl>
 *
 * @category Service
 */
class XxllncToZGWZaakService
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var XxllncToZGWZaakTypeService
     */
    private XxllncToZGWZaakTypeService $xxllncToZGWZaakTypeService;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var ObjectRepository
     */
    private ObjectRepository $objectRepo;

    /**
     * @var ObjectRepository
     */
    private ObjectRepository $schemaRepo;

    /**
     * @var ObjectRepository
     */
    private ObjectRepository $sourceRepo;

    /**
     * @var ObjectRepository
     */
    private ObjectRepository $mappingRepo;

    /**
     * @var ObjectRepository
     */
    private ObjectRepository $synchronizationRepo;

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
    private ?Schema $zaakSchema;

    /**
     * @var Mapping|null
     */
    private ?Mapping $caseMapping;

    private array $skeletonIn;

    /**
     * __construct
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        SynchronizationService $synchronizationService,
        CallService $callService,
        XxllncToZGWZaakTypeService $xxllncToZGWZaakTypeService
    ) {
        $this->entityManager = $entityManager;
        $this->synchronizationService = $synchronizationService;
        $this->callService = $callService;
        $this->xxllncToZGWZaakTypeService = $xxllncToZGWZaakTypeService;

        $this->objectRepo = $this->entityManager->getRepository('App:ObjectEntity');
        $this->schemaRepo = $this->entityManager->getRepository('App:Entity');
        $this->sourceRepo = $this->entityManager->getRepository('App:Gateway');
        $this->synchronizationRepo = $this->entityManager->getRepository('App:Synchronization');
        $this->mappingRepo = $this->entityManager->getRepository('App:Mapping');

        // @todo add this to a mapping
        $this->skeletonIn = [
            'verantwoordelijkeOrganisatie' => '070124036',
            'betalingsindicatie'           => 'geheel',
            'betalingsindicatieWeergave'   => 'Bedrag is volledig betaald',
            'laatsteBetaalDatum'           => '15-07-2022',
            'archiefnominatie'             => 'blijvend_bewaren',
            'archiefstatus'                => 'nog_te_archiveren',
        ];
    }//end __construct())

    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $io
     *
     * @return self
     * 
     * @todo change to monolog
     */
    public function setStyle(SymfonyStyle $io): self
    {
        $this->io = $io;

        return $this;
    }//end setStyle()

    /**
     * Maps the eigenschappen from xxllnc to zgw.
     *
     * @param array      $zaakArray     This is the ZGW Zaak array.
     * @param array      $zaakTypeArray This is the ZGW ZaakType array.
     * @param attributes $ar            This is the xxllnc attributes array that will be mapped to eigenschappen.
     *
     * @return array $zaakArray This is the ZGW Zaak array with the added eigenschappen.
     */
    private function mapEigenschappen(array $zaakArray, array $zaakTypeArray, ObjectEntity $zaakTypeObjectEntity, array $attributes): array
    {
        // Manually map properties to eigenschappen
        if (!isset($zaakTypeArray['eigenschappen'])) {
            $eigenschappen = [];
            foreach ($attributes as $attributeName => $attributeValue) {
                $eigenschappen[] = [
                    'naam'      => $attributeName,
                    'definitie' => $attributeName,
                ];
            }
            $zaakTypeObjectEntity->setValue('eigenschappen', $eigenschappen);
            $this->entityManager->persist($zaakTypeObjectEntity);
            $this->entityManager->flush();
        }

        $zaakTypeArray = $zaakTypeObjectEntity->toArray();

        !isset($zaakArray['eigenschappen']) && $zaakArray['eigenschappen'] = [];
        foreach ($attributes as $attributeName => $attributeValue) {
            foreach ($zaakTypeArray['eigenschappen'] as $eigenschap) {
                if ($eigenschap['naam'] == $attributeName) {
                    $zaakArray['eigenschappen'][] = [
                        'naam'   => $attributeName,
                        'waarde' => is_array($attributeValue) ?
                            json_encode($attributeValue) :
                            (string) $attributeValue,
                        'eigenschap' => $this->objectRepo->find($eigenschap['_self']['id']),
                    ];
                }
            }
        }

        return $zaakArray;
    }//end mapEigenschappen()

    /**
     * Maps the rollen from xxllnc to zgw.
     *
     * @param array $zaakArray     This is the ZGW Zaak array.
     * @param array $zaakTypeArray This is the ZGW ZaakType array.
     * @param array $rol           This is the xxllnc Rol array.
     *
     * @return array $zaakArray This is the ZGW Zaak array with the added rollen.
     */
    private function mapRollen(array $zaakArray, array $zaakTypeArray, array $rol): array
    {
        $zaakArray['rollen'] = [];
        foreach ($zaakTypeArray['roltypen'] as $rolType) {
            if (strtolower($rol['preview']) == strtolower($rolType['omschrijving'])) {
                $zaakArray['rollen'][] = [
                    'roltype'              => $this->objectRepo->find($rolType['_self']['id']),
                    'omschrijving'         => $rol['preview'],
                    'omschrijvingGeneriek' => strtolower($rol['preview']),
                    'roltoelichting'       => $rol['instance']['description'],
                    'betrokkeneType'       => 'natuurlijk_persoon',
                ];
            }
        }

        return $zaakArray;
    }//end mapRollen()

    /**
     * Maps the status from xxllnc to zgw.
     *
     * @param array $zaakArray     This is the ZGW Zaak array.
     * @param array $zaakTypeArray This is the ZGW ZaakType array.
     * @param array $status        This is the xxllnc Status array.
     *
     * @return array $zaakArray This is the ZGW Zaak array with the added status.
     */
    private function mapStatus(array $zaakArray, array $zaakTypeArray, array $status): array
    {
        foreach ($zaakTypeArray['statustypen'] as $statusType) {
            if ($status['preview'] == $statusType['omschrijving']) {
                $zaakArray['status'] = [
                    'statustype'        => $this->objectRepo->find($statusType['_self']['id']),
                    'datumStatusGezet'  => isset($status['instance']['date_modified']) ? $status['instance']['date_modified'] : '2020-04-15',
                    'statustoelichting' => isset($status['instance']['milestone_label']) && (string) $status['instance']['milestone_label'],
                ];

                return $zaakArray;
            }
        }

        return $zaakArray;
    }//end mapStatus()

    /**
     * Gets a existing ZaakType or syncs one from the xxllnc api.
     *
     * @var string xxllnc casetype
     *
     * @return ObjectEntity|null $zaakType object or null
     */
    public function getZaakTypeByExtId(string $caseTypeId)
    {
        // Find existing zaaktype
        $zaakTypeSync = $this->synchronizationService->findSyncBySource($this->xxllncAPI, $this->zaakTypeSchema, $caseTypeId);
        if ($zaakTypeSync && $zaakTypeSync->getObject()) {
            isset($this->io) && $this->io->info("Found a existing zaaktype with sourceId: $caseTypeId and gateway id: {$zaakTypeSync->getObject()->getId()->toString()}");

            return $zaakTypeSync->getObject();
        }

        // Fetch and create new zaaktype
        $zaakTypeObject = $this->xxllncToZGWZaakTypeService->getZaakType($caseTypeId);
        if ($zaakTypeObject) {
            return $zaakTypeObject;
        }

        isset($this->io) && $this->io->error("Could not find or create ZaakType for id: $caseTypeId");

        return null;
    }//end getZaakTypeByExtId()

    /**
     * Makes sure this action has all the gateway objects it needs.
     *
     * @return bool false if some object couldn't be fetched
     */
    private function getRequiredGatewayObjects(): bool
    {
        // Get xxllnc source
        if (!isset($this->xxllncAPI) && !$this->xxllncAPI = $this->sourceRepo->findOneBy(['location' => 'https://development.zaaksysteem.nl/api/v1'])) {
            isset($this->io) && $this->io->error('Could not find Source: Xxllnc API');

            return false;
        }

        // Get ZaakType schema
        if (!isset($this->zaakTypeSchema) && !$this->zaakTypeSchema = $this->schemaRepo->findOneBy(['name' => 'ZaakType'])) {
            isset($this->io) && $this->io->error('Could not find Schema: ZaakType');

            return false;
        }

        // Get Zaak schema
        if (!isset($this->zaakSchema) && !$this->zaakSchema = $this->schemaRepo->findOneBy(['name' => 'Zaak'])) {
            isset($this->io) && $this->io->error('Could not find Schema: Zaak');

            return false;
        }

        if (!isset($this->caseMapping) && !$this->caseMapping = $this->mappingRepo->findOneBy(['reference' => 'https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseToZGWZaak.mapping.json'])) {
            isset($this->io) && $this->io->error('No mapping found for https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseToZGWZaak.mapping.json');

            return false;
        }

        return true;
    }//end getRequiredGatewayObjects()

    /**
     * Sets default values.
     *
     * @param array $zaakArray
     *
     * @return array $zaakArray
     */
    private function setDefaultValues($zaakArray)
    {
        foreach ($this->skeletonIn as $key => $data) {
            if (!isset($zaakArray[$key])) {
                $zaakArray[$key] = $data;
            }
        }

        return $zaakArray;
    }//end setDefaultValues()

    /**
     * Checks if we have a reference in our case.
     *
     * @param array $case xxllnc case object
     *
     * @return void|null
     */
    private function checkId(array $case)
    {
        // If no id found return null.
        if (!isset($case['reference'])) {
            isset($this->io) && $this->io->error('Case has no id (reference)');

            return null;
        }
    }//end checkId()

    /**
     * Checks if we have a casetype in our case and get a ZaakType.
     *
     * @param array $case xxllnc case object
     *
     * @return ObjectEntity|null
     */
    private function checkZaakType(array $case)
    {
        // If no casetype found return null
        if (!isset($case['instance']['casetype']['reference'])) {
            isset($this->io) && $this->io->error('Case has no casetype');

            return null;
        }

        // Get ZGW ZaakType
        isset($this->io) && $this->io->info("Trying to find ZaakType: {$case['instance']['casetype']['reference']}");
        $zaakTypeObject = $this->getZaakTypeByExtId($case['instance']['casetype']['reference']);
        if ($zaakTypeObject === null) {
            return null;
        }

        return $zaakTypeObject;
    }//end checkZaakType()

    /**
     * Checks and fetches or creates a Synchronization for this case.
     *
     * @param array $case xxllnc case object
     *
     * @return Synchronization
     */
    private function getSyncForCase(array $case): Synchronization
    {
        // Find or create synchronization object
        $synchronization = $this->synchronizationService->findSyncBySource($this->xxllncAPI, $this->zaakSchema, $case['reference']);
        $synchronization->setMapping($this->caseMapping);
        $synchronization = $this->synchronizationService->synchronize($synchronization, $case);

        return $synchronization;
    }//end getSyncForCase()

    /**
     * Creates ZGW Zaak subobjects.
     *
     * @param array        $zaakArray      ZGW Zaak
     * @param array        $zaakTypeArray  ZGW ZaakType
     * @param ObjectEntity $zaakTypeObject ZGW ZaakType object
     * @param array        $case           xxllnc case object
     *
     * @return array
     */
    private function createSubObjects(array $zaakArray, array $zaakTypeArray, ObjectEntity $zaakTypeObject, array $case): array
    {
        if (isset($zaakTypeArray['statustypen']) && isset($case['instance']['milestone'])) {
            $zaakArray = $this->mapStatus($zaakArray, $zaakTypeArray, $case['instance']['milestone']);
        }
        if (isset($zaakTypeArray['roltypen']) && isset($case['instance']['route']['instance']['role'])) {
            $zaakArray = $this->mapRollen($zaakArray, $zaakTypeArray, $case['instance']['route']['instance']['role']);
        }
        if (isset($zaakTypeArray['eigenschappen']) && isset($case['instance']['attributes'])) {
            $zaakArray = $this->mapEigenschappen($zaakArray, $zaakTypeArray, $zaakTypeObject, $case['instance']['attributes']);
        }

        return $zaakArray;
    }//end createSubObjects()

    /**
     * Creates or updates a case to zaak.
     *
     * @param array $case Case from the Xxllnc API
     *
     * @return void|null
     */
    public function caseToZaak(array $case)
    {
        $this->checkId($case);
        $zaakTypeObject = $this->checkZaakType($case);
        $zaakTypeArray = $zaakTypeObject->toArray();

        $synchronization = $this->getSyncForCase($case);
        $zaakObject = $synchronization->getObject();
        $zaakArray = $zaakObject->toArray();
        $zaakArray = $this->setDefaultValues($zaakArray);

        $zaakArray['zaaktype'] = $zaakTypeObject;

        // Manually map the xxllnc case to zgw zaak
        isset($this->io) && $this->io->info("Mapping case with sourceId: {$case['reference']}");
        $zaakArray = $this->createSubObjects($zaakArray, $zaakTypeArray, $zaakTypeObject, $case);

        $zaakObject->hydrate($zaakArray);
        $this->entityManager->persist($zaakObject);
        $zaakID = $zaakObject->getId()->toString();

        isset($this->io) && $this->io->success("Created/updated zaak: $zaakID");

        return $synchronization->getObject();
    }//end caseToZaak()

    /**
     * Creates or updates a ZGW Zaak from a xxllnc case with the use of mapping.
     *
     * @param ?array $data          Data from the handler where the xxllnc case is in.
     * @param ?array $configuration Configuration from the Action where the Zaak entity id is stored in.
     *
     * @return ?array $this->data Data which we entered the function with
     */
    public function xxllncToZGWZaakHandler(?array $data = [], ?array $configuration = [])
    {
        isset($this->io) && $this->io->success('xxllncToZGWZaak triggered');

        // Get schemas, sources and other gateway objects
        if (!$this->getRequiredGatewayObjects()) {
            return null;
        }

        isset($this->io) && $this->xxllncToZGWZaakTypeService->setStyle($this->io);

        // Fetch the xxllnc cases
        isset($this->io) && $this->io->info('Fetching xxllnc cases');

        try {
            $xxllncCases = $this->callService->getAllResults($this->xxllncAPI, '/case', [], 'result.instance.rows');
        } catch (Exception $e) {
            isset($this->io) && $this->io->error("Failed to fetch: {$e->getMessage()}");

            return null;
        }
        $caseCount = count($xxllncCases);
        isset($this->io) && $this->io->success("Fetched $caseCount cases");

        $createdZaakCount = 0;
        $flushCount = 0;
        foreach ($xxllncCases as $case) {
            if ($this->caseToZaak($case)) {
                $createdZaakCount = $createdZaakCount + 1;
                $flushCount = $flushCount + 1;
            }

            // Flush every 20
            if ($flushCount == 20) {
                $this->entityManager->flush();
                $flushCount = 0;
            }
        }

        isset($this->io) && $this->io->success("Created $createdZaakCount zaken from the $caseCount fetched cases");
    }//end xxllncToZGWZaakHandler()
}
