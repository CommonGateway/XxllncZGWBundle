<?php
/**
 * The ZGWToXxllncService handles the sending of ZGW objects to the xxllnc v1 api.
 *
 * By mapping, posting and creating a synchronization. Only works if the ztc zaaktype also exists in the xxllnc api.
 *
 * @author Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>, Sarai Misidjan <sarai@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Entity\Value;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\SynchronizationService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Safe\json_encode;

class ZGWToXxllncService
{

    /**
     * @var EntityManagerInterface $entityManager.
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SynchronizationService $syncService.
     */
    private SynchronizationService $syncService;

    /**
     * @var DocumentService
     */
    private DocumentService $documentService;

    /**
     * @var MappingService $mappingService.
     */
    private MappingService $mappingService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var SymfonyStyle $style.
     */
    public array $data;

    /**
     * @var array $configuration.
     */
    private array $configuration;

    /**
     * @var Source|null $xxllncAPI.
     */
    public ?Source $xxllncAPI;

    /**
     * @var Schema|null $xxllncZaakSchema.
     */
    public ?Schema $xxllncZaakSchema;

    /**
     * @var LoggerInterface $logger.
     */
    private LoggerInterface $logger;


    /**
     * __construct.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        MappingService $mappingService,
        LoggerInterface $pluginLogger,
        SynchronizationService $syncService,
        DocumentService $documentService,
        GatewayResourceService $resourceService
    ) {
        $this->entityManager   = $entityManager;
        $this->mappingService  = $mappingService;
        $this->logger          = $pluginLogger;
        $this->syncService     = $syncService;
        $this->resourceService = $resourceService;
        $this->documentService = $documentService;

    }//end __construct()


    /**
     * Saves case to xxllnc by POST or PUT request.
     *
     * @param array                $caseArray       Case object as array.
     * @param ObjectEntity         $caseObject      Case object as ObjectEntity.
     * @param Synchronization|null $synchronization Earlier created synchronization object.
     * @param string|null          $type            zaak or besluit.
     *
     * @return bool True if succesfully saved to xxllnc
     *
     * @todo Make function smaller and more readable
     */
    public function sendCaseToXxllnc(array $caseArray, ObjectEntity $caseObject, ?Synchronization $synchronization = null, ?string $type = 'zaak')
    {
        switch ($type) {
        case 'zaak':
            $resourceId = $caseArray['zgwZaak'];
            break;
        case 'besluit':
            $resourceId = $caseArray['zgwBesluit'];
            break;
        }

        $objectId = $resourceId;

        // If we have a sync with a sourceId we can do a PUT.
        if ($synchronization && $synchronization->getSourceId()) {
            $endpoint     = "/case/{$synchronization->getSourceId()}/update";
            $unsetMapping = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseUnsetPUT.mapping.json', 'xxllnc-zgw-bundle');
        }

        // If we have dont have a sync or sourceId we can do a POST.
        if ($synchronization === null
            || ($synchronization !== null && $synchronization->getSourceId() === null)
        ) {
            $synchronization = new Synchronization($this->xxllncAPI, $this->xxllncZaakSchema);
            $endpoint        = '/case/create';
            $unsetMapping    = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseUnsetPOST.mapping.json', 'xxllnc-zgw-bundle');
        }

        if ($unsetMapping instanceof Mapping == false) {
            return false;
        }

        // Unset unwanted properties.
        $caseArray = $this->mappingService->mapping($unsetMapping, $caseArray);
        $method    = 'POST';
        $this->logger->warning("$method a case to xxllnc ($type ID: $objectId) ".json_encode($caseArray));

        // Method is always POST in the xxllnc api for creating and updating (not needed to pass here).
        $responseBody = $this->syncService->synchronizeTemp($synchronization, $caseArray, $caseObject, $this->xxllncZaakSchema, $endpoint, 'result.reference');
        $this->entityManager->persist($synchronization);
        $this->entityManager->flush();
        $caseId = $synchronization->getSourceId();

        return $caseId ?? false;

    }//end sendCaseToXxllnc()


    /**
     * Searches for an already created case object for when this case has already been synced and we need to update it or creates a new one.
     *
     * @param array  $zaakArrayObject
     * @param string $type
     *
     * @return ObjectEntity|array $caseObject
     */
    public function getCaseObject(array $zaakArrayObject, string $type = 'case')
    {
        switch ($type) {
        case 'case':
            $name = 'zgwZaak';
            break;
        case 'besluit':
            $name = 'zgwBesluit';
            break;
        }

        // Get needed attribute so we can find the already existing case object
        $zgwZaakAttribute = $this->entityManager->getRepository('App:Attribute')->findOneBy(['entity' => $this->xxllncZaakSchema, 'name' => $name]);
        if ($zgwZaakAttribute === null) {
            return [];
        }

        // Find or create case object.
        $caseValue = $this->entityManager->getRepository('App:Value')->findOneBy(['stringValue' => $zaakArrayObject['_self']['id'], 'attribute' => $zgwZaakAttribute]);
        if ($caseValue instanceof Value) {
            $caseObject = $caseValue->getObjectEntity();
        } else {
            $caseObject = new ObjectEntity($this->xxllncZaakSchema);
        }

        return $caseObject;

    }//end getCaseObject()


    /**
     * Checks if we need register zaakinformatieobjecten at the xxllnc api to add them to the case we will send later.
     *
     * @param array $zaakArrayObject The zaak as array.
     *
     * @return array $zaakArrayObject The zaak as array.
     */
    private function checkDocuments(array $zaakArrayObject): array
    {
        // If the zaakinformatieobjecten are already synced we need their source id (number) so we can add them to the case mapping.
        foreach ($zaakArrayObject['zaakinformatieobjecten'] as $key => $zaakInfoObject) {
            $zaakArrayObject['zaakinformatieobjecten'][$key]['xxllncDocumentNumber'] = $this->documentService->checkCustomNumber($zaakInfoObject, $this->xxllncAPI, 'zaakInfoObject');
            $zaakArrayObject['zaakinformatieobjecten'][$key]['xxllncReferenceId']    = $this->documentService->checkCustomNumber($zaakInfoObject, $this->xxllncAPI, 'enkelvoudigInfoObject');
            if (isset($zaakArrayObject['zaakinformatieobjecten'][$key]['xxllncDocumentNumber']) === false || isset($zaakArrayObject['zaakinformatieobjecten'][$key]['xxllncReferenceId']) === false) {
                unset($zaakArrayObject['zaakinformatieobjecten'][$key]);
                $this->logger->error("Ignoring infoobject {$zaakInfoObject['_self']['id']} because the document number or reference could not be created at the xxllnc api.");
            }

            break;
        }

        return $zaakArrayObject;

    }//end checkDocuments()


    /**
     * Maps zgw zaak to xxllnc case.
     *
     * @param  string       $casetypeId      The caseType id.
     * @param  ObjectEntity $zaakTypeObject  ZGW ZaakType object.
     * @param  array        $zaakArrayObject The data array of a zaak Object.
     * @return array        $this->data Data which we entered the function with.
     *
     * @throws Exception
     *
     * @return string|null
     */
    public function mapZGWToXxllnc(string $casetypeId, ObjectEntity $zaakTypeObject, array $zaakArrayObject): array
    {
        if (isset($zaakArrayObject['verantwoordelijkeOrganisatie']) === false) {
            $this->logger->error('verantwoordelijkeOrganisatie is not set');

            return [];
        }

        $bsn = ($zaakArrayObject['rollen'][0]['betrokkeneIdentificatie']['inpBsn'] ?? $zaakArrayObject['verantwoordelijkeOrganisatie']) ?? null;
        if ($bsn === null) {
            $this->logger->error('No bsn found in a rol->betrokkeneIdentificatie->inpBsn or verantwoordelijke organisatie.');

            return [];
        }

        $mapping = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncZaakToCase.mapping.json', 'xxllnc-zgw-bundle');
        if ($mapping instanceof Mapping === false) {
            return [];
        }

        // Check all zaakinformatieobjecten of this Zaak. So we can check if we need to sync those indiviually.
        $zaakArrayObject = $this->checkDocuments($zaakArrayObject);

        // Map ZGW Zaak to xxllnc case.
        $zaakArrayObject = array_merge($zaakArrayObject, ['bsn' => $bsn, 'caseTypeId' => $casetypeId]);
        $caseArray       = $this->mappingService->mapping($mapping, $zaakArrayObject);

        $caseObject = $this->getCaseObject($zaakArrayObject);
        $caseObject->hydrate($caseArray);
        $this->entityManager->persist($caseObject);

        $synchronization = null;

        // Only get synchronization that has a sourceId.
        if ($caseObject->getSynchronizations() && isset($caseObject->getSynchronizations()[0]) === true && $caseObject->getSynchronizations()[0]->getSourceId()) {
            $synchronization = $caseObject->getSynchronizations()[0];
        }

        // Unset empty keys.
        $caseArray = array_filter($caseArray);

        $sourceId = $this->sendCaseToXxllnc($caseArray, $caseObject, $synchronization);
        if ($sourceId === false) {
            return [];
        }

        // Not needed anymore.
        return $caseArray;

    }//end mapZGWToXxllnc()


    /**
     * Gets zaaktype id on multple ways.
     *
     * @return string|bool $zaakTypeId or bool if not found.
     */
    private function getZaakTypeId()
    {
        if (isset($this->data['zaaktype']) === true && is_array($this->data['zaaktype']) === false && Uuid::isValid($this->data['zaaktype']) === true) {
            return $this->data['zaaktype'];
        }

        if (isset($this->data['zaaktype']['_self']['id']) === true) {
            return $this->data['zaaktype']['_self']['id'];
        }

        if (isset($this->data['embedded']['zaaktype']['_self']['id']) === true) {
            return $this->data['embedded']['zaaktype']['_self']['id'];
        }

        if (isset($this->data['zaaktype']) === true && filter_var($this->data['zaaktype'], FILTER_VALIDATE_URL) !== false) {
            $id = substr($this->data['zaaktype'], (strrpos($this->data['zaaktype'], '/') + 1));
            if (Uuid::isValid($id) === true) {
                return $id;
            }
        }

        $this->logger->error('No zaaktype id found on zaak in ZGWToXxllncService');

        return false;

    }//end getZaakTypeId()


    /**
     * Handles all code to send a zgw zaak as a xxllnc case to the xxllnc v1 api.
     *
     * @param string|null $zaakTypeId The id of the zaaktype
     *
     * @return array empty
     * @throws Exception
     */
    public function syncZaakToXxllnc(): array
    {
        $this->logger->debug('function syncZaakToXxllnc triggered');

        $this->xxllncZaakSchema = $this->resourceService->getSchema('https://development.zaaksysteem.nl/schema/xxllnc.zaakPost.schema.json', 'xxllnc-zgw-bundle');
        $this->xxllncAPI        = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'xxllnc-zgw-bundle');

        $zaakTypeId = $this->getZaakTypeId();
        if ($zaakTypeId === false) {
            return [];
        }

        $zaakTypeObject = $this->entityManager->find('App:ObjectEntity', $zaakTypeId);
        if ($zaakTypeObject instanceof ObjectEntity === false) {
            $this->logger->error("Aborting zaak sync to xxllnc, ZaakType not found with id: $zaakTypeId.");
            return [];
        }

        $synchronizations = $zaakTypeObject->getSynchronizations();
        if (isset($synchronizations[0]) === false || $synchronizations[0]->getSourceId() === null) {
            $this->logger->error('ZaakType has no synchronization or sourceId, aborting case sync to xxllnc.');

            return [];
        }

        $casetypeId = $synchronizations[0]->getSourceId();

        $zaakArrayObject = $this->entityManager->find('App:ObjectEntity', $this->data['_self']['id'])->toArray();

        if (isset($this->xxllncZaakSchema) === false || isset($this->xxllncAPI) === false || isset($zaakArrayObject) === false) {
            $this->logger->error('Some objects needed could not be found in ZGWToXxllncService: $this->xxllncZaakSchema or $this->xxllncAPI or $zaakArrayObject');

            return [];
        }

        $this->mapZGWToXxllnc($casetypeId, $zaakTypeObject, $zaakArrayObject);

        return [];

    }//end syncZaakToXxllnc()


    /**
     * Updates xxllnc case synchronization when zaak sub objects are created through their own endpoints.
     *
     * @param array|null $data
     * @param array|null $configuration
     *
     * @throws Exception
     *
     * @return array
     *
     * @todo Make function smaller
     */
    public function updateZaakHandler(?array $data = [], ?array $configuration = []): array
    {
        $this->configuration = $configuration;
        $zaakId              = '';

        if (isset($data['response']['zaak']) === false) {
            $this->logger->error('No zaak found in the object that should update a zaak.');

            return [];
        }

        if (is_array($data['response']['zaak']) === true) {
            $zaakId = $data['response']['zaak']['_self']['id'];
        } else if (isset($zaakId) === false) {
            $zaakId = substr($data['response']['zaak'], (strrpos($data['response']['zaak'], '/') + 1));
        } else if (isset($zaakId) === false) {
            $zaakSubObject = $this->entityManager->find('App:ObjectEntity', $this->data['_self']['id']);
            $zaakId        = $zaakSubObject->getValue('zaak')->getId()->toString();
        }

        if (Uuid::isValid($zaakId) === false && filter_var($data['response']['zaak'], FILTER_VALIDATE_URL) !== false) {
            $zaakId = substr($data['response']['zaak'], (strrpos($data['response']['zaak'], '/') + 1));
        }

        if (Uuid::isValid($zaakId) === false) {
            $this->logger->error('No zaak id found in the object that should update a zaak.');

            return ['response' => []];
        }

        $zaakObject = $this->entityManager->find('App:ObjectEntity', $zaakId);
        if ($zaakObject === null) {
            $this->logger->error("No zaak object found with id: $zaakId.");

            return ['response' => []];
        }

        $this->data = $zaakObject->toArray();

        return ['response' => $this->syncZaakToXxllnc()];

    }//end updateZaakHandler()


    /**
     * Creates or updates a ZGW Zaak that is created through the normal /zaken endpoint.
     *
     * @param ?array $data          Data from the handler where the xxllnc casetype is in.
     * @param ?array $configuration Configuration from the Action where the Zaak entity id is stored in.
     *
     * @throws Exception
     *
     * @return array $this->data Data which we entered the function with.
     *
     * @todo Make function smaller and more readable.
     */
    public function zgwToXxllncHandler(?array $data = [], ?array $configuration = []): array
    {
        $this->data          = $data['response'];
        $this->configuration = $configuration;

        $zaakTypeId = $this->getZaakTypeId();
        if ($zaakTypeId === false) {
            return [];
        }

        return ['response' => $this->syncZaakToXxllnc($zaakTypeId)];

    }//end zgwToXxllncHandler()


}//end class
