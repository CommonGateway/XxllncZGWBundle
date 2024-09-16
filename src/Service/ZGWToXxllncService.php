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
     * @var Source|null $xxllncAPIV2.
     */
    public ?Source $xxllncAPIV2;

    /**
     * @var Schema|null $xxllncZaakSchema.
     */
    public ?Schema $xxllncZaakSchema;

    /**
     * @var Schema|null $xxllncZaakTypeSchema.
     */
    public ?Schema $xxllncZaakTypeSchema;

    /**
     * @var LoggerInterface $logger.
     */
    private LoggerInterface $logger;

    /**
     * @var SymfonyStyle $style.
     */
    private SymfonyStyle $style;


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

        if ($caseId !== null) {
            $this->logger->error("Synchronizng Zaak $resourceId to zaaksysteem failed with request body: " . json_encode($caseArray) . " and response body: " . json_encode($responseBody));

        }

        return $caseId ?? false;

    }//end sendCaseToXxllnc()


    /**
     * Saves casetype to xxllnc by POST or PUT request.
     *
     * @param array                $caseTypeArray   CaseType object as array.
     * @param ObjectEntity         $caseTypeObject  Case object as ObjectEntity.
     * @param Synchronization|null $synchronization Earlier created synchronization object.
     *
     * @return bool True if succesfully saved to xxllnc
     *
     * @todo Make function smaller and more readable
     */
    public function sendCaseTypeToXxllnc(array $caseTypeArray, ObjectEntity $caseTypeObject, ?Synchronization $synchronization = null)
    {
        $objectId = $caseTypeArray['zgwZaakType'];
        $endpoint = "/admin/catalog/create_versioned_casetype";
        $unsetMapping = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseTypeUnsetPOST.mapping.json', 'xxllnc-zgw-bundle');

        // If we have a sync with a sourceId we can do a PUT.
        if ($synchronization && $synchronization->getSourceId()) {
            $endpoint = "/admin/catalog/update_versioned_casetype";
            $unsetMapping = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseTypeUnsetPUT.mapping.json', 'xxllnc-zgw-bundle');
        } else {
            $synchronization = new Synchronization($this->xxllncAPIV2, $this->xxllncZaakTypeSchema);
        }

        if ($unsetMapping instanceof Mapping == false) {
            $this->logger->error('Could not get unset mapping, syncing zaaktype failed for zaaktype: ' . $objectId);
            return false;
        }

        $caseTypeArray = $this->mappingService->mapping($unsetMapping, $caseTypeArray);
        $method    = 'POST';
        $jsonEncodedBody = json_encode($caseTypeArray);
        $this->logger->warning("$method a casetype to xxllnc (ID: $objectId) ".json_encode($caseTypeArray));

        // Method is always POST in the xxllnc api for creating and updating (not needed to pass here).
        $this->syncService->synchronizeTemp($synchronization, $caseTypeArray, $caseTypeObject, $this->xxllncZaakTypeSchema, $endpoint, 'data.id');
        $this->entityManager->persist($synchronization);
        $this->entityManager->flush();
        $caseTypeId = $synchronization->getSourceId();

        if ($caseTypeId !== null) {
            $this->logger->warning("Successfully created/updated casetype with sourceId: $caseTypeId");
        } else {
            $this->logger->error("Something went wrong creating or updating casetype with send request body: $jsonEncodedBody");
        }

        return $caseTypeId ?? false;

    }//end sendCaseTypeToXxllnc()


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
     * Searches for an already created caseType object for when this caseType has already been synced and we need to update it or creates a new one.
     *
     * @param array $zaakTypeArrayObject
     *
     * @return ObjectEntity|array $caseTypeObject
     */
    public function getCaseTypeObject(array $zaakTypeArrayObject)
    {
        // Get needed attribute so we can find the already existing case object
        $zgwZaakTypeAttribute = $this->entityManager->getRepository('App:Attribute')->findOneBy(['entity' => $this->xxllncZaakTypeSchema, 'name' => 'zgwZaakType']);
        if ($zgwZaakTypeAttribute === null) {
            $this->logger->error('Could not find zgwZaakType attribute');

            return [];
        }

        // Find or create case object.
        $caseTypeValue = $this->entityManager->getRepository('App:Value')->findOneBy(['stringValue' => $zaakTypeArrayObject['_self']['id'], 'attribute' => $zgwZaakTypeAttribute]);
        if ($caseTypeValue instanceof Value) {
            $caseTypeObject = $caseTypeValue->getObjectEntity();
        } else {
            $caseTypeObject = new ObjectEntity($this->xxllncZaakTypeSchema);
        }

        return $caseTypeObject;

    }//end getCaseTypeObject()


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
     * Maps zgw zaaktype to xxllnc casetype.
     *
     * @param string|null  $casetypeId          The caseType id.
     * @param ObjectEntity $zaakTypeObject      ZGW ZaakType object.
     * @param array        $zaakTypeArrayObject ZGW ZaakType object.
     *
     * @return array caseType array.
     */
    public function mapZGWZaakTypeToXxllnc(?string $casetypeId = null, ObjectEntity $zaakTypeObject, array $zaakTypeArrayObject): array
    {
        $mapping = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncZaakTypeToCaseType.mapping.json', 'xxllnc-zgw-bundle');
        if ($mapping instanceof Mapping === false) {
            return [];
        }

        $zaakTypeArrayObject = array_merge($zaakTypeArrayObject, [
            'casetypeUuid' => $casetypeId ?? Uuid::uuid4()->toString(),
            'casetypeVersionUuid' =>  Uuid::uuid4()->toString(),
            'catalogFolderUuid' => $this->configuration['catalogFolderUuid']
        ]);

        // Map ZGW Zaak to xxllnc case.
        $caseTypeArray = $this->mappingService->mapping($mapping, $zaakTypeArrayObject);

        $caseTypeObject = $this->getCaseTypeObject($zaakTypeArrayObject);
        $caseTypeObject->hydrate($caseTypeArray);
        $this->entityManager->persist($caseTypeObject);


        // Only get synchronization that has a sourceId.
        $synchronization = null;
        if ($caseTypeObject->getSynchronizations() && isset($caseTypeObject->getSynchronizations()[0]) === true && $caseTypeObject->getSynchronizations()[0]->getSourceId()) {
            $synchronization = $caseTypeObject->getSynchronizations()[0];
        }

        $sourceId = $this->sendCaseTypeToXxllnc($caseTypeArray, $caseTypeObject, $synchronization);
        if ($sourceId === false) {
            return [];
        }

        // Not needed anymore.
        return $caseTypeArray;

    }//end mapZGWZaakTypeToXxllnc()


    /**
     * Gets zaaktype id on multple ways.
     *
     * @param bool $isZaakType If this object in data is a zaaktype or not.
     *
     * @return string|bool $zaakTypeId or bool if not found.
     */
    private function getZaakTypeId(?bool $isZaakType = false)
    {
        if ($isZaakType === true && isset($this->data['_self']['id']) === true) {
            return $this->data['_self']['id'];
        };

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
            return basename($this->data['zaaktype']);
        }


        if (isset($this->data['zaaktype']) === true && filter_var($this->data['zaaktype'], FILTER_VALIDATE_URL) !== false) {
            $zaakTypeId = basename($this->data['zaaktype']);
        } elseif (isset($this->data['_self']['id']) === true) {
            $zaakTypeId = $this->data['_self']['id'];
        };

        $this->logger->error('No zaaktype id found on zaak in ZGWToXxllncService with data: ' . json_encode($this->data));

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

        $zaakTypeId = $this->getZaakTypeId();
        if ($zaakTypeId === false) {
            return [];
        }

        $this->xxllncZaakSchema = $this->resourceService->getSchema('https://development.zaaksysteem.nl/schema/xxllnc.zaakPost.schema.json', 'xxllnc-zgw-bundle');
        $this->xxllncAPI        = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'xxllnc-zgw-bundle');

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
     * Handles all code to send a zgw zaak as a xxllnc case to the xxllnc v1 api.
     *
     * @param string|null $zaakTypeId The id of the zaaktype
     *
     * @return array empty
     * @throws Exception
     */
    public function syncZaakTypeToXxllnc(): array
    {
        $this->logger->debug('function syncZaakTypeToXxllnc triggered');

        if (isset($this->configuration['catalogFolderUuid']) === false || Uuid::isValid($this->configuration['catalogFolderUuid']) === false) {
            $this->logger->error('catalogFolderUuid not configured or is invalid uuid');
            return [];
        }

        $xxllncZaakTypeSchemaReference = 'https://development.zaaksysteem.nl/schema/xxllnc.zaakTypePost.schema.json';
        $this->xxllncZaakTypeSchema = $this->resourceService->getSchema($xxllncZaakTypeSchemaReference, 'xxllnc-zgw-bundle');
        if (isset($this->xxllncZaakTypeSchema) === false) {
            return [];
        }

        $xxllncAPIV2Reference = 'https://development.zaaksysteem.nl/source/xxllnc.zaaksysteemv2.source.json';
        $this->xxllncAPIV2     = $this->resourceService->getSource($xxllncAPIV2Reference, 'xxllnc-zgw-bundle');
        if (isset($this->xxllncAPIV2) === false) {
            return [];
        }

        $zaakTypeId = $this->getZaakTypeId();
        if (isset($zaakTypeId) === false) {
            $this->logger->error('No _self.id found on ZaakType or no zaaktype field found on this object, cant sync to xxllnc');
            return [];
        }

        $zaakTypeObject = $this->entityManager->find('App:ObjectEntity', $zaakTypeId);
        if ($zaakTypeObject instanceof ObjectEntity === false) {
            $this->logger->error("Aborting ZaakType sync to xxllnc, ZaakType not found with id: $zaakTypeId.");
            return [];
        }

        $synchronizations = $zaakTypeObject->getSynchronizations();
        $casetypeId = null;
        if (isset($synchronizations[0]) === true) {
            $casetypeId = $synchronizations[0]->getSourceId();
        }

        $this->mapZGWZaakTypeToXxllnc($casetypeId, $zaakTypeObject, $zaakTypeObject->toArray());

        return [];

    }//end syncZaakTypeToXxllnc()


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

        switch ($data['entity']) {
            case 'https://vng.opencatalogi.nl/schemas/ztc.rolType.schema.json':
            case 'https://vng.opencatalogi.nl/schemas/ztc.statusType.schema.json':
                if (isset($this->data['zaaktype']) === false) {
                    return [];
                }

                return ['response' => $this->syncZaakTypeToXxllnc()];
            case 'https://vng.opencatalogi.nl/schemas/ztc.zaakType.schema.json':
                return ['response' => $this->syncZaakTypeToXxllnc()];
            case 'https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json':
                $zaakTypeId = $this->getZaakTypeId();
                if ($zaakTypeId === false) {
                    return [];
                }
                return ['response' => $this->syncZaakToXxllnc($zaakTypeId)];
        }

    }//end zgwToXxllncHandler()


}//end class
