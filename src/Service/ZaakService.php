<?php
/**
 * This class handles the synchronizations of xxllnc cases to zgw zrc zaken.
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
use App\Entity\ObjectEntity;
use App\Event\ActionEvent;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\HydrationService;
use CommonGateway\ZGWBundle\Service\DRCService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

class ZaakService
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
    private SymfonyStyle $style;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var DRCService
     */
    private DRCService $drcService;

    /**
     * @var ZaakTypeService
     */
    private ZaakTypeService $zaakTypeService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var Source|null
     */
    private ?Source $xxllncAPI;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var EventDispatcherInterface The event dispatcher.
     */
    private EventDispatcherInterface $eventDispatcher;


    /**
     * __construct.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        SynchronizationService $synchronizationService,
        CallService $callService,
        DRCService $drcService,
        ZaakTypeService $zaakTypeService,
        GatewayResourceService $resourceService,
        MappingService $mappingService,
        LoggerInterface $pluginLogger,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->entityManager          = $entityManager;
        $this->synchronizationService = $synchronizationService;
        $this->callService            = $callService;
        $this->drcService             = $drcService;
        $this->zaakTypeService        = $zaakTypeService;
        $this->resourceService        = $resourceService;
        $this->mappingService         = $mappingService;
        $this->logger                 = $pluginLogger;
        $this->eventDispatcher        = $eventDispatcher;

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
     * Gets a existing ZaakType or syncs one from the xxllnc api.
     *
     * @var string $caseTypeId xxllnc casetype identifier.
     *
     * @return ObjectEntity|null $zaakType ObjectEntity or null.
     */
    public function getZaakTypeByExtId(string $caseTypeId)
    {
        $zaakTypeSchema = $this->resourceService->getSchema(
            'https://vng.opencatalogi.nl/schemas/ztc.zaakType.schema.json',
            'common-gateway/xxllnc-zgw-bundle'
        );

        // Find existing zaaktype
        $zaakTypeSync = $this->synchronizationService->findSyncBySource($this->xxllncAPI, $zaakTypeSchema, $caseTypeId);
        if ($zaakTypeSync && $zaakTypeSync->getObject()) {
            isset($this->style) === true && $this->style->info("ZaakType found with id: {$zaakTypeSync->getObject()->getId()->toString()}.");
            $this->logger->info("ZaakType found with id: {$zaakTypeSync->getObject()->getId()->toString()}.");

            return $zaakTypeSync->getObject();
        }

        isset($this->style) === true && $this->style->info("ZaakType not found, trying to fetch and synchronise casetype with id: $caseTypeId..");
        $this->logger->info("ZaakType not found, trying to fetch and synchronise casetype with id: $caseTypeId..");
        // Fetch and create new zaaktype
        $zaakTypeObject = $this->zaakTypeService->getZaakType($caseTypeId);
        if ($zaakTypeObject) {
            return $zaakTypeObject;
        }

        isset($this->style) === true && $this->style->error("Could not find or create ZaakType for id: $caseTypeId");
        $this->logger->error("Could not find or create ZaakType for id: $caseTypeId");

        return null;

    }//end getZaakTypeByExtId()


    /**
     * Checks if we have a casetype in our case and get a ZaakType.
     *
     * @param array $case xxllnc case object
     *
     * @return ObjectEntity|null $zaakTypeObject ObjectEntity or null.
     */
    private function checkZaakType(array $case)
    {
        // If no casetype found return null.
        if (isset($case['instance']['casetype']['reference']) === false) {
            isset($this->style) === true && $this->style->error("Case has no casetype");
            $this->logger->error("Case has no casetype");

            return null;
        }

        // Get ZGW ZaakType.
        isset($this->style) === true && $this->style->info("Searching for related ZaakType (xxllnc casetype) with sourceId: {$case['instance']['casetype']['reference']}..");
        $zaakTypeObject = $this->getZaakTypeByExtId($case['instance']['casetype']['reference']);
        if ($zaakTypeObject === null) {
            return null;
        }

        return $zaakTypeObject;

    }//end checkZaakType()


    /**
     * Gets the actual document from a different endpoint that has the metadata.
     *
     * @param string $documentNumber document number (not the id).
     *
     * @return array $this->callService->decodeResponse() Decoded requested document as PHP array.
     */
    private function getActualDocument(string $documentNumber): array
    {
        try {
            isset($this->style) === true && $this->style->info("Fetching actual document: $documentNumber..");
            $this->logger->info("Fetching actual document: $documentNumber..");
            $response = $this->callService->call($this->xxllncAPI, "/document/get_by_number/$documentNumber", 'GET', [], false, false);
            return $this->callService->decodeResponse($this->xxllncAPI, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to fetch actual document: $documentNumber, message:  {$e->getMessage()}");
            $this->logger->error("Failed to fetch actual document: $documentNumber, message:  {$e->getMessage()}");

            return [];
        }

    }//end getActualDocument()


    /**
     * Gets the inhoud of the document from a different endpoint that has the metadata.
     *
     * @param string $documentId document id.
     * @param Source $xxllncV2   Need V2 api for this request.
     *
     * @return string|null $this->callService->decodeResponse() Decoded requested document as PHP array.
     */
    private function getInhoudDocument(string $documentId, Source $xxllncV2): ?string
    {
        try {
            isset($this->style) === true && $this->style->info("Fetching inhoud document: $documentId..");
            $this->logger->info("Fetching inhoud document: $documentId..");
            $response = $this->callService->call($xxllncV2, "/document/download_document?id=$documentId", 'GET', [], false, false);
            return $this->callService->decodeResponse($xxllncV2, $response, 'application/pdf')['base64'];
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to fetch inhoud of document: $documentId, message:  {$e->getMessage()}");
            $this->logger->error("Failed to fetch inhoud of document: $documentId, message:  {$e->getMessage()}");
            return null;
        }

    }//end getInhoudDocument()


    /**
     * Gets documents (zaakinformatieobjecten) for a case without metadata.
     *
     * @param string $caseId xxllnc case id
     *
     * @return array $documents Decoded documents in an PHP array.
     */
    private function getCaseDocuments(string $caseId): array
    {
        isset($this->style) === true && $this->style->info("Checking for documents on this case (zaakinformatieobjecten)..");
        $this->logger->info("Checking for documents on this case (zaakinformatieobjecten)..");
        // Need V2 api to fetch document inhoud.
        $xxllncV2 = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteemv2.source.json', 'xxllnc-zgw-bundle');
        if ($xxllncV2 === null) {
            return [];
        }

        try {
            $response        = $this->callService->call($xxllncV2, "/document/search_document?case_uuid=$caseId", 'GET', [], false, false);
            $documents       = $this->callService->decodeResponse($xxllncV2, $response);
            $actualDocuments = [];
            foreach ($documents['data'] as $key => $document) {
                $actualDocuments[$key]           = $this->getActualDocument($document['meta']['document_number']);
                $actualDocuments[$key]['inhoud'] = $this->getInhoudDocument($document['id'], $xxllncV2);
            }

            return $actualDocuments;
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to fetch case documents: {$e->getMessage()}");
            $this->logger->error("Failed to fetch case documents: {$e->getMessage()}");

            return [];
        }

    }//end getCaseDocuments()


    /**
     * Creates file endpoints for a given ObjectEntity instance representing a "zaak".
     *
     * This function processes the zaak, retrieves an endpoint for downloading
     * an 'EnkelvoudigInformatieObject', logs an error if the endpoint is not found,
     * and creates or updates files associated with 'zaakinformatieobjecten'.
     *
     * @param ObjectEntity $zaak The zaak object to process.
     *
     * @return void
     */
    private function createFileEndpoints(ObjectEntity $zaak): void
    {
        $zaakArray = $zaak->toArray();

        $downloadEndpoint = $this->resourceService->getEndpoint('https://vng.opencatalogi.nl/endpoints/drc.downloadEnkelvoudigInformatieObject.endpoint.json', 'common-gateway/zgw-bundle');
        if (isset($downloadEndpoint) === false) {
            isset($this->style) === true && $this->style->error("Could not find download endpoint with ref: https://vng.opencatalogi.nl/endpoints/drc.downloadEnkelvoudigInformatieObject.endpoint.json.");
            $this->logger->error("Could not find download endpoint with ref: https://vng.opencatalogi.nl/endpoints/drc.downloadEnkelvoudigInformatieObject.endpoint.json.");

            return;
        }

        foreach ($zaakArray['zaakinformatieobjecten'] as $zaakinformatieobject) {
            if (isset($zaakinformatieobject['informatieobject']) === true) {
                $informatieObject = $this->entityManager->find('App:ObjectEntity', $zaakinformatieobject['informatieobject']['_self']['id']);
                $this->drcService->createOrUpdateFile($informatieObject, $zaakinformatieobject['informatieobject'], $downloadEndpoint, false);
            }
        }

    }//end createFileEndpoints()


    /**
     * Synchronises a case to zgw zaak based on the data retrieved from the Xxllnc api.
     *
     * @param array $case  The case to synchronize.
     * @param bool  $flush Wether or not the case should be flushed already (not functional at this time)
     *
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\SyntaxError
     *
     * @return ObjectEntity The resulting zaak object.
     */
    public function syncCase(array $case, bool $flush = true): ?ObjectEntity
    {
        // 0. Get required config objects.
        $zaakSchema  = $this->resourceService->getSchema(
            'https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json',
            'common-gateway/xxllnc-zgw-bundle'
        );
        $xxllncAPI   = $this->resourceService->getSource(
            'https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json',
            'common-gateway/xxllnc-zgw-bundle'
        );
        $caseMapping = $this->resourceService->getMapping(
            'https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseToZGWZaak.mapping.json',
            'common-gateway/xxllnc-zgw-bundle'
        );

        // 1. Check related ZaakType if its already synced, if not sync.
        $zaakTypeObject = $this->checkZaakType($case);
        if ($zaakTypeObject instanceof ObjectEntity === false) {
            isset($this->style) === true && $this->style->error("ZaakType for case {$case['reference']} could not be found or synced, aborting.");
            $this->logger->error("ZaakType for case {$case['reference']} could not be found or synced, aborting.");

            return null;
        }

        // 2. Fetch documents (zaakinformatieobject) for this case.
        $caseDocuments = $this->getCaseDocuments($case['reference']);

        // 3. Map the case and all its subobjects.
        isset($this->style) === true && $this->style->info("Mapping case to zaak..");
        $this->logger->info("Mapping case to zaak..");

        $hydrationService      = new HydrationService($this->synchronizationService, $this->entityManager);
        $caseAndRelatedObjects = array_merge(
            $case,
            [
                'zaaktype'        => $zaakTypeObject->toArray(),
                'bronorganisatie' => ($this->configuration['bronorganisatie'] ?? 'No bronorganisatie set'),
                'documents'       => $caseDocuments,
            ]
        );

        $zaakArray = $this->mappingService->mapping($caseMapping, $caseAndRelatedObjects);

        // 4. Check or create synchronization for case and its subobjects.
        isset($this->style) === true && $this->style->info("Checking subobjects for synchronizations..");
        $this->logger->info("Checking subobjects for synchronizations..");

        $zaak = $hydrationService->searchAndReplaceSynchronizations(
            $zaakArray,
            $xxllncAPI,
            $zaakSchema,
            $flush,
            true
        );

        if (isset($zaakArray['zaakinformatieobjecten']) === true) {
            $this->createFileEndpoints($zaak);
        }

        isset($this->style) === true && $this->style->info("Zaak object created/updated with id: {$zaak->getId()->toString()}");
        $this->logger->info("Zaak object created/updated with id: {$zaak->getId()->toString()}");

        return $zaak;

    }//end syncCase()


    /**
     * Makes sure this action has the xxllnc api source.
     *
     * @return bool false if some object couldn't be fetched.
     */
    private function getXxllncAPI()
    {
        // Get xxllnc source
        if (isset($this->xxllncAPI) === false
            && ($this->xxllncAPI = $this->resourceService->getSource(
                'https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json',
                'common-gateway/xxllnc-zgw-bundle'
            )) === null
        ) {
            isset($this->style) === true && $this->style->error("Could not find Source: Xxllnc API");
            $this->logger->error("Could not find Source: Xxllnc API");

            return false;
        }

        return true;

    }//end getXxllncAPI()


    /**
     * Fetches a xxllnc case and maps it to a zgw zaak.
     *
     * @param array  $configuration https://development.zaaksysteem.nl/action/xxllnc.Zaak.action.json configuration
     * @param string $caseID        This is the xxllnc case id.
     *
     * @return object|null $zaakTypeObject Fetched and mapped ZGW ZaakType.
     */
    public function getZaak(array $configuration, string $caseID)
    {
        $this->configuration = $configuration;
        $this->getXxllncAPI();

        try {
            isset($this->style) === true && $this->style->info("Fetching case: $caseID..");
            $this->logger->info("Fetching case: $caseID..");
            $response = $this->callService->call($this->xxllncAPI, "/case/$caseID", 'GET', [], false, false);
            $case     = $this->callService->decodeResponse($this->xxllncAPI, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to fetch case: $caseID, message:  {$e->getMessage()}");
            $this->logger->error("Failed to fetch case: $caseID, message:  {$e->getMessage()}");

            return null;
        }

        isset($this->style) === true && $this->zaakTypeService->setStyle($this->style);
        isset($this->style) === true && $this->style->info("Succesfully fetched xxllnc case.");
        $this->logger->info("Succesfully fetched xxllnc case.");

        return $this->syncCase($case['result']);

    }//end getZaak()


    /**
     * Creates or updates a ZGW Zaak from a xxllnc case with the use of mapping.
     *
     * @param ?array $data          Data from the handler where the xxllnc case is in.
     * @param ?array $configuration Configuration from the Action where the Zaak entity id is stored in.
     *
     * @return ?array $this->data Data which we entered the function with.
     */
    public function zaakHandler(?array $data = [], ?array $configuration = [])
    {
        $this->configuration = $configuration;

        // Get schemas, sources and other gateway objects.
        if ($this->getXxllncAPI() === false) {
            return null;
        }
        
        if (isset($data['caseId']) === true) {
            $this->getZaak($configuration, $data['caseId']);
            return $data;
        }

        if (isset($data['body']['case_uuid']) === true) {
            $this->getZaak($configuration, $data['body']['case_uuid']);
            return $data;
        }

        isset($this->style) === true && $this->zaakTypeService->setStyle($this->style);

        // Fetch the xxllnc cases.
        isset($this->style) === true && $this->style->info("Fetching xxllnc cases");
        $this->logger->info("Fetching xxllnc cases");

        $callConfig = [];
        if (isset($configuration['query']) === true) {
            $callConfig['query'] = $configuration['query'];
        }

        try {
            $xxllncCases = $this->callService->getAllResults($this->xxllncAPI, '/case', $callConfig, 'result.instance.rows');
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to fetch: {$e->getMessage()}");
            $this->logger->error("Failed to fetch: {$e->getMessage()}");

            return null;
        }

        $caseCount = count($xxllncCases);
        isset($this->style) === true && $this->style->success("Fetched $caseCount cases");
        $this->logger->info("Fetched $caseCount cases");

        foreach ($xxllncCases as $case) {
            $event = new ActionEvent(
                'commongateway.action.event',
                ['caseId' => $case['reference']],
                'xxllnc.case.received'
            );
            $this->eventDispatcher->dispatch($event, 'commongateway.action.event');
        }//end foreach

    }//end zaakHandler()


}//end class
