<?php

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\ObjectEntity;
use App\Service\ObjectEntityService;
use App\Service\SynchronizationService;
use App\Service\TranslationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\Persistence\ObjectRepository;
use App\Entity\Gateway as Source;
use Exception;
use Symfony\Bridge\Twig\NodeVisitor\Scope;

class XxllncToZGWZaakTypeService
{
    private EntityManagerInterface $entityManager;
    private CallService $callService;
    private TranslationService $translationService;
    private ObjectEntityService $objectEntityService;
    private SynchronizationService $synchronizationService;
    private SymfonyStyle $io;
    private array $configuration;
    private array $data;

    private ObjectRepository $objectRepo;
    private ObjectRepository $schemaRepo;
    private ObjectRepository $sourceRepo;
    private ObjectRepository $mappingRepo;

    private ?Source $xxllncAPI;
    private ?Schema $zaakTypeSchema;
    private ?Schema $rolTypeSchema;
    // private ?Mapping $caseTypeMapping;

    private ?ObjectEntity $catalogusObject;

    private array $skeletonIn;

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        TranslationService $translationService,
        ObjectEntityService $objectEntityService,
        SynchronizationService $synchronizationService,
        CallService $callService
    ) {
        $this->entityManager = $entityManager;
        $this->translationService = $translationService;
        $this->objectEntityService = $objectEntityService;
        $this->synchronizationService = $synchronizationService;
        $this->callService = $callService;

        $this->objectRepo = $this->entityManager->getRepository(ObjectEntity::class);
        $this->schemaRepo = $this->entityManager->getRepository(Schema::class);
        $this->sourceRepo = $this->entityManager->getRepository(Source::class);
        // $this->mappingRepo = $this->entityManager->getRepository(Mapping::class);

        $this->skeletonIn = [
            'handelingInitiator'   => 'indienen',
            'beginGeldigheid'      => '1970-01-01',
            'versieDatum'          => '1970-01-01',
            'doel'                 => 'Overzicht hebben van de bezoekers die aanwezig zijn',
            'versiedatum'          => '1970-01-01',
            'handelingBehandelaar' => 'Hoofd beveiliging',
            'aanleiding'           => 'Er is een afspraak gemaakt met een (niet) natuurlijk persoon',
        ];
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

    /**
     * Maps the statusTypen and rolTypen from xxllnc to zgw.
     *
     * @param array $caseType This is the xxllcn casetype array.
     * @param array $zaakTypeArray This is the ZGW ZaakType array.
     *
     * @return array $zaakTypeArray This is the ZGW ZaakType array with the added statustypen.
     */
    private function mapStatusAndRolTypen(array $caseType, array $zaakTypeArray): array
    {
        $zaakTypeArray['roltypen'] = [];

        // Manually map phases to statustypen
        if (isset($caseType['instance']['phases'])) {
            $zaakTypeArray['statustypen'] = [];
            $zaakTypeArray['eigenschappen'] = [];

            foreach ($caseType['instance']['phases'] as $phase) {
                // Mapping maken voor status
                $statusTypeArray = [];
                isset($phase['name']) && $statusTypeArray['omschrijving'] = $phase['name'];
                isset($phase['fields'][0]['label']) ? $statusTypeArray['omschrijvingGeneriek'] = $phase['fields'][0]['label'] : 'geen omschrijving';
                isset($phase['fields'][0]['help']) ? $statusTypeArray['statustekst'] = $phase['fields'][0]['help'] : 'geen statustekst';
                isset($phase['seq']) && $statusTypeArray['volgnummer'] = $phase['seq'];

                if (isset($phase['fields'])) {
                    foreach ($phase['fields'] as $field) {
                        isset($field['magic_string']) && $zaakTypeArray['eigenschappen'][] = ['naam' => $field['magic_string'], 'definitie' => $field['magic_string']];
                    }
                }

                // Map role to roltype
                if (isset($phase['route']['role']['reference'])) {
                    $rolTypeArray = [
                        'omschrijving'         => isset($phase['route']['role']['instance']['description']) ? $phase['route']['role']['instance']['description'] : null,
                        'omschrijvingGeneriek' => isset($phase['route']['role']['instance']['name']) ? strtolower($phase['route']['role']['instance']['name']) : null,
                    ];
                    $rolTypeObject = new ObjectEntity($this->rolTypeSchema);
                    isset($phase['route']['role']['reference']) && $rolTypeObject->setExternalId($phase['route']['role']['reference']);
                    $rolTypeObject->hydrate($rolTypeArray);
                    $this->entityManager->persist($rolTypeObject);
                    $zaakTypeArray['roltypen'][] = $rolTypeObject->toArray();
                }

                $zaakTypeArray['statustypen'][] = $statusTypeArray;
            }
        }

        return $zaakTypeArray;
    }

    /**
     * Maps the resultaatTypen from xxllnc to zgw.
     *
     * @param array $caseType This is the xxllnc casetype.
     * @param array $zaakTypeArray This is the ZGW ZaakType array.
     *
     * @return array $zaakTypeArray This is the ZGW ZaakType array with the added resultaattypen.
     */
    private function mapResultaatTypen(array $caseType, array $zaakTypeArray): array
    {
        // Manually map results to resultaattypen
        if (isset($caseType['instance']['results'])) {
            $zaakTypeArray['resultaattypen'] = [];
            foreach ($caseType['instance']['results'] as $result) {
                $resultaatTypeArray = [];
                $result['type'] && $resultaatTypeArray['omschrijving'] = $result['type'];
                $result['label'] && $resultaatTypeArray['toelichting'] = $result['label'];
                $resultaatTypeArray['selectielijstklasse'] = $result['selection_list'] ?? 'http://localhost';
                $result['type_of_archiving'] && $resultaatTypeArray['archiefnominatie'] = $result['type_of_archiving'];
                $result['period_of_preservation'] && $resultaatTypeArray['archiefactietermijn'] = $result['period_of_preservation'];

                $zaakTypeArray['resultaattypen'][] = $resultaatTypeArray;
            }
        }

        return $zaakTypeArray;
    }

    /**
     * Makes sure this action has all the gateway objects it needs.
     * 
     * @return bool false if some object couldn't be fetched
     */
    private function getRequiredGatewayObjects(): bool
    {
        // Get xxllnc source
        if (!isset($this->xxllncAPI) && !$this->xxllncAPI = $this->sourceRepo->findOneBy(['location' => 'https://development.zaaksysteem.nl/api/v1'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find Source: Xxllnc API');

            return false;
        }

        // Get ZaakType schema
        if (!isset($this->zaakTypeSchema) && !$this->zaakTypeSchema = $this->schemaRepo->findOneBy(['name' => 'ZaakType'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find Schema: ZaakType');

            return false;
        }

        // Get ZaakType schema
        if (!isset($this->rolTypeSchema) && !$this->rolTypeSchema = $this->schemaRepo->findOneBy(['name' => 'RolType'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find Schema: RolType');

            return false;
        }

        // Get Catalogus object
        $catalogusSchema = $this->schemaRepo->findOneBy(['reference' => 'https://vng.opencatalogi.nl/schemas/ztc.catalogus.schema.json']);
        if (!$catalogusSchema || (!isset($this->catalogusObject) && !$this->catalogusObject = $this->objectRepo->findOneBy(['entity' => $catalogusSchema]))) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find schema: https://vng.opencatalogi.nl/schemas/ztc.catalogus.schema.json or a catalogus object');

            return false;
        }

        // if (!isset($this->caseTypeMapping) && !$this->caseTypeMapping = $this->mappingRepo->findOneBy(['reference' => 'https://development.zaaksysteem.nl/api/v1/casetype'])) {
        //     isset($this->io) && $this->io->error('No mapping found for https://development.zaaksysteem.nl/api/v1/casetype');

        //     return null;
        // }

        return true;
    }

    /**
     * Creates or updates a casetype to zaaktype.
     * 
     * @param array $caseType CaseType from the Xxllnc API
     *
     * @return void|null
     */
    public function caseTypeToZaakType(array $caseType)
    {  
        if (!isset($caseType['reference'])) {
            isset($this->io) && $this->io->error("CaseType has no id (reference)");

            return null;
        }

        // Find or create synchronization object
        $synchronization = $this->synchronizationService->findSyncBySource($this->xxllncAPI, $this->zaakTypeSchema, $caseType['reference']);
        if (!$zaakTypeObject = $synchronization->getObject()) {
            $zaakTypeObject = new ObjectEntity($this->zaakTypeSchema);
            $this->entityManager->persist($zaakTypeObject);
        }
        $zaakTypeArray = $zaakTypeObject->toArray();

        // Customly map the xxllnc casetype to zgw zaaktype
        isset($this->io) && $this->io->info("Mapping casetype: {$caseType['reference']}");
        $zaakTypeArray = $this->mapStatusAndRolTypen($caseType, $zaakTypeArray);
        $zaakTypeArray = $this->mapResultaatTypen($caseType, $zaakTypeArray);
        $zaakTypeArray['catalogus'] = $this->catalogusObject->getId()->toString();

        $zaakTypeObject->hydrate($zaakTypeArray);
        $this->entityManager->persist($zaakTypeObject);
        $zaakTypeID = $zaakTypeObject->getId()->toString();

        // Update catalogus with new zaaktype
        isset($this->io) && $this->io->info("Updating catalogus with zaaktype: $zaakTypeID");
        $linkedZaakTypen = $this->catalogusObject->getValue('zaaktypen')->toArray() ?? [];
        $this->catalogusObject->setValue('zaaktypen', array_merge($linkedZaakTypen, [$zaakTypeID]));
        $this->entityManager->persist($this->catalogusObject);

        // $synchronization->setMapping($this->caseTypeMapping);
        // $synchronization = $this->synchronizationService->synchronize($synchronization, $zaakTypeObject->toArray());
        
        $synchronization = $this->synchronizationService->handleSync($synchronization, $zaakTypeObject->toArray());
        isset($this->io) && $this->io->success("Created/updated zaaktype: $zaakTypeID");

        return $synchronization->getObject();
    }

    /**
     * Creates or updates a ZGW ZaakType from a xxllnc casetype with the use of the CoreBundle.
     *
     * @param ?array $data          Data from the handler where the xxllnc casetype is in.
     * @param ?array $configuration Configuration from the Action where the ZaakType entity id is stored in.
     *
     * @return void|null
     */
    public function xxllncToZGWZaakTypeHandler(?array $data = [], ?array $configuration = [])
    {
        isset($this->io) && $this->io->success('xxllncToZGWZaakType triggered');

        // Get schemas, sources and other gateway objects
        if (!$this->getRequiredGatewayObjects()) {
            return null;
        }

        // Fetch the xxllnc casetypes
        isset($this->io) && $this->io->info('Fetching xxllnc casetypes');
        try {
            $xxllncCaseTypes = $this->callService->getAllResults($this->xxllncAPI, '/casetype');
        } catch (Exception $e) {
            isset($this->io) && $this->io->error("Failed to fetch: {$e->getMessage()}");

            return null;
        }
        $caseTypeCount = count($xxllncCaseTypes);
        isset($this->io) && $this->io->success("Fetched $caseTypeCount casetypes");

        $createdZaakTypeCount = 0;
        foreach ($xxllncCaseTypes as $caseType) {
            $this->caseTypeToZaakType($caseType) && $createdZaakTypeCount = $createdZaakTypeCount + 1;
        }
        isset($this->io) && $this->io->success("Created $createdZaakTypeCount zaaktypen from the $caseTypeCount fetched casetypes");
    }
}
