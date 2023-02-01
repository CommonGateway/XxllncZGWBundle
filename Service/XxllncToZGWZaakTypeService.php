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

    private ObjectRepository $objectEntityRepo;
    private ObjectRepository $entityRepo;
    private ObjectRepository $sourceRepository;

    private ?Source $xxllncSource;
    private ?Schema $zaakTypeSchema;

    private array $mappingIn;
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

        $this->objectEntityRepo = $this->entityManager->getRepository(ObjectEntity::class);
        $this->entityRepo = $this->entityManager->getRepository(Schema::class);
        $this->sourceRepository = $this->entityManager->getRepository(Source::class);

        $this->mappingIn = [
            'identificatie'                   => 'instance.legacy.zaaktype_id|string',
            'onderwerp'                       => 'instance.title',
            'indicatieInternOfExtern'         => 'instance.trigger',
            'doorlooptijd'                    => 'instance.properties.lead_time_legal.weken',
            'servicenorm'                     => 'instance.properties.lead_time_service.weken',
            'vertrouwelijkheidaanduiding'     => 'instance.properties.designation_of_confidentiality',
            'verlengingMogelijk'              => 'instance.properties.extension',
            'trefwoorden'                     => 'instance.subject_types',
            'publicatieIndicatie'             => 'instance.properties.publication|bool',
            'verantwoordingsrelatie'          => 'instance.properties.supervisor_relation|array',
            'omschrijving'                    => 'instance.title',
            'opschortingEnAanhoudingMogelijk' => 'instance.properties.suspension|bool',
        ];

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
     * @param array $zaakTypeArray This is the ZGW ZaakType array.
     *
     * @return array $zaakTypeArray This is the ZGW ZaakType array with the added statustypen.
     */
    private function mapStatusAndRolTypen(array $zaakTypeArray, Schema $rolTypeEntity): array
    {
        $zaakTypeArray['roltypen'] = [];

        // Manually map phases to statustypen
        if (isset($this->data['instance']['phases'])) {
            $zaakTypeArray['statustypen'] = [];
            $zaakTypeArray['eigenschappen'] = [];

            foreach ($this->data['instance']['phases'] as $phase) {
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
                    $rolTypeObject = new ObjectEntity($rolTypeEntity);
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
     * @param array $zaakTypeArray This is the ZGW ZaakType array.
     *
     * @return array $zaakTypeArray This is the ZGW ZaakType array with the added resultaattypen.
     */
    private function mapResultaatTypen(array $zaakTypeArray): array
    {
        // Manually map results to resultaattypen
        if (isset($this->data['instance']['results'])) {
            $zaakTypeArray['resultaattypen'] = [];
            foreach ($this->data['instance']['results'] as $result) {
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

    // /**
    //  * Maps the eigenschappen from xxllnc to zgw.
    //  *
    //  * @param array $zaakTypeArray This is the ZGW ZaakType array.
    //  *
    //  * @return array $zaakTypeArray This is the ZGW ZaakType array with the added eigenschappen.
    //  */
    // private function mapEigenschappen(array $zaakTypeArray): array
    // {
    //     // // Manually map properties to eigenschappen
    //     $zaakTypeArray['eigenschappen'] = [];
    //     $propertyIgnoreList = ['lead_time_legal', 'lead_time_service', 'designation_of_confidentiality', 'extension', 'publication', 'supervisor_relation', 'suspension'];
    //     foreach ($this->data['instance']['properties'] as $propertyName => $propertyValue) {
    //         !in_array($propertyName, $propertyIgnoreList) && $zaakTypeArray['eigenschappen'][] = ['naam' => $propertyName, 'definitie' => $propertyName];
    //     }

    //     return $zaakTypeArray;
    // }

    /**
     * Finds or creates a ObjectEntity from the ZaakType Schema.
     *
     * @param Schema $zaakTypeEntity This is the ZaakType Schema in the gateway.
     *
     * @return ObjectEntity $zaakTypeObjectEntity This is the ZGW ZaakType ObjectEntity.
     */
    private function getZaakTypeObjectEntity(Schema $zaakTypeEntity): ObjectEntity
    {
        // Find already existing zgwZaakType by $this->data['reference']
        $zaakTypeObjectEntity = $this->objectEntityRepo->findOneBy(['externalId' => $this->data['reference'], 'entity' => $zaakTypeEntity]);

        // Create new empty ObjectEntity if no ObjectEntity has been found
        if (!$zaakTypeObjectEntity instanceof ObjectEntity) {
            $zaakTypeObjectEntity = new ObjectEntity();
            $zaakTypeObjectEntity->setEntity($zaakTypeEntity);
        }

        return $zaakTypeObjectEntity;
    }

    /**
     * Makes sure this action has all the gateway objects it needs.
     * 
     * @return null|void
     */
    private function getRequiredGatewayObjects()
    {
        // get xxllnc source
        if (!isset($this->xxllncSource) && !$this->xxllncSource = $this->sourceRepository->findOneBy(['location' => 'https://api.github.com'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find Source: Xxllnc API');

            return null;
        }
        // get ZaakType schema
        if (!isset($this->zaakTypeSchema) && !$this->zaakTypeSchema = $this->schemaRepository->findOneBy(['name' => 'ZaakType'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find Schema: ZaakType');

            return null;
        }
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
        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['id']);

        isset($this->io) && $this->io->comment('Mapping object'.$repository['name']);
        isset($this->io) && $this->io->comment('The mapping object '.$repositoryMapping);

        isset($this->io) && $this->io->comment('Checking repository '.$repository['name']);
        $synchronization->setMapping($repositoryMapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository);

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
        $this->getRequiredGatewayObjects();

        // Fetch the xxllnc casetypes
        try {
            $response = $this->callService->call($this->xxllncSource, '/casetype');
            $xxllncCaseTypes = $this->callService->decodeResponse($this->xxllncSource, $response);
        } catch (Exception $e) {
            isset($this->io) && $this->io->error('Failed to fetch');

            return null;
        }

        foreach ($xxllncCaseTypes as $caseType) {
            $this->caseTypeToZaakType($caseType);
        }

        // START OLD CODE
        // var_dump('XxllncToZGWZaakType triggered');
        // $this->data = $data['response'];
        // $this->configuration = $configuration;


        // This can speed up test sync for local development
        // if ($this->data['reference'] !== '28ee7737-a3d8-4c5d-8760-c23554630248') {
        //     return $this->data;
        // }

        // // Find ZGW Type entities by id from config
        // $zaakTypeEntity = $this->entityRepo->find($configuration['entities']['ZaakType']);
        // $rolTypeEntity = $this->entityRepo->find($configuration['entities']['RolType']);
        // $catalogusObjectEntity = $this->objectEntityRepo->find($configuration['objects']['Catalogus']);

        // if (!isset($zaakTypeEntity)) {
        //     throw new \Exception('ZaakType entity could not be found, check XxllncToZGWZaakTypeHandler Action config');
        // }
        // if (!isset($rolTypeEntity)) {
        //     throw new \Exception('RolType entity could not be found, check XxllncToZGWZaakTypeHandler Action config');
        // }
        // if (!isset($catalogusObjectEntity)) {
        //     throw new \Exception('Catalogus object could not be found, check XxllncToZGWZaakTypeHandler Action config');
        // }

        // $zaakTypeObjectEntity = $this->getZaakTypeObjectEntity($zaakTypeEntity);

        // // Map and set default values from xxllnc casetype to zgw zaaktype
        // $zgwZaakTypeArray = $this->translationService->dotHydrator(isset($skeletonIn) ? array_merge($this->data, $this->skeletonIn) : $this->data, $this->data, $this->mappingIn);
        // if (!isset($zgwZaakTypeArray['omschrijving']) || empty($zgwZaakTypeArray['omschrijving'])) {
        //     return ['response' => $zaakTypeObjectEntity->toArray()];
        // }

        // $zgwZaakTypeArray['instance'] = null;
        // $zgwZaakTypeArray['embedded'] = null;

        // $zgwZaakTypeArray = $this->mapStatusAndRolTypen($zgwZaakTypeArray, $rolTypeEntity);
        // $zgwZaakTypeArray = $this->mapResultaatTypen($zgwZaakTypeArray);
        // // old code
        // // $zgwZaakTypeArray = $this->mapEigenschappen($zgwZaakTypeArray);

        // $zgwZaakTypeArray['catalogus'] = $catalogusObjectEntity->getId()->toString();

        // $zaakTypeObjectEntity->hydrate($zgwZaakTypeArray);

        // $zaakTypeObjectEntity->setExternalId($this->data['reference']);
        // $zaakTypeObjectEntity = $this->synchronizationService->setApplicationAndOrganization($zaakTypeObjectEntity);

        // $this->entityManager->persist($zaakTypeObjectEntity);

        // // Update catalogus with new zaaktype
        // $linkedZaakTypen = $catalogusObjectEntity->getValue('zaaktypen')->toArray() ?? [];

        // $catalogusObjectEntity->setValue('zaaktypen', array_merge($linkedZaakTypen, [$zaakTypeObjectEntity->getId()->toString()]));

        // $this->entityManager->persist($catalogusObjectEntity);

        // $this->entityManager->flush();
        // var_dump('XxllncToZGWZaakType finished with id: '.$zaakTypeObjectEntity->getId()->toString());
        // return ['response' => $zaakTypeObjectEntity->toArray()];
        // END OLD CODE

    }
}
