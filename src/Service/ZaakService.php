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
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;
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
     * __construct.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        SynchronizationService $synchronizationService,
        CallService $callService,
        ZaakTypeService $zaakTypeService,
        GatewayResourceService $resourceService,
        MappingService $mappingService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager          = $entityManager;
        $this->synchronizationService = $synchronizationService;
        $this->callService            = $callService;
        $this->zaakTypeService        = $zaakTypeService;
        $this->resourceService        = $resourceService;
        $this->mappingService         = $mappingService;
        $this->logger                 = $pluginLogger;

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
     * @var string xxllnc casetype
     *
     * @return ObjectEntity|null $zaakType object or null
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
     * @return ObjectEntity|null
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
     * Gets documents (zaakinformatieobjecten) for a case.
     *
     * @param string $caseId xxllnc case id
     *
     * @return array
     */
    private function getCaseDocuments(string $caseId): array
    {
        isset($this->style) === true && $this->style->info("Checking for documents on this case (zaakinformatieobjecten)..");
        $this->logger->info("Checking for documents on this case (zaakinformatieobjecten)..");
        try {
            return $this->callService->getAllResults($this->xxllncAPI, "/case/$caseId/document", [], 'result.instance.rows');
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to fetch case documents: {$e->getMessage()}");
            $this->logger->error("Failed to fetch case documents: {$e->getMessage()}");

            return [];
        }
    }//end getCaseDocuments()


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
    public function syncCase(array $case, bool $flush = true): ObjectEntity
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

        $hydrationService = new HydrationService($this->synchronizationService, $this->entityManager);
        $caseAndRelatedObjects = array_merge(
            $case,
            [
                'zaaktype'        => $zaakTypeObject->toArray(),
                'bronorganisatie' => ($this->configuration['bronorganisatie'] ?? 'No bronorganisatie set'),
                'documents'       => $caseDocuments
            ]
        );
        $zaakArray       = $this->mappingService->mapping($caseMapping, $caseAndRelatedObjects);


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

        $createdZaakCount = 0;
        $flushCount       = 0;
        foreach ($xxllncCases as $case) {
            if ($this->syncCase($case) instanceof ObjectEntity === false) {
                isset($this->style) === true && $this->style->error("Could not sync a case");
                $this->logger->error("Could not sync a case");

                continue;
            }

            $createdZaakCount = ($createdZaakCount + 1);
            $flushCount       = ($flushCount + 1);

            // Flush every 20
            if ($flushCount == 20) {
                $this->entityManager->flush();
                $this->entityManager->flush();
                $flushCount = 0;
            }//end if
        }//end foreach

        isset($this->style) === true && $this->style->success("Created $createdZaakCount zaken from the $caseCount fetched cases");
        $this->logger->info("Created $createdZaakCount zaken from the $caseCount fetched cases");

    }//end zaakHandler()


}//end class
